<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Book;
use App\Models\BookTag;

class BookTagControllerTest extends ApiTestCase
{
    public function test_it_stores_and_returns_tags_for_a_book(): void
    {
        $book = Book::factory()->create([
            'directory_exists' => true,
            'needs_review' => false,
        ]);

        $response = $this->putJson('/api/v1/books/' . $book->id . '/tags', [
            'tags' => ['Series', '  sci-fi ', 'Series', ''],
        ]);

        $response->assertOk();
        $response->assertJson([
            'bookId' => $book->id,
            'tags' => ['Series', 'sci-fi'],
        ]);

        $this->assertDatabaseHas('book_tags', [
            'user_id' => $this->user->id,
            'book_id' => $book->id,
        ]);

        $showResponse = $this->getJson('/api/v1/books/' . $book->id . '/tags');

        $showResponse->assertOk();
        $showResponse->assertJson([
            'bookId' => $book->id,
            'tags' => ['Series', 'sci-fi'],
        ]);
    }

    public function test_it_filters_books_by_tag_for_the_authenticated_user(): void
    {
        $matchingBook = Book::factory()->create([
            'directory_exists' => true,
            'needs_review' => false,
            'title' => 'Matching Book',
        ]);
        $nonMatchingBook = Book::factory()->create([
            'directory_exists' => true,
            'needs_review' => false,
            'title' => 'Other Book',
        ]);

        BookTag::create([
            'user_id' => $this->user->id,
            'book_id' => $matchingBook->id,
            'tags' => ['Queue', 'Favorites'],
        ]);

        BookTag::create([
            'user_id' => $this->user->id,
            'book_id' => $nonMatchingBook->id,
            'tags' => ['Later'],
        ]);

        /** @var DocumentStoreServiceInterface $documentStore */
        $documentStore = app(DocumentStoreServiceInterface::class);
        $result = $documentStore->listBooks(
            1,
            24,
            ['tag' => 'Queue'],
            true,
            'title',
            'asc',
            false,
            $this->user->id
        );

        $this->assertCount(1, $result['data']);
        $this->assertSame($matchingBook->id, $result['data'][0]['id']);
        $this->assertSame(['Queue', 'Favorites'], $result['data'][0]['userTags']);
    }
}
