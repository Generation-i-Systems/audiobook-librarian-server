<?php

namespace App\Services;

use App\Contracts\BookServiceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

abstract class BaseBookService implements BookServiceInterface
{
    /**
     * BaseBookService constructor.
     */
    public function __construct()
    {
        // Constructor logic can be added here if needed
    }
    /**
     * Default headers for HTTP requests
     */
    protected array $defaultHeaders = [
        'Accept' => 'application/json',
        'User-Agent' => 'AudiobookLibrarian/1.0',
    ];

    /**
     * Default parameters for API requests
     */
    protected array $defaultParams = [];

    /**
     * Cache duration in seconds
     */
    protected int $cacheTtl = 3600; // 1 hour

    /**
     * @inheritDoc
     */
    public function searchBooks(string $query, array $options = []): ?array
    {
        $cacheKey = $this->getServiceName() . '_search_' . md5($query . json_encode($options));
        
        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($query, $options) {
            try {
                return $this->performSearch($query, $options);
            } catch (\Exception $e) {
                Log::error("Search failed for {$this->getServiceName()}", [
                    'query' => $query,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return null;
            }
        });
    }

    /**
     * @inheritDoc
     */
    public function getBookDetails(string $id): ?array
    {
        $cacheKey = $this->getServiceName() . '_details_' . $id;
        
        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($id) {
            try {
                return $this->performGetBookDetails($id);
            } catch (\Exception $e) {
                Log::error("Failed to get book details from {$this->getServiceName()}", [
                    'id' => $id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return null;
            }
        });
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(): bool
    {
        return true; // Override in child classes if needed
    }

    /**
     * Perform the actual search implementation
     * 
     * @param string $query
     * @param array $options
     * @return array|null
     */
    abstract protected function performSearch(string $query, array $options = []): ?array;

    /**
     * Perform the actual book details lookup
     * 
     * @param string $id
     * @return array|null
     */
    abstract protected function performGetBookDetails(string $id): ?array;

    /**
     * Make an HTTP GET request
     */
    protected function httpGet(string $url, array $params = []): array
    {
        $response = Http::withHeaders($this->defaultHeaders)
            ->timeout(15)
            ->get($url, array_merge($this->defaultParams, $params));

        if (!$response->successful()) {
            throw new \RuntimeException("HTTP request failed with status: " . $response->status());
        }

        return $response->json() ?? [];
    }
}
