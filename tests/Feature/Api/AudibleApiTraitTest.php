<?php

namespace Tests\Feature\Api;

use App\Traits\AudibleApiTrait;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Feature\Api\BaseApiTest;

class AudibleApiTraitTest extends BaseApiTest
{
    use AudibleApiTrait;

    protected string $apiBaseUrl = 'https://api.audible.com/1.0';
    protected string $testAssociateTag = 'test-tag';
    protected string $testAccessKey = 'test-access-key';
    protected string $testSecretKey = 'test-secret-key';
    protected string $testRegion = 'us';

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock HTTP client
        Http::fake();
        
        // Initialize the trait
        $this->initAudible([
            'access_key' => $this->testAccessKey,
            'secret_key' => $this->testSecretKey,
            'associate_tag' => $this->testAssociateTag,
            'region' => $this->testRegion,
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
                    'authors' => [['name' => 'Test Author']],
                    'narrators' => [['name' => 'Test Narrator']],
                    'publisher_name' => 'Test Publisher',
                    'publisher_summary' => 'Test Description',
                    'release_date' => '2023-01-01T00:00:00Z',
                    'product_images' => [
                        '500' => 'http://example.com/cover.jpg'
                    ]
                ]
            ]
        ];
    }
    
    protected function getMockDetailsResponse(): array
    {
        return [
            'product' => [
                'asin' => 'TEST123',
                'title' => 'Test Audiobook',
                'authors' => [['name' => 'Test Author']],
                'narrators' => [['name' => 'Test Narrator']],
                'publisher_name' => 'Test Publisher',
                'publisher_summary' => 'Test Description',
                'release_date' => '2023-01-01T00:00:00Z',
                'product_images' => [
                    '500' => 'http://example.com/cover.jpg'
                ]
            ]
        ];
    }

    /** @test */
    public function it_can_search_audiobooks()
    {
        $mockResponse = [
            'total_results' => 1,
            'results' => [
                [
                    'asin' => 'TEST123',
                    'title' => 'Test Audiobook',
                    'authors' => [['name' => 'Test Author']],
                    'narrators' => [['name' => 'Test Narrator']],
                    'publisher_name' => 'Test Publisher',
                    'publisher_summary' => 'Test Description',
                    'release_date' => '2023-01-01T00:00:00Z',
                    'product_images' => [
                        '500' => 'http://example.com/cover.jpg'
                    ],
                    'category_ladders' => [
                        [
                            'root' => 'Categories',
                            'ladder' => [
                                ['name' => 'Fiction']
                            ]
                        ]
                    ]
                ]
            ]
        ];

        Http::fake([
            'api.audible.com/1.0/catalog/products*' => Http::response($mockResponse),
        ]);

        $results = $this->searchAudiobooks('test');
        
        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        $this->assertEquals('Test Audiobook', $results[0]['title']);
        $this->assertEquals('Test Author', $results[0]['authors'][0]['name']);
    }

    /** @test */
    public function it_can_get_audiobook_details()
    {
        $mockResponse = [
            'product' => [
                'asin' => 'TEST123',
                'title' => 'Test Audiobook',
                'authors' => [['name' => 'Test Author']],
                'narrators' => [['name' => 'Test Narrator']],
                'publisher_name' => 'Test Publisher',
                'publisher_summary' => 'Test Description',
                'release_date' => '2023-01-01T00:00:00Z',
                'product_images' => [
                    '500' => 'http://example.com/cover.jpg'
                ],
                'category_ladders' => [
                    [
                        'root' => 'Categories',
                        'ladder' => [
                            ['name' => 'Fiction']
                        ]
                    ]
                ],
                'runtime_length_min' => 360,
                'format_type' => 'Unabridged',
                'language' => 'English',
                'rating' => [
                    'average_rating' => 4.5,
                    'rating_count' => 100
                ]
            ]
        ];

        Http::fake([
            'api.audible.com/1.0/catalog/products/TEST123*' => Http::response($mockResponse),
        ]);

        $book = $this->getAudiobookDetails('TEST123');
        
        $this->assertIsArray($book);
        $this->assertEquals('Test Audiobook', $book['title']);
        $this->assertEquals('Test Author', $book['authors'][0]['name']);
    }

    /** @test */
    public function it_can_get_audiobooks_by_author()
    {
        $mockResponse = [
            'total_results' => 1,
            'results' => [
                [
                    'asin' => 'TEST123',
                    'title' => 'Test Audiobook',
                    'authors' => [['name' => 'Test Author']],
                    'narrators' => [['name' => 'Test Narrator']],
                    'publisher_name' => 'Test Publisher',
                    'publisher_summary' => 'Test Description',
                    'release_date' => '2023-01-01T00:00:00Z',
                    'product_images' => [
                        '500' => 'http://example.com/cover.jpg'
                    ],
                    'category_ladders' => [
                        [
                            'root' => 'Categories',
                            'ladder' => [
                                ['name' => 'Fiction']
                            ]
                        ]
                    ]
                ]
            ]
        ];

        Http::fake([
            'api.audible.com/1.0/catalog/products*' => Http::response($mockResponse),
        ]);

        $results = $this->getAudiobooksByAuthor('Test Author');
        
        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        $this->assertEquals('Test Audiobook', $results[0]['title']);
    }

    /** @test */
    public function it_can_get_audiobooks_by_narrator()
    {
        $mockResponse = [
            'total_results' => 1,
            'results' => [
                [
                    'asin' => 'TEST123',
                    'title' => 'Test Audiobook',
                    'authors' => [['name' => 'Test Author']],
                    'narrators' => [['name' => 'Test Narrator']],
                    'publisher_name' => 'Test Publisher',
                    'publisher_summary' => 'Test Description',
                    'release_date' => '2023-01-01T00:00:00Z',
                    'product_images' => [
                        '500' => 'http://example.com/cover.jpg'
                    ],
                    'category_ladders' => [
                        [
                            'root' => 'Categories',
                            'ladder' => [
                                ['name' => 'Fiction']
                            ]
                        ]
                    ]
                ]
            ]
        ];

        Http::fake([
            'api.audible.com/1.0/catalog/products*' => Http::response($mockResponse),
        ]);

        $results = $this->getAudiobooksByNarrator('Test Narrator');
        
        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        $this->assertEquals('Test Audiobook', $results[0]['title']);
    }

    /** @test */
    public function it_can_get_categories()
    {
        $mockResponse = [
            'categories' => [
                ['name' => 'Fiction', 'id' => '1'],
                ['name' => 'Non-Fiction', 'id' => '2'],
            ]
        ];

        Http::fake([
            'api.audible.com/1.0/catalog/categories*' => Http::response($mockResponse),
        ]);

        $categories = $this->getCategories();
        
        $this->assertIsArray($categories);
        $this->assertCount(2, $categories);
        $this->assertEquals('Fiction', $categories[0]['name']);
    }
}
