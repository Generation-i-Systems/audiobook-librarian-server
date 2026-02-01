<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AudiobookBayApiService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\App\Services\AudiobookBayApiService::class)]
class AudiobookBayApiServiceCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function search_audiobooks_handles_invalid_cache_value_gracefully(): void
    {
        $cacheSpy = Cache::spy();
        $cacheSpy->shouldReceive('get')->andReturn(12345); // Return invalid data

        $service = app(AudiobookBayApiService::class);
        $result = $service->searchAudiobooks('foo');

        $this->assertNull($result, 'Should return null when cache contains invalid value');
        // @phpstan-ignore-next-line
        $cacheSpy->shouldHaveReceived('get');
    }
}
