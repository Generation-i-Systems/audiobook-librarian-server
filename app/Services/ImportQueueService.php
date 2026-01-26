<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ImportQueueService
{
    protected BookImportService $importService;
    protected string $queueBasePath;
    protected string $queuePath;
    protected string $statusPath;
    protected string $completedPath;
    protected string $failedPath;

    public function __construct(BookImportService $importService)
    {
        $this->importService = $importService;
        $this->queueBasePath = storage_path('app/import-queue');
        $this->queuePath = $this->queueBasePath . '/queue';
        $this->statusPath = $this->queueBasePath . '/status';
        $this->completedPath = $this->queueBasePath . '/completed';
        $this->failedPath = $this->queueBasePath . '/failed';
    }

    public function getQueuePath(): string
    {
        return $this->queuePath;
    }

    public function getStatusPath(): string
    {
        return $this->statusPath;
    }

    public function ensureDirectoriesExist(): void
    {
        $paths = [$this->queuePath, $this->statusPath, $this->completedPath, $this->failedPath];
        foreach ($paths as $path) {
            if (!File::isDirectory($path)) {
                File::makeDirectory($path, 0775, true);
            }
        }
    }

    public function getPendingImports(): array
    {
        $this->ensureDirectoriesExist();

        $pending = [];
        $importDirs = File::directories($this->queuePath);

        foreach ($importDirs as $importDir) {
            $dirName = basename($importDir);
            if (!str_starts_with($dirName, 'import-')) {
                continue;
            }

            $librarianJsonPath = $importDir . '/librarian.json';
            if (!File::exists($librarianJsonPath)) {
                Log::warning('ImportQueueService: Missing librarian.json', ['path' => $importDir]);
                continue;
            }

            $metadata = $this->readLibrarianJson($librarianJsonPath);
            if (!$metadata) {
                continue;
            }

            $pending[] = [
                'id' => $dirName,
                'path' => $importDir,
                'metadata' => $metadata,
                'queued_at' => $metadata['import_metadata']['queued_at'] ?? null,
            ];
        }

        usort($pending, function ($a, $b) {
            $aTime = $a['queued_at'] ?? '9999-12-31';
            $bTime = $b['queued_at'] ?? '9999-12-31';
            return strcmp($aTime, $bTime);
        });

        return $pending;
    }

    protected function readLibrarianJson(string $path): ?array
    {
        $content = File::get($path);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('ImportQueueService: Invalid JSON in librarian.json', [
                'path' => $path,
                'error' => json_last_error_msg(),
            ]);
            return null;
        }

        return $data;
    }

    public function validateImport(array $import): array
    {
        $errors = [];
        $metadata = $import['metadata'] ?? [];

        if (empty($metadata['title'])) {
            $errors[] = 'Missing required field: title';
        }

        if (empty($metadata['authors']) && empty($metadata['author'])) {
            $errors[] = 'Missing required field: authors';
        }

        $directoryPath = $metadata['directory_path'] ?? null;
        if (empty($directoryPath)) {
            $errors[] = 'Missing required field: directory_path';
        }

        $audioFiles = $metadata['audio_files'] ?? [];
        if (empty($audioFiles)) {
            $errors[] = 'No audio files specified';
        } else {
            foreach ($audioFiles as $audioFile) {
                $fullPath = $import['path'] . '/' . $directoryPath . '/' . $audioFile;
                if (!File::exists($fullPath)) {
                    $errors[] = "Audio file not found: {$audioFile}";
                }
            }
        }

        if (!empty($metadata['import_metadata']['checksum'])) {
            $errors = array_merge($errors, $this->validateChecksums($import));
        }

        return $errors;
    }

    protected function validateChecksums(array $import): array
    {
        $errors = [];
        $metadata = $import['metadata'];
        $directoryPath = $metadata['directory_path'] ?? '';
        $audioFiles = $metadata['audio_files'] ?? [];

        foreach ($audioFiles as $audioFile) {
            $fullPath = $import['path'] . '/' . $directoryPath . '/' . $audioFile;
            if (!File::exists($fullPath)) {
                continue;
            }
        }

        return $errors;
    }

    public function processImport(string $importId, bool $dryRun = false): array
    {
        $importPath = $this->queuePath . '/' . $importId;
        $librarianJsonPath = $importPath . '/librarian.json';

        if (!File::exists($librarianJsonPath)) {
            return [
                'success' => false,
                'error' => 'librarian.json not found',
                'import_id' => $importId,
            ];
        }

        $metadata = $this->readLibrarianJson($librarianJsonPath);
        if (!$metadata) {
            return [
                'success' => false,
                'error' => 'Invalid librarian.json',
                'import_id' => $importId,
            ];
        }

        $import = [
            'id' => $importId,
            'path' => $importPath,
            'metadata' => $metadata,
        ];

        $validationErrors = $this->validateImport($import);
        if (!empty($validationErrors)) {
            return [
                'success' => false,
                'error' => 'Validation failed',
                'validation_errors' => $validationErrors,
                'import_id' => $importId,
            ];
        }

        $directoryPath = $metadata['directory_path'] ?? '';
        $existingBook = $this->importService->findExistingBook($importPath, $metadata);
        if ($existingBook) {
            return [
                'success' => false,
                'error' => 'Duplicate book found',
                'existing_book_id' => $existingBook->id,
                'existing_book_title' => $existingBook->title,
                'import_id' => $importId,
            ];
        }

        if ($dryRun) {
            return [
                'success' => true,
                'dry_run' => true,
                'message' => 'Validation passed (dry run)',
                'import_id' => $importId,
                'title' => $metadata['title'] ?? 'Unknown',
            ];
        }

        try {
            $audiobook = $this->buildAudiobookArray($import);

            $normalizedMetadata = $this->normalizeMetadata($metadata);

            $book = $this->importService->createBookFromMetadata($normalizedMetadata, $audiobook);

            if (!$book) {
                throw new \RuntimeException('Failed to create book record');
            }

            $result = $this->importService->moveFilesToLibrary(
                $audiobook,
                $book,
                ['operation' => 'move'],
                null,
                null,
                fn ($audiobook, $targetDir) => $this->handleDirectoryConflict($audiobook, $targetDir)
            );

            $this->writeStatus($importId, [
                'success' => true,
                'book_id' => $book->id,
                'title' => $book->title,
                'directory_path' => $book->directory_path,
                'processed_at' => now()->toIso8601String(),
            ]);

            $this->moveToCompleted($importId);

            return [
                'success' => true,
                'book_id' => $book->id,
                'title' => $book->title,
                'import_id' => $importId,
            ];
        } catch (\Exception $e) {
            Log::error('ImportQueueService: Import failed', [
                'import_id' => $importId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->writeStatus($importId, [
                'success' => false,
                'error' => $e->getMessage(),
                'processed_at' => now()->toIso8601String(),
            ]);

            $this->moveToFailed($importId, $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'import_id' => $importId,
            ];
        }
    }

    protected function buildAudiobookArray(array $import): array
    {
        $metadata = $import['metadata'];
        $directoryPath = $metadata['directory_path'] ?? '';
        $audioFiles = $metadata['audio_files'] ?? [];

        $files = [];
        foreach ($audioFiles as $audioFile) {
            $files[] = $import['path'] . '/' . $directoryPath . '/' . $audioFile;
        }

        return [
            'path' => $import['path'] . '/' . $directoryPath,
            'name' => basename($directoryPath),
            'files' => $files,
            'is_multi_book_part' => false,
        ];
    }

    protected function normalizeMetadata(array $metadata): array
    {
        $normalized = $metadata;

        if (isset($metadata['authors']) && is_array($metadata['authors'])) {
            $authorNames = [];
            foreach ($metadata['authors'] as $author) {
                if (is_array($author) && isset($author['name'])) {
                    $authorNames[] = $author['name'];
                } elseif (is_string($author)) {
                    $authorNames[] = $author;
                }
            }
            $normalized['author'] = $authorNames;
        }

        if (isset($metadata['narrators']) && is_array($metadata['narrators'])) {
            $narratorNames = [];
            foreach ($metadata['narrators'] as $narrator) {
                if (is_array($narrator) && isset($narrator['name'])) {
                    $narratorNames[] = $narrator['name'];
                } elseif (is_string($narrator)) {
                    $narratorNames[] = $narrator;
                }
            }
            $normalized['narrator'] = $narratorNames;
        }

        if (isset($metadata['series']) && is_array($metadata['series'])) {
            $normalized['series'] = $metadata['series']['name'] ?? null;
            $normalized['series_number'] = $metadata['series']['number'] ?? null;
        }

        if (isset($metadata['genres']) && is_array($metadata['genres'])) {
            $normalized['genre'] = $metadata['genres'];
        }

        return $normalized;
    }

    protected function handleDirectoryConflict(array $audiobook, string $targetDir): string
    {
        return 'merge';
    }

    public function writeStatus(string $importId, array $status): void
    {
        $this->ensureDirectoriesExist();

        $statusFile = $this->statusPath . '/' . $importId . '.json';
        $status['import_id'] = $importId;
        $status['updated_at'] = now()->toIso8601String();

        File::put($statusFile, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function getStatus(string $importId): ?array
    {
        $statusFile = $this->statusPath . '/' . $importId . '.json';
        if (!File::exists($statusFile)) {
            return null;
        }

        $content = File::get($statusFile);
        return json_decode($content, true);
    }

    public function moveToCompleted(string $importId): void
    {
        $this->ensureDirectoriesExist();

        $sourcePath = $this->queuePath . '/' . $importId;
        $targetPath = $this->completedPath . '/' . $importId . '-' . date('Ymd-His');

        if (File::isDirectory($sourcePath)) {
            File::moveDirectory($sourcePath, $targetPath);
        }
    }

    public function moveToFailed(string $importId, string $reason): void
    {
        $this->ensureDirectoriesExist();

        $sourcePath = $this->queuePath . '/' . $importId;
        $targetPath = $this->failedPath . '/' . $importId . '-' . date('Ymd-His');

        if (File::isDirectory($sourcePath)) {
            File::moveDirectory($sourcePath, $targetPath);

            $reasonFile = $targetPath . '/failure_reason.txt';
            File::put($reasonFile, $reason);
        }
    }

    public function cleanupOldCompleted(int $daysToKeep = 30): int
    {
        $cutoffDate = now()->subDays($daysToKeep);
        $count = 0;

        $completedDirs = File::directories($this->completedPath);
        foreach ($completedDirs as $dir) {
            $modifiedTime = File::lastModified($dir);
            if ($modifiedTime < $cutoffDate->timestamp) {
                File::deleteDirectory($dir);
                $count++;
            }
        }

        return $count;
    }

    public function getQueueStats(): array
    {
        $this->ensureDirectoriesExist();

        return [
            'pending' => count($this->getPendingImports()),
            'completed' => count(File::directories($this->completedPath)),
            'failed' => count(File::directories($this->failedPath)),
        ];
    }
}
