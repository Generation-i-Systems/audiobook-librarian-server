<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class FileSystemService
{
    /**
     * Get all files from a directory recursively
     */
    public function getAllFilesFromDirectory(string $path): array
    {
        $files = [];

        if (!File::isDirectory($path)) {
            return $files;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files[] = $file->getPathname();
                }
            }
        } catch (\Exception $e) {
            Log::warning("Error reading directory {$path}: " . $e->getMessage());
        }

        return $files;
    }

    /**
     * Check if files are identical
     */
    public function areFilesIdentical(string $file1, string $file2): bool
    {
        if (!File::exists($file1) || !File::exists($file2)) {
            return false;
        }

        // Quick size check first
        if (File::size($file1) !== File::size($file2)) {
            return false;
        }

        // Compare file hashes
        return hash_file('md5', $file1) === hash_file('md5', $file2);
    }

    /**
     * Check if filename is a torrent tracking file
     */
    public function isTorrentTrackingFile(string $filename): bool
    {
        $trackingPatterns = [
            '~uTorrentPartFile*',
            'Torrent downloaded from*',
            'Torrent Downloaded From*',
            '*README.txt',
            '*info.txt',
            '*.url',
            '*Seeded by FrostWire*',
            '*BitTorrent Seed*',
            '*torrent*tracker*',
            '*RARBG.txt',
            '*Downloaded from*',
            '*AudioBook Bay*',
        ];

        foreach ($trackingPatterns as $pattern) {
            if (fnmatch($pattern, $filename, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract track number from filename
     */
    public function extractTrackNumber(string $filename): ?int
    {
        // Look for track numbers in various formats
        $patterns = [
            '/track\s*(\d+)/i',
            '/^(\d+)[\s\-_]/i',
            '/[\s\-_](\d+)[\s\-_]/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $filename, $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    /**
     * Check if directory is empty
     */
    public function isDirectoryEmpty(string $path): bool
    {
        if (!File::isDirectory($path)) {
            return true;
        }

        $files = File::allFiles($path);
        $directories = File::directories($path);

        return empty($files) && empty($directories);
    }

    /**
     * Find potential duplicate directories
     */
    public function findPotentialDuplicates(string $path): array
    {
        $duplicates = [];
        $baseName = basename($path);
        $parentDir = dirname($path);

        if (!File::isDirectory($parentDir)) {
            return $duplicates;
        }

        $directories = File::directories($parentDir);

        foreach ($directories as $dir) {
            if ($dir === $path) {
                continue;
            }

            $dirBaseName = basename($dir);

            // Check for similar names (case-insensitive)
            if (strcasecmp($baseName, $dirBaseName) === 0) {
                $duplicates[] = $dir;
                continue;
            }

            // Check for names that differ only by special characters
            $cleanBase = preg_replace('/[^a-z0-9]/i', '', $baseName);
            $cleanDir = preg_replace('/[^a-z0-9]/i', '', $dirBaseName);

            if (strcasecmp($cleanBase, $cleanDir) === 0) {
                $duplicates[] = $dir;
                continue;
            }

            // Check for substring matches (one contains the other)
            if (
                stripos($baseName, $dirBaseName) !== false ||
                stripos($dirBaseName, $baseName) !== false
            ) {
                $duplicates[] = $dir;
            }
        }

        return $duplicates;
    }

    /**
     * Find duplicate paths (exact matches)
     */
    public function findDuplicatePaths(string $path): array
    {
        $duplicates = [];
        $baseName = basename($path);
        $parentDir = dirname($path);

        if (!File::isDirectory($parentDir)) {
            return $duplicates;
        }

        $directories = File::directories($parentDir);

        foreach ($directories as $dir) {
            if ($dir === $path) {
                continue;
            }

            if (basename($dir) === $baseName) {
                $duplicates[] = $dir;
            }
        }

        return $duplicates;
    }

    /**
     * Get directory size in bytes
     */
    public function getDirectorySize(string $path): int
    {
        $size = 0;

        if (!File::isDirectory($path)) {
            return $size;
        }

        try {
            $files = $this->getAllFilesFromDirectory($path);

            foreach ($files as $file) {
                if (File::exists($file)) {
                    $size += File::size($file);
                }
            }
        } catch (\Exception $e) {
            Log::warning("Error calculating directory size for {$path}: " . $e->getMessage());
        }

        return $size;
    }

    /**
     * Check if directory has CD subdirectories
     */
    public function hasCdDirectories(string $path): bool
    {
        if (!File::isDirectory($path)) {
            return false;
        }

        $directories = File::directories($path);

        foreach ($directories as $dir) {
            $dirName = basename($dir);
            if (preg_match('/^(cd|disc|disk)[\s_-]*\d+$/i', $dirName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Move or copy directory contents safely
     */
    public function moveDirectoryContents(string $source, string $target, bool $copy = false): bool
    {
        if (!File::isDirectory($source)) {
            return false;
        }

        if (!File::isDirectory($target)) {
            $mkdirError = null;
            set_error_handler(function ($errno, $errstr) use (&$mkdirError) {
                $mkdirError = $errstr;
                return true;
            });
            $success = File::makeDirectory($target, 0775, true);
            restore_error_handler();

            if (!$success && !is_dir($target)) {
                Log::error("Failed to create target directory: {$target}", [
                    'error' => $mkdirError ?? 'No PHP error captured',
                    'parent_exists' => is_dir(dirname($target)),
                    'parent_writable' => is_writable(is_dir(dirname($target)) ? dirname($target) : '/'),
                ]);
                return false;
            }

            if (is_dir($target)) {
                @chmod($target, 0775);
            }
        }

        try {
            $files = $this->getAllFilesFromDirectory($source);

            foreach ($files as $file) {
                $relativePath = str_replace($source . '/', '', $file);
                $targetFile = "{$target}/{$relativePath}";

                $targetSubDir = dirname($targetFile);
                if (!File::isDirectory($targetSubDir)) {
                    File::makeDirectory($targetSubDir, 0775, true);
                    if (is_dir($targetSubDir)) {
                        @chmod($targetSubDir, 0775);
                    }
                }

                if ($copy) {
                    File::copy($file, $targetFile);
                } else {
                    File::move($file, $targetFile);
                }

                // Set appropriate permissions and ownership
                chmod($targetFile, 0664);
            }

            return true;
        } catch (\Exception $e) {
            Log::error("Failed to move directory contents from {$source} to {$target}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Format bytes for human reading
     */
    public function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 1) . ' ' . $units[$unitIndex];
    }
}
