<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Book;
use App\Services\MySqlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MySqlServiceSearchByIdTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function numericSearchTermMatchesBookById(): void
    {
        $target = Book::factory()->create(['title' => 'Unrelated Title One']);
        Book::factory()->create(['title' => 'Unrelated Title Two']);

        $service = app(MySqlService::class);

        $result = $service->listBooks(1, 20, ['search' => (string) $target->id]);

        $ids = array_column($result['data'], 'id');

        $this->assertContains((string) $target->id, array_map('strval', $ids));
        $this->assertCount(1, $result['data']);
    }

    #[Test]
    public function bookIdFilterMatchesExactBook(): void
    {
        $target = Book::factory()->create(['title' => 'Findable Book']);
        Book::factory()->create(['title' => 'Other Book']);

        $service = app(MySqlService::class);

        $result = $service->listBooks(1, 20, ['book_id' => $target->id]);

        $this->assertCount(1, $result['data']);
        $this->assertSame((string) $target->id, (string) $result['data'][0]['id']);
    }
}
