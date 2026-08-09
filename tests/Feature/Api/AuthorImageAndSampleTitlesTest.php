<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Author;
use App\Models\Book;

class AuthorImageAndSampleTitlesTest extends ApiTestCase
{
    public function testAuthorsListIncludesImageUrlFromABookCoverAndSampleTitles(): void
    {
        $author = Author::factory()->create();

        $withCover = Book::factory()->create(['title' => 'Book With Cover', 'cover_image' => 'cover.jpg']);
        $withCover->authors()->attach($author->id);

        $withoutCover = Book::factory()->create(['title' => 'Book Without Cover', 'cover_image' => null]);
        $withoutCover->authors()->attach($author->id);

        $response = $this->getJson('/api/v1/authors');

        $response->assertOk();
        $entry = collect($response->json('authors'))->firstWhere('id', $author->id);

        $this->assertNotNull($entry['image_url']);
        $this->assertStringContainsString("/books/{$withCover->id}/cover", $entry['image_url']);
        $this->assertEqualsCanonicalizing(
            ['Book With Cover', 'Book Without Cover'],
            $entry['sample_book_titles']
        );
    }

    public function testAuthorsListImageUrlIsNullWhenNoBookHasACover(): void
    {
        $author = Author::factory()->create();
        $book = Book::factory()->create(['cover_image' => null]);
        $book->authors()->attach($author->id);

        $response = $this->getJson('/api/v1/authors');

        $response->assertOk();
        $entry = collect($response->json('authors'))->firstWhere('id', $author->id);

        $this->assertNull($entry['image_url']);
    }

    public function testAuthorDetailsIncludesImageUrlAndSampleTitles(): void
    {
        $author = Author::factory()->create();
        $book = Book::factory()->create(['title' => 'Solo Book', 'cover_image' => 'cover.jpg']);
        $book->authors()->attach($author->id);

        $response = $this->getJson("/api/v1/authors/{$author->id}");

        $response->assertOk();
        $this->assertStringContainsString("/books/{$book->id}/cover", $response->json('image_url'));
        $this->assertSame(['Solo Book'], $response->json('sample_book_titles'));
    }
}
