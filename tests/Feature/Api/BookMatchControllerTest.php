<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Author;
use App\Models\Book;

class BookMatchControllerTest extends ApiTestCase
{
    public function test_search_returns_cover_image_api_url_for_local_covers(): void
    {
        $book = Book::factory()->create([
            'title' => 'The Pragmatic Programmer',
            'cover_image' => 'pragmatic.jpg',
            'directory_path' => 'tech/pragmatic-programmer',
        ]);

        $author = Author::factory()->create(['name' => 'Andy Hunt']);
        $book->authors()->attach($author);

        $response = $this->postJson('/api/v1/books/match/search', [
            'title' => 'The Pragmatic Programmer',
            'author' => 'Andy Hunt',
        ]);

        $response->assertOk();
        $candidate = $response->json('candidates.0');

        $this->assertSame('https://localhost/api/v1/books/' . $book->id . '/cover', $candidate['coverImage']);
    }
}
