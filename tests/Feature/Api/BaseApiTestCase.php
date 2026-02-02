<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

abstract class BaseApiTestCase extends TestCase
{
    /**
     * If true, tests will attempt to download images (cover art) from APIs.
     * Enable by running PHPUnit with --get-images flag.
     * Example: vendor/bin/phpunit -- --get-images
     */
    protected bool $getImages = false;

    protected string $apiBaseUrl;

    protected string $apiKey;

    protected string $testQuery = 'test';

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure a clean cache state for tests
        if (config('cache.default') === 'array') {
            \Illuminate\Support\Facades\Cache::store('array')->clear();
        } else {
            /** @phpstan-ignore-next-line */
            \Illuminate\Support\Facades\Cache::fake();
        }

        // HTTP faking will be handled by specific test methods or their own setUp.

        // Set up test API key by directly setting the config value
        $testApiKey = 'test_api_key_for_' . $this->getServiceName();
        Config::set('services.' . $this->getServiceName() . '.key', $testApiKey);
        $this->apiKey = $testApiKey;
    }

    abstract protected function getServiceName(): string;

    protected function mockSuccessfulSearchResponse(): void
    {
        Http::fake([
            $this->apiBaseUrl . '*' => Http::response($this->getMockSearchResponse()),
        ]);
    }

    protected function mockSuccessfulDetailsResponse(): void
    {
        Http::fake([
            $this->apiBaseUrl . '*' => Http::response($this->getMockDetailsResponse()),
        ]);
    }

    abstract protected function getMockSearchResponse(): array;

    abstract protected function getMockDetailsResponse(): array;

    protected function assertCommonBookStructure(array $book): void
    {
        $this->assertArrayHasKey('title', $book);
        $this->assertArrayHasKey('authors', $book);
        $this->assertIsArray($book['authors']);
        $this->assertArrayHasKey('description', $book);
        $this->assertArrayHasKey('cover_image_url', $book);
        $this->assertArrayHasKey('metadata', $book);
        $this->assertIsArray($book['metadata']);
    }
}
