<?php

namespace Tests\Feature\Admin;

use App\Auth\DocumentstoreUser;
use App\Services\AudibleService;
use App\Services\MongoService;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Auth;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(\App\Http\Controllers\Admin\BookController::class)]
class BookControllerAudibleTest extends TestCase
{
    use WithFaker;

    protected $user;


    protected function setUp(): void
    {
        parent::setUp();

        // Set APP_KEY for testing
        $this->app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));

        // Mock the MongoService to avoid database connection issues
        $mockMongoService = Mockery::mock('MongoService');
        $this->app->instance(MongoService::class, $mockMongoService);

        // Create a mock admin user with proper permissions
        $userData = [
            'id' => 'test-admin-user',
            'name' => 'Test Admin',
            'email' => 'admin@test.com',
            'is_admin' => true,
            'permissions' => ['admin.books.*'],
        ];

        // Create the user and authenticate
        $this->user = new DocumentstoreUser($userData);

        // Both of these are needed for proper authentication
        Auth::login($this->user);
        $this->actingAs($this->user);

        // Ensure the middleware recognizes the user as an admin
        $this->withoutMiddleware(\App\Http\Middleware\CheckAdminRole::class);
    }

    /**
     * Test the audible endpoint with a title search
     */
    public function testAudibleSearchByTitle(): void
    {
        // Mock the AudibleService
        $mockAudibleService = Mockery::mock(AudibleService::class);

        // Mock the searchBooksWithFiltering method
        $mockAudibleService->shouldReceive('searchBooksWithFiltering')
            ->once()
            ->with('Test Book', 'Test Author', ['limit' => 10])
            ->andReturn([
                [
                    'title' => 'Test Book',
                    'author' => ['Test Author'],
                    'audibleId' => 'B123456789',
                    'coverImageUrl' => 'https://example.com/cover.jpg',
                    'publishedYear' => '2023',
                    'narrator' => ['Test Narrator'],
                    'seriesName' => 'Test Series',
                    'seriesNumber' => '1',
                    'series' => 'Test Series',
                    'source' => 'Audible',
                    'publisher' => ['Test Publisher'],
                    'category' => ['Science Fiction', 'Fantasy'],
                    'description' => 'Test description',
                ],
            ]);

        $this->app->instance(AudibleService::class, $mockAudibleService);

        // Make the request
        $response = $this->getJson('/admin/books/audible?title=Test+Book&author=Test+Author');

        // Debug the response
        if ($response->getStatusCode() !== 200) {
            // echo "\nResponse content: " . $response->getContent() . "\n";
        }

        // Assert response
        $response->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonStructure([
                [
                    'title',
                    'author',
                    'audibleId',
                    'coverImageUrl',
                    'publishedYear',
                    'narrator',
                    'seriesName',
                    'seriesNumber',
                    'series',
                    'source',
                    'publisher',
                    'category',
                    'description',
                ],
            ])
            ->assertJson([
                [
                    'title' => 'Test Book',
                    'author' => ['Test Author'],
                    'audibleId' => 'B123456789',
                    'coverImageUrl' => 'https://example.com/cover.jpg',
                    'publishedYear' => '2023',
                    'narrator' => ['Test Narrator'],
                    'seriesName' => 'Test Series',
                    'seriesNumber' => '1',
                    'series' => 'Test Series',
                    'source' => 'Audible',
                    'publisher' => ['Test Publisher'],
                    'category' => ['Science Fiction', 'Fantasy'],
                    'description' => 'Test description',
                ],
            ]);
    }

    /**
     * Test the audible endpoint with an ASIN search
     */
    public function testAudibleSearchByAsin(): void
    {
        // Mock the AudibleService
        $mockAudibleService = Mockery::mock(AudibleService::class);
        $mockAudibleService->shouldReceive('getBookDetails')
            ->once()
            ->with('B123456789')
            ->andReturn([
                'title' => 'Test Book',
                'author' => ['Test Author'],
                'audibleAuthors' => ['Test Author'],
                'asin' => 'B123456789',
                'image_url' => 'https://example.com/cover.jpg',
                'release_date' => '2023-01-01',
                'narrator' => ['Test Narrator'],
                'series' => ['Test Series' => '1'],
                'publisher' => 'Test Publisher',
                'description' => 'Test description',
                'categories' => ['Fiction', 'Fantasy'],
                'genre' => ['Science Fiction', 'Fantasy'],
            ]);

        // For ASIN search, transform is not called - the raw data is returned directly

        $this->app->instance(AudibleService::class, $mockAudibleService);

        // Make the request
        $response = $this->getJson('/admin/books/audible?api_id=B123456789');

        // Assert response - when searching by ASIN, the controller returns a single object, not an array
        $response->assertStatus(200)
            ->assertJsonStructure([
                'title',
                'author',
                'audibleAuthors',
                'asin',
                'image_url',
                'release_date',
                'narrator',
                'series',
                'publisher',
                'description',
                'categories',
                'genre',
            ])
            ->assertJson([
                'title' => 'Test Book',
                'audibleAuthors' => ['Test Author'],
                'asin' => 'B123456789',
                'image_url' => 'https://example.com/cover.jpg',
                'release_date' => '2023-01-01',
                'narrator' => ['Test Narrator'],
                'publisher' => 'Test Publisher',
                'description' => 'Test description',
                'categories' => ['Fiction', 'Fantasy'],
                'genre' => ['Science Fiction', 'Fantasy'],
            ]);
    }

    /**
     * Test the audible endpoint with a missing title and ASIN
     */
    public function testAudibleSearchWithMissingTitleAndAsin(): void
    {
        // Make the request with no title or ASIN
        $response = $this->getJson('/admin/books/audible');

        // Assert response
        $response->assertStatus(400)
            ->assertJson([
                'error' => 'Title or ASIN is required.',
            ]);
    }

    /**
     * Test the audible endpoint with an error from the service

        // Assert response
        $response->assertStatus(500)
            ->assertJson([
                'error' => 'Audible search failed: API Error',
            ]);
    }

    /**
     * Test the audible endpoint with author filtering
     */
    public function testAudibleSearchWithAuthorFiltering(): void
    {
        // Mock the AudibleService
        $mockAudibleService = Mockery::mock(AudibleService::class);

        // The controller calls searchBooksWithFiltering which handles author filtering internally
        $mockAudibleService->shouldReceive('searchBooksWithFiltering')
            ->once()
            ->with('Fantasy Book', 'John Smith', ['limit' => 10])
            ->andReturn([
                [
                    'title' => 'Fantasy Book',
                    'author' => ['John Smith'],
                    'audibleId' => 'B123456789',
                    'coverImageUrl' => 'https://example.com/cover1.jpg',
                    'publishedYear' => '2023',
                    'narrator' => [],
                    'seriesName' => '',
                    'seriesNumber' => '',
                    'series' => '',
                    'source' => 'Audible',
                    'publisher' => [],
                    'category' => [],
                    'description' => '',
                ],
                [
                    'title' => 'Fantasy Book: The Sequel',
                    'author' => ['John Smith'],
                    'audibleId' => 'B555555555',
                    'coverImageUrl' => 'https://example.com/cover3.jpg',
                    'publishedYear' => '2024',
                    'narrator' => [],
                    'seriesName' => '',
                    'seriesNumber' => '',
                    'series' => '',
                    'source' => 'Audible',
                    'publisher' => [],
                    'category' => [],
                    'description' => '',
                ],
            ]);

        $this->app->instance(AudibleService::class, $mockAudibleService);

        // Make the request with title and author
        $response = $this->getJson('/admin/books/audible?title=Fantasy+Book&author=John+Smith');

        // Debug the response
        if ($response->getStatusCode() !== 200) {
            // echo "\nResponse content: " . $response->getContent() . "\n";
        }

        // Assert response
        $response->assertStatus(200)
            ->assertJsonCount(2)
            ->assertJsonStructure([
                [
                    'title',
                    'author',
                    'audibleId',
                    'coverImageUrl',
                    'publishedYear',
                    'narrator',
                    'seriesName',
                    'seriesNumber',
                    'series',
                    'source',
                    'publisher',
                    'category',
                    'description',
                ],
            ]);

        // Get the response data
        $responseData = json_decode($response->getContent(), true);

        // Verify all books are by John Smith
        foreach ($responseData as $book) {
            $this->assertContains('John Smith', $book['author'], 'All books should be by John Smith');
        }
    }

    /**
     * Test the audible endpoint with fallback search when no results are found
     */
    public function testAudibleSearchWithFallback(): void
    {
        // Mock the AudibleService
        $mockAudibleService = Mockery::mock(AudibleService::class);

        // The controller calls searchBooksWithFiltering, which handles the fallback internally
        $mockAudibleService->shouldReceive('searchBooksWithFiltering')
            ->once()
            ->with('Rare Book Title', 'Famous Author', ['limit' => 10])
            ->andReturn([
                [
                    'title' => 'Rare Book Title',
                    'author' => ['Famous Author'],
                    'audibleId' => 'B123456789',
                    'coverImageUrl' => 'https://example.com/cover.jpg',
                    'publishedYear' => '2023',
                    'narrator' => ['Great Narrator'],
                    'seriesName' => '',
                    'seriesNumber' => '',
                    'series' => '',
                    'source' => 'Audible',
                    'publisher' => ['Publishing House'],
                    'category' => [],
                    'description' => '',
                ],
            ]);

        $this->app->instance(AudibleService::class, $mockAudibleService);

        // Make the request with title and author
        $response = $this->getJson('/admin/books/audible?title=Rare+Book+Title&author=Famous+Author');

        // Assert response
        $response->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonStructure([
                [
                    'title',
                    'author',
                    'audibleId',
                    'coverImageUrl',
                    'publishedYear',
                    'narrator',
                    'seriesName',
                    'seriesNumber',
                    'series',
                    'source',
                    'publisher',
                    'category',
                    'description',
                ],
            ])
            ->assertJson([
                [
                    'title' => 'Rare Book Title',
                    'author' => ['Famous Author'],
                    'audibleId' => 'B123456789',
                    'coverImageUrl' => 'https://example.com/cover.jpg',
                    'publishedYear' => '2023',
                ],
            ]);
    }
}
