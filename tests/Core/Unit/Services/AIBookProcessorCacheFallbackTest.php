<?php

namespace Tests\Core\Unit\Services;

use App\Services\AIBookProcessor;
use Tests\TestCase;

class AIBookProcessorCacheFallbackTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function itFallsBackToInMemoryCacheWhenCacheBackendThrows(): void
    {
        $this->app->instance('cache', new FailingCacheManager());

        $processor = new AIBookProcessor('gemini-2.5-flash-lite', false);

        $ref = new \ReflectionClass($processor);

        $trackRequest = $ref->getMethod('trackRequest');
        $trackRequest->setAccessible(true);
        $trackRequest->invoke($processor);

        $cacheGetInt = $ref->getMethod('cacheGetInt');
        $cacheGetInt->setAccessible(true);

        $minuteKey = 'gemini_api_requests_gemini-2.5-flash-lite_' . date('Y-m-d-H-i');
        $dayKey = 'gemini_api_requests_gemini-2.5-flash-lite_' . date('Y-m-d');

        $this->assertSame(1, $cacheGetInt->invoke($processor, $minuteKey, 0));
        $this->assertSame(1, $cacheGetInt->invoke($processor, $dayKey, 0));

        $canMakeRequest = $ref->getMethod('canMakeRequest');
        $canMakeRequest->setAccessible(true);
        $this->assertTrue($canMakeRequest->invoke($processor));
    }
}

class FailingCacheManager
{
    public function __call(string $name, array $arguments): mixed
    {
        throw new \RuntimeException('cache call failed: ' . $name);
    }
}
