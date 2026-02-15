<?php

namespace Tests\Core\Unit\Services;

use App\Services\BookImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EmbeddedCoverExtractionTest extends TestCase
{
    use RefreshDatabase;

    protected BookImportService $importService;
    protected string $testDir;
    protected string $testBookRoot;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure consistent configuration
        $this->testBookRoot = storage_path('app/test_books');
        config(['app.book_root' => $this->testBookRoot]);
        config(['filesystems.disks.books.root' => $this->testBookRoot]);

        $this->importService = app(BookImportService::class);
        $this->testDir = storage_path('app/test_covers');

        // Create test directories
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0775, true);
        }
        if (!is_dir($this->testBookRoot)) {
            mkdir($this->testBookRoot, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        if (is_dir($this->testDir)) {
            File::deleteDirectory($this->testDir);
        }
        if (is_dir($this->testBookRoot)) {
            File::deleteDirectory($this->testBookRoot);
        }
        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function embedded_cover_data_is_saved_to_directory()
    {
        // Create fake cover data (1x1 red pixel JPEG)
        $coverData = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwAA8A/9k=');

        $metadata = [
            'title' => 'Test Book',
            'author' => ['Test Author'],
            'cover_data' => $coverData,
            'cover_source' => 'Embedded in M4B',
        ];

        $audiobook = [
            'path' => $this->testDir,
            'files' => [],
        ];

        // Create book with embedded cover
        $book = $this->importService->createBookFromMetadata($metadata, $audiobook);

        // Assert book was created
        $this->assertNotNull($book);
        $this->assertNotNull($book->id);

        // Create book directory before processing cover
        $bookRoot = $this->testBookRoot;
        $bookDir = $bookRoot . '/' . $book->directory_path;
        if (!is_dir($bookDir)) {
            mkdir($bookDir, 0775, true);
        }

        // Process cover image (deferred from createBookFromMetadata to prevent premature dir creation)
        $this->importService->processCoverImage($book, $metadata);

        // Assert cover_image field is set
        $this->assertNotNull($book->cover_image);
        $this->assertStringEndsWith('cover.jpg', $book->cover_image);

        // Assert cover file was actually saved
        $coverPath = $bookDir . '/cover.jpg';
        $this->assertFileExists($coverPath, 'Cover image file should exist in book directory');

        // Assert file has content
        $savedData = file_get_contents($coverPath);
        $this->assertEquals($coverData, $savedData, 'Saved cover data should match original');

        // Cleanup
        File::deleteDirectory($bookDir);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function embedded_cover_has_priority_over_download()
    {
        $coverData = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwAA8A/9k=');

        $metadata = [
            'title' => 'Test Book',
            'author' => ['Test Author'],
            'cover_data' => $coverData,
            'cover_source' => 'Embedded in M4B',
            'cover_url' => 'https://example.com/cover.jpg', // This should be ignored
        ];

        $audiobook = [
            'path' => $this->testDir,
            'files' => [],
        ];

        // Create book with both embedded cover and URL
        $book = $this->importService->createBookFromMetadata($metadata, $audiobook);

        // Create book directory before processing cover
        $bookRoot = $this->testBookRoot;
        $bookDir = $bookRoot . '/' . $book->directory_path;
        if (!is_dir($bookDir)) {
            mkdir($bookDir, 0775, true);
        }

        // Process cover image (deferred from createBookFromMetadata)
        $this->importService->processCoverImage($book, $metadata);

        // Assert embedded cover was used, not downloaded
        $this->assertStringEndsWith('cover.jpg', $book->cover_image);

        // Assert file exists and contains embedded data
        $coverPath = $bookDir . '/cover.jpg';
        $this->assertFileExists($coverPath);

        $savedData = file_get_contents($coverPath);
        $this->assertEquals($coverData, $savedData, 'Should use embedded cover, not download');

        // Cleanup
        File::deleteDirectory($bookDir);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function existing_cover_has_priority_over_embedded()
    {
        // Create existing cover file
        $bookRoot = $this->testBookRoot;
        $testBookDir = 'Test/Test Author/Test Book';
        $fullPath = $bookRoot . '/' . $testBookDir;

        if (!is_dir($fullPath)) {
            mkdir($fullPath, 0775, true);
        }

        $existingCover = $fullPath . '/cover.jpg';
        file_put_contents($existingCover, 'existing cover data');

        $newCoverData = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwAA8A/9k=');

        $metadata = [
            'title' => 'Test Book',
            'author' => ['Test Author'],
            'genre' => 'Test',
            'cover_data' => $newCoverData,
            'custom_directory_path' => $testBookDir,
        ];

        $audiobook = [
            'path' => $this->testDir,
            'files' => [],
        ];

        // Create book - should use existing cover
        $book = $this->importService->createBookFromMetadata($metadata, $audiobook);

        // Process cover image (deferred from createBookFromMetadata)
        $this->importService->processCoverImage($book, $metadata);

        // Assert existing cover was used
        $this->assertStringContainsString('cover.jpg', $book->cover_image);

        // Assert file still contains original data (not overwritten)
        $savedData = file_get_contents($existingCover);
        $this->assertEquals('existing cover data', $savedData, 'Should not overwrite existing cover');

        // Cleanup
        File::deleteDirectory($fullPath);
    }
}
