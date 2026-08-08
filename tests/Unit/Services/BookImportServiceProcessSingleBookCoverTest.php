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
        // must be the one to actually run that deferred step.
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
            fn ($audiobook, $book, $options) => null, // moveFilesToLibrary stubbed out; cover processing must not depend on it
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
