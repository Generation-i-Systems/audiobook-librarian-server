<?php

declare(strict_types=1);

namespace Tests\Cli\Feature\Commands;

use App\Console\Commands\ImportBooksFromDownloads;
use App\Models\Book;
use App\Models\LibraryRepairIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportBooksFromDownloadsRepairCommandTest extends TestCase
{
    use RefreshDatabase;

    public function testRepairModeRefusesBeforeStartingImportWhenTargetCountDiffers(): void
    {
        Book::factory()->count(3)->create([
            'created_at' => '2025-08-06 12:00:00',
            'needs_review' => true,
            'needs_review_reasons' => ['directoryPath title mismatch\nParsed: Directory Title\nDocument: Wrong Title'],
        ]);

        $this->artisan('book:import', [
            '--repair-title-mismatch-date' => '2025-08-06',
            '--repair-expected' => 2,
            '--ui' => 'plain',
            '--no-backup' => true,
        ])->assertExitCode(1);
    }

    public function testResolvedTitleMismatchRepairClearsTheReviewMarkerAndRepairIssue(): void
    {
        $book = Book::factory()->create([
            'needs_review' => true,
            'needs_review_reasons' => [
                'directoryPath title mismatch\nParsed: Expected Title\nDocument: Wrong Title',
                'library_repair',
            ],
        ]);
        $issue = LibraryRepairIssue::query()->create([
            'book_id' => $book->id,
            'issue_type' => 'title_directory_mismatch',
            'status' => 'pending',
            'directory_path' => $book->directory_path,
        ]);

        $command = $this->app->make(ImportBooksFromDownloads::class);
        $method = new \ReflectionMethod($command, 'resolveTitleMismatchReview');
        $method->invoke($command, $book);

        $this->assertDatabaseHas('books', [
            'id' => $book->id,
            'needs_review' => true,
        ]);
        $this->assertSame(['library_repair'], $book->fresh()->needs_review_reasons);
        $this->assertDatabaseHas('library_repair_issues', [
            'id' => $issue->id,
            'status' => 'resolved',
        ]);
    }

    public function testRepairDirectoryFallbackAddsMissingAuthorFromLibraryPath(): void
    {
        $command = $this->app->make(ImportBooksFromDownloads::class);
        $method = new \ReflectionMethod($command, 'applyRepairDirectoryFallbacks');

        $metadata = $method->invoke(
            $command,
            ['title' => 'Rise of the Elder', 'author' => []],
            'Fantasy/D.K. Holmberg/The Dark Ability/07 Rise of the Elder'
        );

        $this->assertSame(['D.K. Holmberg'], $metadata['author']);
    }

    public function testRepairRecognizesAConfirmedDirectoryChange(): void
    {
        $command = $this->app->make(ImportBooksFromDownloads::class);
        $method = new \ReflectionMethod($command, 'repairDirectoryChanged');

        $this->assertTrue($method->invoke($command, 'Fantasy/Author/Old Title', 'Fantasy/Author/New Title'));
        $this->assertFalse($method->invoke($command, 'Fantasy/Author/Title/', 'Fantasy/Author/Title'));
    }
}
