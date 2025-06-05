<?php

namespace Tests\Feature\Api;

use App\Services\AudibleApiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Api\BaseApiTest;

class AudibleApiTraitTest extends BaseApiTest
{
    protected AudibleApiService $audibleApi;
    protected string $apiBaseUrl = 'https://api.audible.com/1.0';
    protected string $testAssociateTag = 'test-tag';
    protected string $testAccessKey = 'test-access-key';
    protected string $testSecretKey = 'test-secret-key';
    protected string $testRegion = 'us';

    protected function setUp(): void
    {
        parent::setUp();

        // Mock Cache::remember() for BaseApiTrait::httpGet users
        Cache::shouldReceive('remember')
            ->andReturnUsing(function ($key, $ttl, $callback) {
                // You might want to add logging here to see which keys are being remembered
                // Log::debug('Cache::remember mock called', ['key' => $key]);
                return $callback(); // Execute the callback to simulate cache miss and fetch
            });

        // Mock the Cache facade for AudibleApiService's custom cachedOrFetch
        Cache::shouldReceive('tags')
            ->with(['audible']) // Assuming 'audible' is the service name tag
            ->andReturnSelf(); // Return the mock itself to chain ->get() and ->put()

        Cache::shouldReceive('get')
            ->withArgs(function ($key) {
                // You can add more specific key checks here if needed
                return is_string($key);
            })
            ->andReturnNull(); // Simulate cache miss

        Cache::shouldReceive('put')
            ->withArgs(function ($key, $value, $ttl) {
                // Allow TTL to be numeric or a Carbon instance for rate limiting
                return is_string($key) && (is_numeric($ttl) || $ttl instanceof \Illuminate\Support\Carbon);
            })
            ->andReturnTrue(); // Or ->byDefault()

        // Configure service credentials for test environment
        config([
            'services.audible.access_key' => $this->testAccessKey,
            'services.audible.secret_key' => $this->testSecretKey,
            'services.audible.associate_tag' => $this->testAssociateTag,
            'services.audible.region' => $this->testRegion,
            'services.audible.base_url' => null, // Ensure service uses default for region
        ]);

        // Resolve the service from the container
        $this->audibleApi = app(AudibleApiService::class);

        // Mock HTTP client - Http::fake() without arguments clears previous fakes and sets a default passthrough.
        // Specific fakes are set in each test method.
        Http::fake();
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

    #[Test]
    public function testCanSearchAudiobooks(): void
    {
        $mockResponse = $this->getMockSearchResponse();

        Http::fake([
            'api.audible.com/1.0/catalog/products*' => Http::response($mockResponse),
        ]);

        $results = $this->audibleApi->searchAudiobooks('test');

        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        $this->assertEquals('Test Audiobook', $results[0]['title']);
        $this->assertEquals('Test Author', $results[0]['authors'][0]['name']);
    }

    #[Test]
    public function testCanGetAudiobookDetails(): void
    {
        $mockResponse = [
            'Items' => [
                'Item' => [
                    [
                        'ASIN' => 'TEST123',
                        'ItemAttributes' => [
                            'Title' => 'Test Audiobook',
                            'Author' => 'Test Author',
                            'Narrator' => 'Test Narrator',
                            'Publisher' => 'Test Publisher',
                            'PublicationDate' => '2023-01-01T00:00:00Z',
                            'Subtitle' => 'A Test Subtitle'
                        ],
                        'LargeImage' => [
                            'URL' => 'http://example.com/cover.jpg'
                        ],
                        'MediumImage' => [
                            'URL' => 'http://example.com/cover_medium.jpg'
                        ],
                        'SmallImage' => [
                            'URL' => 'http://example.com/cover_small.jpg'
                        ],
                        'EditorialReviews' => [
                            'EditorialReview' => [
                                [
                                    'Source' => 'Product Description',
                                    'Content' => 'This is a test description.'
                                ]
                            ]
                        ],
                        'BrowseNodes' => [
                            'BrowseNode' => [
                                'Name' => 'Fiction',
                                'Children' => [
                                    'BrowseNode' => [
                                        'Name' => 'Science Fiction',
                                        'Children' => [
                                            'BrowseNode' => [
                                                'Name' => 'Adventure',
                                                'IsCategoryRoot' => 1,
                                                'Children' => []
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ],
                        'CustomerReviews' => [
                            'AverageRating' => 4.5,
                            'TotalCount' => 100
                        ],
                        'AudioDetails' => [
                            'Time' => '6 hours 30 minutes'
                        ],
                        'DetailPageURL' => 'http://example.com/book/TEST123'
                    ]
                ]
            ]
        ];

        Http::fake([
            'api.audible.com/1.0/catalog/products/TEST123*' => Http::response($mockResponse),
        ]);

        $book = $this->audibleApi->getAudiobookDetails('TEST123');

        $this->assertIsArray($book);
        $this->assertEquals('Test Audiobook', $book['title']);
        $this->assertEquals('Test Author', $book['authors'][0]['name']);
    }

    #[Test]
    public function testCanGetAudiobooksByAuthor(): void
    {
        $mockResponse = $this->getMockSearchResponse();

        Http::fake([
            'api.audible.com/1.0/catalog/products*' => Http::response($mockResponse),
        ]);

        $results = $this->audibleApi->getAudiobooksByAuthor('Test Author');

        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        $this->assertEquals('Test Audiobook', $results[0]['title']);
    }

    #[Test]
    public function testCanGetAudiobooksByNarrator(): void
    {
        $mockResponse = $this->getMockSearchResponse();

        Http::fake([
            'api.audible.com/1.0/catalog/products*' => Http::response($mockResponse),
        ]);

        $results = $this->audibleApi->getAudiobooksByNarrator('Test Narrator');

        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        $this->assertEquals('Test Audiobook', $results[0]['title']);
    }

    #[Test]
    public function testCanGetCategories(): void
    {
        $mockResponse = [
            'BrowseNodes' => [
                'BrowseNode' => [
                    [
                        'BrowseNodeId' => '1',
                        'Name' => 'Fiction',
                        'Children' => [
                            'BrowseNode' => [
                                [
                                    'BrowseNodeId' => '101',
                                    'Name' => 'Science Fiction'
                                ],
                                [
                                    'BrowseNodeId' => '102',
                                    'Name' => 'Fantasy'
                                ]
                            ]
                        ]
                    ],
                    [
                        'BrowseNodeId' => '2',
                        'Name' => 'Non-Fiction',
                        'Children' => [
                            'BrowseNode' => [
                                [
                                    'BrowseNodeId' => '201',
                                    'Name' => 'Biography'
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        Http::fake([
            'api.audible.com/1.0/catalog/categories*' => Http::response($mockResponse),
        ]);

        $categories = $this->audibleApi->getCategories();

        $this->assertIsArray($categories);
        $this->assertNotEmpty($categories);
        $this->assertEquals('Fiction', $categories[0]['name']);
    }
}
