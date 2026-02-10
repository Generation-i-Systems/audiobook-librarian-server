<?php

namespace App\Services\Contracts;

use Illuminate\Http\UploadedFile;

interface SkinServiceInterface
{
    public function listSkins(array $filters, int $page, int $perPage, string $sort): array;

    public function getSkin(int $id): ?array;

    public function createSkin(int $userId, array $data, UploadedFile $file): array;

    public function updateSkin(int $skinId, int $userId, array $data): array;

    public function deleteSkin(int $skinId, int $userId): bool;

    public function forkSkin(int $skinId, int $userId, string $name): array;

    public function rateSkin(int $skinId, int $userId, int $rating, ?string $comment): array;

    public function getMySkins(int $userId, int $page, int $perPage): array;

    public function createBlankSkin(int $userId): array;

    public function addCustomization(int $skinId, int $userId, array $data): array;

    public function getCustomizations(int $skinId, int $userId): array;

    public function updateManifest(int $skinId, int $userId, array $manifest): array;

    public function addAsset(int $skinId, int $userId, UploadedFile $file, string $assetPath): string;

    public function listAssets(int $skinId): array;

    public function extractAssets(int $skinId): void;

    public function buildZip(int $skinId): string;
}
