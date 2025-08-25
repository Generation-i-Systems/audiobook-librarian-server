<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AudibleService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AudibleServiceTest extends TestCase
{
    private AudibleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the cache to prevent database connections
        Cache::shouldReceive('remember')
            ->andReturnUsing(fn ($key, $seconds, $callback) => $callback());

        $this->service = new AudibleService();
    }

    #[Test]
    public function testSearchBooksByTitle(): void
    {
        // Mock the HTTP response
        Http::fake([
            'api.audible.com/1.0/catalog/products*' => Http::response([
                'products' => [
                    $this->getMockBookData(),
                ],
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $results = $this->service->searchBooks('Test Book');

        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $result = $results[0];
        $this->assertEquals('Test Book', $result['title']);
        $this->assertArrayHasKey('authors', $result);
        $this->assertIsArray($result['authors']);
        $this->assertNotEmpty($result['authors']);
        $this->assertArrayHasKey('author', $result['authors'][0]);
        $this->assertEquals('Test Author', $result['authors'][0]['author']['name']);
        $this->assertStringContainsString('500x500', $result['coverImageUrl']);
        $this->assertArrayHasKey('publisher', $result);
        $this->assertEquals('Test Publisher', $result['publisher']['name']);
        $this->assertEquals('This is a test book description.', $result['description']);
    }

    #[Test]
    public function testGetBookDetails(): void
    {
        $mockBook = $this->getMockBookData();

        // Mock the HTTP response
        Http::fake([
            'api.audible.com/1.0/catalog/products/TEST123*' => Http::response([
                'product' => $mockBook,
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        $book = $this->service->getBookDetails('TEST123');

        $this->assertIsArray($book);
        $this->assertEquals('TEST123', $book['id']);
        $this->assertEquals('Test Book', $book['title']);
        // Test authors
        $this->assertIsArray($book['authors']);
        $this->assertNotEmpty($book['authors']);
        $this->assertArrayHasKey('author', $book['authors'][0]);
        $this->assertEquals('Test Author', $book['authors'][0]['author']['name']);
        $this->assertEquals('AUTH123', $book['authors'][0]['author']['id']);

        // Test narrators
        $this->assertIsArray($book['narrators']);
        $this->assertNotEmpty($book['narrators']);
        $this->assertArrayHasKey('narrator', $book['narrators'][0]);
        $this->assertEquals('Test Narrator', $book['narrators'][0]['narrator']['name']);

        // Test other fields
        $this->assertArrayHasKey('publisher', $book);
        $this->assertEquals('Test Publisher', $book['publisher']['name']);
        $this->assertStringContainsString('500x500', $book['coverImageUrl']);
        $this->assertEquals('This is a test book description.', $book['description']);
        $this->assertEquals(630, $book['runtime']);
    }

    #[Test]
    public function testHandlesApiErrorsGracefully(): void
    {
        // Test search error
        Http::fake([
            'api.audible.com/1.0/catalog/products*' => Http::response(
                ['message' => 'Invalid request'],
                400
            ),
        ]);

        $results = $this->service->searchBooks('Nonexistent Book');
        $this->assertEquals([], $results);

        // Test get book details error
        Http::fake([
            'api.audible.com/1.0/catalog/products/INVALID123*' => Http::response(
                ['message' => 'Not found'],
                404
            ),
        ]);

        $book = $this->service->getBookDetails('INVALID123');
        $this->assertNull($book);
    }

    /**
     * Get mock book data for testing.
     */
    private function getMockBookData(): array
    {
        return [
            'asin' => 'TEST123',
            'title' => 'Test Book',
            'subtitle' => 'A Test Subtitle',
            'authors' => [['author' => ['name' => 'Test Author']]],
            'narrators' => [['narrator' => ['name' => 'Test Narrator']]],
            'contributors' => [
                ['role' => 'author', 'name' => 'Test Author', 'asin' => 'AUTH123'],
                ['role' => 'author', 'name' => 'Co-Author', 'asin' => 'AUTH456'],
                ['role' => 'narrator', 'name' => 'Test Narrator', 'asin' => 'NARR123'],
            ],
            'publisher_name' => 'Test Publisher',
            'release_date' => '2023-01-01T00:00:00.000Z',
            'published_date' => '2023-01-01T00:00:00.000Z',
            'publisher_summary' => 'This is a test book summary.',
            'merchandising_summary' => 'This is a test book description.',
            'product_images' => [
                '_500x500' => 'https://example.com/image_500x500.jpg',
                '_400x400' => 'https://example.com/image_400x400.jpg',
                '500' => 'https://example.com/image_500x500.jpg',
                '400' => 'https://example.com/image_400x400.jpg',
            ],
            'images' => [
                '500' => 'https://example.com/image_500x500.jpg',
                '400' => 'https://example.com/image_400x400.jpg',
            ],
            'genres' => [
                ['genre' => ['name' => 'Fiction', 'id' => '1']],
                ['genre' => ['name' => 'Science Fiction', 'id' => '2']],
            ],
            'category_ladders' => [
                [
                    ['name' => 'Fiction'],
                    ['name' => 'Science Fiction'],
                    ['name' => 'Adventure'],
                ],
            ],
            'runtime_length_min' => 630, // 10 hours 30 minutes in minutes
            'language' => 'english',
            'series' => [
                ['title' => 'Test Series', 'sequence' => '1'],
            ],
            'rating' => [
                'overall_distribution' => [
                    'average_rating' => 4.5,
                    'rating_count' => 100,
                ],
            ],
            'publisher' => ['name' => 'Test Publisher'],
            'format_type' => 'unabridged',
            'is_adult_product' => false,
            'region' => 'us',
            'is_listenable' => true,
            'is_childrens' => false,
            'is_public_domain' => false,
            'available_codecs' => [['format' => 'MP3']],
            'has_children' => false,
            'isbn' => '1234567890',
            'product_site_launch_date' => '2023-01-01T00:00:00.000Z',
            'product_updated_date' => '2023-01-01T00:00:00.000Z',
            'publication_name' => 'Test Publication',
            'ratings_count' => 1234,
            'series_sequence' => '1',
            'whats_included' => 'Test content',
            'about_author' => 'Test author bio',
        ];
    }
}
