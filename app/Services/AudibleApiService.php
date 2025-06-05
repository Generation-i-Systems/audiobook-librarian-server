<?php

namespace App\Services;

use App\Traits\BaseApiTrait;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AudibleApiService
{
    use BaseApiTrait;

    protected ?string $audibleAccessKey = null;
    protected ?string $audibleSecretKey = null;
    protected ?string $audibleAssociateTag = null;
    protected string $audibleRegion = 'us'; // Default region
    protected ?float $lastRequestTime = null;

    public function __construct(array $config = [])
    {
        $this->audibleAccessKey = $config['access_key'] ?? config('services.audible.access_key');
        $this->audibleSecretKey = $config['secret_key'] ?? config('services.audible.secret_key');
        $this->audibleAssociateTag = $config['associate_tag'] ?? config('services.audible.associate_tag');

        // Ensure region defaults to 'us' if config provides an empty or null value
        $configuredRegion = $config['region'] ?? config('services.audible.region');
        $this->audibleRegion = !empty($configuredRegion) ? $configuredRegion : 'us';

        Log::debug('AudibleApiService Constructor: Region determined.', [
            'config_param_region' => $config['region'] ?? 'not_set_in_param',
            'env_config_services_audible_region' => config('services.audible.region'),
            'final_audible_region' => $this->audibleRegion
        ]);

        $this->baseUrl = "https://api.audible." . $this->audibleRegion . "/1.0";
        Log::debug('AudibleApiService Constructor: Base URL set.', ['baseUrl' => $this->baseUrl]);

        $this->serviceName = 'Audible'; // Set service name for BaseApiTrait

        // Ensure cacheTtl and rateLimit are initialized from BaseApiTrait defaults or config
        $this->cacheTtl = $config['cache_ttl'] ?? $this->cacheTtl;
        $this->rateLimit = $config['rate_limit'] ?? $this->rateLimit;

        if (empty($this->audibleAccessKey) || empty($this->audibleSecretKey) || empty($this->audibleAssociateTag)) {
            Log::warning('AudibleApiService: Missing required Audible API credentials.');
            Log::warning('Service may not function correctly.');
            // Consider throwing an exception here if credentials are vital for all operations
            // throw new \InvalidArgumentException('Missing required Audible API credentials.');
        }
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
            'ResponseGroup' => 'ItemAttributes,Images,EditorialReview,BrowseNodes',
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

        if (isset($options['category_id'])) { // Assuming category is passed as an ID
            $params['BrowseNodeId'] = $options['category_id'];
        }

        $apiResult = $this->signedRequest('', $params);
        Log::debug(
            'AudibleApiService:searchAudiobooks:apiResult',
            ['query' => $query, 'params' => $params, 'result' => $apiResult]
        );

        if (empty($apiResult)) {
            return [];
        }

        // Extract the actual data payload, typically under a root key like 'ItemSearchResponse'
        $operationKey = array_key_first($apiResult); // This should be 'Items' based on current API structure
        $payload = $operationKey ? ($apiResult[$operationKey] ?? null) : null;
        Log::debug('AudibleApiService:searchAudiobooks:responsePayload', [
            'query' => $query,
            'operation_key' => $operationKey,
            'payload' => $payload
        ]);

        $isPayloadEmptyForSearch = empty($payload);
        $isItemSetInPayloadForSearch = isset($payload['Item']);
        Log::debug('AudibleApiService:searchAudiobooks:CHECKS', [
            'isPayloadEmpty' => $isPayloadEmptyForSearch,
            'isItemSetInPayload' => $isItemSetInPayloadForSearch,
            'condition_is_true' => ($isPayloadEmptyForSearch || !$isItemSetInPayloadForSearch)
        ]);

        if ($isPayloadEmptyForSearch || !$isItemSetInPayloadForSearch) {
            Log::warning(
                'AudibleApiService searchAudiobooks: Condition TRUE. No items found in payload or payload is empty.',
                ['query' => $query, 'payload' => $payload]
            );
            return [];
        }
        Log::debug('AudibleApiService searchAudiobooks: Condition FALSE. Proceeding.');

        // formatSearchResults expects the content of 'ItemSearchResponse', which contains 'Items'
        return $this->formatSearchResults($payload);
    }

    /**
     * Get audiobook details by ASIN
     */
    public function getAudiobookDetails(string $asin): ?array
    {
        $params = [
            'Operation' => 'ItemLookup',
            'ItemId' => $asin,
            'ResponseGroup' => 'ItemAttributes,Images,EditorialReview,BrowseNodes',
            'IdType' => 'ASIN',
        ];

        $apiResult = $this->signedRequest('', $params);
        Log::debug(
            'AudibleApiService:getAudiobookDetails:apiResult',
            ['asin' => $asin, 'params' => $params, 'result' => $apiResult]
        );

        if (empty($apiResult)) {
            return null;
        }

        // Extract the actual data payload, typically under a root key like 'ItemLookupResponse'
        $operationKey = array_key_first($apiResult); // This should be 'Items' based on current API structure
        $payload = $operationKey ? ($apiResult[$operationKey] ?? null) : null;
        Log::debug('AudibleApiService:getAudiobookDetails:responsePayload', [
            'asin' => $asin,
            'operation_key' => $operationKey,
            'payload' => $payload
        ]);

        $isPayloadEmpty = empty($payload);
        $isItemSetInPayload = isset($payload['Item']);
        Log::debug('AudibleApiService:getAudiobookDetails:CHECKS', [
            'isPayloadEmpty' => $isPayloadEmpty,
            'isItemSetInPayload' => $isItemSetInPayload,
            'condition_is_true' => ($isPayloadEmpty || !$isItemSetInPayload)
        ]);

        if ($isPayloadEmpty || !$isItemSetInPayload) {
            Log::warning(
                'AudibleApiService getAudiobookDetails: Condition TRUE. No item found in payload or payload is empty.',
                ['asin' => $asin, 'payload' => $payload]
            );
            return null;
        }
        Log::debug('AudibleApiService getAudiobookDetails: Condition FALSE. Proceeding.');

        $item = $payload['Item'];

        // The API might return a single item not in an array if it's the only one.
        // formatBookResponse expects a single item structure.
        // If $item is already the item structure (not a list containing one item), pass it directly.
        // This check might need adjustment based on actual API behavior for single ItemLookup.
        $itemToFormat = (isset($item[0]) && count($item) === 1 && isset($item[0]['ASIN'])) ? $item[0] : $item;

        $formatted = $this->formatBookResponse($itemToFormat);

        if (empty($formatted['title'])) { // Basic check for a valid formatted response
            Log::warning(
                'AudibleApiService: Failed to format audiobook details.',
                ['asin' => $asin, 'response_item' => $itemToFormat]
            );
            return null;
        }

        return $formatted;
    }

    /**
     * Get audiobooks by author
     */
    public function getAudiobooksByAuthor(string $author, int $limit = 10): ?array
    {
        // Audible API's ItemSearch is generally used. 'Author' is a parameter.
        // The 'limit' parameter is not directly supported by Audible's ItemSearch in a simple way.
        // It returns paginated results. We might fetch the first page and let the caller handle pagination if needed.
        return $this->searchAudiobooks($author, ['author' => $author, 'sort' => 'relevancerank']);
    }

    /**
     * Get audiobooks by narrator
     */
    public function getAudiobooksByNarrator(string $narrator, int $limit = 10): ?array
    {
        return $this->searchAudiobooks($narrator, ['narrator' => $narrator, 'sort' => 'relevancerank']);
    }

    /**
     * Get categories (Browse Nodes from Audible)
     * This is a complex operation as Audible doesn't have a simple 'get all categories' endpoint.
     * Typically, categories are discovered through items or specific BrowseNodeLookup operations.
     * For now, this method might return a predefined list or be a placeholder.
     */
    public function getCategories(): ?array
    {
        // Placeholder: Actual implementation would require BrowseNodeLookup or parsing from items.
        Log::info('AudibleApiService: getCategories() called.');
        Log::info('This is a placeholder and may not return live categories.');
        // Example: Fetch a popular item and extract its browse nodes as a starting point,
        // or use a known root node ID.
        // $params = [
        //     'Operation' => 'BrowseNodeLookup',
        //     'BrowseNodeId' => 'SOME_ROOT_AUDIBLE_BROWSE_NODE_ID', // This ID needs to be known
        // ];
        // $response = $this->signedRequest('', $params);
        // return $this->parseBrowseNodes($response);
        return []; // Return empty array or a static list for now
    }

    /**
     * Make a signed request to the Audible API
     */
    protected function signedRequest(string $path, array $params = []): ?array
    {
        if (empty($this->audibleAccessKey) || empty($this->audibleSecretKey) || empty($this->audibleAssociateTag)) {
            Log::error('AudibleApiService: Attempted to make a signed request without complete API credentials.');
            return null;
        }

        $defaultParams = [
            'AWSAccessKeyId' => $this->audibleAccessKey,
            'AssociateTag' => $this->audibleAssociateTag,
            'Service' => 'AWSECommerceService',
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'Version' => '2013-08-01', // Or appropriate API version
        ];

        $params = array_merge($defaultParams, $params);
        ksort($params);

        $canonicalQueryString = [];
        foreach ($params as $key => $value) {
            $canonicalQueryString[] = $this->urlEncode($key) . '=' . $this->urlEncode($value);
        }
        $canonicalQueryString = implode('&', $canonicalQueryString);

        $stringToSign = "GET\napi.audible." . $this->audibleRegion . "\n/1.0" . $path . "\n" . $canonicalQueryString;
        $signature = base64_encode(hash_hmac('sha256', $stringToSign, $this->audibleSecretKey, true));
        $params['Signature'] = $signature;

        return $this->makeAudibleRequest($path, $params);
    }

    /**
     * Make the actual API request to Audible
     */
    protected function makeAudibleRequest(string $path, array $params): ?array
    {
        $fullUrl = $this->baseUrl . $path;
        $cacheKey = $this->getCacheKey($fullUrl, $params); // Use BaseApiTrait's cache key generation

        $response = Cache::remember($cacheKey, $this->cacheTtl, function () use ($fullUrl, $params) {
            $this->checkRateLimit(); // Use BaseApiTrait's rate limiting

            $httpResponse = Http::withHeaders($this->getDefaultHeaders()) // Use BaseApiTrait's default headers
                ->retry(3, 100) // Example retry logic
                ->get($fullUrl, $params);

            if ($httpResponse->successful()) {
                // Audible API often returns XML, convert to array. Assuming JSON for now (typical Laravel usage).
                // If it's XML, you'll need a conversion step here.
                // Forcing JSON for now, adjust if XML is confirmed.
                if (Str::contains($httpResponse->header('Content-Type'), 'xml')) {
                    $xml = simplexml_load_string($httpResponse->body());
                    $json = json_encode($xml);
                    $decodedJson = json_decode($json, true);
                    return $decodedJson;
                }
                return $httpResponse->json();
            }

            Log::error('Audible API request failed', [
                'service' => $this->serviceName,
                'url' => $fullUrl,
                'params' => $params, // Be careful logging sensitive params
                'status' => $httpResponse->status(),
                'response' => $httpResponse->body(),
            ]);
            return null;
        });

        return $response;
    }

    /**
     * URL encode according to RFC 3986
     */
    protected function urlEncode(string $value): string
    {
        return str_replace('%7E', '~', rawurlencode($value));
    }

    /**
     * Format a single API item to a standard book format
     */
    protected function formatBookResponse(array $item): array
    {
        if (empty($item['ASIN'])) { // Essential field
            return []; // Return empty if it's not a valid item structure
        }

        $itemAttributes = $item['ItemAttributes'] ?? [];
        // API has variations for editorial review
        $editorialReview = $item['EditorialReviews']['EditorialReview']
            ?? $item['EditorialReview']
            ?? null;
        $coverImageUrl = $item['LargeImage']['URL']
            ?? $item['MediumImage']['URL']
            ?? $item['SmallImage']['URL'] ?? null;

        $description = null;
        if (is_array($editorialReview) && isset($editorialReview['Content'])) {
            $description = $editorialReview['Content'];
        } elseif (is_string($editorialReview)) {
            $description = $editorialReview;
        } elseif (is_array($editorialReview) && !empty($editorialReview)) {
            // If it's an array of reviews, pick the first one's content
            $firstReview = reset($editorialReview);
            if (is_array($firstReview) && isset($firstReview['Content'])) {
                $description = $firstReview['Content'];
            }
        }

        $runningTime = $item['AudibleRuntime'] ?? $itemAttributes['RunningTime'] ?? null;
        if ($runningTime && is_numeric($runningTime)) {
            // Convert seconds to HH:MM:SS or similar if needed, or store as seconds
            // For now, assume it's a formatted string if not numeric, or seconds if numeric
            $hours = floor($runningTime / 3600);
            $minutes = floor(($runningTime % 3600) / 60);
            $seconds = $runningTime % 60;
            $runningTime = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return [
            'id' => $item['ASIN'],
            'title' => $itemAttributes['Title'] ?? null,
            'subtitle' => $itemAttributes['Subtitle'] ?? null,
            'authors' => $this->extractContributors($itemAttributes['Author'] ?? []),
            'narrators' => $this->extractContributors($itemAttributes['Narrator'] ?? []),
            'publisher' => $itemAttributes['Publisher'] ?? null,
            'published_date' => $itemAttributes['PublicationDate'] ?? null,
            'description' => $description,
            'duration' => $runningTime, // Or $itemAttributes['RunningTime'] directly
            'cover_image_url' => $coverImageUrl,
            'genres' => $this->extractGenres($item['BrowseNodes'] ?? []),
            'rating' => $item['CustomerReviews']['AverageRating'] ?? ($itemAttributes['AverageRating'] ?? null),
            'rating_count' => $item['CustomerReviews']['TotalReviews'] ?? ($itemAttributes['TotalReviews'] ?? 0),
            'url' => $item['DetailPageURL'] ?? null,
            'language' => $itemAttributes['Languages']['Language'][0]['Name'] ?? ($itemAttributes['Language'] ?? null),
            'format' => 'Audible Audiobook',
            'series' => (isset($itemAttributes['SeriesSequence']) && !empty($itemAttributes['SeriesSequence']))
                ? [['name' => $itemAttributes['Title'] ?? 'N/A', 'part' => $itemAttributes['SeriesSequence']]]
                : [],
            'tags' => [], // Audible API doesn't directly provide tags like user tags
            'source_api' => 'Audible',
            'raw_response' => $item, // Optionally include for debugging or further processing
        ];
    }

    /**
     * Extract contributors (authors, narrators) from the API response
     */
    protected function extractContributors($contributors): array
    {
        if (empty($contributors)) {
            return [];
        }

        // If it's a simple string (single contributor)
        if (is_string($contributors)) {
            return [['name' => $contributors]];
        }

        // If it's an array of strings
        if (is_array($contributors) && isset($contributors[0]) && is_string($contributors[0])) {
            return array_map(fn($name) => ['name' => $name], $contributors);
        }

        // If it's an array of ['Role' => ..., 'Contributor' => ...] or similar structures
        // This part needs to be adapted based on the actual structure of Audible's contributor data.
        // The trait had a more complex structure here, let's simplify and assume a list of names
        // or a single name object.
        // If it's an array of objects, each with a 'Name' key or just being the name string.
        $extracted = [];
        if (is_array($contributors)) {
            // Handle case where it's an array with a 'Name' key (single contributor object)
            if (isset($contributors['Name']) && is_string($contributors['Name'])) {
                return [['name' => $contributors['Name']]];
            }
            // Handle case where it's an array of such objects
            foreach ($contributors as $contributor) {
                if (is_string($contributor)) {
                    $extracted[] = ['name' => $contributor];
                } elseif (is_array($contributor) && isset($contributor['Name']) && is_string($contributor['Name'])) {
                    $extracted[] = ['name' => $contributor['Name']];
                }
            }
        }
        return $extracted;
    }
    /**
     * Extract genres from browse nodes
     */
    protected function extractGenres($browseNodes): array
    {
        if (empty($browseNodes)) {
            return [];
        }

        $genres = [];
        $actualNodeList = [];

        // Check if the primary data is under a 'BrowseNode' key
        if (isset($browseNodes['BrowseNode'])) {
            $bnData = $browseNodes['BrowseNode'];
            // Case 1: $bnData is an array of nodes (indexed array, e.g., multiple <BrowseNode> tags)
            if (isset($bnData[0]) && is_array($bnData[0])) {
                $actualNodeList = $bnData;
            // Case 2: $bnData is a single node (e.g., one <BrowseNode> tag)
            } elseif (is_array($bnData) && isset($bnData['BrowseNodeId'])) {
                $actualNodeList = [$bnData];
            }
        // Case 3: $browseNodes is a single node (no 'BrowseNode' wrapper)
        } elseif (isset($browseNodes['BrowseNodeId'])) {
            $actualNodeList = [$browseNodes];
        }

        foreach ($actualNodeList as $node) {
            if (is_array($node)) {
                $this->extractGenresRecursive($node, $genres);
            }
        }

        return array_values(array_unique($genres, SORT_REGULAR));
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
            $childrenData = $node['Children']['BrowseNode'];
            $children = [];
            if (isset($childrenData[0]) && is_array($childrenData[0])) {
                $children = $childrenData;
            } elseif (is_array($childrenData) && isset($childrenData['BrowseNodeId'])) {
                $children = [$childrenData];
            }

            foreach ($children as $child) {
                if (is_array($child)) {
                    $this->extractGenresRecursive($child, $genres);
                }
            }
        }

        if (!empty($node['Ancestors']['BrowseNode'])) {
            $ancestorsData = $node['Ancestors']['BrowseNode'];
            $ancestors = [];
            if (isset($ancestorsData[0]) && is_array($ancestorsData[0])) {
                $ancestors = $ancestorsData;
            } elseif (is_array($ancestorsData) && isset($ancestorsData['BrowseNodeId'])) {
                $ancestors = [$ancestorsData];
            }

            foreach ($ancestors as $ancestorNode) {
                if (is_array($ancestorNode) && !empty($ancestorNode['Name']) && !empty($ancestorNode['BrowseNodeId'])) {
                    $isAlreadyAdded = false;
                    foreach ($genres as $g) {
                        if (($g['id'] ?? null) === ($ancestorNode['BrowseNodeId'] ?? null)) {
                            $isAlreadyAdded = true;
                            break;
                        }
                    }
                    if (!$isAlreadyAdded) {
                        $genres[] = [
                            'id' => $ancestorNode['BrowseNodeId'],
                            'name' => $ancestorNode['Name'],
                            'path' => $this->getBrowseNodePath($ancestorNode)
                        ];
                    }
                }
            }
        }
    }

    /**
     * Get the full path for a browse node, including ancestors and self.
     */
    protected function getBrowseNodePath(array $node): string
    {
        $path = [];

        if (!empty($node['Ancestors']['BrowseNode'])) {
            $ancestorNodesData = $node['Ancestors']['BrowseNode'];
            $ancestors = [];

            if (isset($ancestorNodesData[0]) && is_array($ancestorNodesData[0])) {
                $ancestors = $ancestorNodesData;
            } elseif (is_array($ancestorNodesData) && isset($ancestorNodesData['BrowseNodeId'])) {
                $ancestors = [$ancestorNodesData];
            }

            foreach ($ancestors as $ancestor) {
                if (is_array($ancestor) && !empty($ancestor['Name'])) {
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
    protected function formatSearchResults(array $itemsNodeContent): array
    {
        Log::debug('AudibleApiService:formatSearchResults:input', ['itemsNodeContent' => $itemsNodeContent]);

        $itemData = $itemsNodeContent['Item'] ?? [];
        Log::debug('AudibleApiService:formatSearchResults:itemData', ['itemData' => $itemData]);

        if (empty($itemData)) {
            Log::debug('AudibleApiService:formatSearchResults:itemData_is_empty_returning_empty_array');
            return [];
        }

        $processedItems = [];
        // Check if $itemData is a single item (associative array) or a list of items (numerically indexed array)
        if (isset($itemData['ASIN'])) { // If 'ASIN' key exists directly, it's a single item
            $processedItems = [$itemData];
            Log::debug('AudibleApiService:formatSearchResults:single_item_detected', [
                'processedItems_count' => count($processedItems),
                'processedItems_keys' => array_keys($processedItems)
            ]);
        } else { // Otherwise, assume it's an array of items (or an empty array if no results)
            $processedItems = $itemData;
            Log::debug('AudibleApiService:formatSearchResults:array_of_items_assumed', [
                'processedItems_count' => count($processedItems),
                'processedItems_keys' => is_array($processedItems) ? array_keys($processedItems) : 'not_an_array'
            ]);
        }

        if (empty($processedItems)) {
            Log::debug(
                'AudibleApiService:formatSearchResults:processedItems_is_empty_returning_empty_array',
                ['processedItems' => $processedItems]
            );
            return [];
        }

        $results = [];
        foreach ($processedItems as $key => $singleItem) {
            Log::debug('AudibleApiService:formatSearchResults:looping_item', [
                'key' => $key,
                'singleItem_type' => gettype($singleItem)
            ]);
            if (empty($singleItem) || !is_array($singleItem)) {
                Log::warning('AudibleApiService:formatSearchResults:skipping_empty_or_non_array_item_in_loop', [
                    'key' => $key,
                    'singleItem' => $singleItem
                ]);
                continue;
            }
            $formattedBook = $this->formatBookResponse($singleItem);
            Log::debug('AudibleApiService:formatSearchResults:formattedBook_from_response', [
                'key' => $key,
                'formattedBook_id' => $formattedBook['id'] ?? 'N/A'
            ]);
            $results[] = $formattedBook;
        }
        Log::debug('AudibleApiService:formatSearchResults:final_results_count', ['count' => count($results)]);
        return $results;
    }
}
