<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\Book;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ListMissingBookDirectoriesCommandTest extends TestCase
{
    public function test_lists_missing_directories_as_text(): void
    {
        Storage::fake('books');

        // Existing directory
        Storage::disk('books')->makeDirectory('fantasy/author/Series/01/Book One');

        // Books
        $exists = Book::factory()->create(['directory_path' => 'fantasy/author/Series/01/Book One']);
        $missing1 = Book::factory()->create(['directory_path' => 'fantasy/author/Series/02/Book Two']);
        $missing2 = Book::factory()->create(['directory_path' => 'sci-fi/other_author/Standalone Book']);

        $this->artisan('books:list-missing-directories', [
            '--disk' => 'books',
        ])->expectsOutput('fantasy/author/Series/02/Book Two')
          ->expectsOutput('sci-fi/other_author/Standalone Book')
          ->assertExitCode(0);
    }

    public function test_outputs_json_when_requested(): void
    {
        Storage::fake('books');

        $missing = Book::factory()->create(['directory_path' => 'missing/path']);

        $out = storage_path('framework/testing/missing_dirs.json');

        $this->artisan('books:list-missing-directories', [
            '--disk' => 'books',
            '--format' => 'json',
            '--output' => $out,
        ])->assertExitCode(0);

        $contents = file_get_contents($out);
        $this->assertIsString($contents);
        $this->assertStringContainsString('"paths"', $contents);
        $this->assertStringContainsString('missing/path', $contents);
    }
}
