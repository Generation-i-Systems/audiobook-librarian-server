<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

use Illuminate\Support\Facades\Artisan;
use App\Models\User;
use App\Models\Book;
use Illuminate\Support\Facades\Storage;

class BookApiOpenApiTest extends TestCase
{
    

    protected $token;
    protected $openApiSpec;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh --seed');
        Artisan::call('passport:install');

        // Create a user and get a token
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'role' => 'admin' // Or 'user' depending on what's needed for book access
        ]);
        $this->token = $user->createToken('test-token')->plainTextToken;

        // Load OpenAPI specification
        $this->openApiSpec = json_decode(file_get_contents(public_path('api-docs/openapi.json')), true);
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
            'Authorization' => 'Bearer ' . $this->token,
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
                    'series_number',
                    'genre',
                    'year',
                    'duration',
                    'description',
                    'cover_url',
                    'file_count',
                    'total_size',
                    'created_at',
                    'updated_at',
                ]
            ],
            'meta' => [
                'current_page',
                'from',
                'last_page',
                'per_page',
                'to',
                'total',
            ]
        ]);

        $responseData = $response->json();
        $books = $responseData['data'];

        foreach ($books as $book) {
            $this->assertIsInt($book['id']);
            $this->assertIsString($book['title']);
            $this->assertIsArray($book['author']);
            $this->assertIsArray($book['narrator']);
            $this->assertIsString($book['series'] ?? ''); // Can be null
            $this->assertIsString($book['series_number'] ?? ''); // Can be null
            $this->assertIsArray($book['genre']);
            $this->assertIsInt($book['year'] ?? 0); // Can be null
            $this->assertMatchesRegularExpression('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $book['duration'] ?? '00:00:00'); // HH:MM:SS format
            $this->assertIsString($book['description'] ?? ''); // Can be null
            $this->assertMatchesRegularExpression('/^http(s)?:\/\/.+\/api\/v1\/books\/\d+\/cover$/', $book['cover_url'] ?? ''); // API endpoint format
            $this->assertIsInt($book['file_count']);
            $this->assertIsInt($book['total_size']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $book['created_at'] ?? ''); // ISO 8601
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $book['updated_at'] ?? ''); // ISO 8601
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
            'Authorization' => 'Bearer ' . $this->token,
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
            $this->assertIsString($book['series'] ?? '');
            $this->assertIsString($book['series_number'] ?? '');
            $this->assertIsArray($book['genre']);
            $this->assertIsInt($book['year'] ?? 0);
            $this->assertMatchesRegularExpression('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $book['duration'] ?? '00:00:00');
            $this->assertIsString($book['description'] ?? '');
            $this->assertMatchesRegularExpression('/^http(s)?:\/\/.+\/api\/v1\/books\/\d+\/cover$/', $book['cover_url'] ?? '');
            $this->assertIsInt($book['file_count']);
            $this->assertIsInt($book['total_size']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $book['created_at'] ?? '');
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $book['updated_at'] ?? '');
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
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/books/' . $book->id);

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'id',
            'title',
            'author',
            'narrator',
            'series',
            'series_number',
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
        $this->assertIsString($responseData['series'] ?? '');
        $this->assertIsString($responseData['series_number'] ?? '');
        $this->assertIsArray($responseData['genre']);
        $this->assertIsInt($responseData['year'] ?? 0);
        $this->assertMatchesRegularExpression('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $responseData['duration'] ?? '00:00:00');
        $this->assertIsString($responseData['description'] ?? '');
        $this->assertMatchesRegularExpression('/^http(s)?:\/\/.+\/api\/v1\/books\/\d+\/cover$/', $responseData['cover_url'] ?? '');
        $this->assertIsInt($responseData['file_count']);
        $this->assertIsInt($responseData['total_size']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $responseData['created_at'] ?? '');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $responseData['updated_at'] ?? '');
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
            'Authorization' => 'Bearer ' . $this->token,
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
                    'series_number',
                    'genre',
                    'year',
                    'duration',
                    'description',
                    'cover_url',
                    'file_count',
                    'total_size',
                    'created_at',
                    'updated_at',
                ]
            ],
            'meta' => [
                'current_page',
                'from',
                'last_page',
                'per_page',
                'to',
                'total',
            ]
        ]);

        $responseData = $response->json();
        $books = $responseData['data'];

        foreach ($books as $book) {
            $this->assertIsInt($book['id']);
            $this->assertIsString($book['title']);
            $this->assertIsArray($book['author']);
            $this->assertIsArray($book['narrator']);
            $this->assertIsString($book['series'] ?? ''); // Can be null
            $this->assertIsString($book['series_number'] ?? ''); // Can be null
            $this->assertIsArray($book['genre']);
            $this->assertIsInt($book['year'] ?? 0); // Can be null
            $this->assertMatchesRegularExpression('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $book['duration'] ?? '00:00:00'); // HH:MM:SS format
            $this->assertIsString($book['description'] ?? ''); // Can be null
            $this->assertMatchesRegularExpression('/^http(s)?:\/\/.+\/api\/v1\/books\/\d+\/cover$/', $book['cover_url'] ?? ''); // API endpoint format
            $this->assertIsInt($book['file_count']);
            $this->assertIsInt($book['total_size']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $book['created_at'] ?? ''); // ISO 8601
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $book['updated_at'] ?? ''); // ISO 8601
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
            'Authorization' => 'Bearer ' . $this->token,
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
            $this->assertIsString($book['series'] ?? '');
            $this->assertIsString($book['series_number'] ?? '');
            $this->assertIsArray($book['genre']);
            $this->assertIsInt($book['year'] ?? 0);
            $this->assertMatchesRegularExpression('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $book['duration'] ?? '00:00:00');
            $this->assertIsString($book['description'] ?? '');
            $this->assertMatchesRegularExpression('/^http(s)?:\/\/.+\/api\/v1\/books\/\d+\/cover$/', $book['cover_url'] ?? '');
            $this->assertIsInt($book['file_count']);
            $this->assertIsInt($book['total_size']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $book['created_at'] ?? '');
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $book['updated_at'] ?? '');
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
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/books/' . $book->id);

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'id',
            'title',
            'author',
            'narrator',
            'series',
            'series_number',
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
        $this->assertIsString($responseData['series'] ?? '');
        $this->assertIsString($responseData['series_number'] ?? '');
        $this->assertIsArray($responseData['genre']);
        $this->assertIsInt($responseData['year'] ?? 0);
        $this->assertMatchesRegularExpression('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $responseData['duration'] ?? '00:00:00');
        $this->assertIsString($responseData['description'] ?? '');
        $this->assertMatchesRegularExpression('/^http(s)?:\/\/.+\/api\/v1\/books\/\d+\/cover$/', $responseData['cover_url'] ?? '');
        $this->assertIsInt($responseData['file_count']);
        $this->assertIsInt($responseData['total_size']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $responseData['created_at'] ?? '');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $responseData['updated_at'] ?? '');
    }
}
