<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AudiobookBayApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\TestCase;

#[\CoversClass(AudiobookBayApiService::class)]
class AudiobookBayApiServiceCacheTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    #[\Test]
    public function searchAudiobooks_handles_invalid_cache_value_gracefully(): void
    {
        $service = app(AudiobookBayApiService::class);
        $endpoint = '/test';
        $params = ['q' => 'foo'];
        // Simulate cache pollution
        $cacheKey = (new \ReflectionClass($service))->getMethod('getCacheKey')->invoke($service, $endpoint, $params);
        Cache::put($cacheKey, 12345, 10); // Invalid value
        $result = $service->searchAudiobooks('foo');
        $this->assertNull($result, 'Should return null when cache contains invalid value');
    }
}
