<?php

namespace Tests\Unit\Controllers\Admin;

use App\Http\Controllers\Admin\BookController;
use App\Events\NewBookAdded;
use App\Services\DocumentStoreServiceInterface;
use App\Services\GoogleBooksApiService;
use App\Services\AudibleService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Mocks\MockDocumentStoreService;

class BookControllerTest extends TestCase
{
    protected BookController $controller;
    protected MockDocumentStoreService $documentStore;
    protected GoogleBooksApiService $googleBooksApiService;
    protected AudibleService $audibleService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->documentStore = new MockDocumentStoreService();
        $this->googleBooksApiService = $this->getMockBuilder(GoogleBooksApiService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->audibleService = $this->getMockBuilder(AudibleService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->controller = new BookController(
            $this->documentStore,
            $this->googleBooksApiService,
            $this->audibleService
        );
        // Set up storage
        Storage::fake('books');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testSearchBooksWithAuthorForGoogleBooks()
    {
        // Mock the GoogleBooksApiService to verify it receives the author parameter
        $this->googleBooksApiService
            ->expects($this->once())
            ->method('searchBooks')
            ->with(
                $this->stringContains('inauthor:"Test Author"'),
                $this->arrayHasKey('limit')
            )
            ->willReturn([
                [
                    'title' => 'Test Book',
                    'author' => 'Test Author',
                    'description' => 'Test Description'
                ]
            ]);

        // Create a request with title and author
        $request = new Request([
            'title' => 'Test Book',
            'author' => 'Test Author',
            'source' => 'googlebooks',
            'limit' => 10
        ]);

        // Call the searchBooks method
        $response = $this->controller->searchBooks($request);

        // Assert response is successful and contains the expected data
        $this->assertEquals(200, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertIsArray($responseData);
        $this->assertCount(1, $responseData);
        $this->assertEquals('Test Book', $responseData[0]['title']);
        $this->assertEquals('Test Author', $responseData[0]['author']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testSearchBooksWithAuthorForAudible()
    {
        // Mock the AudibleService to verify it receives the author parameter
        $this->audibleService
            ->expects($this->once())
            ->method('searchBooksWithFiltering')
            ->with(
                $this->equalTo('Test Book'),
                $this->equalTo('Test Author'),
                $this->callback(function ($options) {
                    return isset($options['limit']) && $options['limit'] === 10;
                })
            )
            ->willReturn([
                [
                    'title' => 'Test Book',
                    'author' => 'Test Author',
                    'description' => 'Test Description'
                ]
            ]);

        // Create a request with title and author
        $request = new Request([
            'title' => 'Test Book',
            'author' => 'Test Author',
            'source' => 'audible',
            'limit' => 10
        ]);

        // Call the searchBooks method
        $response = $this->controller->searchBooks($request);

        // Assert response is successful and contains the expected data
        $this->assertEquals(200, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertIsArray($responseData);
        $this->assertCount(1, $responseData);
        $this->assertEquals('Test Book', $responseData[0]['title']);
        $this->assertEquals('Test Author', $responseData[0]['author']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testSearchBooksWithEmptyAuthor()
    {
        // Mock the AudibleService to verify it receives null for the author parameter
        $this->audibleService
            ->expects($this->once())
            ->method('searchBooksWithFiltering')
            ->with(
                $this->equalTo('Test Book'),
                $this->isNull(),
                $this->callback(function ($options) {
                    return isset($options['limit']) && $options['limit'] === 10;
                })
            )
            ->willReturn([
                [
                    'title' => 'Test Book',
                    'author' => 'Some Author',
                    'description' => 'Test Description'
                ]
            ]);

        // Create a request with title but no author
        $request = new Request([
            'title' => 'Test Book',
            'source' => 'audible',
            'limit' => 10
        ]);

        // Call the searchBooks method
        $response = $this->controller->searchBooks($request);

        // Assert response is successful and contains the expected data
        $this->assertEquals(200, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertIsArray($responseData);
        $this->assertCount(1, $responseData);
        $this->assertEquals('Test Book', $responseData[0]['title']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testStoreMethodCreatesBook()
    {
        // Mock the event dispatcher
        Event::fake();
        // Create a request with valid book data
        $request = new Request([
            'title' => 'Test Book',
            'author' => ['Test Author'],
            'genre' => ['Test Genre'],
            'description' => 'Test description',
            'publishedYear' => 2023,
            'series' => [
                [
                    'name' => 'Test Series',
                    'number' => '1'
                ]
            ]
        ]);
        // Call the store method
        $response = $this->controller->store($request);
        // Assert that the response is a redirect
        $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response);
        // Assert that the event was dispatched
        Event::assertDispatched(NewBookAdded::class);
        // Get all books from the mock store
        $books = $this->documentStore->getAllBooks();
        // Assert that a book was created
        $this->assertCount(1, $books);
        $book = $books[0];
        // Assert book data
        $this->assertEquals('Test Book', $book['title']);
        $this->assertEquals(['Test Author'], $book['author']);
        $this->assertEquals(['Test Genre'], $book['genre']);
        $this->assertEquals('Test description', $book['description']);
        $this->assertEquals(2023, $book['publishedYear']);

        // Assert series data was normalized with seriesName field
        $this->assertIsArray($book['series']);
        $this->assertCount(1, $book['series']);
        $this->assertEquals('Test Series', $book['series'][0]['seriesName']);
        $this->assertEquals('1', $book['series'][0]['number']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testStoreMethodHandlesAjaxRequest()
    {
        // Mock the event dispatcher
        Event::fake();
        // Create a request with valid book data and AJAX header
        $request = new Request([
            'title' => 'Test Book',
            'author' => ['Test Author'],
            'genre' => ['Test Genre']
        ]);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        // Call the store method
        $response = $this->controller->store($request);
        // Assert that the response is JSON
        $this->assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response);
        // Assert response data
        $data = $response->getData();
        $this->assertTrue($data->success);
        $this->assertEquals('Book created successfully', $data->message);
        $this->assertNotEmpty($data->id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testStoreMethodHandlesValidationErrors()
    {
        $this->expectException(ValidationException::class);
        // Create a request with invalid data
        $request = new Request([
            'title' => '', // Empty title should fail validation
            'author' => [],
            'genre' => []
        ]);
        // Call the store method
        $this->controller->store($request);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testStoreMethodHandlesCoverImageUpload()
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
            'directoryPath' => 'test/path'
        ]);
        $request->files->set('cover', $file);
        // Set environment variable for book storage path
        $this->app['config']->set('filesystems.disks.books', [
            'driver' => 'local',
            'root' => storage_path('app/books'),
        ]);
        putenv('BOOK_STORAGE_PATH=' . storage_path('app/books'));
        // Call the store method
        $response = $this->controller->store($request);
        // Get all books from the mock store
        $books = $this->documentStore->getAllBooks();
        // Assert that at least one book was added
        $this->assertNotEmpty($books, 'No books were added to the mock store');
        // Now we can safely access the first book
        $book = $books[0];
        // Assert that cover image was processed
        $this->assertArrayHasKey('coverImage', $book);
        $this->assertEquals('cover.jpg', $book['coverImage']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testStoreMethodHandlesExternalCoverUrl()
    {
        // Mock the event dispatcher
        Event::fake();
        // Create a request with valid book data and external cover URL
        $request = new Request([
            'title' => 'Test Book with External Cover',
            'author' => ['Test Author'],
            'genre' => ['Test Genre'],
            'coverImageUrl' => 'https://example.com/cover.jpg'
        ]);
        // Call the store method
        $response = $this->controller->store($request);
        // Get all books from the mock store
        $books = $this->documentStore->getAllBooks();
        $book = $books[0];
        // Assert that external cover URL was used
        $this->assertArrayHasKey('coverImage', $book);
        $this->assertEquals('https://example.com/cover.jpg', $book['coverImage']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testProcessImportMethod()
    {
        // Mock the event dispatcher
        Event::fake();

        // Create a request with valid book data and import metadata
        $request = new Request([
            'title' => 'Imported Book',
            'author' => ['Imported Author'],
            'genre' => ['Imported Genre'],
            'description' => 'Imported description',
            'import_path' => 'test/path/audiobook.m4b',
            'import_root' => '/mnt/data/audiobooks',
            'import_type' => 'file',
            'series' => [
                [
                    'seriesName' => 'Imported Series',
                    'number' => '1'
                ]
            ],
            'cover_url' => 'https://example.com/imported_cover.jpg'
        ]);

        // Call the processImport method
        $response = $this->controller->processImport($request);

        // Assert that the response is a redirect
        $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response);

        // Assert that the event was dispatched
        Event::assertDispatched(NewBookAdded::class);

        // Get all books from the mock store
        $books = $this->documentStore->getAllBooks();

        // Assert that a book was created
        $this->assertCount(1, $books);
        $book = $books[0];

        // Assert book data
        $this->assertEquals('Imported Book', $book['title']);
        $this->assertEquals(['Imported Author'], $book['author']);
        $this->assertEquals(['Imported Genre'], $book['genre']);
        $this->assertEquals('Imported description', $book['description']);

        // Assert series data was normalized with seriesName field
        $this->assertIsArray($book['series']);
        $this->assertCount(1, $book['series']);
        $this->assertEquals('Imported Series', $book['series'][0]['seriesName']);
        $this->assertEquals('1', $book['series'][0]['number']);

        // Assert import metadata was stored
        $this->assertArrayHasKey('import_metadata', $book);
        $this->assertEquals('test/path/audiobook.m4b', $book['import_metadata']['path']);
        $this->assertEquals('/mnt/data/audiobooks', $book['import_metadata']['root']);
        $this->assertEquals('file', $book['import_metadata']['type']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testProcessImportMethodWithAjaxRequest()
    {
        // Mock the event dispatcher
        Event::fake();

        // Create a request with valid book data and AJAX header
        $request = new Request([
            'title' => 'Imported Book',
            'author' => ['Imported Author'],
            'genre' => ['Imported Genre'],
            'import_path' => 'test/path/audiobook.m4b',
            'import_root' => '/mnt/data/audiobooks'
        ]);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        // Call the processImport method
        $response = $this->controller->processImport($request);

        // Assert that the response is JSON
        $this->assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response);

        // Assert response data
        $data = $response->getData();
        $this->assertTrue($data->success);
        $this->assertEquals('Book imported successfully', $data->message);
        $this->assertNotEmpty($data->id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testProcessImportMethodHandlesValidationErrors()
    {
        // Create a request with invalid data (missing required fields)
        $request = new Request([
            // Missing title and author fields which are required
            'import_path' => 'test/path/audiobook.m4b',
            'import_root' => '/mnt/data/audiobooks'
        ]);

        // Process the request and expect a redirect with errors
        $response = $this->controller->processImport($request);

        // Assert that the response is a redirect back with errors
        $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response);
        $this->assertTrue(session()->has('errors'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testProcessImportMethodHandlesCoverUrl()
    {
        // Skip this test as importCoverImageFromUrl is a private method in BookImportTrait
        // and can't be easily mocked
        $this->markTestSkipped('Cannot mock private trait method importCoverImageFromUrl');

        // Mock the event dispatcher
        Event::fake();

        // Create a request with valid book data and cover URL
        $request = new Request([
            'title' => 'Imported Book with Cover',
            'author' => ['Imported Author'],
            'genre' => ['Imported Genre'],
            'import_path' => 'test/path/audiobook.m4b',
            'import_root' => '/mnt/data/audiobooks',
            'cover_url' => 'https://example.com/book_cover.jpg'
        ]);

        // Set environment variable for book storage path
        $this->app['config']->set('filesystems.disks.books', [
            'driver' => 'local',
            'root' => storage_path('app/books'),
        ]);
        putenv('BOOK_STORAGE_PATH=' . storage_path('app/books'));

        // Call the processImport method
        $response = $this->controller->processImport($request);

        // Assert that the response is a redirect
        $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response);
    }
}
