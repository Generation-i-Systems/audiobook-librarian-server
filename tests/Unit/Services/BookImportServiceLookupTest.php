<?php

namespace Tests\Unit\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Series;
use App\Services\BookImportService;
use App\Services\GenreMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookImportServiceLookupTest extends TestCase
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
    public function lookupGenreReturnsGenreWhenSeriesAndAuthorMatch(): void
    {
        $author = Author::create(['name' => 'Brandon Sanderson']);
        $series = Series::create(['name' => 'Mistborn']);
        $genre = Genre::create(['name' => 'Fantasy']);

        $book = Book::create([
            'title' => 'The Final Empire',
            'directory_path' => 'Fantasy/Brandon Sanderson/Mistborn/The Final Empire',
            'language' => 'en',
        ]);
        $book->authors()->attach($author);
        $book->series()->attach($series, ['series_number' => 1]);
        $book->genres()->attach($genre);

        $metadata = [
            'series' => 'Mistborn',
            'author' => ['Brandon Sanderson'],
        ];

        $result = $this->service->lookupGenreFromExistingSeries($metadata);

        $this->assertEquals('Fantasy', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function lookupGenreReturnsNullWhenNoSeriesProvided(): void
    {
        $result = $this->service->lookupGenreFromExistingSeries([
            'series' => '',
            'author' => ['Brandon Sanderson'],
        ]);

        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function lookupGenreReturnsNullWhenNoAuthorsProvided(): void
    {
        $result = $this->service->lookupGenreFromExistingSeries([
            'series' => 'Mistborn',
            'author' => [],
        ]);

        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function lookupGenreReturnsNullWhenNoMatchingBooks(): void
    {
        $result = $this->service->lookupGenreFromExistingSeries([
            'series' => 'Nonexistent Series',
            'author' => ['Unknown Author'],
        ]);

        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function lookupGenreReturnsOtherWhenOnlyOtherExists(): void
    {
        $author = Author::create(['name' => 'Test Author']);
        $series = Series::create(['name' => 'Test Series']);
        $otherGenre = Genre::create(['name' => 'Other']);

        $book = Book::create([
            'title' => 'Existing Book',
            'directory_path' => 'Other/Test Author/Test Series/Existing Book',
            'language' => 'en',
        ]);
        $book->authors()->attach($author);
        $book->series()->attach($series, ['series_number' => 1]);
        $book->genres()->attach($otherGenre);

        $result = $this->service->lookupGenreFromExistingSeries([
            'series' => 'Test Series',
            'author' => ['Test Author'],
        ]);

        // Current behavior: returns 'Other' (no filtering of unclear genres)
        $this->assertEquals('Other', $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function lookupGenreReturnsFirstGenreFromCollection(): void
    {
        $author = Author::create(['name' => 'Multi Genre Author']);
        $series = Series::create(['name' => 'Multi Genre Series']);
        $fantasy = Genre::create(['name' => 'Fantasy']);
        $sciFi = Genre::create(['name' => 'Science Fiction']);

        $book = Book::create([
            'title' => 'Multi Genre Book',
            'directory_path' => 'Fantasy/Multi Genre Author/Multi Genre Series/Multi Genre Book',
            'language' => 'en',
        ]);
        $book->authors()->attach($author);
        $book->series()->attach($series, ['series_number' => 1]);
        $book->genres()->attach($fantasy, ['is_primary' => true]);
        $book->genres()->attach($sciFi, ['is_primary' => false]);

        $result = $this->service->lookupGenreFromExistingSeries([
            'series' => 'Multi Genre Series',
            'author' => ['Multi Genre Author'],
        ]);

        // Current behavior: uses ->first() which returns first by ID, not by is_primary
        $this->assertEquals('Fantasy', $result);
    }
}
