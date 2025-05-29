<?php

namespace App\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Base trait for API clients with common functionality
 */
trait BaseApiTrait
{
    protected string $baseUrl = '';
    protected ?string $apiKey = null;
    protected int $cacheTtl = 86400; // 24 hours in seconds
    protected int $rateLimit = 100; // Requests per hour
    protected string $serviceName = '';

    /**
     * Make an HTTP GET request
     */
    protected function httpGet(string $endpoint, array $params = []): ?array
    {
        $cacheKey = $this->getCacheKey($endpoint, $params);
        
        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($endpoint, $params) {
            $this->checkRateLimit();
            
            $response = Http::withHeaders($this->getDefaultHeaders())
                ->get($this->baseUrl . $endpoint, $params);
                
            if ($response->successful()) {
                return $response->json();
            }
            
            Log::error('API request failed', [
                'service' => $this->serviceName,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
            
            return null;
        });
    }

    /**
     * Make an HTTP POST request
     */
    protected function httpPost(string $endpoint, array $data = []): ?array
    {
        $cacheKey = $this->getCacheKey($endpoint, $data);
        
        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($endpoint, $data) {
            $this->checkRateLimit();
            
            $response = Http::withHeaders($this->getDefaultHeaders())
                ->post($this->baseUrl . $endpoint, $data);
                
            if ($response->successful()) {
                return $response->json();
            }
            
            Log::error('API request failed', [
                'service' => $this->serviceName,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
            
            return null;
        });
    }

    /**
     * Check and enforce rate limiting
     */
    protected function checkRateLimit(): void
    {
        $cacheKey = "{$this->serviceName}_rate_limit_" . now()->format('YmdH');
        $count = Cache::get($cacheKey, 0);
        
        if ($count >= $this->rateLimit) {
            Log::warning('API rate limit reached', ['service' => $this->serviceName]);
            throw new \RuntimeException("{$this->serviceName} API rate limit exceeded. Please try again later.");
        }
        
        Cache::put($cacheKey, $count + 1, now()->addHour());
    }

    /**
     * Generate a cache key for the request
     */
    protected function getCacheKey(string $endpoint, array $params): string
    {
        return "{$this->serviceName}_" . md5($endpoint . json_encode($params));
    }

    /**
     * Get default headers for API requests
     */
    protected function getDefaultHeaders(): array
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'AudiobookLibrarian/1.0',
        ];
        
        if ($this->apiKey) {
            $headers['Authorization'] = "Bearer {$this->apiKey}";
        }
        
        return $headers;
    }

    /**
     * Set the API key
     */
    public function setApiKey(string $apiKey): self
    {
        $this->apiKey = $apiKey;
        return $this;
    }

    /**
     * Set the base URL
     */
    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        return $this;
    }

    /**
     * Set the cache TTL in seconds
     */
    public function setCacheTtl(int $seconds): self
    {
        $this->cacheTtl = $seconds;
        return $this;
    }

    /**
     * Set the rate limit (requests per hour)
     */
    public function setRateLimit(int $requestsPerHour): self
    {
        $this->rateLimit = $requestsPerHour;
        return $this;
    }
}
