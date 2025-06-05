<?php
// File intentionally left blank. Trait-based feature tests removed due to service refactor.

namespace Tests\Feature\Api;

use App\Traits\AudibleApiTrait;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str; // Added for Str::startsWith and Str::contains
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
                            'Author' => [['Name' => 'Test Author']],
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

        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($mockResponse) {
            Log::info('Fake CB Search: ' . $request->method() . ' ' . $request->url());
            if (Str::startsWith($request->url(), 'https://api.audible.us/1.0') && $request->method() === 'GET' && Str::contains($request->url(), 'Operation=ItemSearch')) {
                Log::info('Matched GET api.audible.us/1.0 with Operation=ItemSearch');
                return Http::response($mockResponse, 200);
            }
            Log::warning('No match ItemSearch. URL: ' . $request->url() . ' M: ' . $request->method());
            return Http::response(['error' => 'Unexpected call for ItemSearch. Expected GET api.audible.us/1.0?Operation=ItemSearch... Actual: ' . $request->method() . ' ' . Str::limit($request->url(), 50)], 404);
        });

        $results = $this->searchAudiobooks('test');

        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $this->assertEquals('TEST123', $results[0]['id']);
        $this->assertEquals('Test Audiobook', $results[0]['title']);
        $this->assertEquals([['name' => 'Test Author']], $results[0]['authors']);
    }

    #[Test]
    public function testCanGetAudiobookDetails()
    {
        // Http::preventStrayRequests(); // Removed for now
        $mockResponse = [
            'Items' => [
                'Item' => [
                    'ASIN' => 'TEST123',
                    'ItemAttributes' => [
                        'Title' => 'Test Audiobook',
                        'Author' => [['Name' => 'Test Author']],
                        'Publisher' => 'Test Publisher',
                        'PublicationDate' => '2023-01-01',
                        'Narrator' => [['Name' => 'Test Narrator']],
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

        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($mockResponse) {
            Log::info('Fake CB Details: ' . $request->method() . ' ' . $request->url());
            if (Str::startsWith($request->url(), 'https://api.audible.us/1.0') && $request->method() === 'GET' && Str::contains($request->url(), 'Operation=ItemLookup')) {
                Log::info('Matched GET api.audible.us/1.0 with Operation=ItemLookup');
                return Http::response($mockResponse, 200);
            }
            Log::warning('No match ItemLookup. URL: ' . $request->url() . ' M: ' . $request->method());
            return Http::response(['error' => 'Unexpected call for ItemLookup. Expected GET api.audible.us/1.0?Operation=ItemLookup... Actual: ' . $request->method() . ' ' . Str::limit($request->url(), 50)], 404);
        });

        $details = $this->getAudiobookDetails('TEST123');

        $this->assertIsArray($details);
        $this->assertEquals('TEST123', $details['id']);
        $this->assertEquals('Test Audiobook', $details['title']);
        $this->assertEquals([['name' => 'Test Author']], $details['authors']);
        $this->assertEquals([['name' => 'Test Narrator']], $details['narrators']);
        $this->assertEquals('Test Description', $details['description']);
    }
}
