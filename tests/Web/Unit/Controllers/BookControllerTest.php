<?php

namespace Tests\Web\Unit\Controllers;

use App\Contracts\DocumentStoreServiceInterface;
use App\Events\NewBookAdded;
use App\Http\Controllers\Admin\BookController;
use App\Http\Controllers\Admin\BookImportController;
use App\Http\Controllers\Admin\BookMetadataSearchController;
use App\Http\Controllers\Admin\ImportFileController;
use App\Services\AudibleService;
use App\Services\BookImportService;
use App\Services\AudioFileAnalyzer;
use App\Services\ExternalCoverService;
use App\Services\GoogleBooksApiService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Mockery;
use Tests\TestCase;

class BookControllerTest extends TestCase
{
    protected BookController $controller;

    protected BookImportController $importController;

    protected BookMetadataSearchController $metadataSearchController;

    protected $documentStore;

    protected $googleBooksApiService;

    protected $audibleService;

    protected $externalCoverService;

    protected $importFileController;

    protected $storedBooks = [];

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Create a mock document store service with stored books
        $this->storedBooks = [];
        $this->documentStore = Mockery::mock(DocumentStoreServiceInterface::class);

        $storedBooks = &$this->storedBooks;

        $this->documentStore->shouldReceive('getAllBooks')->andReturnUsing(function () use (&$storedBooks) {
            return $storedBooks;
        });

        // Mock the storeBook method
        $this->documentStore->shouldReceive('storeBook')
            ->andReturnUsing(function ($bookData) use (&$storedBooks) {
                $id = 'book-' . uniqid();
                $bookData['id'] = $id;
                $storedBooks[$id] = $bookData;
                return $id;
            });

        // Mock the createBook method - this is what the controller actually calls
        $this->documentStore->shouldReceive('createBook')->byDefault()
            ->andReturnUsing(function ($bookData) use (&$storedBooks) {
                $id = $bookData['id'] ?? ('book-' . uniqid());
                $bookData['id'] = $id;
                $storedBooks[$id] = $bookData;
                return $id;
            });

        $this->documentStore->shouldReceive('updateBook')->byDefault()->andReturnUsing(function ($id, $book) use (&$storedBooks) {
            if (isset($storedBooks[$id])) {
                $storedBooks[$id] = array_merge($storedBooks[$id], $book);
                return ['success' => true];
            }
            return ['success' => false];
        });

        $this->documentStore->shouldReceive('deleteBook')->andReturnUsing(function ($id) use (&$storedBooks) {
            if (isset($storedBooks[$id])) {
                unset($storedBooks[$id]);
                return ['success' => true];
            }
            return ['success' => false];
        });

        $this->documentStore->shouldReceive('getBook')->byDefault()->andReturnUsing(function ($id) use (&$storedBooks) {
            return $storedBooks[$id] ?? null;
        });

        // Mock the Google Books API service
        $this->googleBooksApiService = Mockery::mock(GoogleBooksApiService::class);
        $this->googleBooksApiService->shouldReceive('searchBooks')->andReturnUsing(function ($query, $options = []) {
            Log::debug('GoogleBooksApiService mock called', ['query' => $query, 'options' => $options]);
            return [
                ['title' => 'Test Book', 'author' => 'Test Author'],
            ];
        });

        // Mock the Audible service
        $this->audibleService = Mockery::mock(AudibleService::class);
        $this->audibleService->shouldReceive('searchBooks')->andReturnUsing(function () {
            return [
                ['title' => 'Test Book', 'author' => 'Test Author'],
            ];
        });
        // Note: searchBooksWithFiltering expectations are set up in individual tests

        // Mock the external cover service
        $this->externalCoverService = Mockery::mock(ExternalCoverService::class);
        $this->externalCoverService->shouldReceive('downloadCoverImage')->andReturn('/path/to/cover.jpg');

        // Mock the import file controller
        $this->importFileController = Mockery::mock(ImportFileController::class);
        $this->importFileController->shouldReceive('processImportedFile')->andReturn(true);

        $this->controller = new BookController(
            $this->documentStore,
            $this->externalCoverService,
            new AudioFileAnalyzer()
        );

        $this->importController = new BookImportController(
            $this->documentStore
        );

        $this->metadataSearchController = new BookMetadataSearchController(
            $this->googleBooksApiService,
            $this->audibleService
        );

        // Set up storage
        Storage::fake('books');
        Storage::fake('covers');

        // Reset URL generator to use localhost consistently in tests
        config(['app.url' => 'http://localhost']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testSearchBooksAuthorForGoogleBooksApi()
    {
        // Create a request with author parameter for Google Books
        $request = new Request([
            'author' => 'Test Author',
            'source' => 'googlebooks',
            'limit' => 10,
        ]);

        // Set up the mock to return specific data for this test
        $this->googleBooksApiService = Mockery::mock(GoogleBooksApiService::class);
        $this->googleBooksApiService->shouldReceive('searchBooks')
            ->with(Mockery::type('string'), Mockery::type('array'))
            ->once()
            ->andReturn([
                ['title' => 'Test Book', 'author' => 'Test Author'],
            ]);

        // Recreate the search controller with the updated mock
        $this->metadataSearchController = new BookMetadataSearchController(
            $this->googleBooksApiService,
            $this->audibleService
        );

        // Call the searchBooks method
        $response = $this->metadataSearchController->searchBooks($request);

        // Assert that the response is a JSON response
        $this->assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response);

        // Assert that the response contains the expected data
        $data = json_decode($response->getContent(), true);
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        $this->assertEquals('Test Book', $data[0]['title']);
        $this->assertEquals('Test Author', $data[0]['author']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testSearchBooksAuthorForGoogleBooks()
    {
        // Create a request with title and author
        $request = new Request([
            'title' => 'Test Book',
            'author' => 'Test Author',
            'source' => 'googlebooks',
            'limit' => 10,
        ]);

        // Call the searchBooks method
        $response = $this->metadataSearchController->searchBooks($request);

        // Assert response is successful and contains the expected data
        $this->assertEquals(200, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);

        // Debug: Log the actual response content
        Log::debug('Google Books test response', [
            'status' => $response->getStatusCode(),
            'content' => $response->getContent(),
            'responseData' => $responseData,
        ]);

        $this->assertIsArray($responseData);
        $this->assertNotEmpty($responseData);
        $this->assertEquals('Test Book', $responseData[0]['title']);
        $this->assertEquals('Test Author', $responseData[0]['author']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testSearchBooksAuthorForAudible()
    {
        // Mock the AudibleService to verify it receives the author parameter
        $this->audibleService->shouldReceive('searchBooksWithFiltering')
            ->once()
            ->withArgs(function ($title, $author, $options) {
                return $title === 'Test Book' && $author === 'Test Author' && isset($options['limit']) && $options['limit'] === 10;
            })
            ->andReturn([
                [
                    'title' => 'Test Book',
                    'author' => 'Test Author',
                    'description' => 'Test Description',
                ],
            ]);

        // Create a request with title and author
        $request = new Request([
            'title' => 'Test Book',
            'author' => 'Test Author',
            'source' => 'audible',
            'limit' => 10,
        ]);

        // Call the searchBooks method
        $response = $this->metadataSearchController->searchBooks($request);

        // Assert response is successful and contains the expected data
        $this->assertEquals(200, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertIsArray($responseData);
        $this->assertCount(1, $responseData);
        $this->assertEquals('Test Book', $responseData[0]['title']);
        $this->assertEquals('Test Author', $responseData[0]['author']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testSearchBooksEmptyAuthor()
    {
        // Mock the AudibleService to verify it receives null for the author parameter
        $this->audibleService->shouldReceive('searchBooksWithFiltering')
            ->once()
            ->withArgs(function ($title, $author, $options) {
                return $title === 'Test Book' && $author === null && isset($options['limit']) && $options['limit'] === 10;
            })
            ->andReturn([
                [
                    'title' => 'Test Book',
                    'author' => 'Some Author',
                    'description' => 'Test Description',
                ],
            ]);

        // Create a request with title but no author
        $request = new Request([
            'title' => 'Test Book',
            'source' => 'audible',
            'limit' => 10,
        ]);

        // Call the searchBooks method
        $response = $this->metadataSearchController->searchBooks($request);

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
            'authors' => ['Test Author'],
            'narrators' => ['Test Narrator'],
            'genres' => ['Test Genre'],
            'series' => [
                ['seriesName' => 'Test Series', 'number' => '1'],
            ],
            'description' => 'Test description',
        ]);

        // Set up specific mocks for this test
        $this->documentStore->shouldReceive('findOrCreateMany')
            ->with('authors', ['Test Author'])
            ->once()
            ->andReturn(['author-id-1']);

        $this->documentStore->shouldReceive('findOrCreateMany')
            ->with('narrators', ['Test Narrator'])
            ->once()
            ->andReturn(['narrator-id-1']);

        $this->documentStore->shouldReceive('findOrCreateMany')
            ->with('genres', ['Test Genre'])
            ->once()
            ->andReturn(['genre-id-1']);

        $this->documentStore->shouldReceive('getSeriesByName')
            ->with('Test Series')
            ->once()
            ->andReturn(null);

        $this->documentStore->shouldReceive('createSeries')
            ->with('Test Series', false)
            ->once()
            ->andReturn('series-id-1');

        // Call the store method
        $response = $this->controller->store($request, $this->importFileController);

        // Assert that the response is a redirect
        $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response);

        // Get all books from the mock store
        $books = $this->storedBooks;

        // Assert that a book was created
        $this->assertNotEmpty($books, 'No books were added to the mock store');

        // Get the created book
        $book = reset($books);

        // Assert book data
        $this->assertEquals('Test Book', $book['title']);
        $this->assertEquals(['author-id-1'], $book['authors']);
        $this->assertEquals(['narrator-id-1'], $book['narrators']);
        $this->assertEquals(['genre-id-1'], $book['genres']);
        $this->assertIsArray($book['series']);
        $this->assertCount(1, $book['series']);
        $this->assertEquals('Test Series', $book['series'][0]['name']);
        $this->assertEquals('1', $book['series'][0]['number']);
        $this->assertEquals('Test description', $book['description']);

        // Assert that the NewBookAdded event was dispatched with the correct book ID
        Event::assertDispatched(NewBookAdded::class, function ($event) use ($book) {
            return $event->book['id'] === $book['id'];
        });
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testStoreMethodHandlesAjaxRequest()
    {
        // Mock the event dispatcher
        Event::fake();

        // Create a request with valid book data and AJAX header
        $request = new Request([
            'title' => 'Test Book',
            'authors' => ['Test Author'],
            'narrators' => ['Test Narrator'],
            'genres' => ['Test Genre'],
            'description' => 'Test description',
        ]);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        // Set up specific mocks for this test
        $this->documentStore->shouldReceive('findOrCreateMany')
            ->with('authors', ['Test Author'])
            ->once()
            ->andReturn(['author-id-1']);

        $this->documentStore->shouldReceive('findOrCreateMany')
            ->with('narrators', ['Test Narrator'])
            ->once()
            ->andReturn(['narrator-id-1']);

        $this->documentStore->shouldReceive('findOrCreateMany')
            ->with('genres', ['Test Genre'])
            ->once()
            ->andReturn(['genre-id-1']);

        // Mock series processing methods
        $this->documentStore->shouldReceive('getSeriesByName')
            ->andReturn(null); // No existing series
        $this->documentStore->shouldReceive('createSeries')
            ->andReturn('series-id-1');



        // Call the store method
        $response = $this->controller->store($request, $this->importFileController);

        // The controller should return a redirect response even for AJAX requests on success
        $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response);

        // Get all books from the mock store
        $books = $this->storedBooks;

        // Assert that a book was created
        $this->assertNotEmpty($books, 'No books were added to the mock store');

        // Get the created book
        $book = reset($books);

        // Assert book data
        $this->assertEquals('Test Book', $book['title']);
        $this->assertEquals(['author-id-1'], $book['authors']);
        $this->assertEquals(['narrator-id-1'], $book['narrators']);
        $this->assertEquals(['genre-id-1'], $book['genres']);

        // Test that validation errors return a JSON response for AJAX requests
        $invalidRequest = new Request([], [], [], [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest'], json_encode([
            'title' => '', // Invalid: title is required
            'authors' => ['Test Author'],
            'genres' => ['Test Genre'],
        ]));
        $invalidRequest->headers->set('Content-Type', 'application/json');
        $invalidRequest->setMethod('POST');

        $response = $this->controller->store($invalidRequest, $this->importFileController);
        $this->assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response);
        $this->assertEquals(422, $response->status());
        $responseData = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('errors', $responseData);
        $this->assertArrayHasKey('title', $responseData['errors']);
    }

    #\PHPUnit\Framework\Attributes\Test
    public function testStoreMethodHandlesValidationErrors()
    {
        // Test with non-AJAX request
        $request = new Request([
            'title' => '', // Empty title should fail validation
            'authors' => [],
            'genres' => [],
            'sourceType' => 'file',
            'directoryPath' => 'test/path',
        ]);

        $response = $this->controller->store($request, $this->importFileController);

        // Should redirect back with errors
        $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response);
        $this->assertTrue(session()->has('errors'));

        // Test with AJAX request
        $ajaxRequest = new Request([], [], [], [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        $ajaxRequest->merge([
            'title' => '', // Empty title should fail validation
            'authors' => [],
            'genres' => [],
            'sourceType' => 'file',
            'directoryPath' => 'test/path',
        ]);
        $ajaxRequest->headers->set('Content-Type', 'application/json');
        $ajaxRequest->setMethod('POST');

        $response = $this->controller->store($ajaxRequest, $this->importFileController);

        // Should return JSON response with 422 status
        $this->assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response);
        $this->assertEquals(422, $response->status());
        $responseData = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('errors', $responseData);
        $this->assertArrayHasKey('title', $responseData['errors']);
    }

    #\PHPUnit\Framework\Attributes\Test
    public function testStoreMethodHandlesCoverImageUpload()
    {
        // Fake events
        Event::fake();

        // Create a fake uploaded file
        $uploadedFile = UploadedFile::fake()->image('cover.jpg');

        // Create a request with valid book data and cover image
        $request = new Request([
            'title' => 'Test Book with Cover',
            'authors' => ['Test Author'],
            'narrators' => [],
            'genres' => ['Test Genre'],
            'cover' => $uploadedFile,
            'sourceType' => 'file',
            'directoryPath' => 'test/path',
        ]);

        // Mock the storage facade
        $storage = Storage::fake('public');

        // Mock the document store methods
        $this->documentStore->shouldReceive('findOrCreateMany')
            ->with('authors', ['Test Author'])
            ->once()
            ->andReturn(['author-id-1']);

        $this->documentStore->shouldReceive('findOrCreateMany')
            ->with('narrators', [])
            ->once()
            ->andReturn([]);

        $this->documentStore->shouldReceive('findOrCreateMany')
            ->with('genres', ['Test Genre'])
            ->once()
            ->andReturn(['genre-id-1']);

        // Call the store method
        $response = $this->controller->store($request, $this->importFileController);

        // Assert the response is a redirect to the book's edit page
        $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response);

        // Get the stored book to verify the redirect URL
        $this->assertNotEmpty($this->storedBooks, 'No books were stored');
        $book = reset($this->storedBooks);

        $bookId = $book['id'];

        // Assert the redirect is to the book's edit page
        // Note: In unit tests, route() helper may not always resolve correctly
        // so we use URL::to() which constructs absolute URL consistently
        $expectedUrl = URL::to('/admin/books/' . $book['id'] . '/edit');
        $this->assertEquals($expectedUrl, $response->getTargetUrl());

        // Note: Session flash assertions are complex in unit tests
        // The core functionality (book update persistence) is tested above
        // Flash messages are tested in feature tests where session handling works properly
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testUpdateMethodMergesSplitSeriesPayload(): void
    {
        $bookId = 'test-book-id';

        $request = new Request([
            'title' => 'The Thief',
            'author' => ['J.R. Ward'],
            'narrator' => ['Jim Frangione'],
            'genre' => ['Romance'],
            'series' => [
                ['number' => '16'],
                ['seriesName' => 'The Black Dagger Brotherhood'],
            ],
            'description' => 'Updated description',
            'directoryPath' => 'Romance/J.R. Ward/Black Dagger Brotherhood/16 The Thief',
        ]);
        $request->setLaravelSession($this->app['session.store']);

        $this->documentStore->shouldReceive('getBook')
            ->with($bookId)
            ->once()
            ->andReturn([
                'id' => $bookId,
                'title' => 'The Thief',
                'author' => ['J.R. Ward'],
                'genre' => ['Romance'],
                'directoryPath' => 'Romance/J.R. Ward/Black Dagger Brotherhood/16 The Thief',
            ]);

        $this->documentStore->shouldReceive('findOrCreateMany')
            ->with('authors', ['J.R. Ward'])
            ->once()
            ->andReturn(['author-id-1']);

        $this->documentStore->shouldReceive('findOrCreateMany')
            ->with('narrators', ['Jim Frangione'])
            ->once()
            ->andReturn(['narrator-id-1']);

        $this->documentStore->shouldReceive('findOrCreateMany')
            ->with('genres', ['Romance'])
            ->once()
            ->andReturn(['genre-id-1']);

        $this->documentStore->shouldReceive('getSeriesByName')
            ->with('The Black Dagger Brotherhood')
            ->once()
            ->andReturn(['id' => 'series-id-1', 'name' => 'The Black Dagger Brotherhood']);

        $this->documentStore->shouldReceive('updateBook')
            ->with($bookId, \Mockery::on(function ($payload) {
                if (!is_array($payload)) {
                    return false;
                }
                if (!isset($payload['series']) || !is_array($payload['series'])) {
                    return false;
                }

                return count($payload['series']) === 1 &&
                    ($payload['series'][0]['seriesName'] ?? null) === 'The Black Dagger Brotherhood' &&
                    (string) ($payload['series'][0]['number'] ?? '') === '16';
            }))
            ->once()
            ->andReturn(['success' => true]);

        $response = $this->controller->update($request, $bookId);

        $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response);
    }

    /**
     * Test the processImport method
     *
     * @return void
     */
    #\[\PHPUnit\Framework\Attributes\Test\]
    public function testProcessImportMethod()
    {
        // Enable event faking for NewBookAdded event
        Event::fake([NewBookAdded::class]);

        // Create a test book data array
        $bookData = [
            'title' => 'Test Book',
            'author' => ['Test Author'],
            'narrator' => ['Test Narrator'],
            'series' => [
                ['seriesName' => 'Test Series', 'number' => 1],
            ],
            'genre' => ['Test Genre'],
            'description' => 'Test description',
            'year' => '2023',
            'publisher' => 'Test Publisher',
            'isbn' => '1234567890',
            'asin' => 'B0A1B2C3D4',
            'cover_url' => 'http://example.com/cover.jpg',
            'import_path' => '/path/to/import',
            'import_root' => '/import/root',
            'import_type' => 'dir',
            'genre_path' => 'Test Genre',
        ];

        // Create a mock request with the book data
        $bookId = 'test-book-id';
        $this->storedBooks = [];

        // Create a real request with the book data
        $request = Request::create('/admin/books/process-import', 'POST', $bookData);

        // Mock the document store methods for authors, narrators, and genres
        $this->documentStore->shouldReceive('findOrCreateMany')
            ->with('authors', Mockery::any())
            ->andReturn(['author-id-1']);

        $this->documentStore->shouldReceive('findOrCreateMany')
            ->with('narrators', Mockery::any())
            ->andReturn(['narrator-id-1']);

        $this->documentStore->shouldReceive('findOrCreateMany')
            ->with('genres', Mockery::any())
            ->andReturn(['genre-id-1']);

        // Mock the document store methods
        $this->documentStore->shouldReceive('createBook')
            ->once()
            ->with(Mockery::any())
            ->andReturnUsing(function ($data) {
                // Generate a UUID for the book
                $generatedId = (string) \Illuminate\Support\Str::uuid();
                $data['id'] = $generatedId;
                $this->storedBooks[$generatedId] = $data;
                return $generatedId;
            });

        // Mock getBook to return our stored book data
        $this->documentStore->shouldReceive('getBook')
            ->with($bookId)
            ->andReturnUsing(function () use ($bookId) {
                return $this->storedBooks[$bookId] ?? null;
            });

        // Mock series handling
        $this->documentStore->shouldReceive('getSeriesByName')
            ->with('Test Series')
            ->andReturn(null);

        $this->documentStore->shouldReceive('createSeries')
            ->with('Test Series')
            ->andReturn('test-series-id');

        // Mock the external cover service
        $this->externalCoverService->shouldReceive('downloadCoverImage')
            ->with('http://example.com/cover.jpg')
            ->andReturn('/path/to/cover.jpg');

        // Create a mock DocumentStoreServiceInterface
        $documentStoreService = Mockery::mock(DocumentStoreServiceInterface::class);
        $bookImportService = Mockery::mock(BookImportService::class);

        // Create a real ImportFileController instance with the mocked service
        $importFileController = new ImportFileController($documentStoreService, $bookImportService);

        // Create a partial mock of the ImportFileController, only mocking moveSelected
        $importFileController = Mockery::mock($importFileController, ['makePartial' => true]);
        $importFileController->shouldReceive('moveSelected')
            ->andReturn(response()->json(['success' => true, 'newPath' => '/new/path']));

        // Bind the mock to the service container
        $this->app->instance(ImportFileController::class, $importFileController);

        // Call the processImport method
        $response = $this->importController->processImport($request);

        // Assert the response is a redirect to the edit page
        $this->assertEquals(302, $response->getStatusCode());

        // Assert the selected genre is persisted for subsequent imports
        $this->assertSame('Test Genre', session('import_default_genre_path'));

        // Check that the redirect URL contains the admin/books/{id}/edit pattern
        $location = $response->headers->get('Location');
        $this->assertMatchesRegularExpression(
            '/\/admin\/books\/[a-f0-9\-]{36}\/edit$/',
            $location,
            "Expected redirect URL to match admin/books/{uuid}/edit pattern, got [{$location}]"
        );

        // Assert the event was dispatched with the correct book data
        Event::assertDispatched(
            NewBookAdded::class,
            function ($event) {
                return isset($event->book['id']) &&
                    isset($event->book['title']) &&
                    $event->book['title'] === 'Test Book';
            }
        );

        // Verify the book was created with the correct data
        $this->assertCount(1, $this->storedBooks);
        $createdBookId = array_keys($this->storedBooks)[0];
        $book = $this->storedBooks[$createdBookId];
        $this->assertEquals('Test Book', $book['title']);
        $this->assertEquals('Test description', $book['description']);
        $this->assertEquals($createdBookId, $book['id']);
        $this->assertIsArray($book['series']);
        $this->assertCount(1, $book['series']);
        $this->assertEquals('Test Series', $book['series'][0]['name']);
        $this->assertEquals(1, $book['series'][0]['number']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testProcessImportMethodHandlesValidationErrors()
    {
        // Create a request with invalid data (missing required fields)
        $request = new Request([
            // Missing title and author fields which are required
            'import_path' => 'test/path/audiobook.m4b',
            'import_root' => '/mnt/data/audiobooks',
        ]);

        // Process the request and expect a redirect with errors
        $response = $this->importController->processImport($request);

        // Assert that the response is a redirect back with errors
        $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response);
        $this->assertTrue(session()->has('errors'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testProcessImportMethodHandlesCoverUrl(): void
    {
        $reflection = new \ReflectionClass($this->importController);
        $method = $reflection->getMethod('storeCoverImage');
        $method->setAccessible(true);

        $result = $method->invoke($this->importController, 'not-a-url', 'test-book-id');
        $this->assertNull($result);

        $base64Image = 'data:image/jpeg;base64,' . base64_encode('fake-image-data');
        $result = $method->invoke($this->importController, $base64Image, 'test-book-id');
        $this->assertNotNull($result);
        $this->assertStringContainsString('test-book-id', $result);
    }
}
