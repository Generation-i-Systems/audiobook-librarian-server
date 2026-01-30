<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Book;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BookTrashService
{
    public function __construct(
        private DocumentStoreServiceInterface $documentStore,
        private BookDeletionService $deletionService
    ) {
    }

    /**
     * Create a backup snapshot of a book before modification
     */
    public function createBackup(string $bookId, string $reason = 'manual_backup'): array
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
            $backupId = $timestamp . '_backup_' . $bookId;
            $trashDisk = Storage::disk('trash');

            $trashDisk->makeDirectory($backupId);
            $backupPath = $trashDisk->path($backupId);
            @chmod($backupPath, 0775);

            $metadata = [
                'backup_at' => now()->toIso8601String(),
                'backup_by' => Auth::id(),
                'book_id' => $bookId,
                'book_title' => $book['title'] ?? 'Unknown',
                'reason' => $reason,
                'type' => 'backup',
                'directory_path' => $book['directoryPath'] ?? null,
            ];

            $trashDisk->put(
                $backupId . '/metadata.json',
                json_encode($metadata, JSON_PRETTY_PRINT)
            );

            $trashDisk->put(
                $backupId . '/book.json',
                json_encode($book, JSON_PRETTY_PRINT)
            );

            Log::info('Book backup created', [
                'book_id' => $bookId,
                'backup_id' => $backupId,
                'reason' => $reason,
            ]);

            return [
                'success' => true,
                'backup_id' => $backupId,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to create book backup', [
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

    /**
     * Create backups for multiple books
     */
    public function createBulkBackup(array $bookIds, string $reason = 'bulk_operation'): array
    {
        $results = [];
        $successCount = 0;
        $failCount = 0;

        foreach ($bookIds as $bookId) {
            $result = $this->createBackup((string)$bookId, $reason);
            $results[$bookId] = $result;

            if ($result['success']) {
                $successCount++;
            } else {
                $failCount++;
            }
        }

        return [
            'success' => $failCount === 0,
            'total' => count($bookIds),
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'results' => $results,
        ];
    }

    /**
     * Delete a book with trash support (delegates to BookDeletionService)
     */
    public function moveToTrash(string $bookId, bool $deleteFiles = true): array
    {
        return $this->deletionService->moveToTrash($bookId, $deleteFiles);
    }

    /**
     * Restore a book from trash or backup
     */
    public function restore(string $trashItemId): bool
    {
        try {
            $trashDisk = Storage::disk('trash');

            if (!$trashDisk->exists($trashItemId . '/metadata.json')) {
                Log::error('Trash/backup item not found', ['item_id' => $trashItemId]);
                return false;
            }

            $metadataJson = $trashDisk->get($trashItemId . '/metadata.json');
            $metadata = json_decode($metadataJson, true);

            if ($metadata === null) {
                Log::error('Failed to decode metadata', ['item_id' => $trashItemId]);
                return false;
            }

            $type = $metadata['type'] ?? 'deletion';

            if ($type === 'backup') {
                return $this->restoreFromBackup($trashItemId, $metadata);
            }

            return $this->deletionService->restore($trashItemId);
        } catch (\Exception $e) {
            Log::error('Failed to restore item', [
                'item_id' => $trashItemId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Restore a book from a backup snapshot
     */
    private function restoreFromBackup(string $backupId, array $metadata): bool
    {
        try {
            $trashDisk = Storage::disk('trash');

            $bookJson = $trashDisk->get($backupId . '/book.json');
            $book = json_decode($bookJson, true);

            if ($book === null) {
                Log::error('Failed to decode book JSON from backup', ['backup_id' => $backupId]);
                return false;
            }

            $bookId = $metadata['book_id'];
            $existingBook = $this->documentStore->getBook($bookId);

            if ($existingBook !== null) {
                $this->createBackup($bookId, 'before_restore_from_backup');

                $this->documentStore->deleteBook($bookId, false);
            }

            $this->documentStore->createBook($book);

            Log::info('Book restored from backup', [
                'book_id' => $bookId,
                'backup_id' => $backupId,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to restore from backup', [
                'backup_id' => $backupId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * List all trash items and backups
     */
    public function listTrashItems(int $page = 1, int $perPage = 50): array
    {
        return $this->deletionService->listTrashItems($page, $perPage);
    }

    /**
     * Get total trash and backup count
     */
    public function getTotalTrashCount(): int
    {
        return $this->deletionService->getTotalTrashCount();
    }

    /**
     * Get total trash and backup size
     */
    public function getTotalTrashSize(): int
    {
        return $this->deletionService->getTotalTrashSize();
    }

    /**
     * Permanently delete a trash item or backup
     */
    public function permanentDelete(string $trashItemId): bool
    {
        return $this->deletionService->permanentDelete($trashItemId);
    }

    /**
     * Get backup history for a specific book
     */
    public function getBookBackupHistory(string $bookId): array
    {
        try {
            $trashDisk = Storage::disk('trash');
            $directories = $trashDisk->directories();
            $backups = [];

            foreach ($directories as $directory) {
                $metadataPath = $directory . '/metadata.json';

                if (!$trashDisk->exists($metadataPath)) {
                    continue;
                }

                $metadata = json_decode($trashDisk->get($metadataPath), true);

                if ($metadata === null) {
                    continue;
                }

                if (($metadata['book_id'] ?? null) === $bookId && ($metadata['type'] ?? 'deletion') === 'backup') {
                    $backups[] = [
                        'backup_id' => $directory,
                        'created_at' => $metadata['backup_at'] ?? $metadata['deleted_at'] ?? null,
                        'created_by' => $metadata['backup_by'] ?? $metadata['deleted_by'] ?? null,
                        'reason' => $metadata['reason'] ?? 'unknown',
                    ];
                }
            }

            usort($backups, function ($a, $b) {
                return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
            });

            return $backups;
        } catch (\Exception $e) {
            Log::error('Failed to get backup history', [
                'book_id' => $bookId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Clean up old backups (keep deletions, remove old backups)
     */
    public function cleanupOldBackups(int $daysToKeep = 30): int
    {
        try {
            $cutoffDate = now()->subDays($daysToKeep);
            $trashDisk = Storage::disk('trash');
            $directories = $trashDisk->directories();
            $deletedCount = 0;

            foreach ($directories as $directory) {
                $metadataPath = $directory . '/metadata.json';

                if (!$trashDisk->exists($metadataPath)) {
                    continue;
                }

                $metadata = json_decode($trashDisk->get($metadataPath), true);

                if ($metadata === null) {
                    continue;
                }

                $type = $metadata['type'] ?? 'deletion';

                if ($type !== 'backup') {
                    continue;
                }

                $createdAt = $metadata['backup_at'] ?? null;

                if ($createdAt === null) {
                    continue;
                }

                $createdDate = \Carbon\Carbon::parse($createdAt);

                if ($createdDate->lt($cutoffDate)) {
                    if ($this->permanentDelete($directory)) {
                        $deletedCount++;
                    }
                }
            }

            Log::info('Backup cleanup completed', [
                'days_to_keep' => $daysToKeep,
                'deleted_count' => $deletedCount,
            ]);

            return $deletedCount;
        } catch (\Exception $e) {
            Log::error('Backup cleanup failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }
}
