<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\Genre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class GenresApiTest extends ApiTestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_only_returns_genres_with_books(): void
    {
        $genreWithBooks = Genre::factory()->create(['name' => 'Fantasy']);
        $emptyGenre = Genre::factory()->create(['name' => 'Agriculture']);

        $book = Book::factory()->create();
        $book->genres()->attach($genreWithBooks);

        $this->withoutMiddleware();

        $response = $this->getJson('/api/v1/genres');
        $response->assertOk();

        $genres = $response->json();
        $names = array_map(static fn (array $genre) => $genre['name'] ?? '', $genres);

        $this->assertContains('Fantasy', $names);
        $this->assertNotContains('Agriculture', $names);
    }
}
