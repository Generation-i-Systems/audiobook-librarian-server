<?php

namespace Tests\Cli\Feature\Commands;

use App\Models\Book;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MarkInvalidBookDirectoriesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_marks_books_with_missing_directories_as_needs_review(): void
    {
        Storage::fake('books');

        // Book with an existing directory
        $valid = Book::factory()->create([
            'title' => 'Valid Book',
            'directory_path' => 'Authors/Valid/Book One',
            'needs_review' => false,
            'needs_review_reasons' => null,
        ]);
        Storage::disk('books')->makeDirectory('Authors/Valid/Book One');

        // Book with a missing directory
        $invalid = Book::factory()->create([
            'title' => 'Invalid Book',
            'directory_path' => 'Authors/Missing/Book Two',
            'needs_review' => false,
            'needs_review_reasons' => null,
        ]);

        // Dry-run should not modify DB
        $exit = Artisan::call('books:mark-invalid-directories', [
            '--disk' => 'books',
            '--dry-run' => true,
        ]);
        $this->assertSame(0, $exit);
        $this->assertFalse($invalid->fresh()->needs_review);

        // Real run should mark the invalid one
        $exit = Artisan::call('books:mark-invalid-directories', [
            '--disk' => 'books',
        ]);
        $this->assertSame(0, $exit);

        $this->assertFalse($valid->fresh()->needs_review, 'Valid book should remain not needing review');

        $invalid->refresh();
        $this->assertTrue($invalid->needs_review, 'Invalid book should be marked as needs_review');
        $reasons = $invalid->needs_review_reasons;
        if (!is_array($reasons)) {
            // Some DBs may cast JSON to array automatically; ensure array for assertion
            $reasons = (array) $reasons;
        }
        $this->assertContains('missing_directory', $reasons, 'Reason should include missing_directory');
    }
}
