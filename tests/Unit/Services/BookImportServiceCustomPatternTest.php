<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Book;
use App\Models\Series;
use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookImportServiceCustomPatternTest extends TestCase
{
    use RefreshDatabase;

    protected BookImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $genreMappingService = $this->app->make(GenreMappingService::class);
        $sourceTrashService = $this->app->make(SourceTrashService::class);
        $this->service = new BookImportService($genreMappingService, $sourceTrashService);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function generateDirectoryPathSupportsCustomPattern(): void
    {
        $metadata = [
            'title' => 'The Way of Kings',
            'author' => ['Brandon Sanderson'],
            'genre' => 'Fantasy',
            'series' => 'The Stormlight Archive',
            'series_number' => 1,
            'year' => 2010,
        ];

        // Test custom pattern with placeholders
        $options = [
            'directory_pattern' => '[genre]/VA/[series]/[title] ([author])',
            'include_title' => true,
        ];

        $path = $this->service->generateDirectoryPath($metadata, $options);

        $this->assertEquals('Fantasy/VA/The Stormlight Archive/01 The Way of Kings (Brandon Sanderson)', $path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function generateDirectoryPathHandlesMissingMetadataInPattern(): void
    {
        $metadata = [
            'title' => 'Standalone Book',
            'author' => ['John Doe'],
            'genre' => 'Fiction',
            // series and series_number missing
        ];

        $options = [
            'directory_pattern' => '[genre]/[series]/[title]',
            'include_title' => true,
        ];

        $path = $this->service->generateDirectoryPath($metadata, $options);

        // [series] should be empty, and cleaned up
        $this->assertEquals('Fiction/Standalone Book', $path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function generateDirectoryPathSupportsExplicitSeriesNumberInPattern(): void
    {
        $metadata = [
            'title' => 'The Way of Kings',
            'author' => ['Brandon Sanderson'],
            'genre' => 'Fantasy',
            'series' => 'The Stormlight Archive',
            'series_number' => 1,
        ];

        $options = [
            'directory_pattern' => '[author]/[series]/Book [series_number] - [title]',
            'include_title' => true,
        ];

        $path = $this->service->generateDirectoryPath($metadata, $options);

        // title should NOT have the auto-prefix 01 because [series_number] is explicit in pattern
        $this->assertEquals('Brandon Sanderson/The Stormlight Archive/Book 01 - The Way of Kings', $path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function generateDirectoryPathRespectsIncludeTitleFalseWithPattern(): void
    {
        $metadata = [
            'title' => 'The Way of Kings',
            'author' => ['Brandon Sanderson'],
            'genre' => 'Fantasy',
            'series' => 'The Stormlight Archive',
            'series_number' => 1,
        ];

        $options = [
            'directory_pattern' => '[genre]/[author]/[series]/[title]',
            'include_title' => false,
        ];

        $path = $this->service->generateDirectoryPath($metadata, $options);

        // Should return path up to the segment before [title]
        $this->assertEquals('Fantasy/Brandon Sanderson/The Stormlight Archive', $path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function processSingleBookInjectsCollectionFromConfig(): void
    {
        $this->service->setConfig(['collection' => 'My Special Collection']);

        $audiobook = [
            'name' => 'Test Book',
            'path' => '/tmp/test-book',
            'files' => ['/tmp/test-book/file.m4b'],
        ];

        $metadata = [
            'title' => 'Test Book',
            'author' => ['Test Author'],
            'genre' => 'Fiction',
        ];

        // Mock callbacks
        $enrichCallback = fn ($m) => $m;
        $isValidCallback = fn ($m, $e) => true;
        $genPathCallback = fn ($m) => 'test/path';
        $createBookCallback = function ($m, $a) {
            $this->assertEquals('My Special Collection', $m['collection']);
            return new Book(['title' => $m['title']]);
        };
        $moveCallback = fn ($a, $b, $o) => true;
        $getOpCallback = fn () => 'copy';

        $this->service->processSingleBook(
            $audiobook,
            $metadata,
            $enrichCallback,
            $isValidCallback,
            $genPathCallback,
            $createBookCallback,
            $moveCallback,
            $getOpCallback
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function processSingleBookInjectsGenreFromConfig(): void
    {
        $this->service->setConfig(['genre' => 'Science Fiction']);

        $audiobook = [
            'name' => 'Test Book',
            'path' => '/tmp/test-book',
            'files' => ['/tmp/test-book/file.m4b'],
        ];

        $metadata = [
            'title' => 'Test Book',
            'author' => ['Test Author'],
            'genre' => 'Old Genre', // Should be overridden
        ];

        // Mock callbacks
        $enrichCallback = fn ($m) => $m;
        $isValidCallback = fn ($m, $e) => true;
        $genPathCallback = fn ($m) => 'test/path';
        $createBookCallback = function ($m, $a) {
            $this->assertEquals('Science Fiction', $m['genre']);
            return new Book(['title' => $m['title']]);
        };
        $moveCallback = fn ($a, $b, $o) => true;
        $getOpCallback = fn () => 'copy';

        $this->service->processSingleBook(
            $audiobook,
            $metadata,
            $enrichCallback,
            $isValidCallback,
            $genPathCallback,
            $createBookCallback,
            $moveCallback,
            $getOpCallback
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function processSingleBookInjectsGenreFromConfigAndForcesIt(): void
    {
        $this->service->setConfig(['genre' => 'Force This Genre']);

        $audiobook = [
            'name' => 'Test Book',
            'path' => '/tmp/test-book',
            'files' => ['/tmp/test-book/file.m4b'],
        ];

        $metadata = [
            'title' => 'Test Book',
            'author' => ['Test Author'],
            'genre' => 'Original Genre',
        ];

        // Enrichment should NOT be able to overwrite the forced genre
        $enrichCallback = function ($m) {
            $m['genre'] = 'Enriched Genre';
            return $m;
        };
        $isValidCallback = fn ($m, $e) => true;
        $genPathCallback = fn ($m) => 'test/path';
        $createBookCallback = function ($m, $a) {
            $this->assertEquals('Force This Genre', $m['genre']);
            return new Book(['title' => $m['title']]);
        };
        $moveCallback = fn ($a, $b, $o) => true;
        $getOpCallback = fn () => 'copy';

        $this->service->processSingleBook(
            $audiobook,
            $metadata,
            $enrichCallback,
            $isValidCallback,
            $genPathCallback,
            $createBookCallback,
            $moveCallback,
            $getOpCallback
        );
    }
}
