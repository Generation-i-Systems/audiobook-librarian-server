<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Series;

class SeriesBooksTest extends ApiTestCase
{
    public function testBooksBySeriesReturns404ForUnknownSeries(): void
    {
        $response = $this->getJson('/api/v1/series/999999/books');

        $response->assertStatus(404);
    }

    public function testBooksBySeriesExcludesNeedsReviewBooks(): void
    {
        $series = Series::factory()->create();

        $reviewed = Book::factory()->create(['needs_review' => false]);
        $series->books()->attach($reviewed->id, ['series_number' => '1']);

        $pendingReview = Book::factory()->create(['needs_review' => true]);
        $series->books()->attach($pendingReview->id, ['series_number' => '2']);

        $response = $this->getJson("/api/v1/series/{$series->id}/books");

        $response->assertOk();
        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($reviewed->id, $ids);
        $this->assertNotContains($pendingReview->id, $ids);
    }

    public function testBooksBySeriesReturnsDistinctGenresAcrossItsBooks(): void
    {
        $series = Series::factory()->create();
        $fantasy = Genre::factory()->create(['name' => 'Fantasy']);
        $action = Genre::factory()->create(['name' => 'Action']);

        $bookA = Book::factory()->create(['needs_review' => false]);
        $bookA->genres()->attach([$fantasy->id, $action->id]);
        $series->books()->attach($bookA->id, ['series_number' => '1']);

        $bookB = Book::factory()->create(['needs_review' => false]);
        $bookB->genres()->attach($fantasy->id);
        $series->books()->attach($bookB->id, ['series_number' => '2']);

        // A needs_review book's genre must not appear, since it's excluded from the list too.
        $onlyOnPendingBook = Genre::factory()->create(['name' => 'Horror']);
        $pendingReview = Book::factory()->create(['needs_review' => true]);
        $pendingReview->genres()->attach($onlyOnPendingBook->id);
        $series->books()->attach($pendingReview->id, ['series_number' => '3']);

        $response = $this->getJson("/api/v1/series/{$series->id}/books");

        $response->assertOk();
        $genreNames = array_column($response->json('genres'), 'name');
        $this->assertSame(['Action', 'Fantasy'], $genreNames);
    }

    public function testBooksBySeriesFiltersByGenreIdsWithOrSemantics(): void
    {
        $series = Series::factory()->create();
        $fantasy = Genre::factory()->create(['name' => 'Fantasy']);
        $action = Genre::factory()->create(['name' => 'Action']);
        $horror = Genre::factory()->create(['name' => 'Horror']);

        $fantasyBook = Book::factory()->create(['needs_review' => false]);
        $fantasyBook->genres()->attach($fantasy->id);
        $series->books()->attach($fantasyBook->id, ['series_number' => '1']);

        $actionBook = Book::factory()->create(['needs_review' => false]);
        $actionBook->genres()->attach($action->id);
        $series->books()->attach($actionBook->id, ['series_number' => '2']);

        $horrorBook = Book::factory()->create(['needs_review' => false]);
        $horrorBook->genres()->attach($horror->id);
        $series->books()->attach($horrorBook->id, ['series_number' => '3']);

        $response = $this->getJson("/api/v1/series/{$series->id}/books?" . http_build_query([
            'genre_ids' => [$fantasy->id, $action->id],
        ]));

        $response->assertOk();
        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($fantasyBook->id, $ids);
        $this->assertContains($actionBook->id, $ids);
        $this->assertNotContains($horrorBook->id, $ids);
    }

    public function testBooksBySeriesAcceptsPerPageAsQueryStringString(): void
    {
        $series = Series::factory()->create();
        $book = Book::factory()->create(['needs_review' => false]);
        $series->books()->attach($book->id, ['series_number' => '1']);

        // The client sends ?page=1&per_page=100, so per_page arrives as a string.
        $response = $this->getJson("/api/v1/series/{$series->id}/books?page=1&per_page=100");

        $response->assertOk();
        $this->assertSame(100, $response->json('meta.per_page'));
        $this->assertSame($book->id, $response->json('data.0.id'));
    }
}
