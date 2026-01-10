<?php

namespace Tests\Unit\Services;

use App\Services\ImportUIService;
use PHPUnit\Framework\TestCase;
use Mockery;
use SoloTerm\Screen\Screen;

class ImportUIServiceCoverCachingTest extends TestCase
{
    private $importUIService;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the Screen dependency
        $screen = Mockery::mock(Screen::class);
        /** @phpstan-ignore-next-line */
        $this->importUIService = new class ($screen) extends ImportUIService {
            public function exposeCacheCoverForCurrentBook(): void
            {
                $this->cacheCoverForCurrentBook();
            }

            public function exposeSetCurrentBook(array $metadata): void
            {
                $this->setCurrentBook($metadata);
            }

            public function exposeGetCachedCoverTempFile(): ?string
            {
                return $this->cachedCoverTempFile;
            }

            public function exposeGetCachedCoverUrl(): ?string
            {
                return $this->cachedCoverUrl;
            }

            // Override the download method to avoid actual HTTP calls
            protected function downloadCoverBytes(string $coverUrl, mixed $context): string
            {
                // Return fake image data for testing
                return "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\nIDATx\x9cc\xf8\x00\x00\x00\x01\x00\x01\x00\x00\x00\x00IEND\xaeB`\x82";
            }
        };
    }

    protected function tearDown(): void
    {
        // Clean up any temp files created during tests
        if ($this->importUIService) {
            $tempFile = $this->importUIService->exposeGetCachedCoverTempFile();
            if ($tempFile && file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
        Mockery::close();
        parent::tearDown();
    }

    public function testEmbeddedCoversWithDifferentPathsAlwaysReCache(): void
    {
        $book1 = [
            'title' => 'Test Book 1',
            'cover_url' => sys_get_temp_dir() . '/embedded_cover_abc123',
            'cover_is_local_file' => true
        ];

        $book2 = [
            'title' => 'Test Book 2',
            'cover_url' => sys_get_temp_dir() . '/embedded_cover_def456', // Different temp file
            'cover_is_local_file' => true
        ];

        // Set first book with embedded cover
        $this->importUIService->exposeSetCurrentBook($book1);
        $tempFile1 = $this->importUIService->exposeGetCachedCoverTempFile();
        $cachedUrl1 = $this->importUIService->exposeGetCachedCoverUrl();

        $this->assertNotNull($tempFile1);
        $this->assertFileExists($tempFile1);
        $this->assertEquals(sys_get_temp_dir() . '/embedded_cover_abc123', $cachedUrl1);

        // Set second book with different embedded cover path
        $this->importUIService->exposeSetCurrentBook($book2);
        $tempFile2 = $this->importUIService->exposeGetCachedCoverTempFile();
        $cachedUrl2 = $this->importUIService->exposeGetCachedCoverUrl();

        // Should have created new temp file (different embedded cover path)
        $this->assertNotNull($tempFile2);
        $this->assertFileExists($tempFile2);
        $this->assertNotEquals($tempFile1, $tempFile2);
        $this->assertEquals(sys_get_temp_dir() . '/embedded_cover_def456', $cachedUrl2);

        // Old temp file should be cleaned up
        $this->assertFileDoesNotExist($tempFile1);
    }

    public function testCoverClearedBeforeRenderingNewBook(): void
    {
        $book1 = [
            'title' => 'Test Book 1',
            'cover_url' => 'https://example.com/cover1.jpg'
        ];

        $book2 = [
            'title' => 'Test Book 2',
            'cover_url' => 'https://example.com/cover2.jpg' // Different URL
        ];

        // Set first book
        $this->importUIService->exposeSetCurrentBook($book1);
        $tempFile1 = $this->importUIService->exposeGetCachedCoverTempFile();

        $this->assertNotNull($tempFile1);
        $this->assertFileExists($tempFile1);

        // Set second book with different URL - should clear previous cover
        $this->importUIService->exposeSetCurrentBook($book2);
        $tempFile2 = $this->importUIService->exposeGetCachedCoverTempFile();

        // Should have created new temp file (different URL)
        $this->assertNotNull($tempFile2);
        $this->assertFileExists($tempFile2);
        $this->assertNotEquals($tempFile1, $tempFile2);

        // Old temp file should be cleaned up
        $this->assertFileDoesNotExist($tempFile1);
    }

    public function testRenderStateClearedWhenSettingNewBook(): void
    {
        $book1 = [
            'title' => 'Test Book 1',
            'cover_url' => 'https://example.com/cover1.jpg'
        ];

        $book2 = [
            'title' => 'Test Book 2',
            'cover_url' => 'https://example.com/cover1.jpg' // Same URL
        ];

        // Set first book
        $this->importUIService->exposeSetCurrentBook($book1);
        $tempFile1 = $this->importUIService->exposeGetCachedCoverTempFile();

        $this->assertNotNull($tempFile1);
        $this->assertFileExists($tempFile1);

        // Set second book with same URL - should clear render state
        $this->importUIService->exposeSetCurrentBook($book2);
        $tempFile2 = $this->importUIService->exposeGetCachedCoverTempFile();

        // Should reuse the same temp file (URL is same and file exists)
        $this->assertEquals($tempFile1, $tempFile2);
        $this->assertFileExists($tempFile2);
    }

    public function testCoverCacheRecreatesWhenTempFileMissing(): void
    {
        $book1 = [
            'title' => 'Test Book 1',
            'cover_url' => 'https://example.com/cover1.jpg'
        ];

        $book2 = [
            'title' => 'Test Book 2',
            'cover_url' => 'https://example.com/cover1.jpg' // Same URL
        ];

        // Set first book - should create temp file
        $this->importUIService->exposeSetCurrentBook($book1);
        $tempFile1 = $this->importUIService->exposeGetCachedCoverTempFile();
        $cachedUrl1 = $this->importUIService->exposeGetCachedCoverUrl();

        $this->assertNotNull($tempFile1);
        $this->assertFileExists($tempFile1);
        $this->assertEquals('https://example.com/cover1.jpg', $cachedUrl1);

        // Delete the temp file to simulate the issue
        @unlink($tempFile1);
        $this->assertFileDoesNotExist($tempFile1);

        // Set second book with same URL - should recreate temp file
        $this->importUIService->exposeSetCurrentBook($book2);
        $tempFile2 = $this->importUIService->exposeGetCachedCoverTempFile();
        $cachedUrl2 = $this->importUIService->exposeGetCachedCoverUrl();

        // Should have created a new temp file
        $this->assertNotNull($tempFile2);
        $this->assertFileExists($tempFile2);
        $this->assertEquals('https://example.com/cover1.jpg', $cachedUrl2);

        // Should be a different temp file (recreated)
        $this->assertNotEquals($tempFile1, $tempFile2);
    }

    public function testCoverCacheReusesValidTempFile(): void
    {
        $book1 = [
            'title' => 'Test Book 1',
            'cover_url' => 'https://example.com/cover1.jpg'
        ];

        $book2 = [
            'title' => 'Test Book 2',
            'cover_url' => 'https://example.com/cover1.jpg' // Same URL
        ];

        // Set first book - should create temp file
        $this->importUIService->exposeSetCurrentBook($book1);
        $tempFile1 = $this->importUIService->exposeGetCachedCoverTempFile();

        $this->assertNotNull($tempFile1);
        $this->assertFileExists($tempFile1);

        // Set second book with same URL - should reuse temp file
        $this->importUIService->exposeSetCurrentBook($book2);
        $tempFile2 = $this->importUIService->exposeGetCachedCoverTempFile();

        // Should reuse the same temp file
        $this->assertEquals($tempFile1, $tempFile2);
        $this->assertFileExists($tempFile2);
    }

    public function testCoverCacheClearsWhenUrlChanges(): void
    {
        $book1 = [
            'title' => 'Test Book 1',
            'cover_url' => 'https://example.com/cover1.jpg'
        ];

        $book2 = [
            'title' => 'Test Book 2',
            'cover_url' => 'https://example.com/cover2.jpg' // Different URL
        ];

        // Set first book
        $this->importUIService->exposeSetCurrentBook($book1);
        $tempFile1 = $this->importUIService->exposeGetCachedCoverTempFile();
        $cachedUrl1 = $this->importUIService->exposeGetCachedCoverUrl();

        $this->assertNotNull($tempFile1);
        $this->assertFileExists($tempFile1);
        $this->assertEquals('https://example.com/cover1.jpg', $cachedUrl1);

        // Set second book with different URL
        $this->importUIService->exposeSetCurrentBook($book2);
        $tempFile2 = $this->importUIService->exposeGetCachedCoverTempFile();
        $cachedUrl2 = $this->importUIService->exposeGetCachedCoverUrl();

        // Should have created new temp file and updated URL
        $this->assertNotNull($tempFile2);
        $this->assertFileExists($tempFile2);
        $this->assertEquals('https://example.com/cover2.jpg', $cachedUrl2);
        $this->assertNotEquals($tempFile1, $tempFile2);

        // Old temp file should be cleaned up
        $this->assertFileDoesNotExist($tempFile1);
    }
}
