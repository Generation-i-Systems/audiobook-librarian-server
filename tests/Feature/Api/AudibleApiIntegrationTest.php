<?php

namespace Tests\Feature\Api;

use App\Traits\AudibleApiTrait;
use App\Traits\BaseApiTrait;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\BaseApiTest;
use PHPUnit\Framework\Attributes\Test;

class AudibleApiIntegrationTest extends BaseApiTest
{
    private object $audibleApi;

    protected string $apiBaseUrl = 'https://api.audible.us/1.0'; // Aligned with 'us' region
    protected string $testAssociateTag = 'test-tag';
    protected string $testAccessKey = 'test-access-key';
    protected string $testSecretKey = 'test-secret-key';
    protected string $testRegion = 'us';

    protected function setUp(): void
    {
        parent::setUp();

        // Create a new instance of a class that uses the trait
        $this->audibleApi = new class {
            use BaseApiTrait;
            use AudibleApiTrait;
        };

        // Initialize the API client with test credentials
        $this->audibleApi->initAudible([
            'access_key' => $this->testAccessKey,
            'secret_key' => $this->testSecretKey,
            'associate_tag' => $this->testAssociateTag,
            'region' => $this->testRegion,
        ]);

        $this->audibleApi->setServiceName($this->getServiceName());

        // HTTP faking is handled by specific test methods.

        // Set up test API key
        $this->apiKey = config('services.audible.key', 'test_key');
    }

    protected function getServiceName(): string
    {
        return 'audible';
    }

    protected function getRawApiItem(): array
    {
        return [
            'ASIN' => 'TEST123',
            'ItemAttributes' => [
                'Title' => 'Test Audiobook',
                'Author' => ['Name' => 'Test Author'], // Changed structure
                'Narrator' => ['Name' => 'Test Narrator'], // Changed structure
                'Publisher' => 'Test Publisher',
                'PublicationDate' => '2023-01-01',
            ],
            'EditorialReviews' => [ // Changed key to plural and structure
                'EditorialReview' => [
                    [
                        'Source' => 'Product Description',
                        'Content' => 'Test Description'
                    ]
                ]
            ],
            'MediumImage' => ['URL' => 'http://example.com/cover.jpg'],
            'LargeImage' => ['URL' => 'http://example.com/cover_large.jpg'], // Added for completeness
            'SmallImage' => ['URL' => 'http://example.com/cover_small.jpg'], // Added for completeness
            'BrowseNodes' => [
                'BrowseNode' => [
                    'BrowseNodeId' => '12345',
                    'Name' => 'Science Fiction',
                    'Ancestors' => [
                        'BrowseNode' => [
                            'BrowseNodeId' => '123',
                            'Name' => 'Fiction'
                        ]
                    ]
                ]
            ],
            'CustomerReviews' => ['AverageRating' => '4.5', 'TotalCount' => 100],
            'DetailPageURL' => 'http://example.com/details/TEST123',
            'AudioDetails' => ['Time' => 'PT1H23M45S'] // Added AudioDetails for duration
        ];
    }

    protected function getMockSearchResponse(): array
    {
        return [
            'Items' => [
                'Item' => [
                    $this->getRawApiItem()
                ]
            ]
        ];
    }

    protected function getMockDetailsResponse(): array
    {
        return [
            'Items' => [
                'Item' => $this->getRawApiItem()
            ]
        ];
    }

    #[Test]
    public function testItCanSearchAudiobooks()
    {
        $apiBaseUrl = $this->apiBaseUrl;
        $mockSearchResponse = $this->getMockSearchResponse();

        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($apiBaseUrl, $mockSearchResponse) {
            try {
                $requestData = $request->data();
                $urlParts = parse_url($request->url());
                $urlWithoutQuery = ($urlParts['scheme'] ?? 'http') . '://' . ($urlParts['host'] ?? '') . ($urlParts['path'] ?? '');

                $rtrimmedUrlWithoutQuery = rtrim($urlWithoutQuery, '/');
                $rtrimmedApiBaseUrl = rtrim($apiBaseUrl, '/');
                $urlCondition = $rtrimmedUrlWithoutQuery === $rtrimmedApiBaseUrl;
                $keywordsCondition = isset($requestData['Keywords']);

                if ($urlCondition && $keywordsCondition) {
                    return Http::response($mockSearchResponse, 200, ['Content-Type' => 'application/json']);
                }

                // Log if no match, this can be helpful for future debugging if new cases are added.
                \Illuminate\Support\Facades\Log::warning("S_LOG: Http::fake did not match for search.", ['url' => $request->url(), 'data' => $requestData, 'base_url_expected' => $apiBaseUrl]);
                return Http::response(['error' => 'Mock not found for search', 'url' => $request->url(), 'data' => $requestData], 404, ['Content-Type' => 'application/json']);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("S_LOG: Exception in Http::fake callback: " . $e->getMessage(), ['exception' => $e]);
                return Http::response(['error' => 'Exception in mock callback: ' . $e->getMessage()], 500);
            }
        });

        $results = $this->audibleApi->searchAudiobooks('test');

        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $this->assertEquals('TEST123', $results[0]['id']);
        $this->assertEquals('Test Audiobook', $results[0]['title']);
        $this->assertEquals([['name' => 'Test Author']], $results[0]['authors']);
    }

    #[Test]
    public function testItCanGetAudiobookDetails()
    {
        $apiBaseUrl = $this->apiBaseUrl;
        $mockDetailsResponse = $this->getMockDetailsResponse();

        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($apiBaseUrl, $mockDetailsResponse) {
            try {
                $requestData = $request->data();
                $urlParts = parse_url($request->url());
                $urlWithoutQuery = ($urlParts['scheme'] ?? 'http') . '://' . ($urlParts['host'] ?? '') . ($urlParts['path'] ?? '');

                $rtrimmedUrlWithoutQuery = rtrim($urlWithoutQuery, '/');
                $rtrimmedApiBaseUrl = rtrim($apiBaseUrl, '/');
                $urlCondition = $rtrimmedUrlWithoutQuery === $rtrimmedApiBaseUrl;

                $itemIdCondition = isset($requestData['ItemId']);
                $idTypeCondition = isset($requestData['IdType']) && $requestData['IdType'] === 'ASIN';

                if ($urlCondition && $itemIdCondition && $idTypeCondition) {
                    return Http::response($mockDetailsResponse, 200, ['Content-Type' => 'application/json']);
                }

                // Log if no match, this can be helpful for future debugging if new cases are added.
                \Illuminate\Support\Facades\Log::warning("D_LOG: Http::fake did not match for details.", ['url' => $request->url(), 'data' => $requestData, 'base_url_expected' => $apiBaseUrl]);
                return Http::response(['error' => 'Mock not found for details', 'url' => $request->url(), 'data' => $requestData], 404, ['Content-Type' => 'application/json']);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("D_LOG: Exception in Http::fake callback: " . $e->getMessage(), ['exception' => $e]);
                return Http::response(['error' => 'Exception in mock callback: ' . $e->getMessage()], 500);
            }
        });

        $details = $this->audibleApi->getAudiobookDetails('TEST123');

        $this->assertIsArray($details);
        $this->assertEquals('TEST123', $details['id']);
        $this->assertEquals('Test Audiobook', $details['title']);
        $this->assertEquals([['name' => 'Test Author']], $details['authors']);
        $this->assertEquals('Test Description', $details['description']);
    }
}
