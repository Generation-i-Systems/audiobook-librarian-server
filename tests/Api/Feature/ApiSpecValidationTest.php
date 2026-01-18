<?php

namespace Tests\Api\Feature;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * API Specification Validation Test Suite
 *
 * This test suite validates that API responses conform to the OpenAPI specification.
 * It can be run independently with: php artisan test --filter=ApiSpecValidationTest
 */
#[Group('api-spec')]
#[Group('api-validation')]
class ApiSpecValidationTest extends TestCase
{
    use RefreshDatabase;

    protected array $openApiSpec;

    protected function setUp(): void
    {
        parent::setUp();

        // Create admin user for authenticated tests
        /** @var User $user */
        $user = User::factory()->createOne([
            'email' => 'api-spec-test@example.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);
        $this->actingAs($user);

        // Load OpenAPI specification
        $this->loadOpenApiSpec();
    }

    protected function loadOpenApiSpec(): void
    {
        $paths = [
            base_path('docs/openapi.json'),
            public_path('api-docs/openapi.json'),
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $contents = file_get_contents($path);
                $this->openApiSpec = json_decode($contents, true) ?? [];
                return;
            }
        }

        $this->openApiSpec = [];
    }

    /**
     * Get the Book schema from OpenAPI spec
     */
    protected function getBookSchema(): array
    {
        return $this->openApiSpec['components']['schemas']['Book'] ?? [];
    }

    /**
     * Validate that a series entry matches the OpenAPI spec format
     */
    protected function assertValidSeriesFormat(array $seriesArray, string $context = ''): void
    {
        foreach ($seriesArray as $index => $series) {
            $itemContext = $context ? "{$context} series[{$index}]" : "series[{$index}]";

            // Series must be an object with name and series_number
            $this->assertIsArray($series, "{$itemContext} must be an object");

            // name must be a non-null string (per spec - not nullable)
            $this->assertArrayHasKey('name', $series, "{$itemContext} must have 'name' key");
            $this->assertNotNull($series['name'], "{$itemContext} name must not be null");
            $this->assertIsString($series['name'], "{$itemContext} name must be a string");
            $this->assertNotEmpty($series['name'], "{$itemContext} name must not be empty");

            // series_number can be string or null (nullable in spec)
            $this->assertArrayHasKey('series_number', $series, "{$itemContext} must have 'series_number' key");
            if ($series['series_number'] !== null) {
                $this->assertTrue(
                    is_string($series['series_number']) || is_numeric($series['series_number']),
                    "{$itemContext} series_number must be string, numeric, or null"
                );
            }
        }
    }

    /**
     * Validate that author field matches OpenAPI spec (array of strings)
     */
    protected function assertValidAuthorFormat(mixed $author, string $context = ''): void
    {
        $this->assertIsArray($author, "{$context} author must be an array");
        foreach ($author as $index => $name) {
            $this->assertIsString($name, "{$context} author[{$index}] must be a string");
        }
    }

    /**
     * Validate that narrator field matches OpenAPI spec (array of strings)
     */
    protected function assertValidNarratorFormat(mixed $narrator, string $context = ''): void
    {
        $this->assertIsArray($narrator, "{$context} narrator must be an array");
        foreach ($narrator as $index => $name) {
            $this->assertIsString($name, "{$context} narrator[{$index}] must be a string");
        }
    }

    /**
     * Validate that genre field matches OpenAPI spec (array of strings)
     */
    protected function assertValidGenreFormat(mixed $genre, string $context = ''): void
    {
        $this->assertIsArray($genre, "{$context} genre must be an array");
        foreach ($genre as $index => $name) {
            $this->assertIsString($name, "{$context} genre[{$index}] must be a string");
        }
    }

    /**
     * Validate a complete book response against OpenAPI spec
     */
    protected function assertValidBookResponse(array $book, string $context = ''): void
    {
        // Required fields
        $this->assertArrayHasKey('id', $book, "{$context} must have 'id'");
        $this->assertIsInt($book['id'], "{$context} id must be integer");

        $this->assertArrayHasKey('title', $book, "{$context} must have 'title'");
        $this->assertIsString($book['title'], "{$context} title must be string");

        // Author array
        $this->assertArrayHasKey('author', $book, "{$context} must have 'author'");
        $this->assertValidAuthorFormat($book['author'], $context);

        // Narrator array
        $this->assertArrayHasKey('narrator', $book, "{$context} must have 'narrator'");
        $this->assertValidNarratorFormat($book['narrator'], $context);

        // Series array (critical validation)
        $this->assertArrayHasKey('series', $book, "{$context} must have 'series'");
        $this->assertIsArray($book['series'], "{$context} series must be an array");
        $this->assertValidSeriesFormat($book['series'], $context);

        // Genre array
        $this->assertArrayHasKey('genre', $book, "{$context} must have 'genre'");
        $this->assertValidGenreFormat($book['genre'], $context);

        // Nullable integer fields
        if (array_key_exists('year', $book) && $book['year'] !== null) {
            $this->assertIsInt($book['year'], "{$context} year must be integer or null");
        }

        if (array_key_exists('file_count', $book) && $book['file_count'] !== null) {
            $this->assertIsInt($book['file_count'], "{$context} file_count must be integer or null");
        }

        if (array_key_exists('total_size', $book) && $book['total_size'] !== null) {
            $this->assertIsInt($book['total_size'], "{$context} total_size must be integer or null");
        }

        // Nullable string fields
        if (array_key_exists('duration', $book) && $book['duration'] !== null) {
            $this->assertTrue(
                is_string($book['duration']) || is_int($book['duration']),
                "{$context} duration must be string, integer, or null"
            );
        }

        if (array_key_exists('description', $book) && $book['description'] !== null) {
            $this->assertIsString($book['description'], "{$context} description must be string or null");
        }

        if (array_key_exists('cover_url', $book) && $book['cover_url'] !== null) {
            $this->assertIsString($book['cover_url'], "{$context} cover_url must be string or null");
        }

        // Timestamp fields
        if (array_key_exists('created_at', $book) && $book['created_at'] !== null) {
            $this->assertIsString($book['created_at'], "{$context} created_at must be string");
        }

        if (array_key_exists('updated_at', $book) && $book['updated_at'] !== null) {
            $this->assertIsString($book['updated_at'], "{$context} updated_at must be string");
        }
    }

    /**
     * Test: Series field never contains null values
     */
    #[Test]
    public function testSeriesFieldNeverContainsNullValues(): void
    {
        $this->mock(DocumentStoreServiceInterface::class, function (MockInterface $mock) {
            // Mock a book with series containing null values (bad data)
            $mock->shouldReceive('getBook')
                ->with('1', \Mockery::any())
                ->andReturn([
                    'id' => 1,
                    'title' => 'Test Book With Bad Series',
                    'author' => ['Test Author'],
                    'narrator' => ['Test Narrator'],
                    'series' => [
                        ['name' => null, 'pivot' => ['series_number' => null]], // Should be filtered
                        ['name' => '', 'pivot' => ['series_number' => '1']], // Should be filtered
                        ['name' => 'Valid Series', 'pivot' => ['series_number' => '2']], // Should be kept
                    ],
                    'genre' => ['Fantasy'],
                    'year' => 2023,
                    'duration' => '10:30:00',
                    'description' => 'Test description',
                    'coverImage' => null,
                    'file_count' => 1,
                    'total_size' => 1000,
                    'created_at' => '2023-01-01T00:00:00Z',
                    'updated_at' => '2023-01-01T00:00:00Z',
                ]);
        });

        $response = $this->getJson('/api/v1/books/1', ['X-Acting-As-Test' => '1']);

        $response->assertStatus(200);
        $book = $response->json();

        // Validate series format
        $this->assertIsArray($book['series']);

        // Should only contain the valid series entry
        $this->assertCount(1, $book['series'], 'Should filter out null/empty series names');
        $this->assertEquals('Valid Series', $book['series'][0]['name']);
        $this->assertEquals('2', $book['series'][0]['series_number']);
    }

    /**
     * Test: Empty series returns empty array, not array with nulls
     */
    #[Test]
    public function testEmptySeriesReturnsEmptyArray(): void
    {
        $this->mock(DocumentStoreServiceInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('getBook')
                ->with('2', \Mockery::any())
                ->andReturn([
                    'id' => 2,
                    'title' => 'Book Without Series',
                    'author' => ['Author Name'],
                    'narrator' => ['Narrator Name'],
                    'series' => [], // Empty series
                    'genre' => ['Science Fiction'],
                    'year' => 2022,
                    'duration' => '08:00:00',
                    'description' => 'No series book',
                    'coverImage' => null,
                    'file_count' => 5,
                    'total_size' => 5000,
                    'created_at' => '2022-01-01T00:00:00Z',
                    'updated_at' => '2022-01-01T00:00:00Z',
                ]);
        });

        $response = $this->getJson('/api/v1/books/2', ['X-Acting-As-Test' => '1']);

        $response->assertStatus(200);
        $book = $response->json();

        $this->assertIsArray($book['series']);
        $this->assertEmpty($book['series'], 'Empty series should return empty array');
    }

    /**
     * Test: Valid series data is properly formatted
     */
    #[Test]
    public function testValidSeriesDataIsProperlyFormatted(): void
    {
        $this->mock(DocumentStoreServiceInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('getBook')
                ->with('3', \Mockery::any())
                ->andReturn([
                    'id' => 3,
                    'title' => 'Book With Valid Series',
                    'author' => ['Brandon Sanderson'],
                    'narrator' => ['Michael Kramer'],
                    'series' => [
                        ['name' => 'The Stormlight Archive', 'pivot' => ['series_number' => '1']],
                        ['name' => 'Cosmere', 'pivot' => ['series_number' => null]],
                    ],
                    'genre' => ['Fantasy', 'Epic Fantasy'],
                    'year' => 2010,
                    'duration' => '45:30:00',
                    'description' => 'Epic fantasy novel',
                    'coverImage' => null,
                    'file_count' => 10,
                    'total_size' => 100000,
                    'created_at' => '2020-01-01T00:00:00Z',
                    'updated_at' => '2020-06-01T00:00:00Z',
                ]);
        });

        $response = $this->getJson('/api/v1/books/3', ['X-Acting-As-Test' => '1']);

        $response->assertStatus(200);
        $book = $response->json();

        $this->assertValidBookResponse($book, 'Book 3');

        // Check specific series format
        $this->assertCount(2, $book['series']);

        $this->assertEquals('The Stormlight Archive', $book['series'][0]['name']);
        $this->assertEquals('1', $book['series'][0]['series_number']);

        $this->assertEquals('Cosmere', $book['series'][1]['name']);
        $this->assertNull($book['series'][1]['series_number']);
    }

    /**
     * Test: GET /api/v1/books list response structure
     */
    #[Test]
    public function testBooksListResponseStructure(): void
    {
        // Mock listBooks to avoid 500 from updated service interface
        $this->mock(DocumentStoreServiceInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('listBooks')
                ->with(1, 15, \Mockery::any(), true, 'title', 'asc', false, \Mockery::any())
                ->andReturn([
                    'data' => [
                        [
                            'id' => 1,
                            'title' => 'Test Book',
                            'author' => ['Author'],
                            'narrator' => ['Narrator'],
                            'series' => [],
                            'genre' => ['Genre'],
                            'year' => 2023,
                            'file_count' => 1,
                            'total_size' => 1000,
                            'created_at' => now()->toIso8601String(),
                            'updated_at' => now()->toIso8601String(),
                        ]
                    ],
                    'total' => 1,
                    'per_page' => 15,
                    'current_page' => 1,
                    'lastPage' => 1,
                ]);
        });

        $response = $this->getJson('/api/v1/books', ['X-Acting-As-Test' => '1']);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'title',
                    'author',
                    'narrator',
                    'series',
                    'genre',
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

        $data = $response->json();
        foreach ($data['data'] as $index => $book) {
            $this->assertValidBookResponse($book, "Book list item {$index}");
        }
    }

    /**
     * Test: GET /api/v1/books/{id} single book response structure
     */
    #[Test]
    public function testSingleBookResponseStructure(): void
    {
        $book = Book::factory()->create();

        $response = $this->getJson('/api/v1/books/' . $book->id, ['X-Acting-As-Test' => '1']);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'id',
            'title',
            'author',
            'narrator',
            'series',
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
    }

    /**
     * Test: Pagination meta matches OpenAPI spec
     */
    #[Test]
    public function testPaginationMetaMatchesSpec(): void
    {
        $this->mock(DocumentStoreServiceInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('listBooks')
                ->with(2, 5, \Mockery::any(), true, 'title', 'asc', false, \Mockery::any())
                ->andReturn([
                    'data' => [],
                    'total' => 20,
                    'per_page' => 5,
                    'current_page' => 2,
                    'lastPage' => 4,
                ]);
        });

        $response = $this->getJson('/api/v1/books?per_page=5&page=2', ['X-Acting-As-Test' => '1']);

        $response->assertStatus(200);

        $meta = $response->json('meta');

        $this->assertArrayHasKey('current_page', $meta);
        $this->assertArrayHasKey('from', $meta);
        $this->assertArrayHasKey('last_page', $meta);
        $this->assertArrayHasKey('per_page', $meta);
        $this->assertArrayHasKey('to', $meta);
        $this->assertArrayHasKey('total', $meta);

        // Values can be int or numeric string (OpenAPI spec allows both integer types)
        $this->assertIsNumeric($meta['current_page']);
        $this->assertEquals(2, (int) $meta['current_page']);

        $this->assertIsNumeric($meta['per_page']);
        $this->assertEquals(5, (int) $meta['per_page']);

        // from and to can be null for empty pages
        if ($meta['from'] !== null) {
            $this->assertIsNumeric($meta['from']);
        }
        if ($meta['to'] !== null) {
            $this->assertIsNumeric($meta['to']);
        }

        $this->assertIsNumeric($meta['last_page']);
        $this->assertIsNumeric($meta['total']);
    }
}
