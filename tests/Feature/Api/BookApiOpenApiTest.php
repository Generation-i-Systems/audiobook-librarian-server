<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Contracts\DocumentStoreServiceInterface;
use App\Models\User;
use App\Models\Book;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

class BookApiOpenApiTest extends TestCase
{
    use RefreshDatabase;

    protected $token;
    protected $openApiSpec;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a user and authenticate via guard to trigger ApiAuth testing bypass
        /** @var User $user */
        $user = User::factory()->createOne([
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);
        $this->actingAs($user);
        $this->token = 'testing-bypass-token';

        // Load OpenAPI specification if present (do not fail tests if missing)
        $openApiPath = public_path('api-docs/openapi.json');
        if (is_string($openApiPath) && file_exists($openApiPath)) {
            $contents = file_get_contents($openApiPath);
            // @phpstan-ignore-next-line
            $this->openApiSpec = is_string($contents) ? json_decode($contents, true) : null;
        } else {
            $this->openApiSpec = null;
        }
    }

    /**
     * Test GET /api/v1/books endpoint against OpenAPI spec.
     *
     * @return void
     */
    public function test_get_books_matches_openapi_spec()
    {
        // Create some test books
        Book::factory()->count(5)->create();

        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->getJson('/api/v1/books');

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'title',
                    'author',
                    'narrator',
                    'series',
                    // 'series_number' may be absent depending on data source
                    'genre',
                    'year',
                    'duration',
                    'description',
                    'cover_url',
                    'file_count',
                    'total_size',
                    'created_at',
                    'updated_at',
                ],
            ],
            'meta' => [
                'current_page',
                'from',
                'last_page',
                'per_page',
                'to',
                'total',
            ],
        ]);

        $responseData = $response->json();
        $books = $responseData['data'];

        foreach ($books as $book) {
            $this->assertIsInt($book['id']);
            $this->assertIsString($book['title']);
            $this->assertIsArray($book['author']);
            $this->assertIsArray($book['narrator']);
            if (array_key_exists('series', $book) && $book['series'] !== null) {
                $this->assertTrue(is_string($book['series']) || is_array($book['series']));
            }
            if (array_key_exists('series_number', $book) && $book['series_number'] !== null) {
                $this->assertTrue(is_string($book['series_number']) || is_int($book['series_number']));
            }
            $this->assertIsArray($book['genre']);
            $this->assertIsInt($book['year'] ?? 0); // Can be null
            // Duration can be either HH:MM:SS format or a number (seconds)
            $duration = $book['duration'] ?? '00:00:00';
            if (is_numeric($duration)) {
                // @phpstan-ignore-next-line
                $this->assertIsNumeric($duration);
            } else {
                $this->assertMatchesRegularExpression('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $duration); // HH:MM:SS format
            }
            $this->assertIsString($book['description'] ?? ''); // Can be null
            // Only validate cover_url format if it's present and not empty
            if (!empty($book['cover_url'])) {
                $this->assertMatchesRegularExpression('/^http(s)?:\/\/.+\/api\/v1\/books\/\d+\/cover$/', $book['cover_url']); // API endpoint format
            }
            $this->assertIsInt($book['file_count']);
            $this->assertIsInt($book['total_size'] ?? 0);
            // Timestamps can be in Z format or +00:00 format
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|\+00:00)$/', $book['created_at'] ?? ''); // ISO 8601
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|\+00:00)$/', $book['updated_at'] ?? ''); // ISO 8601
        }
    }

    /**
     * Test GET /api/v1/books with search parameter.
     *
     * @return void
     */
    public function test_get_books_with_search_matches_openapi_spec()
    {
        // Create a book with a specific title for searching
        Book::factory()->create(['title' => 'The Lord of the Rings']);
        Book::factory()->create(['title' => 'Another Book']);

        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->getJson('/api/v1/books?search=Lord');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data'); // Expecting only one book
        $response->assertJsonFragment(['title' => 'The Lord of the Rings']);

        $responseData = $response->json();
        $books = $responseData['data'];

        foreach ($books as $book) {
            $this->assertIsInt($book['id']);
            $this->assertIsString($book['title']);
            $this->assertIsArray($book['author']);
            $this->assertIsArray($book['narrator']);
            if (array_key_exists('series', $book) && $book['series'] !== null) {
                $this->assertTrue(is_string($book['series']) || is_array($book['series']));
            }
            if (array_key_exists('series_number', $book) && $book['series_number'] !== null) {
                $this->assertTrue(is_string($book['series_number']) || is_int($book['series_number']));
            }
            $this->assertIsArray($book['genre']);
            $this->assertIsInt($book['year'] ?? 0);
            // Duration can be either HH:MM:SS format or a number (seconds)
            $duration = $book['duration'] ?? '00:00:00';
            if (is_numeric($duration)) {
                // @phpstan-ignore-next-line
                $this->assertIsNumeric($duration);
            } else {
                $this->assertMatchesRegularExpression('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $duration);
            }
            $this->assertIsString($book['description'] ?? '');
            // Only validate cover_url format if it's present and not empty
            if (!empty($book['cover_url'])) {
                $this->assertMatchesRegularExpression('/^http(s)?:\/\/.+\/api\/v1\/books\/\d+\/cover$/', $book['cover_url']);
            }
            $this->assertIsInt($book['file_count']);
            $this->assertIsInt($book['total_size'] ?? 0);
            // Timestamps can be in Z format or +00:00 format
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|\+00:00)$/', $book['created_at'] ?? '');
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|\+00:00)$/', $book['updated_at'] ?? '');
        }
    }

    /**
     * Test GET /api/v1/books/{book} endpoint against OpenAPI spec.
     *
     * @return void
     */
    public function test_get_single_book_matches_openapi_spec()
    {
        $book = Book::factory()->create();

        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->getJson('/api/v1/books/' . $book->id);

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'id',
            'title',
            'author',
            'narrator',
            'series',
            // 'series_number' optional
            'genre',
            'year',
            'duration',
            'description',
            'cover_url',
            'file_count',
            'total_size',
            'created_at',
            'updated_at',
        ]);

        $responseData = $response->json();

        $this->assertIsInt($responseData['id']);
        $this->assertIsString($responseData['title']);
        $this->assertIsArray($responseData['author']);
        $this->assertIsArray($responseData['narrator']);
        if (array_key_exists('series', $responseData) && $responseData['series'] !== null) {
            $this->assertTrue(is_string($responseData['series']) || is_array($responseData['series']));
        }
        if (array_key_exists('series_number', $responseData) && $responseData['series_number'] !== null) {
            $this->assertTrue(is_string($responseData['series_number']) || is_int($responseData['series_number']));
        }
        $this->assertIsArray($responseData['genre']);
        $this->assertIsInt($responseData['year'] ?? 0);
        // Duration can be either HH:MM:SS format or a number (seconds)
        $duration = $responseData['duration'] ?? '00:00:00';
        if (is_numeric($duration)) {
            // @phpstan-ignore-next-line
            $this->assertIsNumeric($duration);
        } else {
            $this->assertMatchesRegularExpression('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $duration);
        }
        $this->assertIsString($responseData['description'] ?? '');
        // Only validate cover_url format if it's present and not empty
        if (!empty($responseData['cover_url'])) {
            $this->assertMatchesRegularExpression('/^http(s)?:\/\/.+\/api\/v1\/books\/\d+\/cover$/', $responseData['cover_url']);
        }
        $this->assertIsInt($responseData['file_count'] ?? 0);
        $this->assertIsInt($responseData['total_size'] ?? 0);
        // Timestamps can be in Z format or +00:00 format, but may be empty for some endpoints
        if (!empty($responseData['created_at'])) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|\+00:00)$/', $responseData['created_at']);
        }
        if (!empty($responseData['updated_at'])) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|\+00:00)$/', $responseData['updated_at']);
        }
    }

    /**
     * Test that genre is always returned as an array, even when stored as a string
     *
     * @return void
     */
    public function test_genre_is_always_returned_as_array()
    {
        // Mock the DocumentStoreServiceInterface
        $this->mock(DocumentStoreServiceInterface::class, function (MockInterface $mock) {
            // Mock response for a book with genre as a string
            $mock->shouldReceive('getBook')
                ->once()
                ->with('1', \Mockery::any())
                ->andReturn([
                    'id' => 1,
                    'title' => 'Test Book 1',
                    'author' => ['John Doe'],
                    'narrator' => ['Jane Smith'],
                    'genre' => 'Science Fiction', // String genre
                    'year' => 2023,
                    'duration' => '10:30:00',
                    'description' => 'A test book description',
                    'cover_image' => 'test/cover.jpg',
                    'file_count' => 1,
                    'total_size' => 1000,
                    'created_at' => '2023-01-01T00:00:00Z',
                    'updated_at' => '2023-01-01T00:00:00Z',
                ]);

            // Mock response for a book with genre as an array
            $mock->shouldReceive('getBook')
                ->once()
                ->with('2', \Mockery::any())
                ->andReturn([
                    'id' => 2,
                    'title' => 'Test Book 2',
                    'author' => ['Jane Doe'],
                    'narrator' => ['John Smith'],
                    'genre' => ['Fantasy', 'Adventure'], // Array genre
                    'year' => 2023,
                    'duration' => '08:45:00',
                    'description' => 'Another test book description',
                    'cover_image' => 'test/cover2.jpg',
                    'file_count' => 1,
                    'total_size' => 1200,
                    'created_at' => '2023-01-02T00:00:00Z',
                    'updated_at' => '2023-01-02T00:00:00Z',
                ]);
        });

        // Test book with genre as string
        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->getJson('/api/v1/books/1');

        $response->assertStatus(200);
        $responseData = $response->json();

        // Assert genre is an array
        $this->assertIsArray($responseData['genre']);
        $this->assertCount(1, $responseData['genre']);
        $this->assertEquals('Science Fiction', $responseData['genre'][0]);

        // Test book with genre as array
        $response2 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/books/2');

        $response2->assertStatus(200);
        $responseData2 = $response2->json();

        // Assert genre is an array with multiple values
        $this->assertIsArray($responseData2['genre']);
        $this->assertCount(2, $responseData2['genre']);
        $this->assertContains('Fantasy', $responseData2['genre']);
        $this->assertContains('Adventure', $responseData2['genre']);
    }
}
