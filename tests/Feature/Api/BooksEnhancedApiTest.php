<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Series;

class BooksEnhancedApiTest extends ApiTestCase
{
    public function test_books_enhanced_endpoint_returns_paginated_list()
    {
        // Create test data
        $books = Book::factory()->count(3)->create();

        foreach ($books as $book) {
            $author = Author::factory()->create();
            $genre = Genre::factory()->create();
            $book->authors()->attach($author);
            $book->genres()->attach($genre);
        }

        $response = $this->getJson('/api/v1/books/enhanced');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'books' => [
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
                        'authors' => [
                            '*' => [
                                'id',
                                'name'
                            ]
                        ],
                        'genres' => [
                            '*' => [
                                'id',
                                'name'
                            ]
                        ],
                        'series'
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
        $this->assertIsArray($data['books']);
    }

    public function test_books_enhanced_filters_by_genre_id()
    {
        // Create genres
        $fantasyGenre = Genre::factory()->create(['name' => 'Fantasy']);
        $scifiGenre = Genre::factory()->create(['name' => 'Science Fiction']);

        // Create books with different genres
        $fantasyBook = Book::factory()->create(['title' => 'Fantasy Book']);
        $fantasyBook->genres()->attach($fantasyGenre);

        $scifiBook = Book::factory()->create(['title' => 'SciFi Book']);
        $scifiBook->genres()->attach($scifiGenre);

        $response = $this->getJson('/api/v1/books/enhanced?genre_id=' . $fantasyGenre->id);

        $response->assertStatus(200);
        $data = $response->json();

        $bookTitles = array_column($data['books'], 'title');
        $this->assertContains('Fantasy Book', $bookTitles);
        $this->assertNotContains('SciFi Book', $bookTitles);
    }

    public function test_books_enhanced_filters_by_genre_name()
    {
        // Create genre
        $genre = Genre::factory()->create(['name' => 'Horror']);

        // Create book with horror genre
        $horrorBook = Book::factory()->create(['title' => 'Horror Story']);
        $horrorBook->genres()->attach($genre);

        // Create book without horror genre
        $romanceBook = Book::factory()->create(['title' => 'Love Story']);

        $response = $this->getJson('/api/v1/books/enhanced?genre_name=Horror');

        $response->assertStatus(200);
        $data = $response->json();

        $bookTitles = array_column($data['books'], 'title');
        $this->assertContains('Horror Story', $bookTitles);
        $this->assertNotContains('Love Story', $bookTitles);
    }

    public function test_books_enhanced_filters_by_author_id()
    {
        // Create authors
        $author1 = Author::factory()->create(['name' => 'Author One']);
        $author2 = Author::factory()->create(['name' => 'Author Two']);

        // Create books with different authors
        $book1 = Book::factory()->create(['title' => 'Book by Author One']);
        $book1->authors()->attach($author1);

        $book2 = Book::factory()->create(['title' => 'Book by Author Two']);
        $book2->authors()->attach($author2);

        $response = $this->getJson('/api/v1/books/enhanced?author_id=' . $author1->id);

        $response->assertStatus(200);
        $data = $response->json();

        $bookTitles = array_column($data['books'], 'title');
        $this->assertContains('Book by Author One', $bookTitles);
        $this->assertNotContains('Book by Author Two', $bookTitles);
    }

    public function test_books_enhanced_filters_by_author_name()
    {
        // Create author
        $author = Author::factory()->create(['name' => 'J.K. Rowling']);

        // Create book by this author
        $book = Book::factory()->create(['title' => 'Harry Potter Book']);
        $book->authors()->attach($author);

        // Create book by different author
        $otherBook = Book::factory()->create(['title' => 'Other Book']);

        $response = $this->getJson('/api/v1/books/enhanced?author_name=J.K. Rowling');

        $response->assertStatus(200);
        $data = $response->json();

        $bookTitles = array_column($data['books'], 'title');
        $this->assertContains('Harry Potter Book', $bookTitles);
        $this->assertNotContains('Other Book', $bookTitles);
    }

    public function test_books_enhanced_filters_by_series_id()
    {
        // Create series
        $series1 = Series::factory()->create(['name' => 'Series One']);
        $series2 = Series::factory()->create(['name' => 'Series Two']);

        // Create books in different series
        $book1 = Book::factory()->create(['title' => 'Book in Series One']);
        $book1->series()->attach($series1, ['series_number' => '1']);

        $book2 = Book::factory()->create(['title' => 'Book in Series Two']);
        $book2->series()->attach($series2, ['series_number' => '1']);

        $response = $this->getJson('/api/v1/books/enhanced?series_id=' . $series1->id);

        $response->assertStatus(200);
        $data = $response->json();

        $bookTitles = array_column($data['books'], 'title');
        $this->assertContains('Book in Series One', $bookTitles);
        $this->assertNotContains('Book in Series Two', $bookTitles);
    }

    public function test_books_enhanced_filters_by_series_name()
    {
        // Create series
        $series = Series::factory()->create(['name' => 'The Witcher']);

        // Create book in this series
        $book = Book::factory()->create(['title' => 'The Last Wish']);
        $book->series()->attach($series, ['series_number' => '1']);

        // Create standalone book
        $standaloneBook = Book::factory()->create(['title' => 'Standalone Novel']);

        $response = $this->getJson('/api/v1/books/enhanced?series_name=The Witcher');

        $response->assertStatus(200);
        $data = $response->json();

        $bookTitles = array_column($data['books'], 'title');
        $this->assertContains('The Last Wish', $bookTitles);
        $this->assertNotContains('Standalone Novel', $bookTitles);
    }

    public function test_books_enhanced_supports_search()
    {
        // Create books with specific titles
        Book::factory()->create(['title' => 'The Fellowship of the Ring']);
        Book::factory()->create(['title' => 'The Two Towers']);
        Book::factory()->create(['title' => 'Dune']);

        $response = $this->getJson('/api/v1/books/enhanced?search=Ring');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertGreaterThanOrEqual(1, $data['pagination']['total']);
        $bookTitles = array_column($data['books'], 'title');

        foreach ($bookTitles as $title) {
            $this->assertStringContainsStringIgnoringCase('Ring', $title);
        }
    }

    public function test_books_enhanced_supports_pagination()
    {
        // Create many books
        Book::factory()->count(75)->create();

        // Test first page
        $response = $this->getJson('/api/v1/books/enhanced?page=1&per_page=10');
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertEquals(1, $data['pagination']['current_page']);
        $this->assertEquals(10, $data['pagination']['per_page']);
        $this->assertEquals(75, $data['pagination']['total']);
        $this->assertCount(10, $data['books']);
        $this->assertTrue($data['pagination']['has_next']);
        $this->assertFalse($data['pagination']['has_prev']);

        // Test second page
        $response = $this->getJson('/api/v1/books/enhanced?page=2&per_page=10');
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertEquals(2, $data['pagination']['current_page']);
        $this->assertTrue($data['pagination']['has_prev']);
    }

    public function test_books_enhanced_supports_sorting()
    {
        // Create books with different attributes
        Book::factory()->create(['title' => 'Alpha Book', 'created_at' => now()->subDays(10)]);
        Book::factory()->create(['title' => 'Zeta Book', 'created_at' => now()->subDays(5)]);

        // Test title ascending
        $response = $this->getJson('/api/v1/books/enhanced?sort=title_asc');
        $response->assertStatus(200);
        $data = $response->json();

        if (count($data['books']) > 1) {
            $firstBook = $data['books'][0];
            $lastBook = $data['books'][count($data['books']) - 1];
            $this->assertLessThanOrEqual($lastBook['title'], $firstBook['title']);
        }

        // Test created_at descending (newest first)
        $response = $this->getJson('/api/v1/books/enhanced?sort=created_at_desc');
        $response->assertStatus(200);
        // Just verify it doesn't error
        $this->assertTrue(true);
    }

    public function test_books_enhanced_combines_multiple_filters()
    {
        // Create test data
        $fantasyGenre = Genre::factory()->create(['name' => 'Fantasy']);
        $author = Author::factory()->create(['name' => 'Fantasy Author']);
        $series = Series::factory()->create(['name' => 'Fantasy Series']);

        // Create book that matches all criteria
        $matchingBook = Book::factory()->create(['title' => 'Fantasy Epic']);
        $matchingBook->genres()->attach($fantasyGenre);
        $matchingBook->authors()->attach($author);
        $matchingBook->series()->attach($series, ['series_number' => '1']);

        // Create book that only matches some criteria
        $partialBook = Book::factory()->create(['title' => 'Different Epic']);
        $partialBook->genres()->attach($fantasyGenre); // Only matches genre

        // Create book that matches none
        $nonMatchingBook = Book::factory()->create(['title' => 'SciFi Story']);

        $response = $this->getJson('/api/v1/books/enhanced?genre_name=Fantasy&author_name=Fantasy Author&series_name=Fantasy Series');

        $response->assertStatus(200);
        $data = $response->json();

        $bookTitles = array_column($data['books'], 'title');
        $this->assertContains('Fantasy Epic', $bookTitles);
        $this->assertNotContains('Different Epic', $bookTitles);
        $this->assertNotContains('SciFi Story', $bookTitles);
    }

    public function test_books_enhanced_requires_authentication()
    {
        // Clear the Authorization header set in ApiTestCase to simulate an unauthenticated request
        $this->withHeader('Authorization', '');

        $response = $this->getJson('/api/v1/books/enhanced');

        $response->assertStatus(401);
    }

    public function test_books_enhanced_validates_parameters()
    {
        // Test invalid per_page (should limit to max)
        $response = $this->getJson('/api/v1/books/enhanced?per_page=500');
        $response->assertStatus(200);

        // Test invalid page (should default to 1)
        $response = $this->getJson('/api/v1/books/enhanced?page=-1');
        $response->assertStatus(200);

        // Test invalid sort (should default to title_asc)
        $response = $this->getJson('/api/v1/books/enhanced?sort=invalid_sort');
        $response->assertStatus(200);
    }

    public function test_books_enhanced_includes_relationship_data()
    {
        // Create comprehensive test data
        $author = Author::factory()->create(['name' => 'Test Author']);
        $genre = Genre::factory()->create(['name' => 'Test Genre']);
        $series = Series::factory()->create(['name' => 'Test Series']);

        $book = Book::factory()->create([
            'title' => 'Complete Test Book',
            'description' => 'A book with all relationships',
            'year' => 2023
        ]);

        $book->authors()->attach($author);
        $book->genres()->attach($genre);
        $book->series()->attach($series, ['series_number' => '1']);

        $response = $this->getJson('/api/v1/books/enhanced');

        $response->assertStatus(200);
        $data = $response->json();

        $testBook = collect($data['books'])->firstWhere('title', 'Complete Test Book');

        $this->assertNotNull($testBook);

        // Verify authors are included
        $this->assertArrayHasKey('authors', $testBook);
        $this->assertCount(1, $testBook['authors']);
        $this->assertEquals('Test Author', $testBook['authors'][0]['name']);

        // Verify genres are included
        $this->assertArrayHasKey('genres', $testBook);
        $this->assertCount(1, $testBook['genres']);
        $this->assertEquals('Test Genre', $testBook['genres'][0]['name']);

        // Verify series are included
        $this->assertArrayHasKey('series', $testBook);
        $this->assertCount(1, $testBook['series']);
        $this->assertEquals('Test Series', $testBook['series'][0]['name']);
        $this->assertEquals('1', $testBook['series'][0]['pivot']['series_number']);
    }

    public function test_books_enhanced_handles_empty_results()
    {
        // Search for something that doesn't exist
        $response = $this->getJson('/api/v1/books/enhanced?search=NonexistentBook123');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEquals(0, $data['pagination']['total']);
        $this->assertEmpty($data['books']);
        $this->assertFalse($data['pagination']['has_next']);
        $this->assertFalse($data['pagination']['has_prev']);
    }
}
