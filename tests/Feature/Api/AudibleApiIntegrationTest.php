<?php

namespace Tests\Feature\Api;

use App\Traits\AudibleApiTrait;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\BaseApiTest;

class AudibleApiIntegrationTest extends BaseApiTest
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
                    ]
                ]
            ]
        ];

        Http::fake([
            $this->apiBaseUrl . '/*' => Http::response($mockResponse, 200)
        ]);

        $results = $this->searchAudiobooks('test');

        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $this->assertEquals('TEST123', $results[0]['id']);
        $this->assertEquals('Test Audiobook', $results[0]['title']);
        $this->assertEquals(['Test Author'], $results[0]['authors']);
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
                ]
            ]
        ];

        Http::fake([
            $this->apiBaseUrl . '/*' => Http::response($mockResponse, 200)
        ]);

        $details = $this->getAudiobookDetails('TEST123');

        $this->assertIsArray($details);
        $this->assertEquals('TEST123', $details['id']);
        $this->assertEquals('Test Audiobook', $details['title']);
        $this->assertEquals(['Test Author'], $details['authors']);
        $this->assertEquals('Test Description', $details['description']);
    }
}
