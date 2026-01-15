<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\LibraryRepairIssueType;
use App\Models\Book;
use App\Models\LibraryRepairIssue;
use App\Services\BookImportService;
use App\Services\BookPathService;
use App\Services\AudiobookBayService;
use App\Services\LibraryRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LibraryRepairServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $libraryRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->libraryRoot = storage_path('framework/testing/library-repair/' . uniqid('root_', true));
        File::ensureDirectoryExists($this->libraryRoot);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->libraryRoot)) {
            File::deleteDirectory($this->libraryRoot);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_creates_missing_directory_issues_and_marks_books_for_review(): void
    {
        // Set a non-existent sync path to avoid interference in tests
        config(['app.library_repair_sync_path' => '/non/existent/sync/path']);

        $service = $this->makeService();

        $missing = Book::factory()->create([
            'title' => 'Missing Files',
            'directory_path' => 'missing/book-one',
            'needs_review' => false,
            'needs_review_reasons' => [],
        ]);

        $existing = Book::factory()->create([
            'title' => 'Existing Files',
            'directory_path' => 'existing/book-two',
            'needs_review' => false,
            'needs_review_reasons' => [],
        ]);

        File::ensureDirectoryExists($this->libraryRoot . '/existing/book-two');

        $result = $service->scan(true, [LibraryRepairIssueType::MISSING_DIRECTORY]);

        $this->assertArrayHasKey(LibraryRepairIssueType::MISSING_DIRECTORY->value, $result);
        $summary = $result[LibraryRepairIssueType::MISSING_DIRECTORY->value];
        $this->assertSame(1, $summary['created']);
        $this->assertSame(0, $summary['resolved']);

        $issue = LibraryRepairIssue::first();
        $this->assertNotNull($issue);
        $this->assertSame($missing->id, $issue->book_id);
        $this->assertSame('missing/book-one', $issue->directory_path);
        $this->assertSame('missing_directory', $issue->issue_type);
        $this->assertSame([], $issue->metadata ?? []);

        $this->assertTrue($missing->fresh()->needs_review);
        $this->assertContains('library_repair', $missing->fresh()->needs_review_reasons);

        $this->assertFalse($existing->fresh()->needs_review, 'Book with existing directory should remain clean');
    }

    #[Test]
    public function it_auto_updates_directory_for_nested_audio_when_safe(): void
    {
        $service = $this->makeService();

        $book = Book::factory()->create([
            'title' => 'Nested Audio',
            'directory_path' => 'nested/book-root',
            'directory_exists' => true,
            'needs_review' => false,
            'needs_review_reasons' => [],
        ]);

        $rootPath = $this->libraryRoot . '/nested/book-root';
        File::ensureDirectoryExists($rootPath . '/disc1');
        file_put_contents($rootPath . '/disc1/01-track.mp3', 'fake audio bytes');

        $result = $service->scan(true, [LibraryRepairIssueType::NESTED_AUDIO]);

        $this->assertArrayHasKey(LibraryRepairIssueType::NESTED_AUDIO->value, $result);
        $summary = $result[LibraryRepairIssueType::NESTED_AUDIO->value];
        $this->assertSame(0, $summary['created'], 'No pending issues should remain after auto-fix');
        $this->assertSame(0, $summary['resolved']);
        $this->assertSame(1, $summary['autoResolved']);

        $book->refresh();
        $this->assertSame('nested/book-root/disc1', $book->directory_path);

        $issue = LibraryRepairIssue::first();
        $this->assertNotNull($issue);
        $this->assertTrue($issue->auto_resolved);
        $this->assertSame('Updated directory_path to nested audio directory.', $issue->resolution_notes);
    }

    #[Test]
    public function it_detects_numbered_suffix_directories_without_base(): void
    {
        $service = $this->makeService();

        Book::factory()->create([
            'title' => 'Suffix Only',
            'directory_path' => 'seriesset/book-title_01',
        ]);

        $result = $service->scan(true, [LibraryRepairIssueType::NUMBERED_SUFFIX_DIRECTORY]);

        $this->assertArrayHasKey(LibraryRepairIssueType::NUMBERED_SUFFIX_DIRECTORY->value, $result);
        $summary = $result[LibraryRepairIssueType::NUMBERED_SUFFIX_DIRECTORY->value];
        $this->assertSame(1, $summary['created']);
        $this->assertSame(0, $summary['resolved']);

        $issue = LibraryRepairIssue::where('issue_type', LibraryRepairIssueType::NUMBERED_SUFFIX_DIRECTORY->value)->first();
        $this->assertNotNull($issue);
        $this->assertSame('seriesset/book-title_01', $issue->directory_path);
        $this->assertSame([], $issue->metadata ?? []);
    }

    #[Test]
    public function it_resolves_numbered_suffix_issue_when_base_directory_exists(): void
    {
        $service = $this->makeService();

        $suffixBook = Book::factory()->create([
            'title' => 'Suffix Book',
            'directory_path' => 'collection/book-name_02',
        ]);

        $service->scan(true, [LibraryRepairIssueType::NUMBERED_SUFFIX_DIRECTORY]);

        $issue = LibraryRepairIssue::where('issue_type', LibraryRepairIssueType::NUMBERED_SUFFIX_DIRECTORY->value)
            ->where('directory_path', 'collection/book-name_02')
            ->first();
        $this->assertNotNull($issue);
        $this->assertSame('pending', $issue->status);

        Book::factory()->create([
            'title' => 'Base Book',
            'directory_path' => 'collection/book-name',
        ]);

        $result = $service->scan(true, [LibraryRepairIssueType::NUMBERED_SUFFIX_DIRECTORY]);
        $summary = $result[LibraryRepairIssueType::NUMBERED_SUFFIX_DIRECTORY->value];
        $this->assertSame(0, $summary['created']);
        $this->assertSame(1, $summary['resolved']);

        $issue->refresh();
        $this->assertSame('resolved', $issue->status);
        $this->assertSame('Base directory now exists.', $issue->resolution_notes);

        $this->assertSame('collection/book-name_02', $suffixBook->fresh()->directory_path);
    }

    #[Test]
    public function it_creates_and_auto_fixes_bogus_directory_issues(): void
    {
        config(['app.library_repair_sync_path' => '/non/existent/sync/path']);

        $service = $this->makeService();

        Book::factory()->create([
            'title' => 'Some Book',
            'directory_path' => 'valid/book',
        ]);

        File::ensureDirectoryExists($this->libraryRoot . '/valid/book');
        file_put_contents($this->libraryRoot . '/valid/book/track01.mp3', 'audio');

        File::ensureDirectoryExists($this->libraryRoot . '/bogus/book_01');
        file_put_contents($this->libraryRoot . '/bogus/book_01/librarian.json', '{"title": "Bogus"}');

        $result = $service->scan(true, [LibraryRepairIssueType::BOGUS_DIRECTORY]);

        $this->assertArrayHasKey(LibraryRepairIssueType::BOGUS_DIRECTORY->value, $result);
        $summary = $result[LibraryRepairIssueType::BOGUS_DIRECTORY->value];
        $this->assertSame(1, $summary['created']);
        $this->assertSame(0, $summary['resolved']);
        $this->assertSame(1, $summary['autoResolved']);

        $issue = LibraryRepairIssue::first();
        $this->assertNotNull($issue);
        $this->assertNull($issue->book_id);
        $this->assertSame('bogus/book_01', $issue->directory_path);
        $this->assertSame('bogus_directory', $issue->issue_type);
        $this->assertSame('resolved', $issue->status);
        $this->assertTrue($issue->auto_resolved);

        $this->assertDirectoryDoesNotExist($this->libraryRoot . '/bogus/book_01');
    }

    #[Test]
    public function it_does_not_create_bogus_directory_issue_for_directories_with_audio(): void
    {
        config(['app.library_repair_sync_path' => '/non/existent/sync/path']);

        $service = $this->makeService();

        File::ensureDirectoryExists($this->libraryRoot . '/good/book');
        file_put_contents($this->libraryRoot . '/good/book/track01.mp3', 'audio');
        file_put_contents($this->libraryRoot . '/good/book/librarian.json', '{"title": "Good"}');

        $result = $service->scan(true, [LibraryRepairIssueType::BOGUS_DIRECTORY]);

        $this->assertArrayHasKey(LibraryRepairIssueType::BOGUS_DIRECTORY->value, $result);
        $summary = $result[LibraryRepairIssueType::BOGUS_DIRECTORY->value];
        $this->assertSame(0, $summary['created']);
    }

    #[Test]
    public function it_resolves_bogus_directory_issue_when_audio_added(): void
    {
        config(['app.library_repair_sync_path' => '/non/existent/sync/path']);

        $service = $this->makeService();

        File::ensureDirectoryExists($this->libraryRoot . '/bogus/book_01');
        file_put_contents($this->libraryRoot . '/bogus/book_01/librarian.json', '{"title": "Bogus"}');

        $result = $service->scan(false, [LibraryRepairIssueType::BOGUS_DIRECTORY]);
        $summary = $result[LibraryRepairIssueType::BOGUS_DIRECTORY->value];
        $this->assertSame(1, $summary['created']);

        $issue = LibraryRepairIssue::first();
        $this->assertNotNull($issue);
        $this->assertSame('bogus/book_01', $issue->directory_path);
        $this->assertSame('pending', $issue->status);

        file_put_contents($this->libraryRoot . '/bogus/book_01/track01.mp3', 'audio');

        $rescanResult = $service->rescanIssue($issue->id);

        $this->assertSame('resolved', $rescanResult['status']);
        $this->assertSame('Directory now contains audio files.', $rescanResult['message']);

        $issue->refresh();
        $this->assertSame('resolved', $issue->status);
        $this->assertSame('Directory now contains audio files.', $issue->resolution_notes);
    }

    private function makeService(): LibraryRepairService
    {
        $bookPathService = Mockery::mock(BookPathService::class);
        $bookPathService->shouldReceive('getBookRoot')->andReturn($this->libraryRoot);

        $audiobookBayService = Mockery::mock(AudiobookBayService::class);
        $audiobookBayService->shouldReceive('buildSearchUrl')->andReturn('https://example.test');

        $bookImportService = Mockery::mock(BookImportService::class);
        $bookImportService->shouldReceive('moveFilesToLibrary')->andReturnTrue();

        return new LibraryRepairService($bookPathService, $audiobookBayService, $bookImportService);
    }
}
