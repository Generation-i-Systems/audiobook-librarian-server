<?php

namespace Tests\Unit\Services\AI\Providers;

use App\Services\AI\Providers\GeminiProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class GeminiProviderTest extends TestCase
{
    public function testGetNameReturnsGemini(): void
    {
        $provider = new GeminiProvider();

        $this->assertEquals('gemini', $provider->getName());
    }

    public function testGetModelReturnsConfiguredModel(): void
    {
        $provider = new GeminiProvider('gemini-2.5-flash');

        $this->assertEquals('gemini-2.5-flash', $provider->getModel());
    }

    public function testGetModelDefaultsToFlashLite(): void
    {
        $provider = new GeminiProvider();

        $this->assertEquals('gemini-2.5-flash-lite', $provider->getModel());
    }

    public function testCompleteReturnsSuccessResponse(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $mockResponse = new Response(200, [], json_encode([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'This is a test response'],
                        ],
                    ],
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 20,
            ],
        ]));

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $provider = new GeminiProvider();
        $this->setProtectedProperty($provider, 'client', $client);

        $response = $provider->complete('Test prompt');

        $this->assertTrue($response->isSuccess());
        $this->assertEquals('This is a test response', $response->getContent());
        $this->assertGreaterThan(0, $response->getCost());
    }

    public function testCompleteHandlesApiError(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $mockResponse = new Response(500, [], json_encode([
            'error' => ['message' => 'Internal server error'],
        ]));

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $provider = new GeminiProvider();
        $this->setProtectedProperty($provider, 'client', $client);

        $response = $provider->complete('Test prompt');

        $this->assertFalse($response->isSuccess());
        $this->assertNotNull($response->getError());
    }

    public function testCompleteStructuredReturnsValidJson(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        $mockResponse = new Response(200, [], json_encode([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => '{"title": "Test Book", "author": "Test Author"}'],
                        ],
                    ],
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 20,
            ],
        ]));

        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $provider = new GeminiProvider();
        $this->setProtectedProperty($provider, 'client', $client);

        $schema = [
            'required' => ['title', 'author'],
        ];

        $response = $provider->completeStructured('Test prompt', $schema);

        $this->assertTrue($response->isSuccess());
        $this->assertIsArray($response->getData());
        $this->assertEquals('Test Book', $response->getData()['title']);
        $this->assertEquals('Test Author', $response->getData()['author']);
    }

    public function testRateLimitsAreEnforcedForFreeMode(): void
    {
        $provider = new GeminiProvider('gemini-2.5-flash-lite', false);

        $stats = $provider->getUsageStats();

        $this->assertArrayHasKey('rate_limits', $stats);
        $this->assertEquals(15, $stats['rate_limits']['requests_per_minute']);
        $this->assertEquals(1000, $stats['rate_limits']['requests_per_day']);
    }

    public function testRateLimitsAreEnforcedForPaidMode(): void
    {
        $provider = new GeminiProvider('gemini-2.5-flash-lite', true);

        $stats = $provider->getUsageStats();

        $this->assertArrayHasKey('rate_limits', $stats);
        $this->assertEquals(1000, $stats['rate_limits']['requests_per_minute']);
        $this->assertNull($stats['rate_limits']['requests_per_day']);
    }

    public function testPricingIsConfiguredCorrectly(): void
    {
        $provider = new GeminiProvider('gemini-2.5-flash-lite');

        $stats = $provider->getUsageStats();

        $this->assertArrayHasKey('pricing', $stats);
        $this->assertEquals(0.10, $stats['pricing']['input_per_million']);
        $this->assertEquals(0.40, $stats['pricing']['output_per_million']);
    }

    protected function setProtectedProperty($object, $propertyName, $value): void
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }
}
