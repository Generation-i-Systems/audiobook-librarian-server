<?php

declare(strict_types=1);

namespace Tests\Core\Unit\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Narrator;
use App\Services\MySqlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MySqlServiceCoverImageNormalizationTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function createBookNormalizesCoverImageToBasename(): void
    {
        $service = new MySqlService();

        $book = $service->createBook([
            'title' => 'Test Book',
            'coverImage' => '/some/path/to/cover_audible_123.jpg',
        ]);

        $this->assertInstanceOf(Book::class, $book);
        $this->assertSame('cover_audible_123.jpg', $book->cover_image);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function updateBookNormalizesCoverImageToBasename(): void
    {
        $book = Book::factory()->create([
            'cover_image' => 'cover_old.jpg',
        ]);

        $service = new MySqlService();

        $service->updateBook((string) $book->id, [
            'coverImage' => 'https://example.com/images/cover_googlebooks_456.png',
        ]);

        $book->refresh();

        $this->assertSame('https://example.com/images/cover_googlebooks_456.png', $book->cover_image);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function updateBookAcceptsAuthorNarratorGenreIds(): void
    {
        $book = Book::factory()->create([
            'cover_image' => 'cover_old.jpg',
        ]);

        $author = Author::query()->create(['name' => 'Real Author']);
        $narrator = Narrator::query()->create(['name' => 'Real Narrator']);
        $genre = Genre::query()->create(['name' => 'Real Genre']);

        $service = new MySqlService();

        $service->updateBook((string) $book->id, [
            'authors' => [(string) $author->id],
            'narrators' => [(string) $narrator->id],
            'genres' => [(string) $genre->id],
        ]);

        $book->refresh();
        $book->load(['authors', 'narrators', 'genres']);

        $this->assertSame(['Real Author'], $book->authors->pluck('name')->all());
        $this->assertSame(['Real Narrator'], $book->narrators->pluck('name')->all());
        $this->assertSame(['Real Genre'], $book->genres->pluck('name')->all());
    }
}
