<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\PendingDownload;

class PendingDownloadApiTest extends ApiTestCase
{
    public function testStoreCreatesPendingDownloadWithBooks(): void
    {
        $response = $this->postJson('/api/v1/pending-downloads', [
            'magnet_uri' => 'magnet:?xt=urn:btih:ABCDEF1234567890ABCDEF1234567890ABCDEF12&dn=Test+Book',
            'abb_url' => 'https://audiobookbay.example/post/test-book',
            'abb_category' => 'Fantasy',
            'books' => [
                [
                    'title' => 'Test Book',
                    'authors' => ['Jane Author'],
                    'genre' => 'Fantasy',
                    'tags' => ['litrpg'],
                    'description' => 'A test description.',
                    'cover_url' => 'https://audiobookbay.example/covers/test-book.jpg',
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.book_count', 1)
            ->assertJsonPath('data.books.0.title', 'Test Book');

        $this->assertDatabaseHas('pending_downloads', [
            'release_name' => 'Test Book',
            'magnet_infohash' => 'abcdef1234567890abcdef1234567890abcdef12',
        ]);
    }

    public function testStoreRequiresValidMagnetUri(): void
    {
        $response = $this->postJson('/api/v1/pending-downloads', [
            'magnet_uri' => 'not-a-magnet',
            'abb_url' => 'https://audiobookbay.example/post/test-book',
            'books' => [['title' => 'Test Book']],
        ]);

        $response->assertStatus(422);
    }

    public function testConsumeMarksPendingDownloadAndBookConsumed(): void
    {
        $book = Book::factory()->create();

        $pendingDownload = PendingDownload::create([
            'magnet_uri' => 'magnet:?xt=urn:btih:ABCDEF1234567890ABCDEF1234567890ABCDEF12',
            'abb_url' => 'https://audiobookbay.example/post/test-book',
            'release_name' => 'Test Book',
            'torrent_name_hint' => 'test book',
            'book_count' => 1,
            'status' => 'pending',
        ]);
        $pendingDownloadBook = $pendingDownload->books()->create([
            'sort_order' => 0,
            'title' => 'Test Book',
            'authors' => ['Jane Author'],
        ]);

        $response = $this->postJson("/api/v1/pending-downloads/{$pendingDownload->id}/consume", [
            'matches' => [
                ['pending_download_book_id' => $pendingDownloadBook->id, 'book_id' => $book->id],
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'consumed');

        $this->assertDatabaseHas('pending_download_books', [
            'id' => $pendingDownloadBook->id,
            'matched_book_id' => $book->id,
        ]);
    }
}
