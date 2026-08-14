<?php

namespace Tests\Unit\Services;

use App\Models\Genre;
use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BookImportServiceProcessSingleBookCoverTest extends TestCase
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
    public function processSingleBookSavesTheEmbeddedCoverAfterMovingFiles(): void
    {
        // Cover processing is deliberately deferred out of createBookFromMetadata()
        // (see its own comment) until after the target directory exists, to avoid
        // tripping moveFilesToLibrary()'s conflict detection. processSingleBook()
        // must be the one to actually run that deferred step — but only after a
        // successful move (a failed move must not report success or process the
        // cover for files that were never actually placed at the target).
        $bookRoot = sys_get_temp_dir() . '/book_root_' . uniqid('', true);
        File::makeDirectory($bookRoot, 0775, true);
        config(['app.book_root' => $bookRoot]);
        config(['filesystems.disks.books.root' => $bookRoot]);

        Genre::create(['name' => 'Science Fiction']);

        $fakeCoverBytes = 'fake-jpeg-bytes';

        $metadata = [
            'title' => 'Cover Test Book',
            'author' => ['Some Author'],
            'genre' => 'Science Fiction',
            'confidence' => 100,
            'cover_data' => $fakeCoverBytes,
        ];

        $audiobook = [
            'path' => '/tmp/source-book',
            'files' => [],
        ];

        $book = $this->service->processSingleBook(
            $audiobook,
            $metadata,
            fn ($metadata) => null,
            fn ($metadata, $enrichedData) => false,
            fn ($metadata) => $this->service->generateDirectoryPath($metadata),
            fn ($metadata, $audiobook) => $this->service->createBookFromMetadata($metadata, $audiobook),
            fn ($audiobook, $book, $options) => true, // moveFilesToLibrary stubbed out as a successful move
            fn () => 'copy',
            null,
            null,
            null,
            null,
            skipEnrichment: true,
            isAutoMode: true,
        );

        $this->assertNotNull($book);
        $this->assertSame('cover.jpg', $book->cover_image);
        $this->assertFileExists($bookRoot . '/' . $book->directory_path . '/cover.jpg');
        $this->assertSame($fakeCoverBytes, file_get_contents($bookRoot . '/' . $book->directory_path . '/cover.jpg'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function processSingleBookDoesNotReportSuccessWhenMoveFilesToLibraryFails(): void
    {
        // Regression test: moveFilesToLibrary() already detects and logs failures
        // (disk full, permissions, a destination sanity check, etc.) and returns
        // false rather than throwing, but that result used to be discarded —
        // meaning a failed move (files never actually left the source directory)
        // was still reported as a successful import with the book DB record
        // pointing at an empty/partial target directory.
        $bookRoot = sys_get_temp_dir() . '/book_root_' . uniqid('', true);
        File::makeDirectory($bookRoot, 0775, true);
        config(['app.book_root' => $bookRoot]);
        config(['filesystems.disks.books.root' => $bookRoot]);

        Genre::create(['name' => 'Science Fiction']);

        $metadata = [
            'title' => 'Move Failure Test Book',
            'author' => ['Some Author'],
            'genre' => 'Science Fiction',
            'confidence' => 100,
            'cover_data' => 'fake-jpeg-bytes',
        ];

        $audiobook = [
            'path' => '/tmp/source-book',
            'files' => [],
        ];

        $infoMessages = [];
        $skippedBooks = [];
        $processedBooks = [];

        $book = $this->service->processSingleBook(
            $audiobook,
            $metadata,
            fn ($metadata) => null,
            fn ($metadata, $enrichedData) => false,
            fn ($metadata) => $this->service->generateDirectoryPath($metadata),
            fn ($metadata, $audiobook) => $this->service->createBookFromMetadata($metadata, $audiobook),
            fn ($audiobook, $book, $options) => false, // simulate a failed move (e.g. disk full)
            fn () => 'copy',
            function (string $message) use (&$infoMessages): void {
                $infoMessages[] = $message;
            },
            null,
            null,
            null,
            skipEnrichment: true,
            isAutoMode: true,
            skippedBooks: $skippedBooks,
            processedBooks: $processedBooks,
        );

        $this->assertNotNull($book);
        $this->assertNull($book->cover_image, 'Cover must not be processed for a book whose files were never moved');
        $this->assertEmpty($processedBooks, 'A failed move must not be recorded as a processed/successful import');
        $this->assertNotEmpty($skippedBooks, 'A failed move must be recorded as skipped/failed');
        $this->assertFalse(
            collect($infoMessages)->contains(fn (string $m) => str_contains($m, '✅')),
            'A failed move must never report a success message'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function processCoverImageNoOpsForABookWithNoDirectoryPathYet(): void
    {
        // Regression test: processSingleBook() unconditionally calls
        // processCoverImage() after a successful move. A Book that doesn't have
        // a directory_path (e.g. a caller's own stubbed/constructed Book, as
        // several existing tests do) must not crash — there's simply nowhere to
        // save a cover to yet.
        $book = new \App\Models\Book(['title' => 'No Directory Yet']);

        $this->service->processCoverImage($book, ['cover_data' => 'fake-jpeg-bytes']);

        $this->assertNull($book->cover_image);
    }
}
