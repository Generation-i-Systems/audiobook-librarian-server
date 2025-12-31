<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleImageSearchService
{
    protected string $apiKey;
    protected string $searchEngineId;

    public function __construct()
    {
        $this->apiKey = config('services.google.api_key', '');
        $this->searchEngineId = config('services.google.search_engine_id', '');
    }

    /**
     * Search for book cover images using Google Image Search
     *
     * @param string $author Book author name
     * @param string $title Book title
     * @param int $limit Number of results to return (default: 3)
     * @return array Array of image URLs
     */
    public function searchBookCovers(string $author, string $title, int $limit = 3): array
    {
        $results = [
            'success' => false,
            'images' => [],
            'error' => null,
        ];

        // If API key or search engine ID not configured, return empty results
        if (empty($this->apiKey) || empty($this->searchEngineId)) {
            $results['error'] = 'Google Custom Search API not configured. Set GOOGLE_API_KEY and GOOGLE_SEARCH_ENGINE_ID in .env';
            Log::warning('GoogleImageSearchService: API not configured');
            return $results;
        }

        try {
            // Build search query
            $query = trim("{$author} {$title} book");

            Log::info('GoogleImageSearchService: Searching for book covers', [
                'query' => $query,
                'limit' => $limit,
            ]);

            $response = Http::timeout(10)->get('https://www.googleapis.com/customsearch/v1', [
                'key' => $this->apiKey,
                'cx' => $this->searchEngineId,
                'q' => $query,
                'searchType' => 'image',
                'num' => min($limit, 10), // Google API max is 10
                'imgSize' => 'large', // Prefer larger images
                'fileType' => 'jpg|png', // Image files only
            ]);

            if (!$response->successful()) {
                $results['error'] = 'Google API request failed: HTTP ' . $response->status();
                Log::error('GoogleImageSearchService: API request failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
                return $results;
            }

            $data = $response->json();

            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $item) {
                    if (isset($item['link'])) {
                        $results['images'][] = [
                            'url' => $item['link'],
                            'title' => $item['title'] ?? '',
                            'width' => $item['image']['width'] ?? 0,
                            'height' => $item['image']['height'] ?? 0,
                            'thumbnail' => $item['image']['thumbnailLink'] ?? null,
                        ];
                    }

                    if (count($results['images']) >= $limit) {
                        break;
                    }
                }

                $results['success'] = true;
                Log::info('GoogleImageSearchService: Found book cover images', [
                    'query' => $query,
                    'count' => count($results['images']),
                ]);
            } else {
                $results['error'] = 'No images found in API response';
                Log::warning('GoogleImageSearchService: No images found', [
                    'query' => $query,
                ]);
            }
        } catch (\Exception $e) {
            $results['error'] = 'Exception: ' . $e->getMessage();
            Log::error('GoogleImageSearchService: Exception during search', [
                'error' => $e->getMessage(),
                'author' => $author,
                'title' => $title,
            ]);
        }

        return $results;
    }

    /**
     * Search for a single book cover image (returns first result)
     *
     * @param string $author Book author name
     * @param string $title Book title
     * @return string|null Image URL or null if not found
     */
    public function searchFirstBookCover(string $author, string $title): ?string
    {
        $results = $this->searchBookCovers($author, $title, 1);

        if ($results['success'] && !empty($results['images'])) {
            return $results['images'][0]['url'];
        }

        return null;
    }

    /**
     * Check if the Google Image Search API is configured
     *
     * @return bool True if API is configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->searchEngineId);
    }
}
