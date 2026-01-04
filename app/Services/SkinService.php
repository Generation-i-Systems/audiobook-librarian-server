<?php

namespace App\Services;

use App\Models\Skin;
use App\Models\SkinRating;
use App\Services\Contracts\SkinServiceInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SkinService implements SkinServiceInterface
{
    public function __construct(
        private SkinValidationService $validationService
    ) {
    }

    public function listSkins(array $filters, int $page, int $perPage, string $sort): array
    {
        $query = Skin::query()->where('is_public', true);

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('author', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $query = match ($sort) {
            'popular' => $query->orderBy('download_count', 'desc'),
            'top_rated' => $query->where('rating_count', '>', 0)->orderBy('average_rating', 'desc'),
            default => $query->orderBy('created_at', 'desc'),
        };

        $total = $query->count();
        $skins = $query->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        return [
            'data' => $skins->toArray(),
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function getSkin(int $id): ?array
    {
        $skin = Skin::with(['user', 'ratings.user', 'forkedFrom'])->find($id);

        return $skin ? $skin->toArray() : null;
    }

    public function createSkin(int $userId, array $data, UploadedFile $file): array
    {
        $errors = $this->validationService->validateZipStructure($file);
        if (! empty($errors)) {
            throw new \InvalidArgumentException('ZIP validation failed: ' . implode(', ', $errors));
        }

        $manifest = $this->validationService->extractManifest($file->getRealPath());
        if (! $manifest) {
            throw new \InvalidArgumentException('Failed to extract manifest.json from ZIP file');
        }

        $manifestErrors = $this->validationService->validateManifest($manifest);
        if (! empty($manifestErrors)) {
            throw new \InvalidArgumentException('Manifest validation failed: ' . implode(', ', $manifestErrors));
        }

        return DB::transaction(function () use ($userId, $data, $file, $manifest) {
            $skin = Skin::create([
                'name' => $data['name'],
                'author' => $data['author'],
                'version' => $data['version'],
                'description' => $data['description'] ?? null,
                'user_id' => $userId,
                'file_path' => 'temp',
                'file_size' => $file->getSize(),
                'manifest' => $manifest,
                'is_public' => $data['is_public'] ?? true,
            ]);

            $filePath = $file->storeAs("skins/{$skin->id}", 'skin.zip', 'local');
            $skin->update(['file_path' => $filePath]);

            $previewContent = $this->validationService->extractPreview($file->getRealPath());
            if ($previewContent) {
                Storage::disk('public')->put("skin-previews/{$skin->id}.png", $previewContent);
                $skin->update(['preview_path' => "skin-previews/{$skin->id}.png"]);
            }

            return $skin->fresh()->toArray();
        });
    }

    public function updateSkin(int $skinId, int $userId, array $data): array
    {
        $skin = Skin::find($skinId);

        if (! $skin) {
            throw new \InvalidArgumentException('Skin not found');
        }

        if ($skin->user_id !== $userId) {
            throw new \RuntimeException('You do not have permission to update this skin', 403);
        }

        $updateData = [];
        if (isset($data['name'])) {
            $updateData['name'] = $data['name'];
        }
        if (isset($data['description'])) {
            $updateData['description'] = $data['description'];
        }
        if (isset($data['is_public'])) {
            $updateData['is_public'] = $data['is_public'];
        }

        $skin->update($updateData);

        return $skin->fresh()->toArray();
    }

    public function deleteSkin(int $skinId, int $userId): bool
    {
        $skin = Skin::find($skinId);

        if (! $skin) {
            throw new \InvalidArgumentException('Skin not found');
        }

        if ($skin->user_id !== $userId) {
            throw new \RuntimeException('You do not have permission to delete this skin', 403);
        }

        Storage::disk('local')->deleteDirectory("skins/{$skin->id}");

        if ($skin->preview_path) {
            Storage::disk('public')->delete($skin->preview_path);
        }

        return $skin->delete();
    }

    public function forkSkin(int $skinId, int $userId, string $name): array
    {
        $originalSkin = Skin::find($skinId);

        if (! $originalSkin) {
            throw new \InvalidArgumentException('Skin not found');
        }

        return DB::transaction(function () use ($originalSkin, $userId, $name) {
            $newSkin = Skin::create([
                'name' => $name,
                'author' => $originalSkin->author,
                'version' => $originalSkin->version,
                'description' => "Forked from {$originalSkin->name}",
                'user_id' => $userId,
                'forked_from_id' => $originalSkin->id,
                'file_path' => 'temp',
                'file_size' => $originalSkin->file_size,
                'manifest' => $originalSkin->manifest,
                'is_public' => true,
            ]);

            Storage::disk('local')->copy(
                $originalSkin->file_path,
                "skins/{$newSkin->id}/skin.zip"
            );
            $newSkin->update(['file_path' => "skins/{$newSkin->id}/skin.zip"]);

            if ($originalSkin->preview_path) {
                Storage::disk('public')->copy(
                    $originalSkin->preview_path,
                    "skin-previews/{$newSkin->id}.png"
                );
                $newSkin->update(['preview_path' => "skin-previews/{$newSkin->id}.png"]);
            }

            return $newSkin->fresh()->toArray();
        });
    }

    public function rateSkin(int $skinId, int $userId, int $rating, ?string $comment): array
    {
        $skin = Skin::find($skinId);

        if (! $skin) {
            throw new \InvalidArgumentException('Skin not found');
        }

        if ($rating < 1 || $rating > 5) {
            throw new \InvalidArgumentException('Rating must be between 1 and 5');
        }

        $skinRating = SkinRating::updateOrCreate(
            ['skin_id' => $skinId, 'user_id' => $userId],
            ['rating' => $rating, 'comment' => $comment]
        );

        $skin->updateRating();

        return $skinRating->toArray();
    }

    public function getMySkins(int $userId, int $page, int $perPage): array
    {
        $query = Skin::query()->where('user_id', $userId);

        $total = $query->count();
        $skins = $query->orderBy('created_at', 'desc')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        return [
            'data' => $skins->toArray(),
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ];
    }
}
