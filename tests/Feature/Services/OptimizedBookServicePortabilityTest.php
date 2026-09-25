<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Book;
use App\Services\OptimizedBookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OptimizedBookServicePortabilityTest extends TestCase
{
    use RefreshDatabase;

    public function testBookListsAndRecentBooksWorkOnSqlite(): void
    {
        $book = Book::factory()->create(['title' => 'Portable SQL Book', 'created_at' => now()]);
        $service = new OptimizedBookService();

        $list = $service->getBooks();
        $recent = $service->getRecentBooks();

        $this->assertSame($book->id, $list['data'][0]['id']);
        $this->assertSame(1, $list['total']);
        $this->assertSame($book->id, $recent[0]['id']);
    }
}
