<?php

namespace Tests\Unit;

use App\Services\AudibleService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AudibleServiceMockTest extends TestCase
{
    private AudibleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the cache facade
        $cache = new \Illuminate\Cache\Repository(
            new \Illuminate\Cache\ArrayStore()
        );
        $this->app->instance('cache', $cache);

        // Create the service instance
        $this->service = new AudibleService();
    }

    #[Test]
    public function testItCanSearchBooksByTitle()
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
        $this->assertEquals('Test Book', $results[0]['title']);
        $this->assertEquals('Test Author', $results[0]['authors'][0]['author']['name']);
        $this->assertStringContainsString('500x500', $results[0]['cover_image_url']);
    }

    #[Test]
    public function testItGetsBookDetails()
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
        $this->assertEquals('Test Book', $book['title']);
        $this->assertEquals('Test Author', $book['authors'][0]['author']['name']);
        $this->assertEquals('Test Narrator', $book['narrators'][0]['narrator']['name']);
        // The runtime is in minutes, so 630 minutes = 10 hours and 30 minutes
        $this->assertEquals(630, $book['runtime']);

        // Check that the book has the expected structure
        $this->assertArrayHasKey('id', $book);
        $this->assertArrayHasKey('title', $book);
        $this->assertArrayHasKey('authors', $book);
        $this->assertArrayHasKey('narrators', $book);
        $this->assertArrayHasKey('cover_image_url', $book);
        $this->assertArrayHasKey('publisher', $book);
        $this->assertArrayHasKey('description', $book);
        $this->assertArrayHasKey('language', $book);
    }

    #[Test]
    public function testHandlesApiErrorsGracefully()
    {
        Http::fake([
            'api.audible.com/1.0/catalog/products*' => Http::response(
                ['message' => 'Invalid request'],
                400
            ),
        ]);

        $results = $this->service->searchBooks('Nonexistent Book');
        $this->assertEmpty($results);

        $book = $this->service->getBookDetails('INVALID123');
        $this->assertNull($book);
    }

    private function getMockBookData(): array
    {
        return [
            'asin' => 'TEST123',
            'title' => 'Test Book',
            'subtitle' => 'A Test Subtitle',
            'contributors' => [
                ['role' => 'author', 'name' => 'Test Author', 'asin' => 'AUTH123'],
                ['role' => 'narrator', 'name' => 'Test Narrator', 'asin' => 'NARR123'],
            ],
            'publisher_name' => 'Test Publisher',
            'release_date' => '2023-01-01T00:00:00.000Z',
            'publisher_summary' => 'This is a test book summary.',
            'merchandising_summary' => 'This is a test book description.',
            'product_images' => [
                '500' => 'https://example.com/image_500x500.jpg',
            ],
            'category_ladders' => [
                [
                    ['name' => 'Fiction'],
                    ['name' => 'Science Fiction'],
                    ['name' => 'Adventure'],
                ],
            ],
            'runtime_length_min' => 630, // 10 hours and 30 minutes in minutes
            'language' => 'english',
            'is_adult_product' => false,
            'format_type' => 'unabridged',
            'available_codecs' => [
                ['name' => 'format4', 'enhanced_codec' => 'mp4a'],
                ['name' => 'format3', 'enhanced_codec' => 'mp3'],
            ],
            'content_type' => 'audio',
            'content_delivery_type' => 'SinglePartBook',
            'publication_name' => 'Test Publication',
            'publisher_summary' => 'This is a test book summary.',
            'merchandising_summary' => 'This is a test book description.',
        ];
    }
}
