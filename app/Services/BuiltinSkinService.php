<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Provides access to built-in skins from the client repository's src-skins directory.
 *
 * Built-in skins live on disk under CLIENT_SKINS_PATH. They are not stored in the
 * database. Users can fork them into DB-backed skins; admins can edit their
 * manifest.json files directly on disk.
 */
class BuiltinSkinService
{
    private string $basePath;

    public function __construct()
    {
        $this->basePath = rtrim(config('app.client_skins_path', ''), '/');
    }

    public function isConfigured(): bool
    {
        return $this->basePath !== '' && is_dir($this->basePath);
    }

    /**
     * List all built-in skins with their metadata.
     *
     * @return array<int, array{slug: string, name: string, author: string, version: string, description: string, preview: string|null, manifest: array}>
     */
    public function list(): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $skins = [];
        foreach (scandir($this->basePath) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $skinDir = $this->basePath . '/' . $entry;
            if (! is_dir($skinDir)) {
                continue;
            }
            $manifestPath = $skinDir . '/manifest.json';
            if (! file_exists($manifestPath)) {
                continue;
            }
            $manifest = json_decode(file_get_contents($manifestPath), true);
            if (! is_array($manifest)) {
                continue;
            }

            $skins[] = $this->buildEntry($entry, $skinDir, $manifest);
        }

        usort($skins, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $skins;
    }

    /**
     * Get a single built-in skin by slug (directory name).
     */
    public function get(string $slug): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $skinDir = $this->basePath . '/' . $slug;
        if (! is_dir($skinDir)) {
            return null;
        }

        $manifestPath = $skinDir . '/manifest.json';
        if (! file_exists($manifestPath)) {
            return null;
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (! is_array($manifest)) {
            return null;
        }

        return $this->buildEntry($slug, $skinDir, $manifest);
    }

    /**
     * Update manifest.json for a built-in skin (admin only).
     */
    public function updateManifest(string $slug, array $manifest): void
    {
        $skinDir = $this->resolveDir($slug);
        $manifestPath = $skinDir . '/manifest.json';

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \InvalidArgumentException('Failed to encode manifest JSON');
        }

        file_put_contents($manifestPath, $json . "\n");
    }

    /**
     * Upload/replace an asset file inside a built-in skin directory (admin only).
     */
    public function addAsset(string $slug, UploadedFile $file, string $relativePath): string
    {
        $skinDir = $this->resolveDir($slug);

        // Prevent path traversal
        $relativePath = ltrim($relativePath, '/');
        if (str_contains($relativePath, '..')) {
            throw new \InvalidArgumentException('Invalid asset path');
        }

        $fullPath = $skinDir . '/' . $relativePath;
        $dir = dirname($fullPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file->move($dir, basename($fullPath));

        return $relativePath;
    }

    /**
     * List assets in a built-in skin directory.
     *
     * @return array<string>
     */
    public function listAssets(string $slug): array
    {
        $skinDir = $this->resolveDir($slug);
        $assets = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($skinDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() !== 'manifest.json') {
                $assets[] = ltrim(str_replace($skinDir, '', $file->getPathname()), '/');
            }
        }

        sort($assets);

        return $assets;
    }

    /**
     * Build a ZIP of the built-in skin for forking or download.
     * Returns path to a temp ZIP file.
     */
    public function buildZip(string $slug): string
    {
        $skinDir = $this->resolveDir($slug);
        $tmpZip = tempnam(sys_get_temp_dir(), 'builtin_skin_') . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create ZIP archive');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($skinDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = ltrim(str_replace($skinDir, '', $file->getPathname()), '/');
                $zip->addFile($file->getPathname(), $relativePath);
            }
        }

        $zip->close();

        return $tmpZip;
    }

    /**
     * Serve an asset file from a built-in skin (for the preview/designer).
     */
    public function resolveAsset(string $slug, string $relativePath): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $relativePath = ltrim($relativePath, '/');
        if (str_contains($relativePath, '..')) {
            return null;
        }

        $candidates = [$slug];
        $skin = $this->get($slug);
        if ($skin && isset($skin['manifest']['skinName'])) {
            $candidates[] = $skin['manifest']['skinName'];
        }

        foreach ($candidates as $candidate) {
            $fullPath = $this->basePath . '/' . $candidate . '/' . $relativePath;
            if (file_exists($fullPath) && is_file($fullPath)) {
                return $fullPath;
            }
        }

        return null;
    }

    /**
     * Returns a URL to serve a built-in skin's preview image.
     */
    public function previewUrl(string $slug): ?string
    {
        foreach (['preview_portrait.png', 'preview.png', 'preview_landscape.png'] as $name) {
            $path = $this->basePath . '/' . $slug . '/' . $name;
            if (file_exists($path)) {
                return route('gallery.skins.builtin.asset', ['slug' => $slug, 'path' => $name]);
            }
        }

        return null;
    }

    private function buildEntry(string $slug, string $skinDir, array $manifest): array
    {
        return [
            'slug' => $slug,
            'name' => $manifest['skinName'] ?? $slug,
            'author' => $manifest['author'] ?? '',
            'version' => $manifest['version'] ?? '1.0',
            'description' => $manifest['description'] ?? '',
            'preview' => $this->previewUrl($slug),
            'manifest' => $manifest,
            'dir' => $skinDir,
        ];
    }

    private function resolveDir(string $slug): string
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('CLIENT_SKINS_PATH is not configured');
        }

        // Prevent path traversal
        if (str_contains($slug, '/') || str_contains($slug, '..')) {
            throw new \InvalidArgumentException('Invalid skin slug');
        }

        $skinDir = $this->basePath . '/' . $slug;
        if (! is_dir($skinDir)) {
            throw new \InvalidArgumentException("Built-in skin not found: {$slug}");
        }

        return $skinDir;
    }
}
