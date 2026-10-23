<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Book;
use App\Services\BookImportService;
use App\Services\GenreMappingService;
use App\Services\SourceTrashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Fix previous import" lets the user change an imported book's metadata, which can
 * change its directory path (e.g. adding a series number). The folder must move with
 * it, otherwise the database points at a path with no files.
 */
class BookImportServiceMoveLibraryDirectoryForConfirmedPathTest extends TestCase
{
    use RefreshDatabase;

    private BookImportService $service;

    private string $oldPath = "Action/Charlie Higson & Ian Fleming/James Bond/On His Majesty's Secret Service";

    private string $newPath = "Action/Charlie Higson & Ian Fleming/James Bond/01 On His Majesty's Secret Service";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('books');
        $this->service = new BookImportService(
            $this->app->make(GenreMappingService::class),
            $this->app->make(SourceTrashService::class)
        );
    }

    #[Test]
    public function movesFolderToConfirmedPath(): void
    {
        $disk = Storage::disk('books');
        $disk->put($this->oldPath . '/book.m4b', 'audio');
        $book = Book::factory()->create(['directory_path' => $this->oldPath]);

        $error = $this->service->moveLibraryDirectoryForConfirmedPath($book, $this->newPath);

        $this->assertSame(
            ['error' => null, 'new' => true, 'old' => false],
            [
                'error' => $error,
                'new' => $disk->exists($this->newPath . '/book.m4b'),
                'old' => $disk->exists($this->oldPath . '/book.m4b'),
            ]
        );
    }

    #[Test]
    public function returnsNullWithoutTouchingDiskWhenPathUnchanged(): void
    {
        $disk = Storage::disk('books');
        $disk->put($this->oldPath . '/book.m4b', 'audio');
        $book = Book::factory()->create(['directory_path' => $this->oldPath]);

        $this->assertNull($this->service->moveLibraryDirectoryForConfirmedPath($book, $this->oldPath . '/'));
        $this->assertTrue($disk->exists($this->oldPath . '/book.m4b'));
    }

    #[Test]
    public function returnsErrorWhenTargetAlreadyHasAudio(): void
    {
        $disk = Storage::disk('books');
        $disk->put($this->oldPath . '/book.m4b', 'audio');
        $disk->put($this->newPath . '/other.m4b', 'audio');
        $book = Book::factory()->create(['directory_path' => $this->oldPath]);

        $error = $this->service->moveLibraryDirectoryForConfirmedPath($book, $this->newPath);

        $this->assertNotNull($error);
        $this->assertTrue($disk->exists($this->oldPath . '/book.m4b'));
    }

    #[Test]
    public function returnsErrorWhenOldFolderIsMissing(): void
    {
        $book = Book::factory()->create(['directory_path' => $this->oldPath]);

        $this->assertSame(
            'Book folder not found: ' . $this->oldPath,
            $this->service->moveLibraryDirectoryForConfirmedPath($book, $this->newPath)
        );
    }
}
