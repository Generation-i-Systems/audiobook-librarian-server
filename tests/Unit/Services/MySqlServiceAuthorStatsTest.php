<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Author;
use App\Models\Book;
use App\Services\MySqlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MySqlServiceAuthorStatsTest extends TestCase
{
    use RefreshDatabase;

    public function testPaginatedAuthorStatsIncludeEveryNonDeletedLinkedBook(): void
    {
        $author = Author::query()->create(['name' => 'Counted Author']);
        $book = Book::factory()->create([
            'directory_exists' => false,
            'needs_review' => true,
        ]);
        $book->authors()->attach($author);

        $authors = app(MySqlService::class)->paginateAuthorsWithStats(search: $author->name);

        $this->assertSame(1, $authors->total());
        $this->assertSame(1, (int) $authors->first()->book_count);
    }
}
