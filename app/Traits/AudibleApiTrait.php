<?php

namespace App\Traits;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Trait for interacting with the Audible API
 */
trait AudibleApiTrait
{
    use BaseApiTrait;

    protected ?string $audibleAccessKey = null;
    protected ?string $audibleSecretKey = null;
    protected ?string $audibleAssociateTag = null;
    protected ?string $audibleRegion = 'us';

    // The following properties are defined in BaseApiTrait:
    // - string $baseUrl = ''
    // - ?string $apiKey = null
    // - int $cacheTtl = 86400 (24 hours)
    // - int $rateLimit = 100 (requests per hour)
    // - string $serviceName = ''

    protected ?float $lastRequestTime = null;

    /**
     * Initialize the Audible API client
     * @return self|null
     */
    public function initAudible(array $config = []): ?self
    {
        $this->audibleAccessKey = $config['access_key'] ?? config('services.audible.access_key');
        $this->audibleSecretKey = $config['secret_key'] ?? config('services.audible.secret_key');
        $this->audibleAssociateTag = $config['associate_tag'] ?? config('services.audible.associate_tag');
        $this->audibleRegion = $config['region'] ?? config('services.audible.region', 'us');
        
        // Set the base URL based on region
        $this->baseUrl = "https://api.audible.{$this->audibleRegion}/1.0";
        
        if (empty($this->audibleAccessKey) || empty($this->audibleSecretKey) || empty($this->audibleAssociateTag)) {
            Log::warning('Missing required Audible API credentials');
            return null;
        }
        
        return $this;
    }

    /**
     * Search for audiobooks
     */
    public function searchAudiobooks(string $query, array $options = []): ?array
    {
        $params = [
            'Operation' => 'ItemSearch',
            'SearchIndex' => 'Audible',
            'Keywords' => $query,
            'ResponseGroup' => 'ItemAttributes,Images,EditorialReview',
            'ItemPage' => $options['page'] ?? 1,
            'Sort' => $options['sort'] ?? 'relevancerank',
        ];

        if (isset($options['author'])) {
            $params['Author'] = $options['author'];
        }
        
        if (isset($options['narrator'])) {
            $params['Narrator'] = $options['narrator'];
        }
        
        if (isset($options['title'])) {
            $params['Title'] = $options['title'];
        }
        
        if (isset($options['category'])) {
            $params['BrowseNodeId'] = $options['category'];
        }

        $response = $this->signedRequest('', $params);
        
        if (empty($response['Items']['Item'])) {
            return [];
        }
        
        $items = isset($response['Items']['Item'][0]) 
            ? $response['Items']['Item'] 
            : [$response['Items']['Item']];
            
        return array_map([$this, 'formatBookResponse'], $items);
    }

    /**
     * Get audiobook details by ASIN
     */
    public function getAudiobookDetails(string $asin): ?array
    {
        $params = [
            'Operation' => 'ItemLookup',
            'ItemId' => $asin,
            'ResponseGroup' => 'ItemAttributes,Images,EditorialReview',
            'IdType' => 'ASIN',
        ];

        $response = $this->signedRequest('', $params);
        
        if (empty($response['Items']['Item'])) {
            return null;
        }
        
        $item = $response['Items']['Item'];

        // If the response is not an array (single item), wrap it in an array
        if (!isset($item[0])) {
            $item = [$item];
        }

        $formatted = $this->formatBookResponse($item[0]);

        // Ensure we have the required fields
        if (empty($formatted['title']) || empty($formatted['authors'])) {
            return null;
        }

        return $formatted;
    }

    /**
     * Get audiobooks by author
     */
    public function getAudiobooksByAuthor(string $author, int $limit = 10): ?array
    {
        return $this->searchAudiobooks('', [
            'author' => $author,
            'limit' => $limit,
        ]);
    }

    /**
     * Get audiobooks by narrator
     */
    public function getAudiobooksByNarrator(string $narrator, int $limit = 10): ?array
    {
        return $this->searchAudiobooks('', [
            'narrator' => $narrator,
            'limit' => $limit,
        ]);
    }

    /**
     * Get categories
     */
    public function getCategories(): ?array
    {
        return $this->signedRequest('/browse/categories', [
            'ResponseGroup' => 'browse_categories',
        ]);
    }

    /**
     * Make a signed request to the Audible API
     */
    protected function signedRequest(string $path, array $params = []): ?array
    {
        // Skip caching in tests
        if (app()->environment('testing')) {
            return $this->makeAudibleRequest($path, $params);
        }
        
        $cacheKey = $this->getCacheKey($path, $params);
        
        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($path, $params) {
            return $this->makeAudibleRequest($path, $params);
        });
    }
    
    /**
     * Make the actual API request to Audible
     */
    protected function makeAudibleRequest(string $path, array $params): ?array
    {
        $this->checkRateLimit();
        
        // Add required parameters
        $defaultParams = [
            'associate_tag' => $this->audibleAssociateTag,
            'access_key' => $this->audibleAccessKey,
            'secret_key' => $this->audibleSecretKey,
            'region' => $this->audibleRegion ?? 'us',
            'Service' => 'AWSECommerceService',
            'Operation' => 'ItemLookup',
            'Version' => '2013-08-01',
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'SignatureMethod' => 'HmacSHA256',
            'SignatureVersion' => '2',
        ];
        
        $params = array_merge($defaultParams, $params);
        
        // Sort parameters by key
        ksort($params);
        
        // Create the canonical query string
        $canonicalQuery = '';
        foreach ($params as $key => $value) {
            $canonicalQuery .= '&' . $this->urlEncode($key) . '=' . $this->urlEncode($value);
        }
        $canonicalQuery = substr($canonicalQuery, 1);
        
        // Create the string to sign
        $stringToSign = "GET\nwebservices.amazon.{$this->audibleRegion}\n{$path}\n{$canonicalQuery}";
        
        // Calculate the signature
        $signature = base64_encode(hash_hmac('sha256', $stringToSign, $this->audibleSecretKey, true));
        
        // Add the signature to the parameters
        $params['Signature'] = $signature;
        
        // Build the URL
        $url = $this->baseUrl . $path . '?' . http_build_query($params);
        
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'AudiobookLibrarian/1.0',
                'Accept' => 'application/json',
            ])->get($url);
            
            if ($response->successful()) {
                return $response->json();
            }
            
            Log::error('Audible API request failed', [
                'status' => $response->status(),
                'response' => $response->body(),
                'url' => $url,
            ]);
            
            return null;
        } catch (\Exception $e) {
            Log::error('Audible API request exception', [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);
            
            return null;
        }
    }

    /**
     * URL encode according to RFC 3986
     */
    protected function urlEncode(string $value): string
    {
        return str_replace('%7E', '~', rawurlencode($value));
    }

    /**
     * Format the API response to a standard book format
     */
    protected function formatBookResponse(array $item): array
    {
        $attributes = $item['ItemAttributes'] ?? [];
        $image = $item['LargeImage']['URL'] ?? $item['MediumImage']['URL'] ?? $item['SmallImage']['URL'] ?? null;
        
        $description = '';
        if (isset($item['EditorialReviews']['EditorialReview'])) {
            $reviews = isset($item['EditorialReviews']['EditorialReview'][0]) 
                ? $item['EditorialReviews']['EditorialReview'] 
                : [$item['EditorialReviews']['EditorialReview']];
                
            foreach ($reviews as $review) {
                if (($review['Source'] ?? '') === 'Product Description') {
                    $description = $review['Content'] ?? '';
                    break;
                }
            }
        }
        return [
            'id' => $item['ASIN'] ?? null,
            'title' => $attributes['Title'] ?? null,
            'subtitle' => $attributes['Subtitle'] ?? null,
            'authors' => $this->extractContributors($attributes['Author'] ?? []),
            'narrators' => $this->extractContributors($attributes['Narrator'] ?? []),
            'publisher' => $attributes['Publisher'] ?? null,
            'published_date' => $attributes['PublicationDate'] ?? null,
            'description' => $description,
            'duration' => $item['AudioDetails']['Time'] ?? null,
            'cover_image_url' => $image,
            'genres' => $this->extractGenres($item['BrowseNodes'] ?? []),
            'rating' => $item['CustomerReviews']['AverageRating'] ?? null,
            'rating_count' => $item['CustomerReviews']['TotalCount'] ?? 0,
            'url' => $item['DetailPageURL'] ?? null,
        ];
    }

    /**
     * Extract editorial review from the API response
     */
    protected function extractEditorialReview(array $reviews): array
    {
        if (empty($reviews['EditorialReview'])) {
            return [];
        }
        
        // Handle both single review and multiple reviews
        if (isset($reviews['EditorialReview']['Source'])) {
            return [
                'source' => $reviews['EditorialReview']['Source'],
                'content' => $reviews['EditorialReview']['Content'] ?? '',
            ];
        }
        
        // Find the first review with content
        foreach ($reviews['EditorialReview'] as $review) {
            if (!empty($review['Content'])) {
                return [
                    'source' => $review['Source'] ?? '',
                    'content' => $review['Content'],
                ];
            }
        }
        
        return [];
    }

    /**
     * Extract contributors from the API response
     */
    protected function extractContributors($contributors): array
    {
        if (is_string($contributors)) {
            return [['name' => $contributors]];
        }
        
        if (is_array($contributors)) {
            // Handle case where it's an array of strings
            if (isset($contributors[0]) && is_string($contributors[0])) {
                return array_map(function ($name) {
                    return ['name' => $name];
                }, $contributors);
            }
            
            // Handle case where it's an array with a 'Name' key
            if (isset($contributors['Name'])) {
                return [['name' => $contributors['Name']]];
            }
        }
        
        return [];
    }

    /**
     * Extract genres from browse nodes
     */
    protected function extractGenres($browseNodes): array
    {
        if (empty($browseNodes['BrowseNode'])) {
            return [];
        }
        
        $genres = [];
        $nodes = isset($browseNodes['BrowseNode'][0]) ? $browseNodes['BrowseNode'] : [$browseNodes['BrowseNode']];
        
        foreach ($nodes as $node) {
            $this->extractGenresRecursive($node, $genres);
        }
        
        return $genres;
    }
    
    /**
     * Recursively extract genres from browse nodes
     */
    protected function extractGenresRecursive(array $node, array &$genres): void
    {
        if (!empty($node['Name'])) {
            $genres[] = [
                'id' => $node['BrowseNodeId'] ?? null,
                'name' => $node['Name'],
                'path' => $this->getBrowseNodePath($node),
            ];
        }
        
        if (!empty($node['Children']['BrowseNode'])) {
            $children = isset($node['Children']['BrowseNode'][0]) 
                ? $node['Children']['BrowseNode'] 
                : [$node['Children']['BrowseNode']];
                
            foreach ($children as $child) {
                $this->extractGenresRecursive($child, $genres);
            }
        }
    }
    
    /**
     * Get the full path for a browse node
     */
    protected function getBrowseNodePath(array $node): string
    {
        $path = [];
        
        if (!empty($node['Ancestors']['BrowseNode'])) {
            $ancestors = isset($node['Ancestors']['BrowseNode'][0])
                ? $node['Ancestors']['BrowseNode']
                : [$node['Ancestors']['BrowseNode']];
                
            foreach ($ancestors as $ancestor) {
                if (!empty($ancestor['Name'])) {
                    array_unshift($path, $ancestor['Name']);
                }
            }
        }
        
        if (!empty($node['Name'])) {
            $path[] = $node['Name'];
        }
        
        return implode(' > ', $path);
    }

    /**
     * Format search results to a standard format
     */
    public function formatSearchResults(array $apiResponse): array
    {
        if (empty($apiResponse['Items']['Item'])) {
            return [];
        }
        
        $items = isset($apiResponse['Items']['Item'][0]) 
            ? $apiResponse['Items']['Item'] 
            : [$apiResponse['Items']['Item']];
        
        return array_map(function ($item) {
            $itemAttributes = $item['ItemAttributes'] ?? [];
            $audioDetails = $item['AudioDetails'] ?? [];
            
            return [
                'id' => $item['ASIN'] ?? null,
                'title' => $itemAttributes['Title'] ?? null,
                'subtitle' => $itemAttributes['Subtitle'] ?? null,
                'authors' => $this->extractContributors($itemAttributes['Author'] ?? []),
                'narrators' => $this->extractContributors($itemAttributes['Narrator'] ?? []),
                'publisher' => $itemAttributes['Publisher'] ?? null,
                'published_date' => $itemAttributes['PublicationDate'] ?? null,
                'description' => $item['EditorialReview'] ?? null,
                'duration' => $audioDetails['Time'] ?? null,
                'cover_image_url' => $item['MediumImage']['URL'] ?? $item['SmallImage']['URL'] ?? null,
                'genres' => $this->extractGenres($item['BrowseNodes'] ?? []),
                'rating' => $item['CustomerReviews']['AverageRating'] ?? null,
                'rating_count' => $item['CustomerReviews']['TotalCount'] ?? 0,
                'url' => $item['DetailPageURL'] ?? null
            ];
        }, $items);
    }
}
