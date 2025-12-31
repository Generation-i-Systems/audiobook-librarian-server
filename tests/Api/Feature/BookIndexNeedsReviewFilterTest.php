<?php

namespace Tests\Api\Feature;

use App\Models\Book;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

class BookIndexNeedsReviewFilterTest extends ApiTestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_excludes_needs_review_books_from_index(): void
    {
        Storage::fake('books');

        // This test focuses on filtering, not auth; disable middleware
        $this->withoutMiddleware();

        $included = Book::factory()->create([
            'title' => 'Included Book',
            'needs_review' => false,
        ]);

        $excluded = Book::factory()->create([
            'title' => 'Excluded Book',
            'needs_review' => true,
        ]);

        $response = $this->getJson('/api/v1/books');
        $response->assertOk();

        $json = $response->json();
        $data = $json['data'] ?? $json; // handle both paginated and plain arrays

        $titles = array_map(fn ($b) => $b['title'] ?? '', $data);
        $this->assertContains('Included Book', $titles);
        $this->assertNotContains('Excluded Book', $titles);
    }
}
