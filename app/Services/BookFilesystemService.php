<?php

namespace App\Services;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Book;
use App\Traits\HandlesLibraryJson;
use Illuminate\Support\Facades\Log;

class BookFilesystemService
{
    use HandlesLibraryJson;

    public function __construct(
        private readonly DocumentStoreServiceInterface $documentStoreService,
        private readonly BookPathService $bookPathService
    ) {
    }

    public function renameItem(string $relativePath, string $newName): array
    {
        $relativePath = trim($relativePath, '/');
        $newName = trim($newName);

        if ($relativePath === '' || $newName === '') {
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Invalid input.',
            ];
        }

        $bookRoot = $this->bookPathService->getBookRoot();
        $fullOldPath = $bookRoot . '/' . $relativePath;

        if (!file_exists($fullOldPath)) {
            return [
                'success' => false,
                'status' => 404,
                'error' => 'Original file/folder does not exist.',
            ];
        }

        $fullDir = dirname($fullOldPath);
        $fullNewPath = $fullDir . DIRECTORY_SEPARATOR . $newName;

        if (file_exists($fullNewPath)) {
            return [
                'success' => false,
                'status' => 409,
                'error' => 'A file/folder with the new name already exists.',
            ];
        }

        $success = @rename($fullOldPath, $fullNewPath);
        if (!$success) {
            return [
                'success' => false,
                'status' => 500,
                'error' => 'Rename failed. Check permissions.',
            ];
        }

        $newRelativePath = $this->buildNewRelativePath($relativePath, $newName);

        if (is_dir($fullNewPath)) {
            $this->updateBookDirectoryPaths($relativePath, $newRelativePath);
        }

        return [
            'success' => true,
            'status' => 200,
            'newPath' => $newRelativePath,
            'newName' => $newName,
        ];
    }

    public function listFiles(string $directory): array
    {
        $directory = trim($directory, '/');
        $bookRoot = $this->bookPathService->getBookRoot();
        $fullPath = $bookRoot . '/' . $directory;

        $exists = is_dir($fullPath);
        $files = [];

        if ($exists) {
            $allFiles = scandir($fullPath);
            foreach ($allFiles as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $path = $fullPath . '/' . $file;
                if (is_file($path)) {
                    $files[] = [
                        'name' => $file,
                        'size' => filesize($path),
                        'type' => is_dir($path) ? 'directory' : 'file',
                        'extension' => pathinfo($file, PATHINFO_EXTENSION),
                    ];
                }
            }
        }

        return [
            'files' => $files,
            'exists' => $exists,
            'path' => $fullPath,
        ];
    }

    public function browseDirectories(string $requestedPath = ''): array
    {
        $requestedPath = trim($requestedPath, '/');
        $bookRoot = $this->bookPathService->getBookRoot();

        $pathParts = array_filter(explode('/', $requestedPath));
        $currentPath = $bookRoot;
        $existingPath = $bookRoot;

        foreach ($pathParts as $part) {
            $testPath = $currentPath . '/' . $part;
            if (is_dir($testPath)) {
                $existingPath = $testPath;
                $currentPath = $testPath;
            } else {
                break;
            }
        }

        $directories = [];
        $files = [];
        if (is_dir($existingPath)) {
            $items = scandir($existingPath);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $fullPath = $existingPath . '/' . $item;
                if (is_dir($fullPath)) {
                    $relativePath = str_replace($bookRoot . '/', '', $fullPath);
                    $directories[] = [
                        'name' => $item,
                        'path' => $relativePath,
                        'fullPath' => $fullPath,
                        'type' => 'directory',
                    ];
                } elseif (is_file($fullPath)) {
                    $files[] = [
                        'name' => $item,
                        'type' => 'file',
                        'size' => filesize($fullPath),
                        'extension' => pathinfo($item, PATHINFO_EXTENSION),
                    ];
                }
            }
        }

        $relativeCurrent = str_replace($bookRoot . '/', '', $existingPath);
        $parentPath = dirname($existingPath);
        $canGoUp = $parentPath !== $bookRoot && strpos($parentPath, $bookRoot) === 0;

        return [
            'currentPath' => $relativeCurrent === $bookRoot ? '' : $relativeCurrent,
            'directories' => $directories,
            'files' => $files,
            'canGoUp' => $canGoUp,
            'parentPath' => $canGoUp ? str_replace($bookRoot . '/', '', $parentPath) : null,
        ];
    }

    /**
     * Split a shared directory into separate subdirectories, one per book.
     *
     * Each entry in $splits is:
     *   [
     *     'book_id'    => int,
     *     'dir'        => string  (new relative path for this book),
     *     'move_files' => string[]  (filenames to move exclusively here),
     *     'copy_files' => string[]  (filenames to copy to both dirs),
     *   ]
     *
     * Files not listed in any split entry remain in the original directory.
     *
     * @param  array<int, array{book_id: int, dir: string, move_files: string[], copy_files: string[]}> $splits
     * @return array{success: bool, errors: string[]}
     */
    public function splitDirectory(string $sourceRelativePath, array $splits): array
    {
        $bookRoot  = $this->bookPathService->getBookRoot();
        $sourceFull = $bookRoot . '/' . trim($sourceRelativePath, '/');
        $errors = [];

        if (!is_dir($sourceFull)) {
            return ['success' => false, 'errors' => ["Source directory does not exist: {$sourceRelativePath}"]];
        }

        // Remove the shared librarian.json before splitting — it belongs to neither destination.
        // Each book will get a fresh one generated from its own DB record after files are moved.
        $sharedLibrarianJson = $sourceFull . '/librarian.json';
        if (file_exists($sharedLibrarianJson)) {
            @unlink($sharedLibrarianJson);
        }

        foreach ($splits as $split) {
            $destRelative = trim($split['dir'], '/');
            $destFull = $bookRoot . '/' . $destRelative;

            if (!is_dir($destFull) && !@mkdir($destFull, 0775, true)) {
                $errors[] = "Could not create directory: {$destRelative}";
                continue;
            }

            foreach ($split['move_files'] as $filename) {
                $src = $sourceFull . '/' . $filename;
                $dst = $destFull . '/' . $filename;
                if (!file_exists($src)) {
                    continue;
                }
                if (!@rename($src, $dst)) {
                    $errors[] = "Could not move {$filename} to {$destRelative}";
                }
            }

            foreach ($split['copy_files'] as $filename) {
                $src = $sourceFull . '/' . $filename;
                $dst = $destFull . '/' . $filename;
                if (!file_exists($src)) {
                    continue;
                }
                if (!@copy($src, $dst)) {
                    $errors[] = "Could not copy {$filename} to {$destRelative}";
                }
            }

            $bookId = (string) $split['book_id'];
            if ($bookId !== '0') {
                try {
                    $this->documentStoreService->updateBook($bookId, ['directoryPath' => $destRelative]);
                } catch (\Throwable $e) {
                    Log::warning('BookFilesystemService::splitDirectory: failed to update book path', [
                        'bookId' => $bookId,
                        'newPath' => $destRelative,
                        'error' => $e->getMessage(),
                    ]);
                    $errors[] = "Could not update DB path for book #{$bookId}";
                    continue;
                }

                // Regenerate librarian.json in the new directory from the book's DB record
                $book = Book::with(['authors', 'narrators', 'genres', 'series', 'publisher'])->find((int) $bookId);
                if ($book !== null) {
                    $book->directory_path = $destRelative;
                    $this->updateLibraryJson($book);
                }
            }
        }

        return ['success' => empty($errors), 'errors' => $errors];
    }

    private function buildNewRelativePath(string $oldRelativePath, string $newName): string
    {
        $oldRelativePath = trim($oldRelativePath, '/');
        $parent = trim((string) dirname($oldRelativePath), '/');
        if ($parent === '.' || $parent === '') {
            return trim($newName, '/');
        }

        return $parent . '/' . trim($newName, '/');
    }

    private function updateBookDirectoryPaths(string $oldRelativePath, string $newRelativePath): void
    {
        $oldRelativePath = trim($oldRelativePath, '/');
        $newRelativePath = trim($newRelativePath, '/');

        $page = 1;
        $perPage = 100;

        while (true) {
            $result = $this->documentStoreService->listBooks(
                $page,
                $perPage,
                ['include_needs_review' => true],
                false,
                'title',
                'asc',
                true
            );

            $books = $result['data'] ?? [];
            if (!is_array($books) || count($books) === 0) {
                break;
            }

            foreach ($books as $book) {
                if (!is_array($book)) {
                    continue;
                }

                $directoryPath = $book['directoryPath'] ?? ($book['directory_path'] ?? null);
                if (!is_string($directoryPath)) {
                    continue;
                }

                if (trim($directoryPath, '/') !== $oldRelativePath) {
                    continue;
                }

                $bookId = (string) ($book['id'] ?? ($book['_id'] ?? ''));
                if ($bookId === '') {
                    continue;
                }

                try {
                    $this->documentStoreService->updateBook($bookId, ['directoryPath' => $newRelativePath]);
                } catch (\Throwable $e) {
                    Log::warning('Failed to update book directoryPath during rename', [
                        'bookId' => $bookId,
                        'oldDirectoryPath' => $oldRelativePath,
                        'newDirectoryPath' => $newRelativePath,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (count($books) < $perPage) {
                break;
            }

            $page++;
        }
    }
}
