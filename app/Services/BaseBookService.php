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
     * @inheritDoc
     */
    public function searchBooks(string $query, array $options = []): array
    {
        // Dedicated logger for this method's debug
        $baseServiceLogPath = storage_path('logs/base_service_debug.log');
        try {
            $baseLogger = Log::build([
                'driver' => 'single',
                'path' => $baseServiceLogPath,
                'level' => env('LOG_LEVEL', 'debug'),
            ]);
        } catch (\Throwable $e) {
            Log::error('BaseBookService: Failed to create baseLogger for searchBooks.', ['error' => $e->getMessage()]);
            $baseLogger = Log::channel(); // Fallback
        }

        $baseLogger->info('BaseBookService: searchBooks called (using baseLogger).', ['query' => $query, 'options' => $options, 'service' => $this->getServiceName()]);
        
        if (!empty($options['no_cache'])) {
            try {
                $baseLogger->info('BaseBookService: About to call performSearch (no_cache path - using baseLogger).');
                $result = $this->performSearch($query, $options);
                $baseLogger->info('BaseBookService: performSearch returned (no_cache path - using baseLogger).', ['result_is_null' => is_null($result), 'result_count' => is_array($result) ? count($result) : 'N/A']);
                return $result ?? [];
            } catch (\Exception $e) {
                $baseLogger->error("Search failed for {$this->getServiceName()} (no_cache - using baseLogger)", [
                    'query' => $query,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return [];
            }
        }
        $baseLogger->info('BaseBookService: Using cache path (using baseLogger).');
            $cacheKey = $this->getServiceName() . '_search_' . md5($query . json_encode($options));
            return Cache::remember($cacheKey, $this->cacheTtl, function () use ($query, $options, $baseLogger) {
            try {
                $baseLogger->info('BaseBookService: About to call performSearch (cache path - using baseLogger).');
                $result = $this->performSearch($query, $options);
                $baseLogger->info('BaseBookService: performSearch returned (cache path - using baseLogger).', ['result_is_null' => is_null($result), 'result_count' => is_array($result) ? count($result) : 'N/A']);
                return empty($result) ? [] : $result;
            } catch (\Exception $e) {
                $baseLogger->error("Search failed for {$this->getServiceName()} (cache path - using baseLogger)", [
                    'query' => $query,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return [];
            }
        });
    }

    /**
     * @inheritDoc
     */
    public function getBookDetails(string $id, array $options = []): ?array
    {
        if (!empty($options['no_cache'])) {
            try {
                return $this->performGetBookDetails($id);
            } catch (\Exception $e) {
                Log::error("Failed to get book details from {$this->getServiceName()} (no_cache)", [
                    'id' => $id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return [];
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
                return [];
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
    protected function httpGet(string $url, array $params = []): ?array
    {
        $response = Http::withHeaders($this->defaultHeaders)
            ->timeout(15)
            ->get($url, array_merge($this->defaultParams, $params));

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
