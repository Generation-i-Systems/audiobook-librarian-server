<?php

namespace Tests\Web\Unit\Controllers;

use App\Http\Controllers\Admin\BookController;
use App\Services\AudioFileAnalyzer;
use App\Services\ExternalCoverService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Mocks\MockDocumentStoreService;
use Tests\TestCase;

class BookControllerCoverImageTest extends TestCase
{
    private BookController $controller;

    private MockDocumentStoreService $documentStore;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock services
        $this->documentStore = new MockDocumentStoreService();
        $externalCoverService = $this->createStub(ExternalCoverService::class);

        $this->controller = new BookController(
            $this->documentStore,
            $externalCoverService,
            new AudioFileAnalyzer()
        );

        // Ensure the controller uses our mock document store
        $this->controller->setDocumentStoreService($this->documentStore);

        // Set up storage fake for file uploads
        Storage::fake('books');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_cover_image_upload_debug()
    {
        // Enable debug logging
        Log::spy();

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

        // Add a book directly to verify the mock store works
        $this->documentStore->createBook([
            'title' => 'Direct Test Book',
            'author' => ['Direct Test Author'],
            'genre' => ['Direct Test Genre'],
        ]);

        // Verify the direct addition worked
        $directBooks = $this->documentStore->getAllBooks();
        $this->assertNotEmpty($directBooks, 'Direct book addition failed');
        // echo "\nDirect books: " . json_encode($directBooks) . "\n";

        // Call the store method
        // echo "\nBefore store method call\n";
        // echo 'Request has file: ' . ($request->hasFile('cover') ? 'true' : 'false') . "\n";
        // echo 'File is valid: ' . ($file->isValid() ? 'true' : 'false') . "\n";
        // echo 'File extension: ' . $file->getClientOriginalExtension() . "\n";
        // echo 'Storage path: ' . env('BOOK_STORAGE_PATH') . "\n";

        $response = $this->controller->store($request);

        // Debug output
        // echo "\nAfter store method call\n";
        // echo 'Response: ' . json_encode($response) . "\n";

        // Get all books from the mock store
        $books = $this->documentStore->getAllBooks();
        // echo 'Books after store: ' . json_encode($books) . "\n";
        // echo 'Raw books array: ' . json_encode($this->documentStore->dumpAllBooks()) . "\n";

        // // Output logs
        // $logs = Log::logged();
        // echo 'Logs: ' . json_encode($logs) . "\n";
    }
}
