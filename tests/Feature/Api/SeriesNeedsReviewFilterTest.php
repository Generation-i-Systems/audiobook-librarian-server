<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Author;
use App\Models\Book;
use App\Models\Series;

class SeriesNeedsReviewFilterTest extends ApiTestCase
{
    public function test_series_excludes_needs_review_books_and_series_by_default(): void
    {
        $author = Author::factory()->create();

        // Series with only needs_review books
        $nrSeries = Series::factory()->create(['name' => 'NR Only Series']);
        $nrBook1 = Book::factory()->create(['needs_review' => true]);
        $nrBook1->authors()->attach($author);
        $nrBook1->series()->attach($nrSeries, ['series_number' => '1']);

        // Series with mixed books
        $mixedSeries = Series::factory()->create(['name' => 'Mixed Series']);
        $okBook = Book::factory()->create(['needs_review' => false]);
        $okBook->authors()->attach($author);
        $okBook->series()->attach($mixedSeries, ['series_number' => '1']);
        $nrBook2 = Book::factory()->create(['needs_review' => true]);
        $nrBook2->authors()->attach($author);
        $nrBook2->series()->attach($mixedSeries, ['series_number' => '2']);

        // Series with only normal books
        $okSeries = Series::factory()->create(['name' => 'OK Series']);
        $okBook2 = Book::factory()->create(['needs_review' => false]);
        $okBook2->authors()->attach($author);
        $okBook2->series()->attach($okSeries, ['series_number' => '1']);

        $response = $this->getJson('/api/v1/series');
        $response->assertStatus(200);
        $data = $response->json();

        $seriesNames = array_column($data['series'], 'name');
        $this->assertNotContains('NR Only Series', $seriesNames);
        $this->assertContains('Mixed Series', $seriesNames);
        $this->assertContains('OK Series', $seriesNames);

        // book_count should exclude needs_review books for mixed series
        $mixed = collect($data['series'])->firstWhere('name', 'Mixed Series');
        $this->assertNotNull($mixed);
        $this->assertEquals(1, $mixed['book_count']);
    }

    public function test_series_include_needs_review_when_flag_is_set(): void
    {
        $author = Author::factory()->create();
        $nrSeries = Series::factory()->create(['name' => 'NR Only Series']);
        $nrBook = Book::factory()->create(['needs_review' => true]);
        $nrBook->authors()->attach($author);
        $nrBook->series()->attach($nrSeries, ['series_number' => '1']);

        $response = $this->getJson('/api/v1/series?includeNeedsReview=1');
        $response->assertStatus(200);
        $data = $response->json();

        $seriesNames = array_column($data['series'], 'name');
        $this->assertContains('NR Only Series', $seriesNames);
    }
}
