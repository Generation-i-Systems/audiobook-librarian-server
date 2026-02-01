<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Book;
use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class BookImportServiceLibraryJsonWrappersTest extends TestCase
{
    use RefreshDatabase;

    protected BookImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $genreMappingService = $this->app->make(GenreMappingService::class);
        $sourceTrashService = $this->app->make(SourceTrashService::class);

        $this->service = new BookImportService($genreMappingService, $sourceTrashService);

        Storage::fake('books');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function getAllBooksReturnsOnlyBooksMissingLibraryJsonUpdateUnlessProcessAll(): void
    {
        /** @var Book $included */
        $included = Book::factory()->create([
            'title' => 'Included',
            'directory_path' => 'included/book',
            'last_library_json_update' => null,
        ]);

        /** @var Book $excluded */
        $excluded = Book::factory()->create([
            'title' => 'Excluded',
            'directory_path' => 'excluded/book',
            'last_library_json_update' => now(),
        ]);

        /** @var Book $includedMissingDirectory */
        $includedMissingDirectory = Book::factory()->create([
            'title' => 'Included Missing Directory',
            'directory_path' => null,
            'last_library_json_update' => now(),
        ]);

        $books = $this->service->getAllBooks(false);
        $bookIds = array_map(static fn (array $b) => (int) ($b['id'] ?? 0), $books);

        $this->assertContains($included->id, $bookIds);
        $this->assertContains($includedMissingDirectory->id, $bookIds);
        $this->assertNotContains($excluded->id, $bookIds);

        $allBooks = $this->service->getAllBooks(true);
        $allBookIds = array_map(static fn (array $b) => (int) ($b['id'] ?? 0), $allBooks);

        $this->assertContains($included->id, $allBookIds);
        $this->assertContains($excluded->id, $allBookIds);
        $this->assertContains($includedMissingDirectory->id, $allBookIds);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function previewLibraryJsonReturnsPreparedBookData(): void
    {
        $book = [
            'id' => 123,
            'title' => 'My Title',
            'directory_path' => 'author/book',
            'language' => 'en',
            'authors' => [['id' => 1, 'name' => 'Author']],
            'genres' => [['id' => 1, 'name' => 'Genre']],
            'duration' => null,
        ];

        $preview = $this->service->previewLibraryJson($book);

        $this->assertSame('My Title', $preview['title'] ?? null);
        $this->assertSame('en', $preview['language'] ?? null);
        $this->assertSame('author/book', $preview['directoryPath'] ?? null);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function resolveBookDirectoryPathResolvesStorageDiskPath(): void
    {
        Storage::disk('books')->makeDirectory('author/book');

        $resolved = $this->service->resolveBookDirectoryPath([
            'directory_path' => 'author/book',
        ]);

        $this->assertIsString($resolved);
        $this->assertStringContainsString('author/book', str_replace('\\', '/', $resolved));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function generateLibraryJsonDryRunReturnsTrueWithoutWriting(): void
    {
        Storage::disk('books')->makeDirectory('author/book');

        $result = $this->service->generateLibraryJson([
            'id' => 123,
            'title' => 'My Title',
            'directory_path' => 'author/book',
            'language' => 'en',
            'authors' => [['id' => 1, 'name' => 'Author']],
            'genres' => [['id' => 1, 'name' => 'Genre']],
        ], true);

        $this->assertTrue($result);
        $this->assertFalse(Storage::disk('books')->exists('author/book/librarian.json'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function generateLibraryJsonWritesFileWhenNotDryRunAndAudioExists(): void
    {
        $genreMappingService = $this->app->make(GenreMappingService::class);
        $sourceTrashService = $this->app->make(SourceTrashService::class);

        $service = Mockery::mock(BookImportService::class, [$genreMappingService, $sourceTrashService])->makePartial();
        $service->shouldAllowMockingProtectedMethods();

        $service->shouldReceive('updateLibraryJson')
            ->once()
            ->andReturn(true);

        $result = $service->generateLibraryJson([
            'id' => 123,
            'title' => 'My Title',
            'directory_path' => 'author/book',
            'language' => 'en',
        ], false);

        $this->assertTrue($result);
    }
}
