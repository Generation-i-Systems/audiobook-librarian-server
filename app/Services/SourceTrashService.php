<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SourceTrashService
{
    public function movePathToTrash(string $path, string $reason, array $context = []): ?array
    {
        $path = rtrim($path);

        if ($path === '' || !file_exists($path)) {
            return null;
        }

        $isDirectory = is_dir($path);
        $trashDisk = Storage::disk('trash');
        $trashItemId = now()->format('Y-m-d_His') . '_source_' . Str::random(6);
        $relativeBasePath = 'sources/' . $trashItemId;
        $payloadRelativePath = $relativeBasePath . '/payload';

        $trashDisk->makeDirectory($relativeBasePath);
        $payloadAbsolutePath = $trashDisk->path($payloadRelativePath);

        $moveSucceeded = $isDirectory
            ? $this->moveDirectory($path, $payloadAbsolutePath)
            : $this->moveFile($path, $payloadAbsolutePath);

        if (!$moveSucceeded) {
            Log::warning('Failed to move path to trash', [
                'path' => $path,
                'reason' => $reason,
            ]);
            return null;
        }

        $metadata = [
            'moved_at' => now()->toIso8601String(),
            'reason' => $reason,
            'original_path' => $path,
            'type' => $isDirectory ? 'directory' : 'file',
            'context' => $context,
        ];

        $trashDisk->put(
            $relativeBasePath . '/metadata.json',
            json_encode($metadata, JSON_PRETTY_PRINT)
        );

        return [
            'id' => $trashItemId,
            'relative_path' => $relativeBasePath,
            'absolute_path' => $trashDisk->path($relativeBasePath),
        ];
    }

    protected function moveDirectory(string $source, string $destination): bool
    {
        $this->ensureParentDirectory(dirname($destination));

        if (@rename($source, $destination)) {
            return true;
        }

        try {
            File::makeDirectory($destination, 0775, true);
            File::copyDirectory($source, $destination);
            File::deleteDirectory($source);
            return true;
        } catch (\Exception $e) {
            Log::warning('Failed to relocate directory to trash', [
                'source' => $source,
                'destination' => $destination,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    protected function moveFile(string $source, string $destination): bool
    {
        $this->ensureParentDirectory(dirname($destination));

        if (@rename($source, $destination)) {
            return true;
        }

        try {
            File::copy($source, $destination);
            File::delete($source);
            return true;
        } catch (\Exception $e) {
            Log::warning('Failed to relocate file to trash', [
                'source' => $source,
                'destination' => $destination,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    protected function ensureParentDirectory(string $directory): void
    {
        if ($directory === '' || $directory === DIRECTORY_SEPARATOR) {
            return;
        }

        if (!is_dir($directory)) {
            File::makeDirectory($directory, 0775, true);
        }
    }
}
