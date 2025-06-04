<?php

namespace Tests\Feature\Api;

use App\Traits\AudibleApiTrait;

class AudibleApiTest extends BaseApiTest
{
    private AudibleApiTrait $audibleApi;

    protected string $apiBaseUrl = 'https://api.audible.com/1.0/catalog/products';

    protected function setUp(): void
    {
        parent::setUp();

        // Create a new instance of a class that uses the trait
        $this->audibleApi = new class {
            use AudibleApiTrait;
        };

        // Initialize the Audible API client with test credentials
        $this->audibleApi->initAudible([
            'access_key' => 'test_access_key',
            'secret_key' => 'test_secret_key',
            'associate_tag' => 'test_associate_tag',
            'region' => 'us',
        ]);
    }

    protected function getServiceName(): string
    {
        return 'audible';
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
    public function canSearchBooks(): void
    {
        $this->mockSuccessfulSearchResponse();

        $results = $this->audibleApi->searchBooks($this->testQuery);

        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        $this->assertCommonBookStructure($results[0]);
    }

    #[Test]
    public function canGetBookDetails(): void
    {
        $this->mockSuccessfulDetailsResponse();

        $book = $this->audibleApi->getBookDetails('TEST123');

        $this->assertIsArray($book);
        $this->assertCommonBookStructure($book);
    }
}
