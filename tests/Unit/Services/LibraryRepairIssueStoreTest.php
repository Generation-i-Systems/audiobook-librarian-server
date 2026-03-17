<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\LibraryRepairIssue;
use App\Services\LibraryRepairIssueStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LibraryRepairIssueStoreTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function listAndCountLibraryRepairIssuesApplyDefaultPendingFilter(): void
    {
        $book = Book::factory()->create([
            'title' => 'Broken Book',
            'directory_path' => 'broken/book',
            'needs_review' => true,
            'needs_review_reasons' => ['library_repair', 'missing_cover'],
        ]);

        $author = Author::query()->create(['name' => 'Repair Author']);
        $book->authors()->attach($author->id);

        LibraryRepairIssue::query()->create([
            'book_id' => $book->id,
            'issue_type' => 'missing_directory',
            'status' => 'pending',
            'directory_path' => 'broken/book',
            'metadata' => ['foo' => 'bar'],
            'auto_resolved' => false,
        ]);

        LibraryRepairIssue::query()->create([
            'issue_type' => 'duplicate_directory',
            'status' => 'resolved',
            'directory_path' => 'resolved/path',
            'auto_resolved' => true,
        ]);

        $store = new LibraryRepairIssueStore();

        $issues = $store->listIssues();

        $this->assertCount(1, $issues);
        $this->assertSame(1, $store->countIssues());
        $this->assertSame('Broken Book', $issues[0]['book']['title']);
        $this->assertSame(['Repair Author'], $issues[0]['book']['authors']);
        $this->assertSame(['foo' => 'bar'], $issues[0]['metadata']);
    }

    #[Test]
    public function getAndResolveLibraryRepairIssueReturnsMappedIssueAndClearsReviewReason(): void
    {
        $book = Book::factory()->create([
            'title' => 'Needs Review',
            'needs_review' => true,
            'needs_review_reasons' => ['library_repair', 'metadata_mismatch'],
        ]);

        $issue = LibraryRepairIssue::query()->create([
            'book_id' => $book->id,
            'issue_type' => 'missing_directory',
            'status' => 'pending',
            'directory_path' => 'needs/review',
            'metadata' => ['severity' => 'high'],
            'auto_resolved' => false,
        ]);

        $store = new LibraryRepairIssueStore();

        $mappedIssue = $store->getIssue($issue->id);

        $this->assertSame('missing_directory', $mappedIssue['issueType']);
        $this->assertSame('needs/review', $mappedIssue['directoryPath']);
        $this->assertSame(['severity' => 'high'], $mappedIssue['metadata']);

        $this->assertTrue($store->resolveIssue($issue->id, 'Fixed by test'));

        $issue->refresh();
        $book->refresh();

        $this->assertSame('resolved', $issue->status);
        $this->assertSame('Fixed by test', $issue->resolution_notes);
        $this->assertTrue($book->needs_review);
        $this->assertSame(['metadata_mismatch'], $book->needs_review_reasons);
    }
}
