<?php

namespace Tests\Unit\Services;

use App\Models\Book;
use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BookImportServiceMergeIntoExistingBookTest extends TestCase
{
    use RefreshDatabase;

    protected BookImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $genreMappingService = $this->app->make(GenreMappingService::class);
        $sourceTrashService = $this->app->make(SourceTrashService::class);
        $this->service = new BookImportService($genreMappingService, $sourceTrashService);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function mergeIntoExistingBookMovesFilesAndUpdatesTheRecordOnSuccess(): void
    {
        $bookRoot = sys_get_temp_dir() . '/book_root_' . uniqid('', true);
        $sourceDir = sys_get_temp_dir() . '/merge_source_' . uniqid('', true);
        File::makeDirectory($bookRoot, 0775, true);
        File::makeDirectory($sourceDir, 0775, true);
        config(['app.book_root' => $bookRoot]);
        config(['filesystems.disks.books.root' => $bookRoot]);

        try {
            file_put_contents($sourceDir . '/track01.mp3', 'audio');

            $existingBook = Book::create([
                'title' => 'Existing Book',
                'directory_path' => 'Fiction/Existing Author/Existing Book',
                'language' => 'en',
            ]);

            $audiobook = ['path' => $sourceDir, 'files' => [$sourceDir . '/track01.mp3']];
            $mergedMetadata = ['title' => 'Existing Book', 'author' => ['Existing Author']];

            $book = $this->service->mergeIntoExistingBook(
                $existingBook,
                $audiobook,
                $mergedMetadata,
                function (): void {
                },
                function (): void {
                },
                fn () => 'copy'
            );

            $this->assertSame($existingBook->id, $book->id);
            $this->assertFileExists($bookRoot . '/Fiction/Existing Author/Existing Book/track01.mp3');
        } finally {
            File::deleteDirectory($bookRoot);
            File::deleteDirectory($sourceDir);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function mergeIntoExistingBookThrowsInsteadOfReportingSuccessWhenMoveFails(): void
    {
        // Regression test: moveFilesToLibrary() detects and logs failures (disk full,
        // permissions, a missing source, etc.) and returns false rather than throwing —
        // but mergeIntoExistingBook() used to discard that result entirely, so a failed
        // move was still reported by callers as "Book merged successfully" while the
        // existing book's directory was left with no (or partial) files.
        $bookRoot = sys_get_temp_dir() . '/book_root_' . uniqid('', true);
        File::makeDirectory($bookRoot, 0775, true);
        config(['app.book_root' => $bookRoot]);
        config(['filesystems.disks.books.root' => $bookRoot]);

        try {
            $existingBook = Book::create([
                'title' => 'Existing Book',
                'directory_path' => 'Fiction/Existing Author/Existing Book',
                'language' => 'en',
            ]);

            // A source path that does not exist forces moveFilesToLibrary() to fail.
            $audiobook = [
                'path' => sys_get_temp_dir() . '/does-not-exist-' . uniqid('', true),
                'files' => [],
            ];
            $mergedMetadata = ['title' => 'Existing Book', 'author' => ['Existing Author']];

            $this->expectException(\Exception::class);
            $this->expectExceptionMessageMatches('/Failed to move files while merging into existing book/');

            $this->service->mergeIntoExistingBook(
                $existingBook,
                $audiobook,
                $mergedMetadata,
                function (): void {
                },
                function (): void {
                },
                fn () => 'copy'
            );
        } finally {
            File::deleteDirectory($bookRoot);
        }
    }
}
