<?php

namespace App\Services\Contracts;

interface ThemeServiceInterface
{
    public function listThemes(array $filters, int $page, int $perPage, string $sort): array;

    public function getTheme(int $id): ?array;

    public function createTheme(int $userId, array $data): array;

    public function updateTheme(int $themeId, int $userId, array $data): array;

    public function deleteTheme(int $themeId, int $userId): bool;

    public function forkTheme(int $themeId, int $userId, string $name): array;

    public function rateTheme(int $themeId, int $userId, int $rating, ?string $comment): array;

    public function getMyThemes(int $userId, int $page, int $perPage): array;
}
