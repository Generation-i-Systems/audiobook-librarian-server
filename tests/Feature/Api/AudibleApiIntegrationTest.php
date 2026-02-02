<?php

namespace Tests\Feature\Api;

use App\Services\AudibleApiService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class AudibleApiIntegrationTest extends BaseApiTestCase
{
    private AudibleApiService $audibleApi;

    protected string $apiBaseUrl = 'https://api.audible.us/1.0'; // Aligned with 'us' region in service

    protected string $testAssociateTag = 'test-tag';

    protected string $testAccessKey = 'test-access-key';

    protected string $testSecretKey = 'test-secret-key';

    protected string $testRegion = 'us';

    protected function setUp(): void
    {
        parent::setUp();

        // Configure service credentials for test environment
        config([
            'services.audible.access_key' => $this->testAccessKey,
            'services.audible.secret_key' => $this->testSecretKey,
            'services.audible.associate_tag' => $this->testAssociateTag,
            'services.audible.region' => $this->testRegion,
            // Ensure a base_url is not set in config for tests, so service defaults correctly
            'services.audible.base_url' => null,
        ]);

        // Resolve the service from the container
        $this->audibleApi = app(AudibleApiService::class);

        // HTTP faking is handled by specific test methods.
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
                        'Content' => 'Test Description',
                    ],
                ],
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
                            'Name' => 'Fiction',
                        ],
                    ],
                ],
            ],
            'CustomerReviews' => ['AverageRating' => '4.5', 'TotalCount' => 100],
            'DetailPageURL' => 'http://example.com/details/TEST123',
            'AudioDetails' => ['Time' => 'PT1H23M45S'], // Added AudioDetails for duration
        ];
    }

    protected function getMockSearchResponse(): array
    {
        return [
            'Items' => [
                'Item' => [
                    $this->getRawApiItem(),
                ],
            ],
        ];
    }

    protected function getMockDetailsResponse(): array
    {
        return [
            'Items' => [
                'Item' => $this->getRawApiItem(),
            ],
        ];
    }

    protected function getFakeXmlSearchResponseString(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<ItemSearchResponse xmlns="http://webservices.amazon.com/AWSECommerceService/2011-08-01">
    <Items>
        <Request><IsValid>True</IsValid></Request>
        <TotalResults>1</TotalResults>
        <TotalPages>1</TotalPages>
        <Item>
            <ASIN>TEST123</ASIN>
            <ItemAttributes>
                <Title>Test Audiobook</Title>
                <Author>Test Author</Author>
            </ItemAttributes>
        </Item>
    </Items>
</ItemSearchResponse>
XML;
    }

    protected function getFakeXmlDetailsResponseString(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<ItemLookupResponse xmlns="http://webservices.amazon.com/AWSECommerceService/2011-08-01">
    <Items>
        <Request><IsValid>True</IsValid></Request>
        <Item>
            <ASIN>TEST123</ASIN>
            <ItemAttributes>
                <Title>Test Audiobook</Title>
                <Author>Test Author</Author>
                <Narrator>Test Narrator</Narrator>
                <Publisher>Test Publisher</Publisher>
                <PublicationDate>2023-01-01</PublicationDate>
            </ItemAttributes>
            <EditorialReviews>
                <EditorialReview>
                    <Source>Product Description</Source>
                    <Content>Test Description</Content>
                </EditorialReview>
            </EditorialReviews>
            <MediumImage><URL>http://example.com/cover.jpg</URL></MediumImage>
            <LargeImage><URL>http://example.com/cover_large.jpg</URL></LargeImage>
            <SmallImage><URL>http://example.com/cover_small.jpg</URL></SmallImage>
            <BrowseNodes>
                <BrowseNode>
                    <BrowseNodeId>12345</BrowseNodeId>
                    <Name>Science Fiction</Name>
                </BrowseNode>
            </BrowseNodes>
            <CustomerReviews><AverageRating>4.5</AverageRating><TotalReviews>100</TotalReviews></CustomerReviews>
            <DetailPageURL>http://example.com/details/TEST123</DetailPageURL>
            <Offers>
                <TotalOffers>1</TotalOffers>
                <Offer>
                    <OfferListing>
                        <Price>
                            <FormattedPrice>$0.00</FormattedPrice>
                        </Price>
                    </OfferListing>
                </Offer>
            </Offers>
            <AudibleProgram><Format>Unabridged</Format></AudibleProgram>
            <AudibleRuntime>5025</AudibleRuntime>
        </Item>
    </Items>
</ItemLookupResponse>
XML;
    }

    #[Test]
    public function test_it_can_search_audiobooks()
    {
        $apiBaseUrl = $this->apiBaseUrl;
        $mockSearchResponse = $this->getMockSearchResponse();

        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($apiBaseUrl) {
            $urlParts = parse_url($request->url());
            $queryString = $urlParts['query'] ?? '';
            parse_str($queryString, $queryParams);

            $urlWithoutQuery = ($urlParts['scheme'] ?? 'http') . '://' . ($urlParts['host'] ?? '') .
                ($urlParts['path'] ?? '');
            $rtrimmedUrlWithoutQuery = rtrim($urlWithoutQuery, '/');
            $rtrimmedApiBaseUrl = rtrim($apiBaseUrl, '/');

            $isCorrectUrl = $rtrimmedUrlWithoutQuery === $rtrimmedApiBaseUrl;
            $isSearchOperation = isset($queryParams['Operation']) && $queryParams['Operation'] === 'ItemSearch';
            $hasKeywords = isset($queryParams['Keywords']);

            if ($isCorrectUrl && $isSearchOperation && $hasKeywords) {
                return Http::response($this->getFakeXmlSearchResponseString(), 200, [
                    'Content-Type' => 'application/xml',
                ]);
            }

            \Illuminate\Support\Facades\Log::warning('S_LOG_INT: Http::fake did not match for search.', [
                'url' => $request->url(),
                'query_params' => $queryParams,
                'base_url_expected' => $apiBaseUrl,
            ]);

            return Http::response('Mock not found for integration search: ' . $request->url(), 404, [
                'Content-Type' => 'text/plain',
            ]);
        });

        $results = $this->audibleApi->searchBooks('test');

        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $this->assertEquals('TEST123', $results[0]['id']);
        $this->assertEquals('Test Audiobook', $results[0]['title']);
        $this->assertEquals([['name' => 'Test Author']], $results[0]['authors']);
    }

    #[Test]
    public function test_it_can_get_audiobook_details()
    {
        $apiBaseUrl = $this->apiBaseUrl;
        $mockDetailsResponse = $this->getMockDetailsResponse();

        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($apiBaseUrl) {
            $urlParts = parse_url($request->url());
            $queryString = $urlParts['query'] ?? '';
            parse_str($queryString, $queryParams);

            $urlWithoutQuery = ($urlParts['scheme'] ?? 'http') . '://' . ($urlParts['host'] ?? '') .
                ($urlParts['path'] ?? '');
            $rtrimmedUrlWithoutQuery = rtrim($urlWithoutQuery, '/');
            $rtrimmedApiBaseUrl = rtrim($apiBaseUrl, '/');

            $isCorrectUrl = $rtrimmedUrlWithoutQuery === $rtrimmedApiBaseUrl;
            $isLookupOperation = isset($queryParams['Operation']) && $queryParams['Operation'] === 'ItemLookup';
            $hasItemId = isset($queryParams['ItemId']);
            $isAsinIdType = isset($queryParams['IdType']) && $queryParams['IdType'] === 'ASIN';

            if ($isCorrectUrl && $isLookupOperation && $hasItemId && $isAsinIdType) {
                return Http::response($this->getFakeXmlDetailsResponseString(), 200, [
                    'Content-Type' => 'application/xml',
                ]);
            }

            \Illuminate\Support\Facades\Log::warning('D_LOG_INT: Http::fake did not match for details.', [
                'url' => $request->url(),
                'query_params' => $queryParams,
                'base_url_expected' => $apiBaseUrl,
            ]);

            return Http::response('Mock not found for integration details: ' . $request->url(), 404, [
                'Content-Type' => 'text/plain',
            ]);
        });

        $details = $this->audibleApi->getAudiobookDetails('TEST123');

        $this->assertIsArray($details);
        $this->assertEquals('TEST123', $details['id']);
        $this->assertEquals('Test Audiobook', $details['title']);
        $this->assertEquals([['name' => 'Test Author']], $details['authors']);
        $this->assertEquals('Test Description', $details['description']);
    }
}
