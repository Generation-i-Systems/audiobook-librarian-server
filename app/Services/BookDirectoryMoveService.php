<?php

declare(strict_types=1);

namespace App\Services;

use App\Traits\HandlesLibraryJson;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BookDirectoryMoveService
{
    use HandlesLibraryJson;

    /**
     * Audio file extensions that constitute actual book content
     */
    private const AUDIO_EXTENSIONS = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'opus', 'aac', 'wav', 'wma'];

    /**
     * Metadata filenames that can be safely overwritten
     */
    private const METADATA_FILES = ['librarian.json', 'cover.jpg', 'cover.jpeg', 'cover.png', 'cover.webp'];
    public function moveBookDirectoryContents(
        string $oldDirectoryPath,
        string $newDirectoryPath,
        ?string $coverImageBasename = null
    ): array {
        $disk = Storage::disk('books');

        $oldDirectoryPath = trim($oldDirectoryPath, '/');
        $newDirectoryPath = trim($newDirectoryPath, '/');

        if ($oldDirectoryPath === '') {
            return [
                'moved' => false,
                'coverImage' => $coverImageBasename,
            ];
        }

        $directoryExists = false;
        if (method_exists($disk, 'directoryExists')) {
            $directoryExists = $disk->{'directoryExists'}($oldDirectoryPath);
        } else {
            $directoryExists = count($disk->allFiles($oldDirectoryPath)) > 0;
        }

        if (!$directoryExists) {
            return [
                'moved' => false,
                'coverImage' => $coverImageBasename,
            ];
        }

        $inPlaceMove = $oldDirectoryPath === $newDirectoryPath;

        if ($inPlaceMove) {
            return [
                'moved' => true,
                'coverImage' => $coverImageBasename,
                'directoryPath' => $newDirectoryPath,
            ];
        }

        $newDirectoryPath = $this->resolveNonConflictingDirectoryPath($disk, $newDirectoryPath);

        // Create directory using native PHP mkdir for better control
        $newAbsPath = $disk->path($newDirectoryPath);
        if (!is_dir($newAbsPath)) {
            $mkdirError = null;
            set_error_handler(function ($errno, $errstr) use (&$mkdirError) {
                $mkdirError = $errstr;
                return true;
            });
            $success = mkdir($newAbsPath, 0775, true);
            restore_error_handler();

            if (!$success && !is_dir($newAbsPath)) {
                Log::error('Failed to create directory', [
                    'path' => $newAbsPath,
                    'error' => $mkdirError ?? 'No PHP error captured',
                    'parent_exists' => is_dir(dirname($newAbsPath)),
                    'parent_writable' => is_writable(is_dir(dirname($newAbsPath)) ? dirname($newAbsPath) : '/'),
                    'user' => posix_getpwuid(posix_geteuid())['name'] ?? 'unknown',
                ]);
                return [
                    'moved' => false,
                    'coverImage' => $coverImageBasename,
                    'error' => 'Failed to create target directory: ' . ($mkdirError ?? 'Unknown error'),
                ];
            }
            $this->setDirectoryOwnership($newAbsPath);
        }

        $files = $disk->allFiles($oldDirectoryPath);
        $newCoverImageBasename = $coverImageBasename;
        $failedFiles = [];
        $movedFiles = [];

        Log::debug('BookDirectoryMoveService: Moving files', [
            'oldDirectoryPath' => $oldDirectoryPath,
            'newDirectoryPath' => $newDirectoryPath,
            'fileCount' => count($files),
            'files' => $files,
            'diskRoot' => $disk->path(''),
        ]);

        foreach ($files as $file) {
            $startsWithCheck = Str::startsWith($file, $oldDirectoryPath . '/');
            Log::debug('BookDirectoryMoveService: File path check', [
                'file' => $file,
                'startsWith' => $startsWithCheck,
                'oldDirectoryPathWithSlash' => $oldDirectoryPath . '/',
            ]);

            if ($startsWithCheck) {
                $relative = Str::after($file, $oldDirectoryPath . '/');
            } else {
                $relative = basename($file);
            }

            // Always use just the basename to avoid creating nested directories
            $filename = basename($relative);
            $target = rtrim($newDirectoryPath, '/') . '/' . $filename;

            Log::debug('BookDirectoryMoveService: Processing file', [
                'file' => $file,
                'relative' => $relative,
                'filename' => $filename,
                'target' => $target,
            ]);

            $finalTarget = $target;
            if ($disk->exists($finalTarget)) {
                $finalTarget = $this->generateNonConflictingPath($disk, $finalTarget);
            }

            try {
                $disk->move($file, $finalTarget);
                $movedFiles[] = $disk->path($finalTarget);

                if ($coverImageBasename !== null && basename($file) === $coverImageBasename) {
                    $newCoverImageBasename = basename($finalTarget);
                }
            } catch (\Exception $e) {
                $failedFiles[] = $file;
                Log::error('Failed to move file during directory update', [
                    'oldPath' => $oldDirectoryPath,
                    'newPath' => $newDirectoryPath,
                    'file' => $file,
                    'target' => $finalTarget,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Apply ownership to all moved files in one batch
        if (!empty($movedFiles)) {
            $this->setBatchOwnership($movedFiles);
        }

        // If any files failed to move, return failure
        if (!empty($failedFiles)) {
            return [
                'moved' => false,
                'coverImage' => $coverImageBasename,
                'error' => 'Failed to move ' . count($failedFiles) . ' file(s): ' . implode(', ', array_map('basename', $failedFiles)),
            ];
        }

        $remainingFiles = $disk->allFiles($oldDirectoryPath);
        if (count($remainingFiles) === 0) {
            try {
                $disk->deleteDirectory($oldDirectoryPath);
            } catch (\Exception $e) {
                Log::error('Failed to delete old directory during directory update', [
                    'oldPath' => $oldDirectoryPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'moved' => true,
            'coverImage' => $newCoverImageBasename,
            'directoryPath' => $newDirectoryPath,
        ];
    }

    private function directoryExistsOnDisk($disk, string $path): bool
    {
        if (method_exists($disk, 'directoryExists')) {
            return $disk->{'directoryExists'}($path);
        }

        return count($disk->allFiles($path)) > 0;
    }

    private function resolveNonConflictingDirectoryPath($disk, string $directoryPath): string
    {
        $directoryPath = trim($directoryPath, '/');
        if ($directoryPath === '') {
            return $directoryPath;
        }

        // Check if directory exists
        if (method_exists($disk, 'directoryExists')) {
            $exists = $disk->{'directoryExists'}($directoryPath);
        } else {
            $exists = count($disk->allFiles($directoryPath)) > 0;
        }

        if (!$exists) {
            return $directoryPath;
        }

        // Get all files in the directory
        $files = $disk->allFiles($directoryPath);
        if (empty($files)) {
            return $directoryPath;
        }

        // Check if directory only contains metadata files
        if ($this->containsOnlyMetadataFiles($files)) {
            // Safe to use this directory - metadata files will be overwritten
            return $directoryPath;
        }

        // Directory has actual content, find a non-conflicting path
        $counter = 1;
        while (true) {
            $suffix = '_' . str_pad((string) $counter, 2, '0', STR_PAD_LEFT);
            $candidate = $directoryPath . $suffix;

            if (method_exists($disk, 'directoryExists')) {
                $candidateExists = $disk->{'directoryExists'}($candidate);
            } else {
                $candidateExists = count($disk->allFiles($candidate)) > 0;
            }
            if (!$candidateExists) {
                return $candidate;
            }
            $counter++;
        }
    }

    /**
     * Check if a list of files contains only metadata files (librarian.json, covers, etc.)
     */
    public function containsOnlyMetadataFiles(array $files): bool
    {
        foreach ($files as $file) {
            $filename = basename($file);
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            // If it's an audio file, this is not just metadata
            if (in_array($extension, self::AUDIO_EXTENSIONS)) {
                return false;
            }

            // If it's not a recognized metadata file, consider it content
            if (!in_array(strtolower($filename), self::METADATA_FILES, true)) {
                return false;
            }
        }

        return true;
    }

    private function generateNonConflictingPath($disk, string $targetPath): string
    {
        $dir = trim((string) dirname($targetPath), '/');
        $filename = basename($targetPath);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);

        $counter = 1;
        while (true) {
            $suffix = '_' . str_pad((string) $counter, 2, '0', STR_PAD_LEFT);
            $candidateName = $name . $suffix;
            if ($ext !== '') {
                $candidateName .= '.' . $ext;
            }

            $candidate = ($dir !== '' ? $dir . '/' : '') . $candidateName;
            if (!$disk->exists($candidate)) {
                return $candidate;
            }

            $counter++;
        }
    }
}
