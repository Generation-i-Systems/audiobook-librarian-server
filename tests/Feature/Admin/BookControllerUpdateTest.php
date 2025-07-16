<?php

namespace Tests\Feature\Admin;

use App\Auth\DocumentstoreUser;
use App\Http\Controllers\Admin\BookController;
use App\Contracts\DocumentStoreServiceInterface;
use App\Services\GoogleBooksApiService;
use App\Services\AudibleService;
use App\Services\ExternalCoverService;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use Mockery;

class BookControllerUpdateTest extends TestCase
{
    use WithoutMiddleware;

    private $controller;
    private $documentStoreService;
    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable logging to avoid permission issues
        Log::shouldReceive('info')->andReturn(null);
        Log::shouldReceive('error')->andReturn(null);
        Log::shouldReceive('warning')->andReturn(null);
        Log::shouldReceive('debug')->andReturn(null);

        // Create a mock DocumentStoreService
        $this->documentStoreService = $this->createMock(\App\Contracts\DocumentStoreServiceInterface::class);

        // Create a controller instance with the mock service
        $this->controller = new BookController(
            $this->documentStoreService,
            $this->createMock(\App\Services\GoogleBooksApiService::class),
            $this->createMock(\App\Services\AudibleService::class),
            $this->createMock(\App\Services\ExternalCoverService::class)
        );

        // Create a test user with admin role
        $this->user = new DocumentstoreUser([
            'id' => 'test-user-1',
            'name' => 'Test Admin',
            'email' => 'admin@example.com',
            'roles' => ['admin']
        ]);

        // Mock Auth facade
        Auth::shouldReceive('user')->andReturn($this->user);
        Auth::shouldReceive('check')->andReturn(true);

        // Set up request binding to ensure controller receives the request
        $this->app->bind('request', function () {
            return new Request();
        });
    }

    /**
     * Test that updating a book with a cover candidate works correctly
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function updateBookRedirectsToIndexAndSavesCoverCandidate()
    {
        // Test book data
        $bookId = 'test-book-1';
        $bookData = [
            'id' => $bookId,
            'title' => 'Old Title',
            'authors' => ['Old Author'],
            'narrators' => ['Old Narrator'],
            'genre' => ['Old Genre'],
            'description' => 'Old desc',
            'directoryPath' => 'old/path',
        ];

        // Set up DocumentStoreService expectations
        $this->documentStoreService->expects($this->once())
            ->method('getBook')
            ->with($bookId)
            ->willReturn($bookData);

        $this->documentStoreService->method('updateBook')
            ->with(
                $this->equalTo($bookId),
                $this->callback(function ($data) {
                    return $data['title'] === 'New Title' &&
                        $data['coverImageCandidate'] === 'coverfile.jpg';
                })
            )
            ->willReturn(true);

        // Create request with data
        $request = new Request([
            'title' => 'New Title',
            'authors' => ['New Author'],
            'narrators' => ['New Narrator'],
            'genre' => ['New Genre'],
            'description' => 'New desc',
            'directoryPath' => 'old/path',
            'coverImageCandidate' => 'coverfile.jpg',
        ]);
        $this->app->instance('request', $request);

        // Call the controller method directly
        $response = $this->controller->update($request, $bookId);

        // Assert response is a redirect
        $this->assertEquals(302, $response->getStatusCode());
    }

    /**
     * Test that updating a book with an uploaded cover image works correctly
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function updateBookSavesUploadedCoverImage()
    {
        // Mock Storage facade
        Storage::fake('books');

        // Test book data
        $bookId = 'test-book-2';
        $bookData = [
            'id' => $bookId,
            'title' => 'Old Title',
            'authors' => ['Old Author'],
            'narrators' => ['Old Narrator'],
            'genre' => ['Old Genre'],
            'description' => 'Old desc',
            'directoryPath' => 'old/path',
        ];

        // Set up DocumentStoreService expectations
        $this->documentStoreService->expects($this->once())
            ->method('getBook')
            ->with($bookId)
            ->willReturn($bookData);

        $this->documentStoreService->method('updateBook')
            ->with(
                $this->equalTo($bookId),
                $this->callback(function ($data) {
                    return $data['title'] === 'New Title' &&
                        !empty($data['coverImage']);
                })
            )
            ->willReturn(true);

        // Create fake upload file
        $file = UploadedFile::fake()->image('cover.jpg');

        // Create request with data
        $request = new Request(
            [
                'title' => 'New Title',
                'authors' => ['New Author'],
                'narrators' => ['New Narrator'],
                'genre' => ['New Genre'],
                'description' => 'New desc',
                'directoryPath' => 'old/path',
            ],
            [],
            [],
            [],
            ['coverImage' => $file]
        );
        $this->app->instance('request', $request);

        // Call the controller method directly
        $response = $this->controller->update($request, $bookId);

        // Assert response is a redirect
        $this->assertEquals(302, $response->getStatusCode());
    }

    /**
     * Test that updating a non-existent book returns an error
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function updateBookReturnsErrorIfNotFound()
    {
        // Set up DocumentStoreService to return null (book not found)
        $this->documentStoreService->expects($this->once())
            ->method('getBook')
            ->willReturn(null);

        // Create request with minimal data
        $request = new Request([
            'title' => 'T',
            'genre' => ['G']
        ]);
        $this->app->instance('request', $request);

        // Call the controller method directly
        $response = $this->controller->update($request, 'missing-book');

        // Assert response redirects back with errors
        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals(route('admin.books.index'), $response->headers->get('Location'));
    }

    /**
     * Test that updating a book with an Audible cover URL downloads and saves the image
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function updateBookDownloadsAndSavesAudibleCoverImage()
    {
        // Disable logging to avoid permission issues
        Log::spy();

        // Mock HTTP facade for Audible cover download
        Http::fake([
            '*' => Http::response(
                file_get_contents(base_path('tests/fixtures/cover.jpg')),
                200,
                ['Content-Type' => 'image/jpeg']
            ),
        ]);

        // Mock Storage facade
        Storage::fake('books');

        // Test book data
        $bookId = 'test-book-3';
        $asin = 'B01234ABCD';
        $bookData = [
            'id' => $bookId,
            'title' => 'Old Audible Book',
            'author' => ['Old Author'], // Note: controller expects 'author', not 'authors'
            'narrators' => ['Old Narrator'],
            'genre' => ['Old Genre'],
            'directoryPath' => 'test/path',
            'audibleId' => $asin
        ];

        // Set up DocumentStoreService expectations
        $this->documentStoreService->expects($this->once())
            ->method('getBook')
            ->with($bookId)
            ->willReturn($bookData);

        $this->documentStoreService->method('updateBook')
            ->with(
                $this->equalTo($bookId),
                $this->callback(function ($data) {
                    return $data['title'] === 'Updated Audible Book' &&
                        isset($data['coverImage']);
                })
            )
            ->willReturn(true);

        // Create request with data including Audible cover URL
        $request = new Request([
            'title' => 'Updated Audible Book',
            'author' => 'Updated Author',
            'genre' => ['Updated Genre'],
            'description' => 'Updated desc',
            'directoryPath' => 'test/path',
            'audible_cover_image_url' => 'https://images-na.ssl-images-amazon.com/images/I/B01234ABCD.jpg',
            'audibleId' => $asin
        ]);
        $this->app->instance('request', $request);

        // Call the controller method directly
        $response = $this->controller->update($request, $bookId);

        // Assert response is a redirect
        $this->assertEquals(302, $response->getStatusCode());

        // Verify Storage received the expected put call for the Audible cover
        // When using Storage::fake(), we need to check if the file was stored
        // The controller uses 'jpg' extension for image/jpeg content type
        $this->assertTrue(Storage::disk('books')->exists('test/path/cover_audible_B01234ABCD.jpg'));
    }
}
