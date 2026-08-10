<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Series;

class GenreBooksAndSeriesTest extends ApiTestCase
{
    public function testBooksByGenreReturns404ForUnknownGenre(): void
    {
        $response = $this->getJson('/api/v1/genres/999999/books');

        $response->assertStatus(404);
    }

    public function testBooksByGenreDefaultsToMostRecentFirst(): void
    {
        $genre = Genre::factory()->create(['name' => 'Fantasy']);

        $older = Book::factory()->create(['created_at' => now()->subDays(5)]);
        $older->genres()->attach($genre->id);

        $newer = Book::factory()->create(['created_at' => now()]);
        $newer->genres()->attach($genre->id);

        $unrelated = Book::factory()->create();

        $response = $this->getJson("/api/v1/genres/{$genre->id}/books");

        $response->assertOk();
        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$newer->id, $older->id], $ids);
    }

    public function testBooksByGenreSupportsAlphaSort(): void
    {
        $genre = Genre::factory()->create();

        $bookB = Book::factory()->create(['title' => 'Bravo']);
        $bookB->genres()->attach($genre->id);
        $bookA = Book::factory()->create(['title' => 'Alpha']);
        $bookA->genres()->attach($genre->id);

        $response = $this->getJson("/api/v1/genres/{$genre->id}/books?sort=alpha");

        $response->assertOk();
        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$bookA->id, $bookB->id], $ids);
    }

    public function testSeriesByGenreReturns404ForUnknownGenre(): void
    {
        $response = $this->getJson('/api/v1/genres/999999/series');

        $response->assertStatus(404);
    }

    public function testSeriesByGenreOnlyReturnsSeriesWithABookInThatGenre(): void
    {
        $genre = Genre::factory()->create();
        $otherGenre = Genre::factory()->create();

        $matchingSeries = Series::factory()->create(['name' => 'Matching Saga']);
        $matchingBook = Book::factory()->create();
        $matchingBook->genres()->attach($genre->id);
        $matchingSeries->books()->attach($matchingBook->id, ['series_number' => '1']);

        $unrelatedSeries = Series::factory()->create(['name' => 'Unrelated Saga']);
        $unrelatedBook = Book::factory()->create();
        $unrelatedBook->genres()->attach($otherGenre->id);
        $unrelatedSeries->books()->attach($unrelatedBook->id, ['series_number' => '1']);

        $response = $this->getJson("/api/v1/genres/{$genre->id}/series");

        $response->assertOk();
        $names = array_column($response->json('data'), 'name');
        $this->assertContains('Matching Saga', $names);
        $this->assertNotContains('Unrelated Saga', $names);
    }

    public function testSeriesByGenreIncludesCoverUrlsForFirstFourBooksInSeriesOrder(): void
    {
        $genre = Genre::factory()->create();
        $series = Series::factory()->create(['name' => 'Numbered Saga']);

        $booksByNumber = [];
        foreach ([3, 1, 2] as $number) {
            $book = Book::factory()->create(['cover_image' => "cover-{$number}.jpg"]);
            $book->genres()->attach($genre->id);
            $series->books()->attach($book->id, ['series_number' => (string) $number]);
            $booksByNumber[$number] = $book;
        }

        $unrelatedGenre = Genre::factory()->create();
        $bookInOtherGenre = Book::factory()->create(['cover_image' => 'cover-other.jpg']);
        $bookInOtherGenre->genres()->attach($unrelatedGenre->id);
        $series->books()->attach($bookInOtherGenre->id, ['series_number' => '4']);

        $response = $this->getJson("/api/v1/genres/{$genre->id}/series");

        $response->assertOk();
        $data = $response->json('data');
        $coverUrls = $data[0]['cover_urls'];

        // Ordered by series_number (1, 2, 3), and excludes the book only in the other genre.
        $this->assertCount(3, $coverUrls);
        $this->assertStringContainsString("/books/{$booksByNumber[1]->id}/cover", $coverUrls[0]);
        $this->assertStringContainsString("/books/{$booksByNumber[2]->id}/cover", $coverUrls[1]);
        $this->assertStringContainsString("/books/{$booksByNumber[3]->id}/cover", $coverUrls[2]);
    }

    public function testSeriesByGenreSortsByLengthDescending(): void
    {
        $genre = Genre::factory()->create();

        $shortSeries = Series::factory()->create(['name' => 'Short Series']);
        $shortBook = Book::factory()->create();
        $shortBook->genres()->attach($genre->id);
        $shortSeries->books()->attach($shortBook->id, ['series_number' => '1']);

        $longSeries = Series::factory()->create(['name' => 'Long Series']);
        foreach (range(1, 3) as $number) {
            $book = Book::factory()->create();
            $book->genres()->attach($genre->id);
            $longSeries->books()->attach($book->id, ['series_number' => (string) $number]);
        }

        $response = $this->getJson("/api/v1/genres/{$genre->id}/series?sort=length");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame('Long Series', $data[0]['name']);
        $this->assertSame(3, $data[0]['book_count_in_genre']);
        $this->assertSame('Short Series', $data[1]['name']);
    }
}
