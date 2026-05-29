<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Book;
use App\Traits\HandlesLibraryJson;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BookDeletionService
{
    use HandlesLibraryJson;

    public function __construct(
        private DocumentStoreServiceInterface $documentStore,
        private PermissionsQueueService $permissionsQueueService
    ) {
    }

    public function moveToTrash(string $bookId, bool $deleteFiles = true): array
    {
        try {
            $book = $this->documentStore->getBook($bookId);

            if ($book === null) {
                return [
                    'success' => false,
                    'error' => 'Book not found',
                ];
            }

            $timestamp = now()->format('Y-m-d_His');
            $trashItemId = $timestamp . '_' . $bookId;
            $trashDisk = Storage::disk('trash');

            $trashDisk->makeDirectory($trashItemId);
            $trashPath = $trashDisk->path($trashItemId);
            @chmod($trashPath, 0775);
            @chown($trashPath, 'eric');
            @chgrp($trashPath, 'audio');

            $fileCount = 0;
            $movedFiles = false;

            if ($deleteFiles && !empty($book['directoryPath']) && $this->isDirectoryShared($bookId, $book['directoryPath'])) {
                Log::warning('Refusing to delete/move files for book because directory is shared', [
                    'book_id' => $bookId,
                    'directory_path' => $book['directoryPath'],
                ]);
                $deleteFiles = false;
            }

            if ($deleteFiles && !empty($book['directoryPath'])) {
                $result = $this->moveFilesToTrash($book['directoryPath'], $trashItemId);
                $fileCount = $result['file_count'];
                $movedFiles = $result['moved'];
            }

            $metadata = [
                'deleted_at' => now()->toIso8601String(),
                'deleted_by' => Auth::id(),
                'book_id' => $bookId,
                'book_title' => $book['title'] ?? 'Unknown',
                'directory_path' => $book['directoryPath'] ?? null,
                'file_count' => $fileCount,
                'files_moved' => $movedFiles,
            ];

            $trashDisk->put(
                $trashItemId . '/metadata.json',
                json_encode($metadata, JSON_PRETTY_PRINT)
            );

            $trashDisk->put(
                $trashItemId . '/book.json',
                json_encode($book, JSON_PRETTY_PRINT)
            );

            $this->documentStore->deleteBook($bookId, $deleteFiles);

            Log::info('Book moved to trash', [
                'book_id' => $bookId,
                'trash_item_id' => $trashItemId,
                'files_moved' => $movedFiles,
                'file_count' => $fileCount,
            ]);

            return [
                'success' => true,
                'trash_item_id' => $trashItemId,
                'file_count' => $fileCount,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to move book to trash', [
                'book_id' => $bookId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function isDirectoryShared(string $bookId, string $directoryPath): bool
    {
        $directoryPath = trim($directoryPath);

        if ($directoryPath === '') {
            return false;
        }

        return Book::query()
            ->where('directory_path', $directoryPath)
            ->where('id', '!=', $bookId)
            ->exists();
    }

    public function restore(string $trashItemId): bool
    {
        try {
            $trashDisk = Storage::disk('trash');

            if (!$trashDisk->exists($trashItemId . '/book.json')) {
                Log::error('Trash item not found', ['trash_item_id' => $trashItemId]);
                return false;
            }

            $bookJson = $trashDisk->get($trashItemId . '/book.json');
            $book = json_decode($bookJson, true);

            if ($book === null) {
                Log::error('Failed to decode book JSON', ['trash_item_id' => $trashItemId]);
                return false;
            }

            $metadataJson = $trashDisk->get($trashItemId . '/metadata.json');
            $metadata = json_decode($metadataJson, true);

            if ($metadata['files_moved'] ?? false) {
                $originalDirectoryPath = $book['directoryPath'] ?? null;

                if ($originalDirectoryPath) {
                    $restoredPath = $this->restoreFilesFromTrash($trashItemId, $originalDirectoryPath);

                    if ($restoredPath !== $originalDirectoryPath) {
                        $book['directoryPath'] = $restoredPath;
                    }

                    $restoredAbsPath = Storage::disk('books')->path($restoredPath);
                    $this->permissionsQueueService->addPath($restoredAbsPath);
                }
            }

            $this->documentStore->createBook($book);

            $trashDisk->deleteDirectory($trashItemId);

            Log::info('Book restored from trash', [
                'book_id' => $book['id'] ?? 'unknown',
                'trash_item_id' => $trashItemId,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to restore book from trash', [
                'trash_item_id' => $trashItemId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    public function permanentDelete(string $trashItemId): bool
    {
        try {
            $trashDisk = Storage::disk('trash');

            if (!$trashDisk->exists($trashItemId)) {
                return false;
            }

            $trashDisk->deleteDirectory($trashItemId);

            Log::info('Permanently deleted trash item', ['trash_item_id' => $trashItemId]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to permanently delete trash item', [
                'trash_item_id' => $trashItemId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function permanentDeleteAll(): int
    {
        try {
            $trashDisk = Storage::disk('trash');
            $directories = $trashDisk->directories();
            $count = 0;

            foreach ($directories as $directory) {
                if ($this->permanentDelete($directory)) {
                    $count++;
                }
            }

            Log::info('Permanently deleted all trash items', ['count' => $count]);

            return $count;
        } catch (\Exception $e) {
            Log::error('Failed to delete all trash items', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    public function listTrashItems(int $page = 1, int $perPage = 50): array
    {
        try {
            $trashDisk = Storage::disk('trash');
            $directories = $trashDisk->directories();
            $items = [];

            foreach ($directories as $directory) {
                $metadataPath = $directory . '/metadata.json';

                if (!$trashDisk->exists($metadataPath)) {
                    continue;
                }

                try {
                    $metadata = json_decode($trashDisk->get($metadataPath), true);

                    if ($metadata === null) {
                        Log::warning('Corrupt trash metadata', ['directory' => $directory]);
                        continue;
                    }

                    $size = $this->getDirectorySize($trashDisk, $directory);

                    $items[] = [
                        'id' => $directory,
                        'book_id' => $metadata['book_id'] ?? 'unknown',
                        'book_title' => $metadata['book_title'] ?? 'Unknown',
                        'deleted_at' => $metadata['deleted_at'] ?? null,
                        'deleted_by' => $metadata['deleted_by'] ?? null,
                        'file_count' => $metadata['file_count'] ?? 0,
                        'size' => $size,
                    ];
                } catch (\Exception $e) {
                    Log::warning('Failed to process trash item', [
                        'directory' => $directory,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            usort($items, function ($a, $b) {
                return strcmp($b['deleted_at'] ?? '', $a['deleted_at'] ?? '');
            });

            $total = count($items);
            $totalPages = (int) ceil($total / $perPage);
            $offset = ($page - 1) * $perPage;
            $pageItems = array_slice($items, $offset, $perPage);

            return [
                'items' => $pageItems,
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'total_pages' => $totalPages,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to list trash items', ['error' => $e->getMessage()]);

            return [
                'items' => [],
                'total' => 0,
                'per_page' => $perPage,
                'current_page' => $page,
                'total_pages' => 0,
            ];
        }
    }

    public function getTotalTrashSize(): int
    {
        try {
            $trashDisk = Storage::disk('trash');
            $directories = $trashDisk->directories();
            $totalSize = 0;

            foreach ($directories as $directory) {
                $totalSize += $this->getDirectorySize($trashDisk, $directory);
            }

            return $totalSize;
        } catch (\Exception $e) {
            Log::error('Failed to calculate trash size', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    public function getTotalTrashCount(): int
    {
        try {
            $trashDisk = Storage::disk('trash');
            return count($trashDisk->directories());
        } catch (\Exception $e) {
            Log::error('Failed to count trash items', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    public function applyAutoCleanup(int $days): int
    {
        try {
            $cutoffDate = now()->subDays($days);
            $trashDisk = Storage::disk('trash');
            $directories = $trashDisk->directories();
            $deletedCount = 0;

            foreach ($directories as $directory) {
                $metadataPath = $directory . '/metadata.json';

                if (!$trashDisk->exists($metadataPath)) {
                    continue;
                }

                try {
                    $metadata = json_decode($trashDisk->get($metadataPath), true);
                    $deletedAt = $metadata['deleted_at'] ?? null;

                    if ($deletedAt === null) {
                        continue;
                    }

                    $deletedDate = \Carbon\Carbon::parse($deletedAt);

                    if ($deletedDate->lt($cutoffDate)) {
                        if ($this->permanentDelete($directory)) {
                            $deletedCount++;
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to process trash item for cleanup', [
                        'directory' => $directory,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('Auto-cleanup completed', [
                'days' => $days,
                'deleted_count' => $deletedCount,
            ]);

            return $deletedCount;
        } catch (\Exception $e) {
            Log::error('Auto-cleanup failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    public function moveOrphanDirectoryToTrash(string $directoryPath): array
    {
        try {
            $booksDisk = Storage::disk('books');
            $directoryPath = trim($directoryPath, '/');

            if (!$booksDisk->exists($directoryPath)) {
                return ['success' => false, 'error' => 'Directory not found'];
            }

            $timestamp = now()->format('Y-m-d_His');
            $trashItemId = $timestamp . '_orphan_' . md5($directoryPath);
            $trashDisk = Storage::disk('trash');

            $trashDisk->makeDirectory($trashItemId);
            $trashPath = $trashDisk->path($trashItemId);
            @chmod($trashPath, 0775);
            @chown($trashPath, 'eric');
            @chgrp($trashPath, 'audio');

            $result = $this->moveFilesToTrash($directoryPath, $trashItemId);

            $metadata = [
                'deleted_at' => now()->toIso8601String(),
                'deleted_by' => Auth::id(),
                'book_id' => null,
                'book_title' => basename($directoryPath),
                'directory_path' => $directoryPath,
                'file_count' => $result['file_count'],
                'files_moved' => $result['moved'],
                'type' => 'orphan_directory',
            ];

            $trashDisk->put($trashItemId . '/metadata.json', json_encode($metadata, JSON_PRETTY_PRINT));

            Log::info('Orphan directory moved to trash', [
                'directory_path' => $directoryPath,
                'trash_item_id' => $trashItemId,
                'file_count' => $result['file_count'],
            ]);

            return [
                'success' => true,
                'trash_item_id' => $trashItemId,
                'file_count' => $result['file_count'],
            ];
        } catch (\Exception $e) {
            Log::error('Failed to move orphan directory to trash', [
                'directory_path' => $directoryPath,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function moveFilesToTrash(string $directoryPath, string $trashItemId): array
    {
        $booksDisk = Storage::disk('books');
        $trashDisk = Storage::disk('trash');

        $directoryPath = trim($directoryPath, '/');

        if ($directoryPath === '') {
            return ['moved' => false, 'file_count' => 0];
        }

        $directoryExists = $booksDisk->exists($directoryPath);

        if (!$directoryExists) {
            Log::warning('Directory not found when moving to trash', [
                'directory_path' => $directoryPath,
            ]);
            return ['moved' => false, 'file_count' => 0];
        }

        $filesPath = $trashItemId . '/files';
        $trashDisk->makeDirectory($filesPath);

        $trashFilesPath = $trashDisk->path($filesPath);
        @chmod($trashFilesPath, 0775);

        $files = $booksDisk->allFiles($directoryPath);
        $fileCount = 0;

        foreach ($files as $file) {
            try {
                $relativePath = str_starts_with($file, $directoryPath . '/') ? substr($file, strlen($directoryPath) + 1) : basename($file);

                $targetPath = $filesPath . '/' . $relativePath;
                $targetDir = dirname($targetPath);

                if ($targetDir !== $filesPath) {
                    $trashDisk->makeDirectory($targetDir);
                    $targetAbsDir = $trashDisk->path($targetDir);
                    if (is_dir($targetAbsDir)) {
                        @chmod($targetAbsDir, 0775);
                    }
                }

                $sourceAbsPath = $booksDisk->path($file);
                $targetAbsPath = $trashDisk->path($targetPath);

                if (rename($sourceAbsPath, $targetAbsPath)) {
                    @chmod($targetAbsPath, 0664);
                    $fileCount++;
                } else {
                    Log::warning('Failed to move file to trash', [
                        'source' => $file,
                        'target' => $targetPath,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Error moving file to trash', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $remainingFiles = $booksDisk->allFiles($directoryPath);
        if (count($remainingFiles) === 0) {
            try {
                $booksDisk->deleteDirectory($directoryPath);
            } catch (\Exception $e) {
                Log::warning('Failed to delete empty directory after move', [
                    'directory' => $directoryPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['moved' => true, 'file_count' => $fileCount];
    }

    private function restoreFilesFromTrash(string $trashItemId, string $originalDirectoryPath): string
    {
        $trashDisk = Storage::disk('trash');
        $booksDisk = Storage::disk('books');

        $filesPath = $trashItemId . '/files';

        $files = $trashDisk->allFiles($filesPath);

        if (empty($files)) {
            return $originalDirectoryPath;
        }

        $restoredPath = $this->resolveNonConflictingDirectoryPath($booksDisk, $originalDirectoryPath);

        $booksDisk->makeDirectory($restoredPath);
        $restoredAbsPath = $booksDisk->path($restoredPath);
        if (is_dir($restoredAbsPath)) {
            $this->setDirectoryOwnership($restoredAbsPath);
            $this->permissionsQueueService->addPath($restoredAbsPath);
        }
        $restoredFiles = [];

        foreach ($files as $file) {
            try {
                $relativePath = str_starts_with($file, $filesPath . '/') ? substr($file, strlen($filesPath) + 1) : basename($file);

                $targetPath = $restoredPath . '/' . $relativePath;
                $targetDir = dirname($targetPath);

                if ($targetDir !== $restoredPath) {
                    $booksDisk->makeDirectory($targetDir);
                    $targetAbsDir = $booksDisk->path($targetDir);
                    if (is_dir($targetAbsDir)) {
                        $this->setDirectoryOwnership($targetAbsDir);
                    }
                }

                $targetAbsPath = $booksDisk->path($targetPath);

                $contents = $trashDisk->get($file);
                if ($contents !== null) {
                    $booksDisk->put($targetPath, $contents);
                    $trashDisk->delete($file);
                    $restoredFiles[] = $targetAbsPath;
                    $this->permissionsQueueService->addPath($targetAbsPath);
                } else {
                    Log::warning('Failed to restore file from trash', [
                        'source' => $file,
                        'target' => $targetPath,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Error restoring file from trash', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!empty($restoredFiles)) {
            $this->setBatchOwnership($restoredFiles);
        }

        return $restoredPath;
    }

    private function resolveNonConflictingDirectoryPath($disk, string $directoryPath): string
    {
        $directoryPath = trim($directoryPath, '/');

        if ($directoryPath === '') {
            return $directoryPath;
        }

        $exists = $disk->exists($directoryPath);

        if (!$exists) {
            return $directoryPath;
        }

        if (count($disk->allFiles($directoryPath)) === 0) {
            return $directoryPath;
        }

        $counter = 1;
        while (true) {
            $suffix = '_' . str_pad((string) $counter, 2, '0', STR_PAD_LEFT);
            $candidate = $directoryPath . $suffix;

            $candidateExists = $disk->exists($candidate);

            if (!$candidateExists) {
                return $candidate;
            }

            $counter++;
        }
    }

    private function getDirectorySize($disk, string $directory): int
    {
        $size = 0;
        $files = $disk->allFiles($directory);

        foreach ($files as $file) {
            try {
                $size += $disk->size($file);
            } catch (\Exception $e) {
                Log::warning('Failed to get file size', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $size;
    }
}
