<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AudiobookBayApiService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * @covers \App\Services\AudiobookBayApiService
 */
class AudiobookBayApiServiceCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** @test */
    public function searchAudiobooksHandlesInvalidCacheValueGracefully(): void
    {
        $service = app(AudiobookBayApiService::class);
        $endpoint = '/search';
        $params = ['q' => 'foo'];
        // Simulate cache pollution
        $cacheKey = (new \ReflectionClass($service))->getMethod('getCacheKey')->invoke($service, $endpoint, $params);
        Cache::put($cacheKey, 12345, 10); // Invalid value
        $result = $service->searchAudiobooks('foo');
        $this->assertNull($result, 'Should return null when cache contains invalid value');
    }
}
