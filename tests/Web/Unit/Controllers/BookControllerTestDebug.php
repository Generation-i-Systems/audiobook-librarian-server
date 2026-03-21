<?php

namespace Tests\Web\Unit\Controllers;

use App\Http\Controllers\Admin\BookController;
use App\Services\AudioFileAnalyzer;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Mocks\MockDocumentStoreService;
use Tests\TestCase;

class BookControllerTestDebug extends TestCase
{
    protected $controller;

    protected $documentStore;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a mock document store service
        $this->documentStore = new MockDocumentStoreService();

        // Mock the required services
        $mockExternalCoverService = $this->createMock(\App\Services\ExternalCoverService::class);

        // Create the controller with the mock services
        $this->controller = new BookController(
            $this->documentStore,
            $mockExternalCoverService,
            new AudioFileAnalyzer()
        );

        // Set up storage
        Storage::fake('books');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_debug_store_method()
    {
        // Mock the event dispatcher
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

        // Verify the initial state
        $initialBooks = $this->documentStore->getAllBooks();
        $this->assertEmpty($initialBooks, 'Mock store should be empty before test');

        // Call the store method
        $response = $this->controller->store($request);

        // Debug the books after store
        $rawBooks = $this->documentStore->dumpAllBooks();
        $booksArray = $this->documentStore->getAllBooks();

        // Output debug info
        echo 'Raw books array: ' . json_encode($rawBooks) . "\n";
        echo 'Books array from getAllBooks: ' . json_encode($booksArray) . "\n";

        // Assert that at least one book was added
        $this->assertNotEmpty($booksArray, 'No books were added to the mock store');

        if (!empty($booksArray)) {
            $book = $booksArray[0];
            // Assert that cover image was processed
            $this->assertArrayHasKey('coverImage', $book, 'Book is missing coverImage key');
            $this->assertEquals('cover.jpg', $book['coverImage'], 'Cover image name does not match');
        }
    }
}
