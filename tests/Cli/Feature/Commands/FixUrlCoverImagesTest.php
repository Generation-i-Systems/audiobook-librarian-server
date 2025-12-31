<?php

namespace Tests\Cli\Feature\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FixUrlCoverImagesTest extends TestCase
{
    private $documentStoreMock;
    private string $testDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->documentStoreMock = Mockery::mock(DocumentStoreServiceInterface::class);
        $this->app->instance(DocumentStoreServiceInterface::class, $this->documentStoreMock);

        $this->testDirectory = storage_path('app/test_books/test_book');
        if (!File::exists($this->testDirectory)) {
            File::makeDirectory($this->testDirectory, 0755, true);
        }

        // Set test book root
        config(['app.book_root' => storage_path('app/test_books')]);
    }

    protected function tearDown(): void
    {
        if (File::exists(storage_path('app/test_books'))) {
            File::deleteDirectory(storage_path('app/test_books'));
        }

        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_finds_books_with_url_covers(): void
    {
        $books = [
            [
                'id' => '1',
                'title' => 'Test Book',
                'coverImage' => 'https://example.com/cover.jpg',
                'directoryPath' => 'test_book',
            ],
        ];

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(1, 100, [], false)
            ->andReturn(['data' => $books, 'total' => 1]);

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(2, 100, [], false)
            ->andReturn(['data' => [], 'total' => 0]);

        $this->documentStoreMock
            ->shouldReceive('getBook')
            ->with('1')
            ->andReturn($books[0]);

        $this->documentStoreMock
            ->shouldNotReceive('updateBook');

        $this->artisan('books:fix-url-covers --dry-run')
            ->expectsOutput('Found 1 books with URL covers')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_downloads_and_updates_url_covers(): void
    {
        $fakeImageData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        Http::fake([
            'example.com/cover.jpg' => Http::response($fakeImageData, 200),
        ]);

        $books = [
            [
                'id' => '1',
                'title' => 'Test Book',
                'coverImage' => 'https://example.com/cover.jpg',
                'directoryPath' => 'test_book',
            ],
        ];

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(1, 100, [], false)
            ->andReturn(['data' => $books, 'total' => 1]);

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(2, 100, [], false)
            ->andReturn(['data' => [], 'total' => 0]);

        $this->documentStoreMock
            ->shouldReceive('getBook')
            ->with('1')
            ->andReturn($books[0]);

        $this->documentStoreMock
            ->shouldReceive('updateBook')
            ->once()
            ->with('1', ['cover_image' => 'cover_unknown.jpg']);

        $this->artisan('books:fix-url-covers')
            ->assertExitCode(0);

        $this->assertFileExists($this->testDirectory . '/cover_unknown.jpg');
    }

    #[Test]
    public function it_skips_books_with_local_covers(): void
    {
        $books = [
            [
                'id' => '1',
                'title' => 'Test Book',
                'coverImage' => 'cover.jpg',
                'directoryPath' => 'test_book',
            ],
        ];

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(1, 100, [], false)
            ->andReturn(['data' => $books, 'total' => 1]);

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(2, 100, [], false)
            ->andReturn(['data' => [], 'total' => 0]);

        $this->documentStoreMock
            ->shouldNotReceive('updateBook');

        $this->artisan('books:fix-url-covers')
            ->expectsOutput('Found 0 books with URL covers')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_handles_download_failures_gracefully(): void
    {
        Http::fake([
            'example.com/cover.jpg' => Http::response('', 404),
        ]);

        $books = [
            [
                'id' => '1',
                'title' => 'Test Book',
                'coverImage' => 'https://example.com/cover.jpg',
                'directoryPath' => 'test_book',
            ],
        ];

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(1, 100, [], false)
            ->andReturn(['data' => $books, 'total' => 1]);

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(2, 100, [], false)
            ->andReturn(['data' => [], 'total' => 0]);

        $this->documentStoreMock
            ->shouldReceive('getBook')
            ->with('1')
            ->andReturn($books[0]);

        $this->documentStoreMock
            ->shouldNotReceive('updateBook');

        $this->artisan('books:fix-url-covers')
            ->expectsOutput('Failed to fix: 1')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_respects_limit_option(): void
    {
        $books = [
            ['id' => '1', 'title' => 'Book 1', 'coverImage' => 'https://example.com/1.jpg', 'directoryPath' => 'test_book'],
            ['id' => '2', 'title' => 'Book 2', 'coverImage' => 'https://example.com/2.jpg', 'directoryPath' => 'test_book'],
        ];

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(1, 100, [], false)
            ->andReturn(['data' => $books, 'total' => 2]);

        $this->documentStoreMock
            ->shouldReceive('getBook')
            ->with('1')
            ->once()
            ->andReturn($books[0]);

        $this->documentStoreMock
            ->shouldNotReceive('updateBook');

        $this->artisan('books:fix-url-covers --dry-run --limit=1')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_detects_audible_source_from_url(): void
    {
        $fakeImageData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        Http::fake([
            'amazon.com/*' => Http::response($fakeImageData, 200),
        ]);

        $books = [
            [
                'id' => '1',
                'title' => 'Test Book',
                'coverImage' => 'https://m.media-amazon.com/images/I/test.jpg',
                'directoryPath' => 'test_book',
            ],
        ];

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(1, 100, [], false)
            ->andReturn(['data' => $books, 'total' => 1]);

        $this->documentStoreMock
            ->shouldReceive('listBooks')
            ->with(2, 100, [], false)
            ->andReturn(['data' => [], 'total' => 0]);

        $this->documentStoreMock
            ->shouldReceive('getBook')
            ->with('1')
            ->andReturn($books[0]);

        $this->documentStoreMock
            ->shouldReceive('updateBook')
            ->once()
            ->with('1', ['cover_image' => 'cover_audible.jpg']);

        $this->artisan('books:fix-url-covers')
            ->assertExitCode(0);

        $this->assertFileExists($this->testDirectory . '/cover_audible.jpg');
    }
}
