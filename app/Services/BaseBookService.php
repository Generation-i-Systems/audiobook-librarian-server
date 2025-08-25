<?php

namespace App\Services;

use App\Contracts\BookServiceInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
     * {@inheritDoc}
     */
    public function searchBooks(string $query, array $options = []): ?array
    {
        Log::info('BaseBookService: searchBooks called.', [
            'query' => $query,
            'options' => $options,
            'service' => $this->getServiceName(),
        ]);

        if (!empty($options)) {
            try {
                Log::info('BaseBookService: About to call performSearch (no_cache path).');
                $result = $this->performSearch($query, $options);
                Log::info('BaseBookService: performSearch returned (no_cache path).', [
                    'result_is_null' => is_null($result),
                    'result_count' => is_array($result) ? count($result) : 'N/A',
                ]);

                // Tests expect an empty array on API failure in search path
                return $result ?? [];
            } catch (\Exception $e) {
                Log::error("Search failed for {$this->getServiceName()} (no_cache)", [
                    'query' => $query,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return null;
            }
        }
        Log::info('BaseBookService: Using cache path.');
        $cacheKey = $this->getServiceName() . '_search_' . md5($query . json_encode($options));

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($query, $options) {
            try {
                Log::info('BaseBookService: About to call performSearch (cache path).');
                $result = $this->performSearch($query, $options);
                Log::info('BaseBookService: performSearch returned (cache path).', [
                    'result_is_null' => is_null($result),
                    'result_count' => is_array($result) ? count($result) : 'N/A',
                ]);

                // If result is null => failure; if empty array => no matches
                return $result ?? [];
            } catch (\Exception $e) {
                Log::error("Search failed for {$this->getServiceName()} (cache path)", [
                    'query' => $query,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return null;
            }
        });
    }

    /**
     * {@inheritDoc}
     */
    public function getBookDetails(string $id, array $options = []): ?array
    {
        if (!empty($options)) {
            try {
                return $this->performGetBookDetails($id);
            } catch (\Exception $e) {
                Log::error("Failed to get book details from {$this->getServiceName()} (no_cache)", [
                    'id' => $id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return null;
            }
        }
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
     * {@inheritDoc}
     */
    public function isAvailable(): bool
    {
        return true; // Override in child classes if needed
    }

    /**
     * Perform the actual search implementation
     */
    abstract protected function performSearch(string $query, array $options = []): ?array;

    /**
     * Perform the actual book details lookup
     */
    abstract protected function performGetBookDetails(string $id): ?array;

    /**
     * Make an HTTP GET request
     */
    protected function httpGet(string $url, array $params = []): ?array
    {
        $queryString = http_build_query(array_merge($this->defaultParams, $params));
        $fullUrl = $url . (strpos($url, '?') === false ? '?' : '&') . $queryString;
        Log::info('BaseBookService.httpGet: ' . $fullUrl);
        $response = Http::withHeaders($this->defaultHeaders)
            ->timeout(15)
            ->get($url, array_merge($this->defaultParams, $params));

        Log::info('BaseBookService.httpGet: Response body', [
            'url' => $fullUrl,
            'body' => $response->body(),
        ]);

        if (!$response->successful()) {
            Log::error('HTTP request failed', [
                'url' => $url,
                'params' => $params,
                'status' => $response->status(),
                'body' => $response->body(), // Log the body for debugging
            ]);

            return null; // Return null on failure
        }

        return $response->json() ?? [];
    }
}
