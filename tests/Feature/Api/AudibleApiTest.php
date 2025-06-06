<?php

namespace Tests\Feature\Api;

use App\Services\AudibleApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class AudibleApiTest extends BaseApiTest
{
    private AudibleApiService $audibleApi;

    protected string $apiBaseUrl = 'https://api.audible.com/1.0/catalog/products';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        // Mock Cache::remember()
        Cache::shouldReceive('remember')
            ->andReturnUsing(function ($key, $ttl, $callback) {
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
            'services.audible.access_key' => 'test_access_key',
            'services.audible.secret_key' => 'test_secret_key',
            'services.audible.associate_tag' => 'test_associate_tag',
            'services.audible.region' => 'us',
            'services.audible.base_url' => null, // Ensure service uses default for region
        ]);

        // Resolve the service from the container
        $this->audibleApi = app(AudibleApiService::class);
    }

    protected function getServiceName(): string
    {
        return 'audible';
    }

    protected function getTestAsin(): string
    {
        return 'B002V8L3F4'; // Example ASIN
    }

    protected function getFakeXmlSearchResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" ?>
<ItemSearchResponse xmlns="http://webservices.amazon.com/AWSECommerceService/2011-08-01">
    <Items>
        <Request>
            <IsValid>True</IsValid>
        </Request>
        <TotalResults>1</TotalResults>
        <TotalPages>1</TotalPages>
        <Item>
            <ASIN>B002V8L3F4</ASIN>
            <ItemAttributes>
                <Title>The Test Book</Title>
                <Author>Test Author</Author>
            </ItemAttributes>
        </Item>
    </Items>
</ItemSearchResponse>
XML;
    }

    protected function getFakeXmlDetailsResponse(): string
    {
        return <<<'XML'
<?xml version="1.0" ?>
<ItemLookupResponse xmlns="http://webservices.amazon.com/AWSECommerceService/2011-08-01">
    <Items>
        <Request>
            <IsValid>True</IsValid>
        </Request>
        <Item>
            <ASIN>B002V8L3F4</ASIN>
            <ItemAttributes>
                <Title>The Test Book Details</Title>
                <Author>Test Author</Author>
                <Narrator>Test Narrator</Narrator>
            </ItemAttributes>
        </Item>
    </Items>
</ItemLookupResponse>
XML;
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
        Http::fake([
            'api.audible.*' => Http::response($this->getFakeXmlSearchResponse(), 200, ['Content-Type' => 'application/xml']),
        ]);

        // $this->audibleApi is initialized in BaseApiTest::setUp()
        $this->mockSuccessfulSearchResponse();

        $results = $this->audibleApi->searchAudiobooks($this->testQuery);

        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        $this->assertCommonBookStructure($results[0]);
    }

    #[Test]
    public function canGetBookDetails(): void
    {
        Http::fake([
            'api.audible.*' => Http::response($this->getFakeXmlDetailsResponse(), 200, ['Content-Type' => 'application/xml']),
        ]);

        // $this->audibleApi is initialized in BaseApiTest::setUp()
        $this->mockSuccessfulDetailsResponse();

        $book = $this->audibleApi->getAudiobookDetails('TEST123');

        $this->assertIsArray($book);
        $this->assertCommonBookStructure($book);
    }
}
