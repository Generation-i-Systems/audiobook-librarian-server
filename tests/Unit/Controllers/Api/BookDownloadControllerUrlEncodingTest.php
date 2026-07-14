<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Api\BookDownloadController;
use Tests\TestCase;

class BookDownloadControllerUrlEncodingTest extends TestCase
{
    public function test_build_download_file_url_encodes_reserved_characters_per_segment(): void
    {
        $controller = new BookDownloadController($this->createStub(DocumentStoreServiceInterface::class));
        $method = new \ReflectionMethod(BookDownloadController::class, 'buildDownloadFileUrl');
        $method->setAccessible(true);

        $url = $method->invoke($controller, 297, "Disc 1/Track #1 [special]? 100%.m4b");

        $this->assertSame(
            'http://localhost/api/v1/books/297/download/Disc%201/Track%20%231%20%5Bspecial%5D%3F%20100%25.m4b',
            $url
        );
    }

    public function test_build_download_file_url_preserves_safe_path_separators(): void
    {
        $controller = new BookDownloadController($this->createStub(DocumentStoreServiceInterface::class));
        $method = new \ReflectionMethod(BookDownloadController::class, 'buildDownloadFileUrl');
        $method->setAccessible(true);

        $url = $method->invoke($controller, 42, 'part one/chapter two/file name.m4b');

        $this->assertSame(
            'http://localhost/api/v1/books/42/download/part%20one/chapter%20two/file%20name.m4b',
            $url
        );
    }

    public function test_hash_file_chunks_returns_offsets_sizes_and_hashes(): void
    {
        $controller = new BookDownloadController($this->createStub(DocumentStoreServiceInterface::class));
        $method = new \ReflectionMethod(BookDownloadController::class, 'hashFileChunks');
        $method->setAccessible(true);
        $path = tempnam(sys_get_temp_dir(), 'chunk-hash-');
        $content = str_repeat('abc123', 2048);
        file_put_contents($path, $content);

        try {
            $chunks = $method->invoke($controller, $path);
        } finally {
            unlink($path);
        }

        $this->assertSame([
            [
                'offset' => 0,
                'size' => strlen($content),
                'sha256' => hash('sha256', $content),
            ],
        ], $chunks);
    }

    /**
     * Reproduces the actual bug: PHP caches stat() results per-process. Once something in this
     * process has called filesize() on a path, a later plain filesize() call on the same path
     * keeps returning the size from that first call - even though the file has since grown on
     * disk - until clearstatcache() runs. freshFileSize() must clear the cache before reading,
     * so it has to see the file's real, current size where a naive filesize() call would not.
     */
    public function test_fresh_file_size_sees_growth_that_a_cached_filesize_call_would_miss(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'stat-cache-');
        file_put_contents($path, str_repeat('a', 100));

        try {
            // Prime PHP's stat cache at the old size, simulating an earlier read in this same
            // worker process (e.g. while the file was still being written).
            $staleRead = filesize($path);
            $this->assertSame(100, $staleRead);

            file_put_contents($path, str_repeat('a', 250));

            // Without clearing the cache, PHP would still report the old size here.
            $this->assertSame(100, filesize($path), 'Precondition: PHP is indeed serving a stale cached stat');

            $controller = new BookDownloadController($this->createStub(DocumentStoreServiceInterface::class));
            $method = new \ReflectionMethod(BookDownloadController::class, 'freshFileSize');
            $method->setAccessible(true);

            $result = $method->invoke($controller, $path);

            $this->assertSame(250, $result);
        } finally {
            unlink($path);
        }
    }

    public function test_fresh_file_size_returns_null_for_a_missing_file(): void
    {
        $controller = new BookDownloadController($this->createStub(DocumentStoreServiceInterface::class));
        $method = new \ReflectionMethod(BookDownloadController::class, 'freshFileSize');
        $method->setAccessible(true);

        $result = $method->invoke($controller, sys_get_temp_dir() . '/does-not-exist-' . uniqid());

        $this->assertNull($result);
    }
}
