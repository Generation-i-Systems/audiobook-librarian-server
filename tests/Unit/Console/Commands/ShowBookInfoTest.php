<?php

namespace Tests\Unit\Console\Commands;

use App\Models\Book;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowBookInfoTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function editFlagIsDefinedInSignature(): void
    {
        $this->artisan('books:info', ['--help' => true])
            ->expectsOutputToContain('--edit')
            ->assertSuccessful();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function editFlagOpensEditPageForBookById(): void
    {
        $book = Book::factory()->create();

        // Run command with --edit flag - it will try to open browser but we just verify it doesn't error
        $this->artisan('books:info', ['directories' => [(string) $book->id], '--edit' => true])
            ->assertSuccessful();
    }
}
