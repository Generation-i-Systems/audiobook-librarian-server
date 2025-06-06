<?php

namespace Tests\Feature\Api;

// File intentionally left blank. Trait-based test logic removed due to service refactor.

use App\Services\AudibleApiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Api\BaseApiTest;

/**
 * Service-based tests for AudibleApiService.
 * Replaces trait-based tests for AudibleApiTrait.
 */
class AudibleTraitTest extends BaseApiTest
{
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
    private AudibleApiService $audibleApi;
    protected string $testRegion = 'us';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::shouldReceive('remember')->andReturnUsing(function ($key, $ttl, $callback) {
            return $callback();
        });
        Cache::shouldReceive('tags')->with(['audible'])->andReturnSelf();
        Cache::shouldReceive('get')->andReturnNull();
        Cache::shouldReceive('put')->andReturnTrue();
        config([
            'services.audible.access_key' => 'test-access-key',
            'services.audible.secret_key' => 'test-secret-key',
            'services.audible.associate_tag' => 'test-tag',
            'services.audible.region' => 'us',
            'services.audible.base_url' => null,
        ]);
        $this->audibleApi = app(AudibleApiService::class);
        Http::fake();
    }

    #[Test]
    public function testCanInitializeAudibleApi(): void
    {
        $this->assertNotNull($this->audibleApi);
    }

    #[Test]
    public function testCanSearchAudiobooks(): void
    {
        Http::fake([
            '*' => Http::response($this->getMockSearchResponse(), 200),
        ]);
        $results = $this->audibleApi->searchBooks('test');
        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        $this->assertEquals('Test Audiobook', $results[0]['title']);
    }

    #[Test]
    public function testCanGetAudiobookDetails(): void
    {
        Http::fake([
            '*' => Http::response($this->getMockDetailsResponse(), 200),
        ]);
        $details = $this->audibleApi->getBookDetails('TEST123');
        $this->assertIsArray($details);
        $this->assertEquals('TEST123', $details['asin']);
        $this->assertEquals('Test Audiobook', $details['title']);
    }
}
