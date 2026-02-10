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

    public function createBlankSkin(int $userId): array
    {
        return DB::transaction(function () use ($userId) {
            $user = \App\Models\User::find($userId);

            $manifest = [
                'version' => '1.0',
                'skinName' => 'New Skin',
                'author' => $user->name,
                'dimensions' => [
                    'portrait' => ['width' => 360, 'height' => 640, 'aspectRatio' => 'free'],
                    'landscape' => ['width' => 640, 'height' => 360, 'aspectRatio' => 'free'],
                ],
                'theme' => [
                    'allowColorOverride' => true,
                    'defaultTheme' => 'default',
                    'embeddedThemes' => [
                        'default' => [
                            'version' => '1.0',
                            'themeName' => 'Default Theme',
                            'author' => $user->name,
                            'colors' => [
                                'primary' => '#2196F3',
                                'background' => '#121212',
                                'surface' => '#1E1E1E',
                                'text' => '#FFFFFF',
                                'accent' => '#FF4081',
                                'progressActive' => '#2196F3',
                                'progressInactive' => '#424242',
                                'timeText' => '#FFFFFF',
                                'buttonTint' => '#2196F3',
                            ],
                        ],
                    ],
                ],
                'layout' => [
                    'portrait' => [],
                    'landscape' => [],
                ],
            ];

            $skin = Skin::create([
                'name' => 'Skin-' . $userId . '-' . time(),
                'author' => $user->name,
                'version' => '1.0',
                'description' => 'Created in Designer',
                'user_id' => $userId,
                'file_path' => '',
                'file_size' => 0,
                'manifest' => $manifest,
                'is_public' => false,
            ]);

            return $skin->fresh()->toArray();
        });
    }

    public function addCustomization(int $skinId, int $userId, array $data): array
    {
        $skin = Skin::find($skinId);

        if (! $skin) {
            throw new \InvalidArgumentException('Skin not found');
        }

        $type = $data['type'];
        $value = $data['value'] ?? null;
        $filePath = null;

        if ($type === 'image' && isset($data['image']) && $data['image'] instanceof UploadedFile) {
            $path = $data['image']->store('customizations', 'public');
            $filePath = $path;
        }

        /** @var \App\Models\SkinCustomization $customization */
        $customization = $skin->customizations()->create([
            'user_id' => $userId,
            'type' => $type,
            'value' => $value,
            'file_path' => $filePath,
            'visibility' => $data['visibility'] ?? 'private',
        ]);

        $result = $customization->toArray();
        if ($customization->file_path) {
            $result['url'] = asset('storage/' . $customization->file_path);
        }

        return $result;
    }

    public function getCustomizations(int $skinId, int $userId): array
    {
        $skin = Skin::find($skinId);

        if (! $skin) {
            throw new \InvalidArgumentException('Skin not found');
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\SkinCustomization> $customizations */
        $customizations = $skin->customizations()
            ->where(function ($query) use ($userId) {
                $query->where('user_id', $userId)
                    ->orWhere('visibility', 'public');
            })
            ->with('user')
            ->get();

        return $customizations
            ->map(function ($item) {
                /** @var \App\Models\SkinCustomization $item */
                $data = $item->toArray();
                if ($item->file_path) {
                    $data['url'] = asset('storage/' . $item->file_path);
                }
                $data['author'] = $item->user->name ?? 'Unknown';

                return $data;
            })
            ->toArray();
    }

    public function updateManifest(int $skinId, int $userId, array $manifest): array
    {
        $skin = Skin::find($skinId);

        if (! $skin) {
            throw new \InvalidArgumentException('Skin not found');
        }

        // Allow owner or admin
        if ($skin->user_id !== $userId && ! auth()->user()?->is_admin) {
            throw new \RuntimeException('You do not have permission to update this skin', 403);
        }

        $manifestErrors = $this->validationService->validateManifest($manifest);
        if (! empty($manifestErrors)) {
            throw new \InvalidArgumentException('Manifest validation failed: ' . implode(', ', $manifestErrors));
        }

        $skin->update(['manifest' => $manifest]);

        return $skin->fresh()->toArray();
    }

    public function addAsset(int $skinId, int $userId, UploadedFile $file, string $assetPath): string
    {
        $skin = Skin::find($skinId);

        if (! $skin) {
            throw new \InvalidArgumentException('Skin not found');
        }

        if ($skin->user_id !== $userId && ! auth()->user()?->is_admin) {
            throw new \RuntimeException('You do not have permission to update this skin', 403);
        }

        // Sanitize path
        $assetPath = str_replace('..', '', $assetPath);
        $assetPath = ltrim($assetPath, '/');

        // Store in public disk so it's accessible by the designer
        $fullPath = "skins/{$skin->id}/assets/{$assetPath}";
        Storage::disk('public')->put($fullPath, file_get_contents($file->getRealPath()));

        return Storage::url($fullPath);
    }

    public function listAssets(int $skinId): array
    {
        // Recursively list all files in the skin's asset directory
        $files = Storage::disk('public')->allFiles("skins/{$skinId}/assets");

        return array_map(function ($file) use ($skinId) {
            return [
                'path' => $file,
                'url' => Storage::url($file),
                'name' => basename($file),
                'relative_path' => str_replace("skins/{$skinId}/", '', $file), // e.g. "assets/images/foo.png"
            ];
        }, $files);
    }

    public function buildZip(int $skinId): string
    {
        $skin = Skin::find($skinId);

        if (! $skin) {
            throw new \InvalidArgumentException('Skin not found');
        }

        $zipFileName = 'skin_generated_' . time() . '.zip';
        // Store in local disk (storage/app) not public
        $zipPath = storage_path("app/skins/{$skin->id}/{$zipFileName}");

        // Ensure directory exists
        if (!file_exists(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create ZIP file');
        }

        // Add manifest
        $zip->addFromString('manifest.json', json_encode($skin->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Add assets from public storage
        $assetFiles = Storage::disk('public')->allFiles("skins/{$skin->id}/assets");
        foreach ($assetFiles as $file) {
            // $file is like "skins/1/assets/images/foo.png"
            // We want it as "assets/images/foo.png" in the ZIP
            $zipName = str_replace("skins/{$skin->id}/", '', $file);
            $localPath = Storage::disk('public')->path($file);
            $zip->addFile($localPath, $zipName);
        }

        // Also add preview images if they exist in standard locations
        // Standard location for preview is "skin-previews/{id}.png" in public disk
        // But usually skins include "preview.png" inside the zip
        // Let's check if the skin has a preview_path
        if ($skin->preview_path && Storage::disk('public')->exists($skin->preview_path)) {
            $zip->addFile(Storage::disk('public')->path($skin->preview_path), 'preview.png');
        }

        $zip->close();

        return $zipPath;
    }

    public function extractAssets(int $skinId): void
    {
        $skin = Skin::find($skinId);

        if (! $skin || ! $skin->file_path) {
            return;
        }

        // Target directory for extracted assets
        $extractPath = Storage::disk('public')->path("skins/{$skin->id}/assets");

        // If directory exists and has files (more than . and ..), skip extraction
        if (file_exists($extractPath) && count(scandir($extractPath) ?: []) > 2) {
            return;
        }

        // Locate the ZIP file
        // createSkin stores it in 'local' disk at specific path, or relative path in file_path
        $zipFullPath = Storage::disk('local')->path($skin->file_path);

        if (! file_exists($zipFullPath)) {
            return;
        }

        // Extract
        if (! file_exists($extractPath)) {
            mkdir($extractPath, 0755, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipFullPath) === true) {
            $zip->extractTo($extractPath);
            $zip->close();

            // Cleanup manifest from assets folder since it's stored in DB
            if (file_exists("{$extractPath}/manifest.json")) {
                unlink("{$extractPath}/manifest.json");
            }
        }
    }
}
