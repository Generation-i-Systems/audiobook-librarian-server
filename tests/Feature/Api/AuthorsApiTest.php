<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;

class AuthorsApiTest extends ApiTestCase
{
    public function test_authors_endpoint_returns_paginated_list()
    {
        // Create test data
        /** @var \Illuminate\Database\Eloquent\Collection<int, Author> $authors */
        $authors = Author::factory()->count(3)->create();

        // Make each author have at least one book
        foreach ($authors as $author) {
            $book = Book::factory()->create();
            $book->authors()->attach($author);
        }

        $response = $this->getJson('/api/v1/authors');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'authors' => [
                    '*' => [
                        'id',
                        'name',
                        'biography',
                        'book_count',
                        'image_url',
                        'genres',
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
        $this->assertIsArray($data['authors']);
    }

    public function test_authors_endpoint_filters_by_genre_id()
    {
        // Create genres
        $fantasyGenre = Genre::factory()->create(['name' => 'Fantasy']);
        $scifiGenre = Genre::factory()->create(['name' => 'Science Fiction']);

        // Create authors
        /** @var Author $fantasyAuthor */
        $fantasyAuthor = Author::factory()->create(['name' => 'Fantasy Author']);
        /** @var Author $scifiAuthor */
        $scifiAuthor = Author::factory()->create(['name' => 'SciFi Author']);
        /** @var Author $bothAuthor */
        $bothAuthor = Author::factory()->create(['name' => 'Both Genres Author']);

        // Create books and attach genres/authors
        $fantasyBook = Book::factory()->create();
        $fantasyBook->authors()->attach($fantasyAuthor);
        $fantasyBook->genres()->attach($fantasyGenre);

        $scifiBook = Book::factory()->create();
        $scifiBook->authors()->attach($scifiAuthor);
        $scifiBook->genres()->attach($scifiGenre);

        $bothBook1 = Book::factory()->create();
        $bothBook1->authors()->attach($bothAuthor);
        $bothBook1->genres()->attach($fantasyGenre);

        $bothBook2 = Book::factory()->create();
        $bothBook2->authors()->attach($bothAuthor);
        $bothBook2->genres()->attach($scifiGenre);

        // Test filtering by fantasy genre
        $response = $this->getJson('/api/v1/authors?genre_id=' . $fantasyGenre->id);

        $response->assertStatus(200);
        $data = $response->json();

        $authorNames = array_column($data['authors'], 'name');
        $this->assertContains('Fantasy Author', $authorNames);
        $this->assertContains('Both Genres Author', $authorNames);
        $this->assertNotContains('SciFi Author', $authorNames);
    }

    public function test_authors_endpoint_filters_by_genre_name()
    {
        // Create genre
        $genre = Genre::factory()->create(['name' => 'Mystery']);

        // Create author with book in mystery genre
        /** @var Author $author */
        $author = Author::factory()->create(['name' => 'Mystery Author']);
        $book = Book::factory()->create();
        $book->authors()->attach($author);
        $book->genres()->attach($genre);

        // Create author without mystery books
        $otherAuthor = Author::factory()->create(['name' => 'Romance Author']);
        $otherBook = Book::factory()->create();
        $otherBook->authors()->attach($otherAuthor);

        $response = $this->getJson('/api/v1/authors?genre_name=Mystery');

        $response->assertStatus(200);
        $data = $response->json();

        $authorNames = array_column($data['authors'], 'name');
        $this->assertContains('Mystery Author', $authorNames);
        $this->assertNotContains('Romance Author', $authorNames);
    }

    public function test_authors_endpoint_supports_pagination()
    {
        // Create many authors with books
        /** @var \Illuminate\Database\Eloquent\Collection<int, Author> $authors */
        $authors = Author::factory()
            ->count(75)
            ->sequence(fn (\Illuminate\Database\Eloquent\Factories\Sequence $sequence) => [
                'name' => 'Pagination Author ' . $sequence->index,
            ])
            ->create();
        foreach ($authors as $author) {
            $book = Book::factory()->create();
            $book->authors()->attach($author);
        }

        // Test first page
        $response = $this->getJson('/api/v1/authors?page=1&per_page=10');
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertEquals(1, $data['pagination']['current_page']);
        $this->assertEquals(10, $data['pagination']['per_page']);
        $this->assertEquals(75, $data['pagination']['total']);
        $this->assertCount(10, $data['authors']);
        $this->assertTrue($data['pagination']['has_next']);
        $this->assertFalse($data['pagination']['has_prev']);

        // Test second page
        $response = $this->getJson('/api/v1/authors?page=2&per_page=10');
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertEquals(2, $data['pagination']['current_page']);
        $this->assertTrue($data['pagination']['has_prev']);
    }

    public function test_authors_endpoint_supports_search()
    {
        // Create authors with specific names
        Author::factory()->create(['name' => 'Brandon Sanderson'])->each(function ($author) {
            $book = Book::factory()->create();
            $book->authors()->attach($author);
        });

        Author::factory()->create(['name' => 'Stephen King'])->each(function ($author) {
            $book = Book::factory()->create();
            $book->authors()->attach($author);
        });

        $response = $this->getJson('/api/v1/authors?search=Brandon');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertGreaterThanOrEqual(1, $data['pagination']['total']);
        $authorNames = array_column($data['authors'], 'name');

        foreach ($authorNames as $name) {
            $this->assertStringContainsStringIgnoringCase('Brandon', $name);
        }
    }

    public function test_authors_endpoint_supports_sorting()
    {
        // Create authors with different names and book counts
        /** @var Author $authorA */
        $authorA = Author::factory()->create(['name' => 'Alpha Author']);
        /** @var Author $authorZ */
        $authorZ = Author::factory()->create(['name' => 'Zeta Author']);

        // Give Alpha Author more books
        for ($i = 0; $i < 5; $i++) {
            $book = Book::factory()->create();
            $book->authors()->attach($authorA);
        }

        // Give Zeta Author fewer books
        for ($i = 0; $i < 2; $i++) {
            $book = Book::factory()->create();
            $book->authors()->attach($authorZ);
        }

        // Test name ascending
        $response = $this->getJson('/api/v1/authors?sort=name_asc');
        $response->assertStatus(200);
        $data = $response->json();

        $firstAuthor = $data['authors'][0] ?? null;
        $lastAuthor = $data['authors'][count($data['authors']) - 1] ?? null;

        if ($firstAuthor && $lastAuthor && count($data['authors']) > 1) {
            $this->assertLessThanOrEqual($lastAuthor['name'], $firstAuthor['name']);
        }

        // Test book count descending
        $response = $this->getJson('/api/v1/authors?sort=book_count_desc');
        $response->assertStatus(200);
        $data = $response->json();

        // Find our test authors in the results
        $alphaAuthor = collect($data['authors'])->firstWhere('name', 'Alpha Author');
        $zetaAuthor = collect($data['authors'])->firstWhere('name', 'Zeta Author');

        if ($alphaAuthor && $zetaAuthor) {
            $this->assertGreaterThan($zetaAuthor['book_count'], $alphaAuthor['book_count']);
        }
    }

    public function test_authors_endpoint_requires_authentication()
    {
        // Clear the Authorization header set in ApiTestCase to simulate unauthenticated request
        $this->withHeader('Authorization', '');

        $response = $this->getJson('/api/v1/authors');

        $response->assertStatus(401);
    }

    public function test_authors_endpoint_validates_parameters()
    {
        // Test invalid per_page
        $response = $this->getJson('/api/v1/authors?per_page=500');
        $response->assertStatus(200); // Should still work but limit to max

        // Test invalid page
        $response = $this->getJson('/api/v1/authors?page=-1');
        $response->assertStatus(200); // Should default to page 1

        // Test invalid sort
        $response = $this->getJson('/api/v1/authors?sort=invalid_sort');
        $response->assertStatus(200); // Should default to name_asc
    }

    public function test_authors_response_includes_book_count_in_genre()
    {
        // Create genre
        $genre = Genre::factory()->create(['name' => 'Fantasy']);

        // Create author with multiple books, some in fantasy
        /** @var Author $author */
        $author = Author::factory()->create();

        // Create 3 fantasy books
        for ($i = 0; $i < 3; $i++) {
            $book = Book::factory()->create();
            $book->authors()->attach($author);
            $book->genres()->attach($genre);
        }

        // Create 2 non-fantasy books
        for ($i = 0; $i < 2; $i++) {
            $book = Book::factory()->create();
            $book->authors()->attach($author);
        }

        $response = $this->getJson('/api/v1/authors?genre_id=' . $genre->id);

        $response->assertStatus(200);
        $data = $response->json();

        $testAuthor = collect($data['authors'])->firstWhere('id', $author->id);

        $this->assertNotNull($testAuthor);
        $this->assertEquals(5, $testAuthor['book_count']); // Total books
        $this->assertEquals(3, $testAuthor['book_count_in_genre']); // Books in fantasy genre
    }

    public function test_toggle_author_favorite()
    {
        $user = $this->user;
        /** @var Author $author */
        $author = Author::factory()->create();
        $book = Book::factory()->create();
        $book->authors()->attach($author);

        // Mark as favorite
        $response = $this->postJson("/api/v1/authors/{$author->id}/favorite", ['is_favorite' => true]);
        $response->assertStatus(200)
            ->assertJson([
                'id' => $author->id,
                'name' => $author->name,
                'isFavorite' => true,
            ]);

        $this->assertTrue($user->favoritedAuthors()->where('author_id', $author->id)->exists());

        // Unmark as favorite
        $response = $this->postJson("/api/v1/authors/{$author->id}/favorite", ['is_favorite' => false]);
        $response->assertStatus(200)
            ->assertJson([
                'id' => $author->id,
                'name' => $author->name,
                'isFavorite' => false,
            ]);

        $this->assertFalse($user->favoritedAuthors()->where('author_id', $author->id)->exists());
    }

    public function test_authors_endpoint_filters_by_favorites()
    {
        $user = $this->user;
        /** @var Author $favoritedAuthor */
        $favoritedAuthor = Author::factory()->create();
        /** @var Author $nonFavoritedAuthor */
        $nonFavoritedAuthor = Author::factory()->create();

        // Attach books to authors so they appear in results
        $book1 = Book::factory()->create();
        $book1->authors()->attach($favoritedAuthor);
        $book2 = Book::factory()->create();
        $book2->authors()->attach($nonFavoritedAuthor);

        $user->favoritedAuthors()->attach($favoritedAuthor);

        $response = $this->getJson('/api/v1/authors?favorites=true');

        $response->assertStatus(200)
            ->assertJsonFragment([
                'id' => $favoritedAuthor->id,
                'name' => $favoritedAuthor->name,
                'isFavorite' => true,
            ]);

        $authorsInResponse = collect($response->json('authors'));
        $this->assertCount(1, $authorsInResponse);
        $this->assertEquals($favoritedAuthor->id, $authorsInResponse->first()['id']);

        // Test that is_favorite is true when requesting all authors
        $responseAll = $this->getJson('/api/v1/authors');
        $responseAll->assertStatus(200);
        $authorsAll = collect($responseAll->json('authors'));

        $this->assertTrue($authorsAll->firstWhere('id', $favoritedAuthor->id)['isFavorite']);
        $this->assertFalse($authorsAll->firstWhere('id', $nonFavoritedAuthor->id)['isFavorite']);
    }
}
