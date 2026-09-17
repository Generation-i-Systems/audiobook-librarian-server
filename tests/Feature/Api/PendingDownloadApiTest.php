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

    public function testStoreIsIdempotentForADuplicateInfohash(): void
    {
        $payload = [
            'magnet_uri' => 'magnet:?xt=urn:btih:AABBCCDDEEFF00112233445566778899AABBCCDD&dn=Revenant+Book+4',
            'abb_url' => 'https://audiobookbay.example/post/revenant-4',
            'abb_category' => 'Fantasy',
            'release_name' => 'Revenant Book 4',
            'books' => [['title' => 'Revenant Book 4']],
        ];

        $first = $this->postJson('/api/v1/pending-downloads', $payload);
        $first->assertCreated()->assertJsonPath('data.status', 'pending');

        // The bridge extension retries the same magnet after a failed download;
        // it must not create a duplicate row or return a 500 on the unique index.
        $second = $this->postJson('/api/v1/pending-downloads', $payload);
        $second->assertCreated()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(
            1,
            PendingDownload::where('magnet_infohash', 'aabbccddeeff00112233445566778899aabbccdd')->count()
        );
    }

    public function testStoreRefreshesAnExpiredRecordWithTheSameInfohash(): void
    {
        $magnet = 'magnet:?xt=urn:btih:1122334455667788990011223344556677889900&dn=Stale+Release';

        PendingDownload::create([
            'magnet_infohash' => '1122334455667788990011223344556677889900',
            'release_name' => 'Stale Release',
            'torrent_name_hint' => 'stale release',
            'abb_url' => 'https://audiobookbay.example/post/stale',
            'magnet_uri' => $magnet,
            'book_count' => 1,
            'status' => 'pending',
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->postJson('/api/v1/pending-downloads', [
            'magnet_uri' => $magnet,
            'abb_url' => 'https://audiobookbay.example/post/stale-new',
            'books' => [['title' => 'Stale Release']],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.abb_url', 'https://audiobookbay.example/post/stale-new');
        $this->assertTrue(
            PendingDownload::find($response->json('data.id'))->expires_at->isFuture()
        );
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
