<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BookDirectoryMoveService
{
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

        $disk->makeDirectory($newDirectoryPath);

        $files = $disk->allFiles($oldDirectoryPath);
        $newCoverImageBasename = $coverImageBasename;

        foreach ($files as $file) {
            $relative = Str::startsWith($file, $oldDirectoryPath . '/')
                ? Str::after($file, $oldDirectoryPath . '/')
                : basename($file);

            $target = rtrim($newDirectoryPath, '/') . '/' . ltrim($relative, '/');
            $targetDir = trim((string) dirname($target), '/');
            if ($targetDir !== '') {
                $disk->makeDirectory($targetDir);
            }

            $finalTarget = $target;
            if ($disk->exists($finalTarget)) {
                $finalTarget = $this->generateNonConflictingPath($disk, $finalTarget);
            }

            try {
                $disk->move($file, $finalTarget);

                if ($coverImageBasename !== null && basename($file) === $coverImageBasename) {
                    $newCoverImageBasename = basename($finalTarget);
                }
            } catch (\Exception $e) {
                Log::error('Failed to move file during directory update', [
                    'oldPath' => $oldDirectoryPath,
                    'newPath' => $newDirectoryPath,
                    'file' => $file,
                    'target' => $finalTarget,
                    'error' => $e->getMessage(),
                ]);
            }
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
        ];
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
