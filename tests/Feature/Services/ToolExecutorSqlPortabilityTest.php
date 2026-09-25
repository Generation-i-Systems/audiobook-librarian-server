<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Book;
use App\Services\AI\ToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolExecutorSqlPortabilityTest extends TestCase
{
    use RefreshDatabase;

    public function testDuplicateBookToolGroupsByTitleAndIsbnOnSqlite(): void
    {
        $first = Book::factory()->create(['title' => 'Shared Title', 'isbn' => '9780000000001']);
        $second = Book::factory()->create(['title' => 'Shared Title', 'isbn' => '9780000000001']);

        $executor = new class () extends ToolExecutor {
            public function duplicates(): array
            {
                return $this->findDuplicateBooks(['method' => 'all']);
            }
        };
        $result = $executor->duplicates();

        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame(2, $result['total_duplicate_groups']);
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            array_column($result['duplicates'][0]['books'], 'id')
        );
    }
}
