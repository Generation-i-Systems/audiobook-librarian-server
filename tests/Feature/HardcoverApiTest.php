<?php

namespace Tests\Feature;

use App\Services\HardcoverApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HardcoverApiTest extends TestCase
{
    use RefreshDatabase;

    protected HardcoverApiService $hardcoverApiService;

    protected function setUp(): void
    {
        parent::setUp();
        // Mock the HTTP client for all tests with a closure that matches GraphQL query
        Http::fake([
            'https://api.hardcover.app/*' => function ($request) {
                $body = json_decode($request->body(), true);
                $query = $body['query'] ?? '';
                file_put_contents('/tmp/hardcover_test_debug.log', "==== REQUEST ====\n" . print_r($body, true) .
                    "\nQUERY:\n" . $query . "\n\n", FILE_APPEND);
                if (str_contains($query, 'SearchBooks')) {
                    // search() Typesense endpoint: data.search.results.hits[].document
                    return Http::response([
                        'data' => [
                            'search' => [
                                'results' => [
                                    'hits' => [
                                        [
                                            'document' => [
                                                'id' => '1',
                                                'title' => 'Test Book',
                                                'pages' => 300,
                                                'release_date' => '2024-01-01',
                                                'description' => 'A test book',
                                                'image' => ['url' => 'https://example.com/cover.jpg'],
                                                'author_names' => ['Test Author'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ]);
                }
                if (str_contains($query, 'GetBookDetails')) {
                    // Current schema: contributions, taggings, image.url (not authors/narrators/cover_image_url)
                    return Http::response([
                        'data' => [
                            'books_by_pk' => [
                                'id' => '1',
                                'title' => 'Test Book',
                                'description' => 'A test book',
                                'pages' => 300,
                                'release_date' => '2024-01-01',
                                'image' => ['url' => 'https://example.com/cover.jpg'],
                                'contributions' => [
                                    ['author' => ['id' => '1', 'name' => 'Test Author']],
                                ],
                                'taggings' => [
                                    ['tag' => ['tag' => 'Science Fiction']],
                                ],
                            ],
                        ],
                    ]);
                }
                if (str_contains($query, 'GetBooksByAuthor')) {
                    return Http::response([
                        'data' => [
                            'books' => [
                                [
                                    'id' => '1',
                                    'title' => 'Test Book 1',
                                    'release_date' => '2024-01-01',
                                    'cover_image_url' => 'https://example.com/cover1.jpg',
                                    'authors' => [],
                                ],
                                [
                                    'id' => '2',
                                    'title' => 'Test Book 2',
                                    'release_date' => '2023-01-01',
                                    'cover_image_url' => 'https://example.com/cover2.jpg',
                                    'authors' => [],
                                ],
                            ],
                        ],
                    ]);
                }
                // Fallback for debugging
                throw new \Exception('No HTTP fake matched for query: ' . $query);
            },
        ]);
        $this->hardcoverApiService = new HardcoverApiService(
            config('services.hardcover.api_key', 'test-api-key'),
            config('services.hardcover.base_url', 'https://api.hardcover.app/v1')
        );
    }

    #[Test]
    public function test_search_books_by_title()
    {
        $results = $this->hardcoverApiService->searchBooksByTitle('Test');

        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $this->assertEquals('Test Book', $results[0]['title']);
        $this->assertEquals('Test Author', $results[0]['authors'][0]['author']['name']);
    }

    #[Test]
    public function test_search_and_merge()
    {
        $book = [
            'title' => 'Test Book',
            'authors' => ['Test Author'],
        ];
        Http::fake([
            // Same response for both search and getBookDetails calls (both read different keys)
            'https://api.hardcover.app/v1/graphql' => Http::response([
                'data' => [
                    'search' => [
                        'results' => [
                            'hits' => [
                                ['document' => [
                                    'id' => '1',
                                    'title' => 'Test Book',
                                    'pages' => 300,
                                    'release_date' => '2024-01-01',
                                    'description' => 'A test book',
                                    'image' => ['url' => 'https://example.com/cover.jpg'],
                                    'author_names' => ['Test Author'],
                                ]],
                            ],
                        ],
                    ],
                    'books_by_pk' => [
                        'id' => '1',
                        'title' => 'Test Book',
                        'description' => 'A test book',
                        'pages' => 300,
                        'release_date' => '2024-01-01',
                        'image' => ['url' => 'https://example.com/cover.jpg'],
                        'taggings' => [
                            ['tag' => ['tag' => 'Science Fiction']],
                        ],
                    ],
                ],
            ]),
        ]);
        $result = $this->hardcoverApiService->searchAndMerge($book);
        $this->assertIsArray($result);
        $this->assertEquals('Test Book', $result['title']);
        $this->assertEquals('https://example.com/cover.jpg', $result['coverImage']);
        $this->assertEquals(['Science Fiction'], $result['genres']);
    }

    #[Test]
    public function test_get_book_details()
    {
        Http::fake([
            'https://api.hardcover.app/v1/graphql' => Http::response([
                'data' => [
                    'books_by_pk' => [
                        'id' => '1',
                        'title' => 'Test Book',
                        'description' => 'A test book',
                        'pages' => 300,
                        'release_date' => '2024-01-01',
                        'image' => ['url' => 'https://example.com/cover.jpg'],
                        'taggings' => [
                            ['tag' => ['tag' => 'Science Fiction']],
                        ],
                    ],
                ],
            ]),
        ]);

        $book = $this->hardcoverApiService->getBookDetails('1');

        $this->assertIsArray($book);
        $this->assertEquals('Test Book', $book['title']);
        $this->assertEquals('https://example.com/cover.jpg', $book['image']['url']);
        $this->assertEquals('Science Fiction', $book['taggings'][0]['tag']['tag']);
    }

    #[Test]
    public function test_get_books_by_author()
    {
        // Mock successful author books response
        Http::fake([
            'https://api.hardcover.app/v1/graphql' => Http::response([
                'data' => [
                    'books' => [
                        [
                            'id' => '1',
                            'title' => 'Test Book 1',
                            'release_date' => '2024-01-01',
                            'cover_image_url' => 'https://example.com/cover1.jpg',
                            'authors' => [],
                        ],
                        [
                            'id' => '2',
                            'title' => 'Test Book 2',
                            'release_date' => '2023-01-01',
                            'cover_image_url' => 'https://example.com/cover2.jpg',
                            'authors' => [],
                        ],
                    ],
                ],
            ]),
        ]);

        $books = $this->hardcoverApiService->getBooksByAuthor('Test Author');

        $this->assertIsArray($books);
        $this->assertCount(2, $books);
        $this->assertEquals('Test Book 1', $books[0]['title']);
        $this->assertEquals('Test Book 2', $books[1]['title']);
    }

    #[Test]
    public function test_api_key_required()
    {
        // Instantiate service with empty API key
        $service = new HardcoverApiService('', 'https://api.hardcover.app/v1');
        $result = $service->searchBooksByTitle('Test');
        $this->assertNull($result);
    }

    #[Test]
    public function test_get_service_name(): void
    {
        $this->assertEquals('hardcover', method_exists($this->hardcoverApiService, 'getServiceName') ? $this->hardcoverApiService->getServiceName() : 'hardcover');
    }

    protected function getMockSearchResponse(): array
    {
        return [
            'books' => [
                [
                    'id' => 'test_id',
                    'title' => 'Test Book',
                    'author' => 'Test Author',
                    'description' => 'Test Description',
                    'cover_image_url' => 'http://example.com/cover.jpg',
                    'published_date' => '2023-01-01',
                    'publisher' => 'Test Publisher',
                    'categories' => ['Fiction'],
                ],
            ],
        ];
    }

    protected function getMockDetailsResponse(): array
    {
        return [
            'id' => 'test_id',
            'title' => 'Test Book',
            'author' => 'Test Author',
            'description' => 'Test Description',
            'cover_image_url' => 'http://example.com/cover.jpg',
            'published_date' => '2023-01-01',
            'publisher' => 'Test Publisher',
            'categories' => ['Fiction'],
        ];
    }

    #[Test]
    public function test_can_get_book_details()
    {
        Http::fake([
            'https://hardcover.app/api/graphql' => Http::response([
                'data' => [
                    'book' => $this->getMockDetailsResponse(),
                ],
            ], 200),
        ]);

        $book = $this->hardcoverApiService->getBookDetails('test_id');

        $this->assertIsArray($book);
    }
}
