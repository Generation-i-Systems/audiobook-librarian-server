<?php

declare(strict_types=1);

namespace Tests\Api\Feature;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;

class AuthorsNeedsReviewFilterTest extends ApiTestCase
{
    public function test_authors_excludes_needs_review_books_and_authors_by_default(): void
    {
        // Author with only needs_review books
        $nrAuthor = Author::factory()->create(['name' => 'Needs Review Only']);
        $nrBook1 = Book::factory()->create(['needs_review' => true]);
        $nrBook1->authors()->attach($nrAuthor);
        $nrBook2 = Book::factory()->create(['needs_review' => true]);
        $nrBook2->authors()->attach($nrAuthor);

        // Author with a mix of needs_review and normal books
        $mixAuthor = Author::factory()->create(['name' => 'Mixed Author']);
        $okBook = Book::factory()->create(['needs_review' => false]);
        $okBook->authors()->attach($mixAuthor);
        $nrBook3 = Book::factory()->create(['needs_review' => true]);
        $nrBook3->authors()->attach($mixAuthor);

        // Author with only normal books
        $okAuthor = Author::factory()->create(['name' => 'Normal Author']);
        $okBook2 = Book::factory()->create(['needs_review' => false]);
        $okBook2->authors()->attach($okAuthor);

        $response = $this->getJson('/api/v1/authors');
        $response->assertStatus(200);
        $data = $response->json();

        $authorNames = array_column($data['authors'], 'name');
        $this->assertNotContains('Needs Review Only', $authorNames, 'Authors with only needs_review books should be excluded');
        $this->assertContains('Mixed Author', $authorNames, 'Authors with at least one non-needs_review book should be included');
        $this->assertContains('Normal Author', $authorNames);

        // book_count should exclude needs_review books
        $mixed = collect($data['authors'])->firstWhere('name', 'Mixed Author');
        $this->assertNotNull($mixed);
        $this->assertEquals(1, $mixed['book_count']);
    }

    public function test_authors_include_needs_review_when_flag_is_set(): void
    {
        $nrAuthor = Author::factory()->create(['name' => 'Needs Review Only']);
        $nrBook1 = Book::factory()->create(['needs_review' => true]);
        $nrBook1->authors()->attach($nrAuthor);

        $response = $this->getJson('/api/v1/authors?includeNeedsReview=1');
        $response->assertStatus(200);
        $data = $response->json();

        $authorNames = array_column($data['authors'], 'name');
        $this->assertContains('Needs Review Only', $authorNames, 'includeNeedsReview should include authors whose books are needs_review');
    }

    public function test_genre_filter_respects_needs_review_filtering(): void
    {
        $genre = Genre::factory()->create(['name' => 'Fantasy']);

        $author = Author::factory()->create(['name' => 'Fantasy Mixed']);
        $okBook = Book::factory()->create(['needs_review' => false]);
        $okBook->authors()->attach($author);
        $okBook->genres()->attach($genre);

        $nrOnlyAuthor = Author::factory()->create(['name' => 'Fantasy NR Only']);
        $nrBook = Book::factory()->create(['needs_review' => true]);
        $nrBook->authors()->attach($nrOnlyAuthor);
        $nrBook->genres()->attach($genre);

        $response = $this->getJson('/api/v1/authors?genre_name=Fantasy');
        $response->assertStatus(200);
        $data = $response->json();

        $authorNames = array_column($data['authors'], 'name');
        $this->assertContains('Fantasy Mixed', $authorNames);
        $this->assertNotContains('Fantasy NR Only', $authorNames);
    }
}
