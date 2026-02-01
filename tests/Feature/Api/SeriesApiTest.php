<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Author;
use App\Models\Book;
use App\Models\Series;

class SeriesApiTest extends ApiTestCase
{
    public function test_series_endpoint_returns_paginated_list()
    {
        // Create test series with books and authors
        /** @var \Illuminate\Database\Eloquent\Collection<int, Series> $series */
        $series = Series::factory()->count(3)->create();

        foreach ($series as $s) {
            $author = Author::factory()->create();
            $book = Book::factory()->create();
            $book->authors()->attach($author);
            $book->series()->attach($s, ['series_number' => '1']);
        }

        $response = $this->getJson('/api/v1/series');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'series' => [
                    '*' => [
                        'id',
                        'name',
                        'description',
                        'book_count',
                        'book_count_by_author',
                        'authors'
                    ]
                ],
                'pagination' => [
                    'current_page',
                    'per_page',
                    'total',
                    'total_pages',
                    'has_next',
                    'has_prev'
                ]
            ]);

        $data = $response->json();
        $this->assertGreaterThanOrEqual(3, $data['pagination']['total']);
        $this->assertIsArray($data['series']);
    }

    public function test_series_endpoint_filters_by_author_id()
    {
        // Create authors
        $author1 = Author::factory()->create(['name' => 'Brandon Sanderson']);
        $author2 = Author::factory()->create(['name' => 'Stephen King']);

        // Create series
        /** @var Series $series1 */
        $series1 = Series::factory()->create(['name' => 'The Stormlight Archive']);
        /** @var Series $series2 */
        $series2 = Series::factory()->create(['name' => 'The Dark Tower']);
        /** @var Series $series3 */
        $series3 = Series::factory()->create(['name' => 'Mistborn']); // Also by Sanderson

        // Create books and relationships
        // Author 1 has books in series 1 and 3
        $book1 = Book::factory()->create();
        $book1->authors()->attach($author1);
        $book1->series()->attach($series1, ['series_number' => '1']);

        $book3 = Book::factory()->create();
        $book3->authors()->attach($author1);
        $book3->series()->attach($series3, ['series_number' => '1']);

        // Author 2 has books in series 2
        $book2 = Book::factory()->create();
        $book2->authors()->attach($author2);
        $book2->series()->attach($series2, ['series_number' => '1']);

        // Test filtering by author1
        /** @var \Illuminate\Database\Eloquent\Model $author1 */
        $response = $this->getJson('/api/v1/series?author_id=' . $author1->id);

        $response->assertStatus(200);
        $data = $response->json();

        $seriesNames = array_column($data['series'], 'name');
        $this->assertContains('The Stormlight Archive', $seriesNames);
        $this->assertContains('Mistborn', $seriesNames);
        $this->assertNotContains('The Dark Tower', $seriesNames);
    }

    public function test_series_endpoint_filters_by_author_name()
    {
        // Create author
        $author = Author::factory()->create(['name' => 'Isaac Asimov']);

        // Create series with books by this author
        /** @var Series $series */
        $series = Series::factory()->create(['name' => 'Foundation']);
        $book = Book::factory()->create();
        $book->authors()->attach($author);
        $book->series()->attach($series, ['series_number' => '1']);

        // Create series without books by this author
        $otherSeries = Series::factory()->create(['name' => 'Other Series']);
        $otherBook = Book::factory()->create();
        $otherBook->series()->attach($otherSeries, ['series_number' => '1']);

        $response = $this->getJson('/api/v1/series?author_name=Isaac Asimov');

        $response->assertStatus(200);
        $data = $response->json();

        $seriesNames = array_column($data['series'], 'name');
        $this->assertContains('Foundation', $seriesNames);
        $this->assertNotContains('Other Series', $seriesNames);
    }

    public function test_series_endpoint_supports_pagination()
    {
        // Create many series with books
        /** @var \Illuminate\Database\Eloquent\Collection<int, Series> $series */
        $series = Series::factory()->count(75)->create();
        foreach ($series as $s) {
            $author = Author::factory()->create();
            $book = Book::factory()->create();
            $book->authors()->attach($author);
            $book->series()->attach($s, ['series_number' => '1']);
        }

        // Test first page
        $response = $this->getJson('/api/v1/series?page=1&per_page=10');
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertEquals(1, $data['pagination']['current_page']);
        $this->assertEquals(10, $data['pagination']['per_page']);
        $this->assertEquals(75, $data['pagination']['total']);
        $this->assertCount(10, $data['series']);
        $this->assertTrue($data['pagination']['has_next']);
        $this->assertFalse($data['pagination']['has_prev']);

        // Test second page
        $response = $this->getJson('/api/v1/series?page=2&per_page=10');
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertEquals(2, $data['pagination']['current_page']);
        $this->assertTrue($data['pagination']['has_prev']);
    }

    public function test_series_endpoint_supports_search()
    {
        // Create series with specific names
        /** @var Series $series1 */
        $series1 = Series::factory()->create(['name' => 'Harry Potter']);
        /** @var Series $series2 */
        $series2 = Series::factory()->create(['name' => 'Lord of the Rings']);

        // Add books to make them appear in results
        foreach ([$series1, $series2] as $series) {
            $author = Author::factory()->create();
            $book = Book::factory()->create();
            $book->authors()->attach($author);
            $book->series()->attach($series, ['series_number' => '1']);
        }

        $response = $this->getJson('/api/v1/series?search=Harry');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertGreaterThanOrEqual(1, $data['pagination']['total']);
        $seriesNames = array_column($data['series'], 'name');

        foreach ($seriesNames as $name) {
            $this->assertStringContainsStringIgnoringCase('Harry', $name);
        }
    }

    public function test_series_endpoint_supports_sorting()
    {
        // Create series with different names and book counts
        /** @var Series $seriesA */
        $seriesA = Series::factory()->create(['name' => 'Alpha Series']);
        /** @var Series $seriesZ */
        $seriesZ = Series::factory()->create(['name' => 'Zeta Series']);

        $author = Author::factory()->create();

        // Give Alpha Series more books
        for ($i = 1; $i <= 5; $i++) {
            $book = Book::factory()->create();
            $book->authors()->attach($author);
            $book->series()->attach($seriesA, ['series_number' => (string)$i]);
        }

        // Give Zeta Series fewer books
        for ($i = 1; $i <= 2; $i++) {
            $book = Book::factory()->create();
            $book->authors()->attach($author);
            $book->series()->attach($seriesZ, ['series_number' => (string)$i]);
        }

        // Test name ascending
        $response = $this->getJson('/api/v1/series?sort=name_asc');
        $response->assertStatus(200);
        $data = $response->json();

        if (count($data['series']) > 1) {
            $firstSeries = $data['series'][0];
            $lastSeries = $data['series'][count($data['series']) - 1];
            $this->assertLessThanOrEqual($lastSeries['name'], $firstSeries['name']);
        }

        // Test book count descending
        $response = $this->getJson('/api/v1/series?sort=book_count_desc');
        $response->assertStatus(200);
        $data = $response->json();

        // Find our test series in the results
        $alphaSeries = collect($data['series'])->firstWhere('name', 'Alpha Series');
        $zetaSeries = collect($data['series'])->firstWhere('name', 'Zeta Series');

        if ($alphaSeries && $zetaSeries) {
            $this->assertGreaterThan($zetaSeries['book_count'], $alphaSeries['book_count']);
        }
    }

    public function test_series_endpoint_requires_authentication()
    {
        // Clear the Authorization header set in ApiTestCase to simulate unauthenticated request
        $this->withHeader('Authorization', '');

        $response = $this->getJson('/api/v1/series');

        $response->assertStatus(401);
    }

    public function test_series_endpoint_validates_parameters()
    {
        // Test invalid per_page (should limit to max)
        $response = $this->getJson('/api/v1/series?per_page=500');
        $response->assertStatus(200);

        // Test invalid page (should default to 1)
        $response = $this->getJson('/api/v1/series?page=-1');
        $response->assertStatus(200);

        // Test invalid sort (should default to name_asc)
        $response = $this->getJson('/api/v1/series?sort=invalid_sort');
        $response->assertStatus(200);
    }

    public function test_series_response_includes_author_information()
    {
        // Create authors
        $author1 = Author::factory()->create(['name' => 'Author One']);
        $author2 = Author::factory()->create(['name' => 'Author Two']);

        // Create series
        /** @var Series $series */
        $series = Series::factory()->create(['name' => 'Multi-Author Series']);

        // Create books by both authors in the same series
        $book1 = Book::factory()->create();
        $book1->authors()->attach($author1);
        $book1->series()->attach($series, ['series_number' => '1']);

        $book2 = Book::factory()->create();
        $book2->authors()->attach($author1);
        $book2->series()->attach($series, ['series_number' => '2']);

        $book3 = Book::factory()->create();
        $book3->authors()->attach($author2);
        $book3->series()->attach($series, ['series_number' => '3']);

        $response = $this->getJson('/api/v1/series');

        $response->assertStatus(200);
        $data = $response->json();

        $testSeries = collect($data['series'])->firstWhere('name', 'Multi-Author Series');

        $this->assertNotNull($testSeries);
        $this->assertEquals(3, $testSeries['book_count']);
        $this->assertCount(2, $testSeries['authors']); // Two different authors

        // Check author information
        $this->assertContains('Author One', $testSeries['authors']);
        $this->assertContains('Author Two', $testSeries['authors']);
    }

    public function test_series_filtered_by_author_shows_correct_book_counts()
    {
        // Create authors
        $targetAuthor = Author::factory()->create(['name' => 'Target Author']);
        $otherAuthor = Author::factory()->create(['name' => 'Other Author']);

        // Create series
        /** @var Series $series */
        $series = Series::factory()->create(['name' => 'Mixed Series']);

        // Target author has 2 books in the series
        for ($i = 1; $i <= 2; $i++) {
            $book = Book::factory()->create();
            $book->authors()->attach($targetAuthor);
            $book->series()->attach($series, ['series_number' => (string)$i]);
        }

        // Other author has 3 books in the series
        for ($i = 3; $i <= 5; $i++) {
            $book = Book::factory()->create();
            $book->authors()->attach($otherAuthor);
            $book->series()->attach($series, ['series_number' => (string)$i]);
        }

        // Filter by target author
        /** @var \Illuminate\Database\Eloquent\Model $targetAuthor */
        $response = $this->getJson('/api/v1/series?author_id=' . $targetAuthor->id);

        $response->assertStatus(200);
        $data = $response->json();

        $testSeries = collect($data['series'])->firstWhere('name', 'Mixed Series');

        $this->assertNotNull($testSeries);
        $this->assertEquals(5, $testSeries['book_count']); // Total books in series
        $this->assertEquals(2, $testSeries['book_count_by_author']); // Books by filtered author
    }

    public function test_toggle_series_favorite()
    {
        $user = $this->user;
        /** @var Series $series */
        $series = Series::factory()->create();
        $author = Author::factory()->create();
        $book = Book::factory()->create();
        $book->authors()->attach($author);
        $book->series()->attach($series, ['series_number' => '1']);

        // Mark as favorite
        $response = $this->postJson("/api/v1/series/{$series->id}/favorite", ['is_favorite' => true]);
        $response->assertStatus(200)
            ->assertJson([
                'id' => $series->id,
                'name' => $series->name,
                'isFavorite' => true,
            ]);

        $this->assertTrue($user->favoritedSeries()->where('series_id', $series->id)->exists());

        // Unmark as favorite
        $response = $this->postJson("/api/v1/series/{$series->id}/favorite", ['is_favorite' => false]);
        $response->assertStatus(200)
            ->assertJson([
                'id' => $series->id,
                'name' => $series->name,
                'isFavorite' => false,
            ]);

        $this->assertFalse($user->favoritedSeries()->where('series_id', $series->id)->exists());
    }

    public function test_series_endpoint_filters_by_favorites()
    {
        $user = $this->user;
        /** @var Series $favoritedSeries */
        $favoritedSeries = Series::factory()->create();
        /** @var Series $nonFavoritedSeries */
        $nonFavoritedSeries = Series::factory()->create();

        // Attach books to series so they appear in results
        $author = Author::factory()->create();
        $book1 = Book::factory()->create();
        $book1->authors()->attach($author);
        $book1->series()->attach($favoritedSeries, ['series_number' => '1']);
        $book2 = Book::factory()->create();
        $book2->authors()->attach($author);
        $book2->series()->attach($nonFavoritedSeries, ['series_number' => '1']);

        $user->favoritedSeries()->attach($favoritedSeries);

        $response = $this->getJson('/api/v1/series?favorites=true');

        $response->assertStatus(200)
            ->assertJsonFragment([
                'id' => $favoritedSeries->id,
                'name' => $favoritedSeries->name,
                'isFavorite' => true,
            ]);

        $seriesInResponse = collect($response->json('series'));
        $this->assertCount(1, $seriesInResponse);
        $this->assertEquals($favoritedSeries->id, $seriesInResponse->first()['id']);

        // Test that is_favorite is true when requesting all series
        $responseAll = $this->getJson('/api/v1/series');
        $responseAll->assertStatus(200);
        $seriesAll = collect($responseAll->json('series'));

        $this->assertTrue($seriesAll->firstWhere('id', $favoritedSeries->id)['isFavorite']);
        $this->assertFalse($seriesAll->firstWhere('id', $nonFavoritedSeries->id)['isFavorite']);
    }
}
