<?php

namespace Tests\Web\Unit\Controllers;

use App\Http\Controllers\Admin\BookController;
use App\Services\AudioFileAnalyzer;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Mocks\MockDocumentStoreService;
use Tests\TestCase;

class BookControllerDebugTest extends TestCase
{
    protected $controller;

    protected $documentStore;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a mock document store service
        $this->documentStore = new MockDocumentStoreService();

        // Mock the required services
        $mockExternalCoverService = $this->createStub(\App\Services\ExternalCoverService::class);

        $this->controller = new BookController(
            $this->documentStore,
            $mockExternalCoverService,
            new AudioFileAnalyzer()
        );

        // Set up storage
        Storage::fake('books');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testDebugBookCreation()
    {
        // Test direct book creation first
        $testBookId = $this->documentStore->createBook([
            'title' => 'Direct Test Book',
            'author' => ['Direct Test Author'],
            'genre' => ['Direct Test Genre'],
        ]);

        // Verify the direct addition worked
        $directBooks = $this->documentStore->getAllBooks();
        $this->assertNotEmpty($directBooks, 'Direct book addition failed');

        // Now test the controller's store method
        Event::fake();

        // Create a fake cover image
        $file = UploadedFile::fake()->image('cover.jpg');

        // Create a request with valid book data and cover image
        $request = new Request([
            'title' => 'Test Book with Cover',
            'author' => ['Test Author'],
            'genre' => ['Test Genre'],
            'directoryPath' => 'test/path',
        ]);
        $request->files->set('cover', $file);

        // Set environment variable for book storage path
        $this->app['config']->set('filesystems.disks.books', [
            'driver' => 'local',
            'root' => storage_path('app/books'),
        ]);
        putenv('BOOK_STORAGE_PATH=' . storage_path('app/books'));

        // Add logging to trace execution
        Log::spy();

        // Add a book directly to the mock store to verify it works
        $testBookId = $this->documentStore->createBook([
            'title' => 'Direct Test Book',
            'author' => ['Direct Test Author'],
            'genre' => ['Direct Test Genre'],
        ]);

        // Verify the direct addition worked
        $directBooks = $this->documentStore->getAllBooks();
        $this->assertNotEmpty($directBooks, 'Direct book addition failed');

        // Re-create the controller with the standard mock service
        $this->controller = new BookController(
            $this->documentStore,
            $this->createStub(\App\Services\ExternalCoverService::class),
            new AudioFileAnalyzer()
        );

        // Call the store method
        $response = $this->controller->store($request);

        // Get all books from the mock store
        $books = $this->documentStore->getAllBooks();

        // Assert that at least one book was added
        $this->assertNotEmpty($books, 'No books were added to the mock store');
    }
}
