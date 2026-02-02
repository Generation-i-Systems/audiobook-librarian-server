<?php

namespace Tests\Feature\Api;

use App\Services\AudibleService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class AudibleApiTest extends BaseApiTestCase
{
    private AudibleService $audibleService;

    protected string $apiBaseUrl = 'https://api.audible.com/1.0/catalog/products';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // Resolve the service from the container
        $this->audibleService = app(AudibleService::class);
    }

    protected function getServiceName(): string
    {
        return 'audible';
    }

    protected function getTestAsin(): string
    {
        return 'B002V8L3F4'; // Example ASIN
    }

    protected function getMockSearchResponse(): array
    {
        return [
            'products' => [
                [
                    'asin' => 'TEST123',
                    'title' => 'Test Audiobook',
                    'authors' => [
                        ['name' => 'Test Author'],
                    ],
                    'publisher_name' => 'Test Publisher',
                    'publisher_summary' => 'Test Description',
                    'release_date' => '2023-01-01T00:00:00Z',
                    'product_images' => [
                        '500' => 'http://example.com/cover.jpg',
                    ],
                    'category_ladders' => [
                        ['name' => 'Fiction'],
                    ],
                ],
            ],
        ];
    }

    protected function getMockDetailsResponse(): array
    {
        return [
            'product' => [
                'asin' => 'TEST123',
                'title' => 'Test Audiobook',
                'authors' => [
                    ['name' => 'Test Author'],
                ],
                'publisher_name' => 'Test Publisher',
                'publisher_summary' => 'Test Description',
                'release_date' => '2023-01-01T00:00:00Z',
                'product_images' => [
                    '500' => 'http://example.com/cover.jpg',
                ],
                'category_ladders' => [
                    ['name' => 'Fiction'],
                ],
            ],
        ];
    }

    #[Test]
    public function can_search_books(): void
    {
        Http::fake([
            'https://api.audible.com/1.0/catalog/products*' => Http::response($this->getMockSearchResponse(), 200),
        ]);

        $results = $this->audibleService->searchBooks('test');

        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        $this->assertEquals('Test Audiobook', $results[0]['title']);
    }

    #[Test]
    public function can_get_book_details(): void
    {
        Http::fake([
            'https://api.audible.com/1.0/catalog/products/*' => Http::response($this->getMockDetailsResponse(), 200),
        ]);

        $book = $this->audibleService->getBookDetails('TEST123');

        $this->assertIsArray($book);
        $this->assertEquals('Test Audiobook', $book['title']);
    }
}
