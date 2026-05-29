<?php

namespace Tests\Web\Feature\Admin;

use App\Auth\DocumentstoreUser;
use App\Http\Controllers\Admin\BookController;
use App\Services\AudioFileAnalyzer;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\TestCase;

class BookControllerUpdateTest extends TestCase
{
    use WithoutMiddleware;

    private $controller;

    private $documentStoreService;

    private $externalCoverService;

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

        // Create a mock ExternalCoverService
        $this->externalCoverService = $this->createMock(\App\Services\ExternalCoverService::class);

        // Create a controller instance with the mock service
        $this->controller = new BookController(
            $this->documentStoreService,
            $this->externalCoverService,
            new AudioFileAnalyzer()
        );

        // Create a test user with admin role
        $this->user = new DocumentstoreUser([
            'id' => 'test-user-1',
            'name' => 'Test Admin',
            'email' => 'admin@example.com',
            'roles' => ['admin'],
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
    #[AllowMockObjectsWithoutExpectations]
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
                        (!isset($data['coverImageCandidate']) || strpos($data['coverImageCandidate'], 'coverfile.jpg') !== false);
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
    #[AllowMockObjectsWithoutExpectations]
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
    #[AllowMockObjectsWithoutExpectations]
    public function updateBookReturnsErrorIfNotFound()
    {
        // Set up DocumentStoreService to return null (book not found)
        $this->documentStoreService->expects($this->once())
            ->method('getBook')
            ->willReturn(null);

        // Create request with minimal data
        $request = new Request([
            'title' => 'T',
            'genre' => ['G'],
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
            'audibleId' => $asin,
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
                        isset($data['coverImage']) &&
                        $data['coverImage'] === 'cover_audible_B01234ABCD.jpg';
                })
            )
            ->willReturn(true);

        // Mock ExternalCoverService to return successful download and create the file
        $this->externalCoverService->expects($this->once())
            ->method('downloadCoverImage')
            ->with(
                $this->equalTo('https://images-na.ssl-images-amazon.com/images/I/B01234ABCD.jpg'),
                $this->equalTo('test/path'),
                $this->equalTo('audible'),
                $this->equalTo($asin)
            )
            ->willReturnCallback(function () {
                // Create the file in the fake storage to simulate successful download
                Storage::disk('books')->put('test/path/cover_audible_B01234ABCD.jpg', 'fake image content');
                return [
                    'success' => true,
                    'path' => 'test/path/cover_audible_B01234ABCD.jpg',
                ];
            });

        // Mock findOrCreateMany calls that the controller makes
        $this->documentStoreService->method('findOrCreateMany')
            ->willReturnCallback(function ($type, $items) {
                // Return mock IDs for authors and genres
                if ($type === 'authors') {
                    return ['author-1'];
                } elseif ($type === 'genres') {
                    return ['genre-1'];
                }
                return [];
            });

        // Create request with data including Audible cover URL
        $request = new Request([
            'title' => 'Updated Audible Book',
            'author' => ['Updated Author'], // Controller expects array
            'genre' => ['Updated Genre'],
            'description' => 'Updated desc',
            'directoryPath' => 'test/path',
            'audibleCoverImageUrl' => 'https://images-na.ssl-images-amazon.com/images/I/B01234ABCD.jpg',
            'audibleId' => $asin,
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

    #[\PHPUnit\Framework\Attributes\Test]
    #[AllowMockObjectsWithoutExpectations]
    public function updateBookMovesFilesWhenDirectoryPathChangesAndDestinationExists(): void
    {
        Log::spy();
        Storage::fake('books');

        $bookId = 'test-book-move-1';
        $bookData = [
            'id' => $bookId,
            'title' => 'Move Test',
            'author' => ['Old Author'],
            'genre' => ['Old Genre'],
            'directoryPath' => 'old/path',
            'coverImage' => 'cover.jpg',
        ];

        Storage::disk('books')->put('old/path/track1.mp3', 'a');
        Storage::disk('books')->put('old/path/cover.jpg', 'oldcover');
        Storage::disk('books')->put('old/path/sub/track2.mp3', 'b');

        Storage::disk('books')->put('new/path/existing.mp3', 'c');
        Storage::disk('books')->put('new/path/cover.jpg', 'newcover');
        Storage::disk('books')->put('new/path/sub/track2.mp3', 'existing-sub');

        $this->documentStoreService->expects($this->once())
            ->method('getBook')
            ->with($bookId)
            ->willReturn($bookData);

        $this->documentStoreService->method('findOrCreateMany')
            ->willReturnCallback(function ($type, $items) {
                if ($type === 'authors') {
                    return ['author-1'];
                }
                if ($type === 'genres') {
                    return ['genre-1'];
                }
                return [];
            });

        $this->documentStoreService->expects($this->once())
            ->method('updateBook')
            ->with(
                $this->equalTo($bookId),
                $this->callback(function ($data) {
                    return ($data['directoryPath'] ?? null) === 'new/path_01'
                        && ($data['coverImage'] ?? null) === 'cover.jpg';
                })
            )
            ->willReturn(true);

        $request = new Request([
            'title' => 'Move Test',
            'author' => ['Old Author'],
            'genre' => ['Old Genre'],
            'directoryPath' => 'new/path',
        ]);
        $this->app->instance('request', $request);

        $response = $this->controller->update($request, $bookId);

        $this->assertEquals(302, $response->getStatusCode());

        $this->assertTrue(Storage::disk('books')->exists('new/path_01/track1.mp3'));
        $this->assertTrue(Storage::disk('books')->exists('new/path_01/cover.jpg'));
        $this->assertTrue(Storage::disk('books')->exists('new/path_01/track2.mp3'));

        $this->assertTrue(Storage::disk('books')->exists('new/path/cover.jpg'));
        $this->assertTrue(Storage::disk('books')->exists('new/path/sub/track2.mp3'));

        $this->assertCount(0, Storage::disk('books')->allFiles('old/path'));
    }
}
