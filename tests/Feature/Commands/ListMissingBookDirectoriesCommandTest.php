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

    public function test_unreferenced_mode_lists_directories_with_audio_files_not_in_db(): void
    {
        Storage::fake('books');

        // Create audio files across directories
        Storage::disk('books')->put('fantasy/author/Series/01/track1.mp3', 'A');
        Storage::disk('books')->put('fantasy/author/Series/02/track1.m4b', 'B');
        Storage::disk('books')->put('sci-fi/other/Standalone/part1.mp3', 'C');
        Storage::disk('books')->put('misc/readme.txt', 'no-audio'); // should be ignored

        // Create a DB book that references one existing directory
        Book::factory()->create(['directory_path' => 'fantasy/author/Series/01']);

        // Run unreferenced mode
        $this->artisan('books:list-missing-directories', [
            '--disk' => 'books',
            '--unreferenced' => true,
        ])->expectsOutput('fantasy/author/Series/02')
          ->expectsOutput('sci-fi/other/Standalone')
          ->doesntExpectOutput('fantasy/author/Series/01')
          ->assertExitCode(0);
    }

    public function test_unreferenced_mode_honors_root_option(): void
    {
        Storage::fake('books');

        Storage::disk('books')->put('rootA/book1/track1.mp3', 'X');
        Storage::disk('books')->put('rootB/book2/track1.mp3', 'Y');

        // DB references rootA/book1 only
        Book::factory()->create(['directory_path' => 'rootA/book1']);

        // Scan only under rootB, should report rootB/book2 as unreferenced
        $this->artisan('books:list-missing-directories', [
            '--disk' => 'books',
            '--unreferenced' => true,
            '--root' => 'rootB',
        ])->expectsOutput('rootB/book2')
          ->doesntExpectOutput('rootA/book1')
          ->assertExitCode(0);
    }
}
