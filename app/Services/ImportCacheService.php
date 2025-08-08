<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;

class ImportCacheService
{
    protected Filesystem $files;
    protected string $cacheFile;
    protected array $cache = [];
    protected bool $cacheEnabled = true;
    protected int $maxCacheAge = 86400; // 24 hours
    protected int $maxCacheSize = 100; // MB

    public function __construct(Filesystem $files, array $options = [])
    {
        $this->files = $files;
        $this->cacheFile = $options['cache_file'] ?? storage_path('app/import_cache.json');
        $this->cacheEnabled = $options['enabled'] ?? true;
        $this->maxCacheAge = $options['max_age'] ?? 86400;
        $this->maxCacheSize = $options['max_size_mb'] ?? 100;

        if ($this->cacheEnabled) {
            $this->loadCache();
        }
    }

    /**
     * Initialize cache
     */
    public function initializeCache(): void
    {
        if (!$this->cacheEnabled) {
            return;
        }

        $this->cleanupCache();
        $this->displayCacheStatistics();
    }

    /**
     * Load cache from file
     */
    protected function loadCache(): void
    {
        if (!$this->files->exists($this->cacheFile)) {
            $this->cache = [
                'version' => '1.0',
                'created_at' => time(),
                'tasks' => [],
                'metadata' => [],
            ];
            return;
        }

        try {
            $content = $this->files->get($this->cacheFile);
            $data = json_decode($content, true);

            if (!$data || !isset($data['version'])) {
                throw new \Exception('Invalid cache format');
            }

            $this->cache = $data;
        } catch (\Exception $e) {
            Log::warning("Failed to load cache, starting fresh: " . $e->getMessage());
            $this->cache = [
                'version' => '1.0',
                'created_at' => time(),
                'tasks' => [],
                'metadata' => [],
            ];
        }
    }

    /**
     * Save cache to file
     */
    public function saveCache(): void
    {
        if (!$this->cacheEnabled) {
            return;
        }

        try {
            $this->cache['updated_at'] = time();
            $content = json_encode($this->cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            $directory = dirname($this->cacheFile);
            if (!$this->files->isDirectory($directory)) {
                $this->files->makeDirectory($directory, 0755, true);
            }

            $this->files->put($this->cacheFile, $content);
        } catch (\Exception $e) {
            Log::error("Failed to save cache: " . $e->getMessage());
        }
    }

    /**
     * Clean up old cache entries
     */
    protected function cleanupCache(): void
    {
        if (!$this->cacheEnabled) {
            return;
        }

        $currentTime = time();
        $removedCount = 0;

        // Clean up old task results
        foreach ($this->cache['tasks'] as $key => $result) {
            if (
                isset($result['timestamp']) &&
                ($currentTime - $result['timestamp']) > $this->maxCacheAge
            ) {
                unset($this->cache['tasks'][$key]);
                $removedCount++;
            }
        }

        // Clean up old metadata
        foreach ($this->cache['metadata'] as $key => $metadata) {
            if (
                isset($metadata['timestamp']) &&
                ($currentTime - $metadata['timestamp']) > $this->maxCacheAge
            ) {
                unset($this->cache['metadata'][$key]);
                $removedCount++;
            }
        }

        if ($removedCount > 0) {
            Log::info("Cleaned up {$removedCount} old cache entries");
        }

        // Check cache file size
        if ($this->files->exists($this->cacheFile)) {
            $sizeMB = $this->files->size($this->cacheFile) / 1024 / 1024;
            if ($sizeMB > $this->maxCacheSize) {
                $this->truncateCache();
            }
        }
    }

    /**
     * Truncate cache to reduce size
     */
    protected function truncateCache(): void
    {
        // Remove oldest 25% of entries
        $taskCount = count($this->cache['tasks']);
        $metadataCount = count($this->cache['metadata']);

        if ($taskCount > 0) {
            $tasks = $this->cache['tasks'];
            uasort($tasks, fn ($a, $b) => ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0));
            $this->cache['tasks'] = array_slice($tasks, intval($taskCount * 0.25), null, true);
        }

        if ($metadataCount > 0) {
            $metadata = $this->cache['metadata'];
            uasort($metadata, fn ($a, $b) => ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0));
            $this->cache['metadata'] = array_slice($metadata, intval($metadataCount * 0.25), null, true);
        }

        Log::info("Truncated cache to reduce file size");
    }

    /**
     * Get cache key for audiobook
     */
    public function getCacheKey(array $audiobook, string $prefix = ''): string
    {
        $identifier = $audiobook['path'] ?? json_encode($audiobook);
        $modTime = $this->getDirectoryModificationTime($audiobook['path'] ?? '');
        return ($prefix ? $prefix . '_' : '') . md5($identifier . '_' . $modTime);
    }

    /**
     * Get cached result
     */
    public function getCachedResult(array $audiobook, string $taskType): ?array
    {
        if (!$this->cacheEnabled) {
            return null;
        }

        $cacheKey = $this->getCacheKey($audiobook, $taskType);

        if (!isset($this->cache['tasks'][$cacheKey])) {
            return null;
        }

        $cached = $this->cache['tasks'][$cacheKey];

        // Check if cache is still valid
        if (
            isset($cached['timestamp']) &&
            (time() - $cached['timestamp']) > $this->maxCacheAge
        ) {
            unset($this->cache['tasks'][$cacheKey]);
            return null;
        }

        return $cached['result'] ?? null;
    }

    /**
     * Set cached result
     */
    public function setCachedResult(array $audiobook, string $taskType, array $result): void
    {
        if (!$this->cacheEnabled) {
            return;
        }

        $cacheKey = $this->getCacheKey($audiobook, $taskType);

        $this->cache['tasks'][$cacheKey] = [
            'result' => $result,
            'timestamp' => time(),
            'task_type' => $taskType,
            'path' => $audiobook['path'] ?? 'unknown'
        ];
    }

    /**
     * Cache metadata
     */
    public function cacheMetadata(string $key, array $metadata): void
    {
        if (!$this->cacheEnabled) {
            return;
        }

        $this->cache['metadata'][$key] = [
            'data' => $metadata,
            'timestamp' => time(),
        ];
    }

    /**
     * Get cached metadata
     */
    public function getCachedMetadata(string $key): ?array
    {
        if (!$this->cacheEnabled || !isset($this->cache['metadata'][$key])) {
            return null;
        }

        $cached = $this->cache['metadata'][$key];

        if (
            isset($cached['timestamp']) &&
            (time() - $cached['timestamp']) > $this->maxCacheAge
        ) {
            unset($this->cache['metadata'][$key]);
            return null;
        }

        return $cached['data'] ?? null;
    }

    /**
     * Get directory modification time
     */
    protected function getDirectoryModificationTime(string $path): int
    {
        if (!$this->files->isDirectory($path)) {
            return 0;
        }

        try {
            $latestTime = filemtime($path);

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                $fileTime = $file->getMTime();
                if ($fileTime > $latestTime) {
                    $latestTime = $fileTime;
                }
            }

            return $latestTime;
        } catch (\Exception $e) {
            return $this->files->lastModified($path) ?: 0;
        }
    }

    /**
     * Display cache statistics
     */
    public function displayCacheStatistics(): array
    {
        if (!$this->cacheEnabled) {
            return ['enabled' => false];
        }

        $stats = [
            'enabled' => true,
            'task_count' => isset($this->cache['tasks']) ? count($this->cache['tasks']) : 0,
            'metadata_count' => isset($this->cache['metadata']) ? count($this->cache['metadata']) : 0,
            'cache_file' => $this->cacheFile,
            'file_size' => 0,
            'file_size_formatted' => '0 B',
        ];

        if ($this->files->exists($this->cacheFile)) {
            $stats['file_size'] = $this->files->size($this->cacheFile);
            $stats['file_size_formatted'] = $this->formatBytes($stats['file_size']);
        }

        return $stats;
    }

    /**
     * Format bytes for display
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 1) . ' ' . $units[$unitIndex];
    }

    /**
     * Clear all cache
     */
    public function clearCache(): void
    {
        $this->cache = [
            'version' => '1.0',
            'created_at' => time(),
            'tasks' => [],
            'metadata' => [],
        ];

        if ($this->files->exists($this->cacheFile)) {
            $this->files->delete($this->cacheFile);
        }
    }

    /**
     * Check if cache is enabled
     */
    public function isEnabled(): bool
    {
        return $this->cacheEnabled;
    }

    /**
     * Enable/disable cache
     */
    public function setEnabled(bool $enabled): void
    {
        $this->cacheEnabled = $enabled;
    }
}
