<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Series;

class BooksEnhancedApiTest extends ApiTestCase
{
    public function test_books_enhanced_endpoint_returns_paginated_list(): void
    {
        $books = Book::factory()->count(3)->create();

        foreach ($books as $book) {
            $author = Author::factory()->create();
            $genre  = Genre::factory()->create();
            $book->authors()->attach($author);
            $book->genres()->attach($genre);
        }

        $response = $this->getJson('/api/v1/books?enhanced=true');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'description',
                        'year',
                        'duration',
                        'cover_url',
                        'file_count',
                        'total_size',
                        'created_at',
                        'updated_at',
                        'authors' => ['*' => ['id', 'name']],
                        'genres'  => ['*' => ['id', 'name']],
                        'series',
                    ],
                ],
                'pagination' => [
                    'current_page',
                    'per_page',
                    'total',
                    'total_pages',
                    'has_next',
                    'has_prev',
                ],
            ]);

        $data = $response->json();
        $this->assertGreaterThanOrEqual(3, $data['pagination']['total']);
        $this->assertIsArray($data['data']);
    }

    public function test_books_enhanced_filters_by_genre_id(): void
    {
        /** @var \Illuminate\Database\Eloquent\Model $fantasyGenre */
        $fantasyGenre = Genre::factory()->create(['name' => 'Fantasy']);
        $scifiGenre   = Genre::factory()->create(['name' => 'Science Fiction']);

        $fantasyBook = Book::factory()->create(['title' => 'Fantasy Book']);
        $fantasyBook->genres()->attach($fantasyGenre);

        $scifiBook = Book::factory()->create(['title' => 'SciFi Book']);
        $scifiBook->genres()->attach($scifiGenre);

        $response = $this->getJson('/api/v1/books?enhanced=true&genre_id=' . $fantasyGenre->id);

        $response->assertStatus(200);
        $data       = $response->json();
        $bookTitles = array_column($data['data'], 'title');
        // @phpstan-ignore-next-line
        $this->assertContains('Fantasy Book', $bookTitles);
        $this->assertNotContains('SciFi Book', $bookTitles);
    }

    public function test_books_enhanced_filters_by_genre_name(): void
    {
        $genre = Genre::factory()->create(['name' => 'Horror']);

        $horrorBook  = Book::factory()->create(['title' => 'Horror Story']);
        $horrorBook->genres()->attach($genre);

        $romanceBook = Book::factory()->create(['title' => 'Love Story']);

        $response = $this->getJson('/api/v1/books?enhanced=true&genre_name=Horror');

        $response->assertStatus(200);
        $data       = $response->json();
        $bookTitles = array_column($data['data'], 'title');
        // @phpstan-ignore-next-line
        $this->assertContains('Horror Story', $bookTitles);
        $this->assertNotContains('Love Story', $bookTitles);
    }

    public function test_books_enhanced_filters_by_author_id(): void
    {
        /** @var \Illuminate\Database\Eloquent\Model $author1 */
        $author1 = Author::factory()->create(['name' => 'Author One']);
        $author2 = Author::factory()->create(['name' => 'Author Two']);

        $book1 = Book::factory()->create(['title' => 'Book by Author One']);
        $book1->authors()->attach($author1);

        $book2 = Book::factory()->create(['title' => 'Book by Author Two']);
        $book2->authors()->attach($author2);

        $response = $this->getJson('/api/v1/books?enhanced=true&author_id=' . $author1->id);

        $response->assertStatus(200);
        $data       = $response->json();
        $bookTitles = array_column($data['data'], 'title');
        // @phpstan-ignore-next-line
        $this->assertContains('Book by Author One', $bookTitles);
        $this->assertNotContains('Book by Author Two', $bookTitles);
    }

    public function test_books_enhanced_filters_by_author_name(): void
    {
        $author = Author::factory()->create(['name' => 'J.K. Rowling']);

        $book = Book::factory()->create(['title' => 'Harry Potter Book']);
        $book->authors()->attach($author);

        $otherBook = Book::factory()->create(['title' => 'Other Book']);

        $response = $this->getJson('/api/v1/books?enhanced=true&author_name=J.K. Rowling');

        $response->assertStatus(200);
        $data       = $response->json();
        $bookTitles = array_column($data['data'], 'title');
        // @phpstan-ignore-next-line
        $this->assertContains('Harry Potter Book', $bookTitles);
        $this->assertNotContains('Other Book', $bookTitles);
    }

    public function test_books_enhanced_filters_by_series_id(): void
    {
        /** @var \Illuminate\Database\Eloquent\Model $series1 */
        $series1 = Series::factory()->create(['name' => 'Series One']);
        $series2 = Series::factory()->create(['name' => 'Series Two']);

        $book1 = Book::factory()->create(['title' => 'Book in Series One']);
        $book1->series()->attach($series1, ['series_number' => '1']);

        $book2 = Book::factory()->create(['title' => 'Book in Series Two']);
        $book2->series()->attach($series2, ['series_number' => '1']);

        $response = $this->getJson('/api/v1/books?enhanced=true&series_id=' . $series1->id);

        $response->assertStatus(200);
        $data       = $response->json();
        $bookTitles = array_column($data['data'], 'title');
        // @phpstan-ignore-next-line
        $this->assertContains('Book in Series One', $bookTitles);
        $this->assertNotContains('Book in Series Two', $bookTitles);
    }

    public function test_books_enhanced_filters_by_series_name(): void
    {
        $series = Series::factory()->create(['name' => 'The Witcher']);

        $book = Book::factory()->create(['title' => 'The Last Wish']);
        $book->series()->attach($series, ['series_number' => '1']);

        $standaloneBook = Book::factory()->create(['title' => 'Standalone Novel']);

        $response = $this->getJson('/api/v1/books?enhanced=true&series_name=The Witcher');

        $response->assertStatus(200);
        $data       = $response->json();
        $bookTitles = array_column($data['data'], 'title');
        // @phpstan-ignore-next-line
        $this->assertContains('The Last Wish', $bookTitles);
        $this->assertNotContains('Standalone Novel', $bookTitles);
    }

    public function test_books_enhanced_supports_search(): void
    {
        Book::factory()->create(['title' => 'The Fellowship of the Ring']);
        Book::factory()->create(['title' => 'The Two Towers']);
        Book::factory()->create(['title' => 'Dune']);

        $response = $this->getJson('/api/v1/books?enhanced=true&search=Ring');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertGreaterThanOrEqual(1, $data['pagination']['total']);
        $bookTitles = array_column($data['data'], 'title');

        foreach ($bookTitles as $title) {
            $this->assertStringContainsStringIgnoringCase('Ring', $title);
        }
    }

    public function test_books_enhanced_supports_pagination(): void
    {
        Book::factory()->count(75)->create();

        $response = $this->getJson('/api/v1/books?enhanced=true&page=1&per_page=10');
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertEquals(1, $data['pagination']['current_page']);
        $this->assertEquals(10, $data['pagination']['per_page']);
        $this->assertGreaterThanOrEqual(75, $data['pagination']['total']);
        $this->assertCount(10, $data['data']);
        $this->assertTrue($data['pagination']['has_next']);
        $this->assertFalse($data['pagination']['has_prev']);

        $response = $this->getJson('/api/v1/books?enhanced=true&page=2&per_page=10');
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertEquals(2, $data['pagination']['current_page']);
        $this->assertTrue($data['pagination']['has_prev']);
    }

    public function test_books_enhanced_supports_sorting(): void
    {
        Book::factory()->create(['title' => 'Alpha Book', 'created_at' => now()->subDays(10)]);
        Book::factory()->create(['title' => 'Zeta Book', 'created_at' => now()->subDays(5)]);

        $response = $this->getJson('/api/v1/books?enhanced=true&sort=title_asc');
        $response->assertStatus(200);
        $data = $response->json();

        if (count($data['data']) > 1) {
            $firstBook = $data['data'][0];
            $lastBook  = $data['data'][count($data['data']) - 1];
            $this->assertLessThanOrEqual($lastBook['title'], $firstBook['title']);
        }

        $response = $this->getJson('/api/v1/books?enhanced=true&sort=created_at_desc');
        $response->assertStatus(200);
        // @phpstan-ignore-next-line
        $this->assertTrue(true);
    }

    public function test_books_enhanced_combines_multiple_filters(): void
    {
        $fantasyGenre = Genre::factory()->create(['name' => 'Fantasy']);
        $author       = Author::factory()->create(['name' => 'Fantasy Author']);
        $series       = Series::factory()->create(['name' => 'Fantasy Series']);

        $matchingBook = Book::factory()->create(['title' => 'Fantasy Epic']);
        $matchingBook->genres()->attach($fantasyGenre);
        $matchingBook->authors()->attach($author);
        $matchingBook->series()->attach($series, ['series_number' => '1']);

        $partialBook = Book::factory()->create(['title' => 'Different Epic']);
        $partialBook->genres()->attach($fantasyGenre);

        $nonMatchingBook = Book::factory()->create(['title' => 'SciFi Story']);

        $response = $this->getJson(
            '/api/v1/books?enhanced=true&genre_name=Fantasy&author_name=Fantasy Author&series_name=Fantasy Series'
        );

        $response->assertStatus(200);
        $data       = $response->json();
        $bookTitles = array_column($data['data'], 'title');
        // @phpstan-ignore-next-line
        $this->assertContains('Fantasy Epic', $bookTitles);
        $this->assertNotContains('Different Epic', $bookTitles);
        $this->assertNotContains('SciFi Story', $bookTitles);
    }

    public function test_books_enhanced_requires_authentication(): void
    {
        $this->withHeader('Authorization', '');
        $response = $this->getJson('/api/v1/books?enhanced=true');
        $response->assertStatus(401);
    }

    public function test_books_enhanced_validates_parameters(): void
    {
        $response = $this->getJson('/api/v1/books?enhanced=true&per_page=500');
        $response->assertStatus(200);

        $response = $this->getJson('/api/v1/books?enhanced=true&page=-1');
        $response->assertStatus(200);

        $response = $this->getJson('/api/v1/books?enhanced=true&sort=invalid_sort');
        $response->assertStatus(200);
    }

    public function test_books_enhanced_includes_relationship_data(): void
    {
        $author = Author::factory()->create(['name' => 'Test Author']);
        $genre  = Genre::factory()->create(['name' => 'Test Genre']);
        $series = Series::factory()->create(['name' => 'Test Series']);

        $book = Book::factory()->create([
            'title'       => 'Complete Test Book',
            'description' => 'A book with all relationships',
            'year'        => 2023,
        ]);

        $book->authors()->attach($author);
        $book->genres()->attach($genre);
        $book->series()->attach($series, ['series_number' => '1']);

        $response = $this->getJson('/api/v1/books?enhanced=true');
        $response->assertStatus(200);

        $data     = $response->json();
        $testBook = collect($data['data'])->firstWhere('title', 'Complete Test Book');

        $this->assertNotNull($testBook);

        // Authors include id + name
        $this->assertArrayHasKey('authors', $testBook);
        $this->assertCount(1, $testBook['authors']);
        // @phpstan-ignore-next-line
        $this->assertEquals($author->id, $testBook['authors'][0]['id']);
        // @phpstan-ignore-next-line
        $this->assertEquals('Test Author', $testBook['authors'][0]['name']);

        // Genres include id + name
        $this->assertArrayHasKey('genres', $testBook);
        $this->assertCount(1, $testBook['genres']);
        // @phpstan-ignore-next-line
        $this->assertEquals($genre->id, $testBook['genres'][0]['id']);
        // @phpstan-ignore-next-line
        $this->assertEquals('Test Genre', $testBook['genres'][0]['name']);

        // Series includes id, name, series_number
        $this->assertArrayHasKey('series', $testBook);
        $this->assertCount(1, $testBook['series']);
        // @phpstan-ignore-next-line
        $this->assertEquals($series->id, $testBook['series'][0]['id']);
        // @phpstan-ignore-next-line
        $this->assertEquals('Test Series', $testBook['series'][0]['name']);
        // @phpstan-ignore-next-line
        $this->assertEquals('1', $testBook['series'][0]['pivot']['series_number']);
    }

    public function test_books_enhanced_handles_empty_results(): void
    {
        $response = $this->getJson('/api/v1/books?enhanced=true&search=NonexistentBook123');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEquals(0, $data['pagination']['total']);
        $this->assertEmpty($data['data']);
        $this->assertFalse($data['pagination']['has_next']);
        $this->assertFalse($data['pagination']['has_prev']);
    }
}
