<?php

namespace Tests\Feature\Admin;

use App\Auth\DocumentstoreUser;
use App\Services\AudibleService;
use App\Services\GoogleBooksApiService;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\App\Http\Controllers\Admin\BookController::class)]
class BookControllerSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set APP_KEY for testing
        $this->app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));

        // Create a mock admin user with proper permissions
        $userData = [
            'id' => 'test-admin-user',
            'name' => 'Test Admin',
            'email' => 'admin@test.com',
            'is_admin' => true,
            'permissions' => ['admin.books.*'],
        ];

        // Create the user and authenticate
        $user = new DocumentstoreUser($userData);

        // Both of these are needed for proper authentication
        Auth::login($user);
        $this->actingAs($user);

        // Ensure the middleware recognizes the user as an admin
        $this->withoutMiddleware(\App\Http\Middleware\CheckAdminRole::class);
    }

    /**
     * Test the unified search endpoint with Audible source
     */
    public function testSearchBooksWithAudibleSource(): void
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

        // Make the request with source=audible
        $response = $this->getJson('/admin/books/search?source=audible&title=Test+Book&author=Test+Author');

        // Assert response
        $response->assertStatus(200);

        // Get the response data
        $responseData = json_decode($response->getContent(), true);

        // Check if we have the expected book
        $this->assertCount(1, $responseData);
        $this->assertEquals('Test Book', $responseData[0]['title']);
        $this->assertEquals(['Test Author'], $responseData[0]['author']);
        $this->assertEquals('B123456789', $responseData[0]['audibleId']);
    }

    /**
     * Test the unified search endpoint with Google Books source
     */
    public function testSearchBooksWithGoogleBooksSource(): void
    {
        // Mock the GoogleBooksApiService
        $mockGoogleBooksService = Mockery::mock(GoogleBooksApiService::class);

        // Mock the searchBooks method
        $mockGoogleBooksService->shouldReceive('searchBooks')
            ->once()
            ->with('intitle:"Test Book" inauthor:"Test Author"', ['limit' => 10])
            ->andReturn([
                [
                    'title' => 'Test Book',
                    'author' => ['Test Author'],
                    'googleBooksId' => '123456789',
                    'coverImageUrl' => 'https://example.com/cover.jpg',
                    'publishedYear' => '2023',
                    'seriesName' => 'Test Series',
                    'seriesNumber' => '1',
                    'series' => 'Test Series',
                    'source' => 'Google Books',
                    'publisher' => ['Test Publisher'],
                    'category' => ['Science Fiction', 'Fantasy'],
                    'description' => 'Test description',
                ],
            ]);

        $this->app->instance(GoogleBooksApiService::class, $mockGoogleBooksService);

        // Make the request with source=googlebooks
        $response = $this->getJson('/admin/books/search?source=googlebooks&title=Test+Book&author=Test+Author');

        // Assert response
        $response->assertStatus(200);

        // Get the response data
        $responseData = json_decode($response->getContent(), true);

        // Check if we have the expected book
        $this->assertCount(1, $responseData);
        $this->assertEquals('Test Book', $responseData[0]['title']);
        $this->assertEquals(['Test Author'], $responseData[0]['author']);
        $this->assertEquals('123456789', $responseData[0]['googleBooksId']);
    }

    /**
     * Test the unified search endpoint with an invalid source
     */
    public function testSearchBooksInvalidSource(): void
    {
        // Make the request with an invalid source
        $response = $this->getJson('/admin/books/search?source=invalid&title=Test+Book');

        // Assert response
        $response->assertStatus(400)
            ->assertJson([
                'error' => 'Invalid source specified. Supported sources: audible, googlebooks, audiobookbay, hardcover',
            ]);
    }

    /**
     * Test the unified search endpoint with missing title and API ID
     */
    public function testSearchBooksMissingTitleAndApiId(): void
    {
        // Make the request without title or api_id
        $response = $this->getJson('/admin/books/search?source=audible');

        // Assert response
        $response->assertStatus(400)
            ->assertJson([
                'error' => 'Title or API ID is required.',
            ]);
    }

    /**
     * Test the unified search endpoint with an error from the service
     */
    public function testSearchBooksServiceError(): void
    {
        // Mock the AudibleService
        $mockAudibleService = Mockery::mock(AudibleService::class);
        $mockAudibleService->shouldReceive('searchBooksWithFiltering')
            ->once()
            ->with('Test Book', '', ['limit' => 10])
            ->andThrow(new \Exception('API Error'));

        $this->app->instance(AudibleService::class, $mockAudibleService);

        // Make the request
        $response = $this->getJson('/admin/books/search?source=audible&title=Test+Book');

        // Assert response
        $response->assertStatus(500)
            ->assertJson([
                'error' => 'Audible search failed: API Error',
            ]);
    }
}
