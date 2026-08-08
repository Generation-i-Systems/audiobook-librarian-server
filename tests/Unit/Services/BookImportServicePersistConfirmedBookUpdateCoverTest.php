<?php

namespace Tests\Unit\Services;

use App\Models\Book;
use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use App\Support\ConfirmedBookMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BookImportServicePersistConfirmedBookUpdateCoverTest extends TestCase
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
    public function persistConfirmedBookUpdateDoesNotPrematurelyCreateTheDirectoryOrCoverFile(): void
    {
        // Regression test: persistConfirmedBookUpdate() used to save the cover inline,
        // which mkdir()'d the target directory (via saveEmbeddedCover) before
        // moveFilesToLibrary() ever ran. moveFilesToLibrary() then found that
        // directory already existing and raised a false "directory already exists"
        // conflict — against a directory this same import had just created moments
        // earlier for the very same book. Cover processing must stay deferred to
        // processCoverImage(), called only after files are moved.
        $bookRoot = sys_get_temp_dir() . '/book_root_' . uniqid('', true);
        File::makeDirectory($bookRoot, 0775, true);
        config(['app.book_root' => $bookRoot]);
        config(['filesystems.disks.books.root' => $bookRoot]);

        $book = Book::create([
            'title' => 'Skeleton Key',
            'directory_path' => 'Kids/Anthony Horowitz/Alex Rider/03 Skeleton Key',
            'language' => 'en',
        ]);

        $metadata = [
            'title' => 'Skeleton Key',
            'cover_data' => 'fake-jpeg-bytes',
        ];

        $this->service->persistConfirmedBookUpdate(
            $book,
            ConfirmedBookMetadata::fromConfirmed($metadata),
            ['files' => []]
        );

        $targetDir = $bookRoot . '/' . $book->directory_path;

        $this->assertDirectoryDoesNotExist(
            $targetDir,
            'persistConfirmedBookUpdate() must not create the target directory before files are moved'
        );
        $this->assertNull(
            $book->fresh()->cover_image,
            'Cover processing must stay deferred to processCoverImage()'
        );

        // The deferred step, run afterward (as every real caller does, whether or
        // not it also moves files first) — this is what actually saves the cover.
        $this->service->processCoverImage($book, $metadata);

        $this->assertFileExists($targetDir . '/cover.jpg');
        $this->assertSame('cover.jpg', $book->fresh()->cover_image);
    }
}
