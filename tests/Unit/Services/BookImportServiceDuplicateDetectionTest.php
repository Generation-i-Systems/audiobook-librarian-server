<?php

namespace Tests\Unit\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\Series;
use App\Services\BookImportService;
use App\Services\GenreMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookImportServiceDuplicateDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected BookImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $genreMappingService = $this->app->make(GenreMappingService::class);
        $sourceTrashService = $this->app->make(\App\Services\SourceTrashService::class);
        $this->service = new BookImportService($genreMappingService, $sourceTrashService);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function findExistingBookMatchesByTitleAndAuthor(): void
    {
        $author = Author::create(['name' => 'Brandon Sanderson']);
        $book = Book::create([
            'title' => 'The Final Empire',
            'directory_path' => 'Fantasy/Brandon Sanderson/The Final Empire',
            'language' => 'en',
        ]);
        $book->authors()->attach($author);

        $metadata = [
            'title' => 'The Final Empire',
            'author' => ['Brandon Sanderson'],
        ];

        $result = $this->service->findExistingBook('/some/path', $metadata);

        $this->assertNotNull($result);
        $this->assertEquals($book->id, $result->id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function findExistingBookReturnsNullWhenSeriesNumberDiffers(): void
    {
        $author = Author::create(['name' => 'Brandon Sanderson']);
        $series = Series::create(['name' => 'Mistborn']);

        $book = Book::create([
            'title' => 'The Final Empire',
            'directory_path' => 'Fantasy/Brandon Sanderson/Mistborn/The Final Empire',
            'language' => 'en',
        ]);
        $book->authors()->attach($author);
        $book->series()->attach($series, ['series_number' => 5]);

        $metadata = [
            'title' => 'The Final Empire',
            'author' => ['Brandon Sanderson'],
            'series' => 'Mistborn',
            'series_number' => 3,
        ];

        $result = $this->service->findExistingBook('/some/path', $metadata);

        // BUG: $existingBook->series_number is always null (no such attribute on Book model,
        // it's on the pivot). So $existingSeriesNumber = null ?? 0 = 0.
        // Then: 0 > 0 || 3 > 0 → true, and 0 != 3 → true → returns null.
        // This happens to return null (correct outcome) but for wrong reason.
        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function findExistingBookReturnsNullWhenSeriesDiffers(): void
    {
        $author = Author::create(['name' => 'Brandon Sanderson']);
        $series = Series::create(['name' => 'Mistborn']);

        $book = Book::create([
            'title' => 'The Hero of Ages',
            'directory_path' => 'Fantasy/Brandon Sanderson/Mistborn/The Hero of Ages',
            'language' => 'en',
        ]);
        $book->authors()->attach($author);
        $book->series()->attach($series, ['series_number' => 3]);

        $metadata = [
            'title' => 'The Hero of Ages',
            'author' => ['Brandon Sanderson'],
            'series' => 'Stormlight Archive',
        ];

        $result = $this->service->findExistingBook('/some/path', $metadata);

        // BUG: $existingBook->series returns a Collection (BelongsToMany), not a string.
        // !empty(Collection) is always true (objects are never empty()).
        // Collection !== 'Stormlight Archive' is always true.
        // So it returns null (correct outcome for different series, but for wrong reason).
        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function findExistingBookMatchesByDirectoryNameSuffix(): void
    {
        $book = Book::create([
            'title' => 'Archive Title',
            'directory_path' => 'Fantasy/Author Name/Series Name/Archive Title',
            'language' => 'en',
        ]);

        $result = $this->service->findExistingBook('/incoming/Archive Title', []);

        $this->assertNotNull($result);
        $this->assertSame($book->id, $result->id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function createBookFromMetadataReusesExistingRecordWithMissingDirectoryPath(): void
    {
        $author = Author::create(['name' => 'Brandon Sanderson']);
        $series = Series::create(['name' => 'Mistborn']);

        $existingBook = Book::create([
            'title' => 'The Final Empire',
            'directory_path' => null,
            'language' => 'en',
        ]);
        $existingBook->authors()->attach($author);
        $existingBook->series()->attach($series, ['series_number' => 1]);

        $metadata = [
            'title' => 'The Final Empire',
            'author' => ['Brandon Sanderson'],
            'series' => 'Mistborn',
            'series_number' => 1,
            'genre' => ['Fantasy'],
            'language' => 'en',
        ];

        $book = $this->service->createBookFromMetadata($metadata, ['path' => '/incoming/The Final Empire', 'files' => []]);

        $this->assertNotNull($book);
        $this->assertSame($existingBook->id, $book->id);
        $this->assertSame(1, Book::query()->count());
        $this->assertNotNull($book->fresh()?->directory_path);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function findExistingBookDoesNotMatchDifferentTitleOnGenreAuthorOnlyDirectory(): void
    {
        // Regression test: two unrelated books by the same author can each legitimately
        // end up with no series, which historically could degenerate their directory_path
        // down to just "Genre/Author" (no title/series segment). That thin path must never
        // be trusted as a duplicate-detection signal on its own, or importing the second
        // book silently overwrites the first book's already-confirmed metadata.
        $author = Author::create(['name' => 'Sadie King']);
        $existingBook = Book::create([
            'title' => 'Men of Maple Mountain Books 1-7 - A Mountain Man Romance Collection',
            'directory_path' => 'Romance/Sadie King',
            'language' => 'en',
        ]);
        $existingBook->authors()->attach($author);

        $metadata = [
            'title' => 'Wild Heart Mountain: Wild Riders MC Books 1-3 - An MC Romance Collection',
            'author' => ['Sadie King'],
            'genre' => ['Romance'],
            'custom_directory_path' => 'Romance/Sadie King',
        ];

        $result = $this->service->findExistingBook('/some/path', $metadata);

        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function findExistingBookStillMatchesGenreAuthorOnlyDirectoryWhenTitleAgrees(): void
    {
        $author = Author::create(['name' => 'Sadie King']);
        $existingBook = Book::create([
            'title' => 'Men of Maple Mountain Books 1-7 - A Mountain Man Romance Collection',
            'directory_path' => 'Romance/Sadie King',
            'language' => 'en',
        ]);
        $existingBook->authors()->attach($author);

        $metadata = [
            'title' => 'Men of Maple Mountain Books 1-7 - A Mountain Man Romance Collection',
            'author' => ['Sadie King'],
            'genre' => ['Romance'],
            'custom_directory_path' => 'Romance/Sadie King',
        ];

        $result = $this->service->findExistingBook('/some/path', $metadata);

        $this->assertNotNull($result);
        $this->assertSame($existingBook->id, $result->id);
    }
}
