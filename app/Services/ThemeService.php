<?php

namespace App\Services;

use App\Models\Theme;
use App\Models\ThemeRating;
use App\Services\Contracts\ThemeServiceInterface;
use Illuminate\Support\Facades\DB;

class ThemeService implements ThemeServiceInterface
{
    public function __construct(
        private ThemeValidationService $validationService
    ) {
    }

    public function listThemes(array $filters, int $page, int $perPage, string $sort): array
    {
        $query = Theme::query()->where('is_public', true);

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
        $themes = $query->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        return [
            'data' => $themes->toArray(),
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function getTheme(int $id): ?array
    {
        $theme = Theme::with(['user', 'ratings.user', 'forkedFrom'])->find($id);

        return $theme ? $theme->toArray() : null;
    }

    public function createTheme(int $userId, array $data): array
    {
        $themeData = $data['theme_data'] ?? [];

        $errors = $this->validationService->validateThemeData($themeData);
        if (! empty($errors)) {
            throw new \InvalidArgumentException('Theme validation failed: ' . implode(', ', $errors));
        }

        return DB::transaction(function () use ($userId, $data, $themeData) {
            $theme = Theme::create([
                'name' => $data['name'],
                'author' => $data['author'],
                'version' => $data['version'],
                'description' => $data['description'] ?? null,
                'user_id' => $userId,
                'theme_data' => $themeData,
                'is_public' => $data['is_public'] ?? true,
            ]);

            return $theme->fresh()->toArray();
        });
    }

    public function updateTheme(int $themeId, int $userId, array $data): array
    {
        $theme = Theme::find($themeId);

        if (! $theme) {
            throw new \InvalidArgumentException('Theme not found');
        }

        if ($theme->user_id !== $userId) {
            throw new \RuntimeException('You do not have permission to update this theme', 403);
        }

        $updateData = [];
        if (isset($data['name'])) {
            $updateData['name'] = $data['name'];
        }
        if (isset($data['description'])) {
            $updateData['description'] = $data['description'];
        }
        if (isset($data['theme_data'])) {
            $errors = $this->validationService->validateThemeData($data['theme_data']);
            if (! empty($errors)) {
                throw new \InvalidArgumentException('Theme validation failed: ' . implode(', ', $errors));
            }
            $updateData['theme_data'] = $data['theme_data'];
        }
        if (isset($data['is_public'])) {
            $updateData['is_public'] = $data['is_public'];
        }

        $theme->update($updateData);

        return $theme->fresh()->toArray();
    }

    public function deleteTheme(int $themeId, int $userId): bool
    {
        $theme = Theme::find($themeId);

        if (! $theme) {
            throw new \InvalidArgumentException('Theme not found');
        }

        if ($theme->user_id !== $userId) {
            throw new \RuntimeException('You do not have permission to delete this theme', 403);
        }

        return $theme->delete();
    }

    public function forkTheme(int $themeId, int $userId, string $name): array
    {
        $originalTheme = Theme::find($themeId);

        if (! $originalTheme) {
            throw new \InvalidArgumentException('Theme not found');
        }

        return DB::transaction(function () use ($originalTheme, $userId, $name) {
            $newTheme = Theme::create([
                'name' => $name,
                'author' => $originalTheme->author,
                'version' => $originalTheme->version,
                'description' => "Forked from {$originalTheme->name}",
                'user_id' => $userId,
                'forked_from_id' => $originalTheme->id,
                'theme_data' => $originalTheme->theme_data,
                'is_public' => true,
            ]);

            return $newTheme->fresh()->toArray();
        });
    }

    public function rateTheme(int $themeId, int $userId, int $rating, ?string $comment): array
    {
        $theme = Theme::find($themeId);

        if (! $theme) {
            throw new \InvalidArgumentException('Theme not found');
        }

        if ($rating < 1 || $rating > 5) {
            throw new \InvalidArgumentException('Rating must be between 1 and 5');
        }

        $themeRating = ThemeRating::updateOrCreate(
            ['theme_id' => $themeId, 'user_id' => $userId],
            ['rating' => $rating, 'comment' => $comment]
        );

        $theme->updateRating();

        return $themeRating->toArray();
    }

    public function getMyThemes(int $userId, int $page, int $perPage): array
    {
        $query = Theme::query()->where('user_id', $userId);

        $total = $query->count();
        $themes = $query->orderBy('created_at', 'desc')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        return [
            'data' => $themes->toArray(),
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ];
    }
}
