<?php

namespace App\Services;

use RuntimeException;

class BookPathService
{
    public function getBookRoot(): string
    {
        $bookRoot = config('filesystems.disks.books.root') ?? config('app.book_root');
        if (!is_string($bookRoot) || trim($bookRoot) === '') {
            throw new RuntimeException('app.book_root is not configured. Set BOOK_STORAGE_PATH in your environment.');
        }

        $bookRoot = rtrim($bookRoot, '/');

        $realPath = realpath($bookRoot);
        if (is_string($realPath) && $realPath !== '') {
            $bookRoot = rtrim($realPath, '/');
        }

        if (!is_dir($bookRoot)) {
            throw new RuntimeException("Book root directory does not exist or is not accessible: {$bookRoot}");
        }

        return $bookRoot;
    }
}
