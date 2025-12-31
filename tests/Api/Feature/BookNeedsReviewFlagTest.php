<?php

namespace Tests\Api\Feature;

use App\Models\Book;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

class BookNeedsReviewFlagTest extends ApiTestCase
{
    use RefreshDatabase;

    #[Test]
    public function index_includes_needs_review_when_flagged(): void
    {
        Storage::fake('books');
        $this->withoutMiddleware();

        Book::factory()->create(['title' => 'Included Book', 'needs_review' => false]);
        Book::factory()->create(['title' => 'Needs Review Book', 'needs_review' => true]);

        $response = $this->getJson('/api/v1/books?includeNeedsReview=1');
        $response->assertOk();

        $json = $response->json();
        $data = $json['data'] ?? $json;
        $titles = array_map(fn ($b) => $b['title'] ?? '', $data);

        $this->assertContains('Included Book', $titles);
        $this->assertContains('Needs Review Book', $titles);
    }

    #[Test]
    public function show_returns_404_by_default_for_needs_review(): void
    {
        Storage::fake('books');
        $this->withoutMiddleware();

        $book = Book::factory()->create(['needs_review' => true]);

        $this->getJson('/api/v1/books/' . $book->id)
            ->assertStatus(404)
            ->assertJsonFragment(['error' => 'Book not available']);
    }

    #[Test]
    public function show_includes_needs_review_when_flagged(): void
    {
        Storage::fake('books');
        $this->withoutMiddleware();

        $book = Book::factory()->create([
            'title' => 'Flagged Visible',
            'needs_review' => true,
        ]);

        $this->getJson('/api/v1/books/' . $book->id . '?includeNeedsReview=1')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Flagged Visible']);
    }

    #[Test]
    public function download_manifest_is_404_by_default_for_needs_review(): void
    {
        Storage::fake('books');
        $this->withoutMiddleware();

        $book = Book::factory()->create([
            'needs_review' => true,
            'directory_path' => 'books/abc',
        ]);
        Storage::disk('books')->put('books/abc/01 - test.mp3', 'audio');

        $this->getJson('/api/v1/books/' . $book->id . '/download')
            ->assertStatus(404);
    }

    #[Test]
    public function download_manifest_includes_when_flagged(): void
    {
        Storage::fake('books');
        $this->withoutMiddleware();

        $book = Book::factory()->create([
            'needs_review' => true,
            'directory_path' => 'books/abc',
        ]);
        Storage::disk('books')->put('books/abc/01 - test.mp3', 'audio');

        $this->getJson('/api/v1/books/' . $book->id . '/download?includeNeedsReview=1')
            ->assertOk()
            ->assertJsonFragment(['book_id' => (int) $book->id]);
    }
}
