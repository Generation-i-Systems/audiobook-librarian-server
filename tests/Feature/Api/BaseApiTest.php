<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

abstract class BaseApiTest extends TestCase
{
    use RefreshDatabase;

    protected string $apiBaseUrl;
    protected string $apiKey;
    protected string $testQuery = 'test';

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock HTTP client
        Http::fake();
        
        // Set up test API key
        $this->apiKey = config('services.' . $this->getServiceName() . '.key', 'test_key');
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
