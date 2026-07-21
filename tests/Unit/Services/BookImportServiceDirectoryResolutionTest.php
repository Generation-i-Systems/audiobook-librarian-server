<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Book;
use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class BookImportServiceDirectoryResolutionTest extends TestCase
{
    private BookImportService $service;
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $genreMappingService = Mockery::mock(GenreMappingService::class);
        $sourceTrashService = Mockery::mock(SourceTrashService::class);
        $this->service = new BookImportService($genreMappingService, $sourceTrashService);

        $this->storageRoot = sys_get_temp_dir() . '/book_dir_resolution_test_' . uniqid();
        File::makeDirectory($this->storageRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storageRoot);
        Mockery::close();
        parent::tearDown();
    }

    public function testFallsBackToTitleSubdirectoryWhenDirectoryPathIsTruncatedToAuthorFolder(): void
    {
        // Simulates the "Charlie N. Holmberg" bug: directory_path is missing the
        // book's own title segment, so it points at an author folder containing
        // several sibling books' audio files.
        $authorDir = $this->storageRoot . '/Fantasy/Charlie N. Holmberg';
        $titleDir = $authorDir . '/The Hanging City';
        $siblingDir = $authorDir . '/The Fifth Doll';

        File::makeDirectory($titleDir, 0755, true);
        File::makeDirectory($siblingDir, 0755, true);
        File::put($titleDir . '/book.m4b', 'hanging-city-audio');
        File::put($siblingDir . '/book.m4b', 'fifth-doll-audio');

        $book = new Book([
            'title' => 'The Hanging City',
            'directory_path' => 'Fantasy/Charlie N. Holmberg',
        ]);

        $resolved = $this->service->resolveExistingBookDirectory($book, $this->storageRoot);

        $this->assertSame($titleDir, $resolved);
    }

    public function testUsesStoredDirectoryPathWhenItAlreadyContainsAudioFilesDirectly(): void
    {
        $bookDir = $this->storageRoot . '/Fantasy/Some Author/Some Book';
        File::makeDirectory($bookDir, 0755, true);
        File::put($bookDir . '/book.m4b', 'audio');

        $book = new Book([
            'title' => 'Some Book',
            'directory_path' => 'Fantasy/Some Author/Some Book',
        ]);

        $resolved = $this->service->resolveExistingBookDirectory($book, $this->storageRoot);

        $this->assertSame($bookDir, $resolved);
    }

    public function testReturnsStoredDirectoryWhenNoTitleSubdirectoryExistsEither(): void
    {
        $emptyDir = $this->storageRoot . '/Fantasy/Empty Author';
        File::makeDirectory($emptyDir, 0755, true);

        $book = new Book([
            'title' => 'Untraceable Book',
            'directory_path' => 'Fantasy/Empty Author',
        ]);

        $resolved = $this->service->resolveExistingBookDirectory($book, $this->storageRoot);

        $this->assertSame($emptyDir, $resolved);
    }

    public function testReturnsNullWhenDirectoryPathIsEmpty(): void
    {
        $book = new Book(['title' => 'No Path Book', 'directory_path' => null]);

        $resolved = $this->service->resolveExistingBookDirectory($book, $this->storageRoot);

        $this->assertNull($resolved);
    }

    public function testReturnsNullWhenStoredDirectoryDoesNotExistOnDisk(): void
    {
        $book = new Book([
            'title' => 'Missing Book',
            'directory_path' => 'Fantasy/Nonexistent',
        ]);

        $resolved = $this->service->resolveExistingBookDirectory($book, $this->storageRoot);

        $this->assertNull($resolved);
    }
}
