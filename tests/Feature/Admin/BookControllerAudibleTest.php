<?php

namespace Tests\Feature\Admin;

use App\Auth\DocumentstoreUser;
use App\Services\AudibleService;
use App\Services\MongoService;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;

/**
 * @covers \App\Http\Controllers\Admin\BookController
 */
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
     *
     * @return void
     */
    public function testAudibleSearchByTitle(): void
    {
        // Mock the AudibleService
        $mockAudibleService = Mockery::mock(AudibleService::class);

        // Mock the searchBooksWithFiltering method
        $mockAudibleService->shouldReceive('searchBooksWithFiltering')
            ->once()
            ->with('Test Book', '', ['limit' => 10])
            ->andReturn([
                [
                    'title' => 'Test Book',
                    'author' => ['Test Author'],
                    'audibleId' => 'B123456789',
                    'coverImageUrl' => 'https://example.com/cover.jpg',
                    'publishedYear' => '2023',
                    'narratorList' => ['Test Narrator'],
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
            echo "\nResponse content: " . $response->getContent() . "\n";
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
                    'narratorList',
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
                    'narratorList' => ['Test Narrator'],
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
     *
     * @return void
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

        // Mock the transform method
        $mockAudibleService->shouldReceive('transform')
            ->once()
            ->andReturnUsing(function ($book) {
                return [
                    'title' => $book['title'] ?? '',
                    'author' => $book['audibleAuthors'] ?? $book['author'] ?? [],
                    'audibleId' => $book['asin'] ?? '',
                    'coverImageUrl' => $book['image_url'] ?? '',
                    'publishedYear' => isset($book['release_date']) ? substr($book['release_date'], 0, 4) : '',
                    'narratorList' => $book['narrator'] ?? [],
                    'seriesName' => isset($book['series']) && is_array($book['series']) ? array_key_first($book['series']) : '',
                    'seriesNumber' => isset($book['series']) && is_array($book['series']) ? reset($book['series']) : '',
                    'series' => isset($book['series']) && is_array($book['series']) ? array_key_first($book['series']) : '',
                    'source' => 'Audible',
                    'publisher' => is_string($book['publisher'] ?? '') ? [$book['publisher']] : ($book['publisher'] ?? []),
                    'category' => $book['genre'] ?? $book['categories'] ?? [],
                    'description' => $book['description'] ?? '',
                ];
            });

        $this->app->instance(AudibleService::class, $mockAudibleService);

        // Make the request
        $response = $this->getJson('/admin/books/audible?api_id=B123456789');

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
                    'narratorList',
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
                    'narratorList' => ['Test Narrator'],
                    'seriesName' => 'Test Series',
                    'seriesNumber' => '1',
                    'source' => 'Audible',
                    'publisher' => ['Test Publisher'],
                    'category' => ['Science Fiction', 'Fantasy'],
                    'description' => 'Test description',
                ],
            ]);
    }

    /**
     * Test the audible endpoint with a missing title and ASIN
     *
     * @return void
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
     *
     * @return void
     */
    public function testAudibleSearchWithAuthorFiltering(): void
    {
        // Mock the AudibleService
        $mockAudibleService = Mockery::mock(AudibleService::class);

        // First call with just the title
        $mockAudibleService->shouldReceive('searchBooks')
            ->once()
            ->with('Fantasy Book', ['limit' => 10])
            ->andReturn([
                [
                    'title' => 'Fantasy Book',
                    'author' => 'John Smith',
                    'asin' => 'B123456789',
                    'image_url' => 'https://example.com/cover1.jpg',
                    'release_date' => '2023-01-01',
                ],
                [
                    'title' => 'Fantasy Book',
                    'author' => 'Jane Doe',
                    'asin' => 'B987654321',
                    'image_url' => 'https://example.com/cover2.jpg',
                    'release_date' => '2022-01-01',
                ],
                [
                    'title' => 'Fantasy Book: The Sequel',
                    'author' => 'John Smith',
                    'asin' => 'B555555555',
                    'image_url' => 'https://example.com/cover3.jpg',
                    'release_date' => '2024-01-01',
                ],
            ]);

        // Mock the transform method
        $mockAudibleService->shouldReceive('transform')
            ->times(3)
            ->andReturnUsing(function ($book) {
                return [
                    'title' => $book['title'] ?? '',
                    'author' => is_array($book['author'] ?? '') ? $book['author'] : [$book['author'] ?? ''],
                    'audibleId' => $book['asin'] ?? '',
                    'coverImageUrl' => $book['image_url'] ?? '',
                    'publishedYear' => isset($book['release_date']) ? substr($book['release_date'], 0, 4) : '',
                    'narratorList' => $book['narrator'] ?? [],
                    'seriesName' => isset($book['series']) && is_array($book['series']) ? array_key_first($book['series']) : '',
                    'seriesNumber' => isset($book['series']) && is_array($book['series']) ? reset($book['series']) : '',
                    'series' => isset($book['series']) && is_array($book['series']) ? array_key_first($book['series']) : '',
                    'source' => 'Audible',
                    'publisher' => isset($book['publisher']) ? (is_string($book['publisher']) ? [$book['publisher']] : $book['publisher']) : [],
                    'category' => $book['genre'] ?? $book['categories'] ?? [],
                    'description' => $book['description'] ?? '',
                ];
            });

        $this->app->instance(AudibleService::class, $mockAudibleService);

        // Make the request with title and author
        $response = $this->getJson('/admin/books/audible?title=Fantasy+Book&author=John+Smith');

        // Debug the response
        if ($response->getStatusCode() !== 200) {
            echo "\nResponse content: " . $response->getContent() . "\n";
        }

        // The controller doesn't actually filter by author in the response JSON, it filters the input data
        // So we need to check the response content manually
        $response->assertStatus(200);

        // Get the response data
        $responseData = json_decode($response->getContent(), true);

        // Find books by John Smith
        $johnSmithBooks = array_filter($responseData, function ($book) {
            return isset($book['author']) &&
                ((is_string($book['author']) && stripos($book['author'], 'John Smith') !== false) ||
                    (is_array($book['author']) && in_array('John Smith', $book['author'])));
        });

        // Assert we have John Smith books
        $this->assertNotEmpty($johnSmithBooks, 'No books by John Smith found in response');

        // Verify that the Jane Doe book is not included
        $responseData = json_decode($response->getContent(), true);
        $foundJaneDoe = false;
        foreach ($responseData as $book) {
            if (isset($book['author']) && is_array($book['author']) && in_array('Jane Doe', $book['author'])) {
                $foundJaneDoe = true;
                break;
            }
        }
        $this->assertFalse($foundJaneDoe, 'Jane Doe book should be filtered out');
    }

    /**
     * Test the audible endpoint with fallback search when no results are found
     *
     * @return void
     */
    public function testAudibleSearchWithFallback(): void
    {
        // Mock the AudibleService
        $mockAudibleService = Mockery::mock(AudibleService::class);

        // First call with just the title returns no results
        $mockAudibleService->shouldReceive('searchBooks')
            ->once()
            ->with('Rare Book Title', ['limit' => 10])
            ->andReturn([]);

        // Second call with combined title and author should return results
        $mockAudibleService->shouldReceive('searchBooks')
            ->once()
            ->with('Rare Book Title Famous Author', ['limit' => 10])
            ->andReturn([
                [
                    'title' => 'Rare Book Title',
                    'author' => 'Famous Author',
                    'asin' => 'B123456789',
                    'image_url' => 'https://example.com/cover.jpg',
                    'release_date' => '2023-01-01',
                    'narrator' => ['Great Narrator'],
                    'publisher' => 'Publishing House',
                ],
            ]);

        // Mock the transform method
        $mockAudibleService->shouldReceive('transform')
            ->once()
            ->andReturnUsing(function ($book) {
                return [
                    'title' => $book['title'] ?? '',
                    'author' => is_array($book['author'] ?? '') ? $book['author'] : [$book['author'] ?? ''],
                    'audibleId' => $book['asin'] ?? '',
                    'coverImageUrl' => $book['image_url'] ?? '',
                    'publishedYear' => isset($book['release_date']) ? substr($book['release_date'], 0, 4) : '',
                    'narratorList' => $book['narrator'] ?? [],
                    'seriesName' => isset($book['series']) && is_array($book['series']) ? array_key_first($book['series']) : '',
                    'seriesNumber' => isset($book['series']) && is_array($book['series']) ? reset($book['series']) : '',
                    'series' => isset($book['series']) && is_array($book['series']) ? array_key_first($book['series']) : '',
                    'source' => 'Audible',
                    'publisher' => isset($book['publisher']) ? (is_string($book['publisher']) ? [$book['publisher']] : $book['publisher']) : [],
                    'category' => $book['genre'] ?? $book['categories'] ?? [],
                    'description' => $book['description'] ?? '',
                ];
            });

        $this->app->instance(AudibleService::class, $mockAudibleService);

        // Make the request with title and author
        $response = $this->getJson('/admin/books/audible?title=Rare+Book+Title&author=Famous+Author');

        // Assert response
        $response->assertStatus(200);

        // Get the response data
        $responseData = json_decode($response->getContent(), true);

        // Check if we have any books with the expected title and author
        $matchingBooks = array_filter($responseData, function ($book) {
            return isset($book['title']) && $book['title'] === 'Rare Book Title' &&
                isset($book['author']) && in_array('Famous Author', (array) $book['author']);
        });

        $this->assertNotEmpty($matchingBooks, 'No books matching title "Rare Book Title" and author "Famous Author" found');
    }
}
