<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Book;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression: getRecentBooks() selected a "total_size" column that only ever
 * exists as a Book accessor (derived from chapters). MySQL 1054'd the query
 * (live "Error fetching recent books" log spam, feature silently returned []),
 * while SQLite tests silently coerced the unknown double-quoted identifier to
 * a string literal — so the assertion must be on the emitted SQL itself.
 */
class SqlDatabaseServiceGetRecentBooksTest extends TestCase
{
    use RefreshDatabase;

    public function testGetRecentBooksReturnsRecentBooks(): void
    {
        $book = Book::factory()->create([
            'title' => 'Fresh Arrival',
            'needs_review' => false,
            'created_at' => now()->subDay(),
        ]);
        Book::factory()->create([
            'title' => 'Ancient Arrival',
            'needs_review' => false,
            'created_at' => now()->subDays(30),
        ]);

        $recent = app(DocumentStoreServiceInterface::class)->getRecentBooks(5, 7);

        $this->assertCount(1, $recent);
        $this->assertSame((string) $book->id, $recent[0]['id']);
        $this->assertSame('Fresh Arrival', $recent[0]['title']);
        $this->assertSame(0, $recent[0]['totalSize']);
    }

    public function testGetRecentBooksNeverSelectsTheVirtualTotalSizeColumn(): void
    {
        Book::factory()->create([
            'needs_review' => false,
            'created_at' => now()->subDay(),
        ]);

        $sqls = [];
        DB::listen(function (QueryExecuted $query) use (&$sqls): void {
            $sqls[] = $query->sql;
        });

        app(DocumentStoreServiceInterface::class)->getRecentBooks(5, 7);

        $bookQueries = array_values(array_filter(
            $sqls,
            fn (string $sql): bool => str_contains($sql, 'from "books"') || str_contains($sql, 'from `books`')
        ));

        $this->assertNotEmpty($bookQueries, 'expected getRecentBooks to query the books table');
        foreach ($bookQueries as $sql) {
            $this->assertStringNotContainsString('total_size', $sql);
        }
    }
}
