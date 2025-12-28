<?php

namespace App\Services;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Support\Facades\Log;

class BookFilesystemService
{
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
