<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Support\Facades\Storage;

class BookEditPlannedActionsService
{
    public function __construct(private readonly DocumentStoreServiceInterface $documentStoreService)
    {
    }

    public function computePlannedActions(string $bookId, string $newDirectoryPath, string $coverUrl): array
    {
        $book = $this->documentStoreService->getBook($bookId) ?? [];

        $existingDirectoryPath = $book['directoryPath'] ?? '';
        $oldDirectoryPath = is_string($existingDirectoryPath) ? trim($existingDirectoryPath) : '';
        $newDirectoryPath = trim($newDirectoryPath);
        $coverUrl = trim($coverUrl);

        $disk = Storage::disk('books');

        $oldHasFiles = $oldDirectoryPath !== '' && count($disk->allFiles($oldDirectoryPath)) > 0;
        $newHasFiles = $newDirectoryPath !== '' && count($disk->allFiles($newDirectoryPath)) > 0;

        $actions = [];

        if ($newDirectoryPath !== '' && $this->hasDirectoryPath($oldDirectoryPath) && $newDirectoryPath !== $oldDirectoryPath) {
            if ($oldHasFiles && !$newHasFiles) {
                $actions[] = [
                    'type' => 'move_files',
                    'message' => "Move files: {$oldDirectoryPath} → {$newDirectoryPath}",
                ];
            } elseif (!$oldHasFiles && $newHasFiles) {
                $actions[] = [
                    'type' => 'db_only',
                    'message' => "Update DB only: point book to existing directory {$newDirectoryPath} (no files to move)",
                ];
            } elseif ($oldHasFiles && $newHasFiles) {
                $actions[] = [
                    'type' => 'db_only',
                    'message' => "Update DB only: both directories contain files; no automatic move will be performed",
                ];
            } else {
                $actions[] = [
                    'type' => 'db_only',
                    'message' => "Update DB only: directory path will change but no files were detected to move",
                ];
            }
        } elseif ($newDirectoryPath !== '' && !$this->hasDirectoryPath($oldDirectoryPath)) {
            if ($newHasFiles) {
                $actions[] = [
                    'type' => 'db_only',
                    'message' => "Update DB only: set directory to {$newDirectoryPath}",
                ];
            } else {
                $actions[] = [
                    'type' => 'db_only',
                    'message' => "Update DB only: set directory to {$newDirectoryPath} (directory currently empty)",
                ];
            }
        }

        if ($coverUrl !== '' && $newDirectoryPath !== '') {
            $actions[] = [
                'type' => 'download_cover',
                'message' => "Download cover image into {$newDirectoryPath}",
            ];
        } elseif ($coverUrl !== '' && $oldDirectoryPath !== '') {
            $actions[] = [
                'type' => 'download_cover',
                'message' => "Download cover image into {$oldDirectoryPath}",
            ];
        }

        if (count($actions) === 0) {
            $actions[] = [
                'type' => 'no_op',
                'message' => 'No filesystem actions. Saving will update database fields only.',
            ];
        }

        return [
            'oldDirectoryPath' => $oldDirectoryPath,
            'newDirectoryPath' => $newDirectoryPath,
            'oldHasFiles' => $oldHasFiles,
            'newHasFiles' => $newHasFiles,
            'actions' => $actions,
        ];
    }

    private function hasDirectoryPath(string $directoryPath): bool
    {
        return $directoryPath !== '';
    }
}
