<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Series;
use App\Services\BookDataTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookDataTransformerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function toDocumentStoreBookMapsRelatedDataToLegacyShape(): void
    {
        $book = Book::factory()->create([
            'title' => 'Refactoring',
            'cover_image' => 'covers/refactoring.jpg',
            'directory_path' => 'tech/refactoring',
        ]);

        $author = Author::query()->create(['name' => 'Martin Fowler']);
        $narrator = Narrator::query()->create(['name' => 'Simon Vance']);
        $genre = Genre::query()->create(['name' => 'Technology']);
        $series = Series::query()->create(['name' => 'Software Classics', 'is_collection' => true]);

        $book->authors()->attach($author->id);
        $book->narrators()->attach($narrator->id);
        $book->genres()->attach($genre->id);
        $book->series()->attach($series->id, ['series_number' => '1.5']);

        $book->load(['authors', 'narrators', 'genres', 'series', 'chapters']);

        $result = (new BookDataTransformer())->toDocumentStoreBook($book);

        $this->assertSame('refactoring.jpg', $result['coverImage']);
        $this->assertSame(['Martin Fowler'], $result['author']);
        $this->assertSame(['Martin Fowler'], $result['authors']);
        $this->assertSame(['Technology'], $result['genre']);
        $this->assertSame(['id' => $author->id, 'name' => 'Martin Fowler'], $result['authors_data'][0]);
        $this->assertSame('Software Classics', $result['series'][0]['seriesName']);
        $this->assertSame('1.5', $result['series'][0]['number']);
        $this->assertFalse($result['series'][0]['isCollection']);
        $this->assertSame($series->id, $result['series_data'][0]['id']);
        $this->assertSame('1.5', $result['series_data'][0]['series_number']);
        $this->assertFalse($result['series_data'][0]['is_collection']);
    }

    #[Test]
    public function toBookListItemBuildsApiCoverUrlAndRelationshipPayloads(): void
    {
        $this->app->instance('request', Request::create('https://library.test/api/v1/books', 'GET'));

        $book = Book::factory()->create([
            'title' => 'Domain-Driven Design',
            'cover_image' => 'ddd.jpg',
            'directory_path' => 'design/ddd',
            'duration' => 3661,
            'audio_file_count' => 12,
        ]);

        $author = Author::query()->create(['name' => 'Eric Evans']);
        $genre = Genre::query()->create(['name' => 'Architecture']);
        $series = Series::query()->create(['name' => 'Classics', 'is_collection' => false]);

        $book->authors()->attach($author->id);
        $book->genres()->attach($genre->id);
        $book->series()->attach($series->id, ['series_number' => '2']);
        $book->load(['authors', 'narrators', 'genres', 'series']);
        $book->setRawAttributes(array_merge($book->getAttributes(), ['total_size' => 2048]), true);

        $result = (new BookDataTransformer())->toBookListItem($book);

        $this->assertSame('design/ddd/ddd.jpg', $result['coverImage']);
        $this->assertSame('https://library.test/api/v1/books/' . $book->id . '/cover', $result['cover_url']);
        $this->assertSame(['Eric Evans'], $result['author']);
        $this->assertSame(['Architecture'], $result['genre']);
        $this->assertSame('01:01:01', $result['duration']);
        $this->assertSame('2', $result['series'][0]['series_number']);
        $this->assertSame('2', $result['series_data'][0]['pivot']['series_number']);
    }

    #[Test]
    public function toBookListItemUpgradesCoverLinksToHttps(): void
    {
        $this->app->instance('request', Request::create('http://library.test/api/v1/books', 'GET'));

        $book = Book::factory()->create([
            'cover_image' => 'http://cdn.example.test/ddd.jpg',
            'directory_path' => 'design/ddd',
        ]);

        $book->load(['authors', 'narrators', 'genres', 'series']);

        $result = (new BookDataTransformer())->toBookListItem($book);

        $this->assertSame('https://cdn.example.test/ddd.jpg', $result['coverImage']);
        $this->assertSame('https://library.test/api/v1/books/' . $book->id . '/cover', $result['cover_url']);
    }

    #[Test]
    public function toRecentBookReturnsCompactRecentBookShape(): void
    {
        $book = Book::factory()->create([
            'title' => 'Clean Code',
            'cover_image' => 'clean-code.jpg',
            'directory_path' => 'software/clean-code',
            'description' => 'A handbook of agile software craftsmanship.',
            'duration' => 7200,
            'audio_file_count' => 5,
            'release_date' => '2008-08-01',
        ]);

        $author = Author::query()->create(['name' => 'Robert C. Martin']);
        $narrator = Narrator::query()->create(['name' => 'Uncle Bob']);

        $book->authors()->attach($author->id);
        $book->narrators()->attach($narrator->id);
        $book->load(['authors', 'narrators']);
        $book->setRawAttributes(array_merge($book->getAttributes(), ['total_size' => 4096]), true);

        $result = (new BookDataTransformer())->toRecentBook($book);

        $this->assertSame((string) $book->id, $result['id']);
        $this->assertSame('software/clean-code/clean-code.jpg', $result['coverImage']);
        $this->assertSame('2008-08-01', $result['releaseDate']);
        $this->assertSame(['Robert C. Martin'], $result['authors']);
        $this->assertSame(['Uncle Bob'], $result['narrators']);
        $this->assertSame(4096, $result['totalSize']);
    }
}
