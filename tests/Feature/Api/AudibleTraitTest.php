<?php

namespace Tests\Feature\Api;

use App\Traits\AudibleApiTrait;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class AudibleTraitTest extends TestCase
{
    use AudibleApiTrait;

    protected string $audibleBaseUrl = 'https://api.audible.com/1.0';
    protected string $testAssociateTag = 'test-tag';
    protected string $testAccessKey = 'test-access-key';
    protected string $testSecretKey = 'test-secret-key';
    protected string $testRegion = 'us';

    protected function setUp(): void
    {
        parent::setUp();

        // Mock Cache facade
        Cache::shouldReceive('remember')
            ->andReturnUsing(function ($key, $ttl, $callback) {
                return $callback();
            });

        Cache::shouldReceive('get')
            ->andReturn(0);

        Cache::shouldReceive('put')
            ->andReturn(true);

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

    #[Test]
    public function testCanInitializeAudibleApi()
    {
        $this->assertNotNull($this->audibleAccessKey);
        $this->assertNotNull($this->audibleSecretKey);
        $this->assertNotNull($this->audibleAssociateTag);
        $this->assertNotNull($this->audibleRegion);
        $this->assertEquals($this->testAccessKey, $this->audibleAccessKey);
        $this->assertEquals($this->testSecretKey, $this->audibleSecretKey);
        $this->assertEquals($this->testAssociateTag, $this->audibleAssociateTag);
        $this->assertEquals($this->testRegion, $this->audibleRegion);
    }

    #[Test]
    public function testCanSearchAudiobooks()
    {
        $mockResponse = [
            'Items' => [
                'Item' => [
                    [
                        'ASIN' => 'TEST123',
                        'ItemAttributes' => [
                            'Title' => 'Test Audiobook',
                            'Author' => ['Test Author'],
                            'Publisher' => 'Test Publisher',
                            'PublicationDate' => '2023-01-01',
                        ],
                        'DetailPageURL' => 'http://example.com/test123',
                        'MediumImage' => ['URL' => 'http://example.com/cover.jpg'],
                        'EditorialReviews' => [
                            'EditorialReview' => [
                                'Source' => 'Product Description',
                                'Content' => 'Test Description',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            'api.audible.*' => Http::response($mockResponse, 200),
        ]);

        $results = $this->searchAudiobooks('test');

        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $this->assertEquals('TEST123', $results[0]['id']);
        $this->assertEquals('Test Audiobook', $results[0]['title']);
        $this->assertEquals(['Test Author'], $results[0]['authors']);
    }

    #[Test]
    public function testCanGetAudiobookDetails()
    {
        $mockResponse = [
            'Items' => [
                'Item' => [
                    'ASIN' => 'TEST123',
                    'ItemAttributes' => [
                        'Title' => 'Test Audiobook',
                        'Author' => ['Test Author'],
                        'Publisher' => 'Test Publisher',
                        'PublicationDate' => '2023-01-01',
                        'Narrator' => ['Test Narrator'],
                    ],
                    'DetailPageURL' => 'http://example.com/test123',
                    'LargeImage' => ['URL' => 'http://example.com/cover.jpg'],
                    'EditorialReviews' => [
                        'EditorialReview' => [
                            'Source' => 'Product Description',
                            'Content' => 'Test Description',
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            'api.audible.*' => Http::response($mockResponse, 200),
        ]);

        $details = $this->getAudiobookDetails('TEST123');

        $this->assertIsArray($details);
        $this->assertEquals('TEST123', $details['id']);
        $this->assertEquals('Test Audiobook', $details['title']);
        $this->assertEquals(['Test Author'], $details['authors']);
        $this->assertEquals('Test Description', $details['description']);
    }
}
