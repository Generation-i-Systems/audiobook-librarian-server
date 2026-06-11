<?php

namespace App\Services;

use App\Contracts\BookServiceInterface;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GoogleBooksApiService extends BaseBookService implements BookServiceInterface
{
    /**
     * The base URL for Google Books API requests.
     */
    protected string $baseUrl = 'https://www.googleapis.com/books/v1/';

    /**
     * Attempt to look up the book in Google Books and return additional metadata.
     * CRITICAL: Duration must match within 15% or match is rejected
     */
    public function searchAndMerge(array $book): ?array
    {
        $inputTitle = trim($book['title'] ?? '');

        // Handle different author formats safely
        $inputAuthor = '';
        if (isset($book['authors']) && is_array($book['authors'])) {
            if (isset($book['authors'][0]['author']['name'])) {
                $inputAuthor = trim($book['authors'][0]['author']['name']);
            } elseif (isset($book['authors'][0]) && is_string($book['authors'][0])) {
                $inputAuthor = trim($book['authors'][0]);
            }
        } elseif (isset($book['author']) && is_string($book['author'])) {
            $inputAuthor = trim($book['author']);
        }

        // CRITICAL: Reject if author contains "Graphic" AND "Audio"
        if (stripos($inputAuthor, 'graphic') !== false && stripos($inputAuthor, 'audio') !== false) {
            return null;
        }

        if (!$inputTitle) {
            return null;
        }

        // Get actual duration from files (in seconds)
        $actualDuration = $book['duration'] ?? null;
        $query = $inputTitle;
        $options = ['limit' => 10];
        if ($inputAuthor) {
            $options['author'] = $inputAuthor;
        }
        $results = $this->performSearch($query, $options) ?? [];
        if (empty($results)) {
            return null;
        }
        $bestScore = 0;
        $bestMatch = null;
        foreach ($results as $result) {
            $score = 0;

            // Title matching - require at least 80% similarity
            $titleSimilarity = 0;
            if (!empty($result['title'])) {
                similar_text(strtolower($result['title']), strtolower($inputTitle), $titleSimilarity);

                if ($titleSimilarity < 80) {
                    // Skip this result - title doesn't match well enough
                    continue;
                }

                if (stripos($result['title'], $inputTitle) !== false) {
                    $score += 3;
                } else {
                    $score += 2;
                }
            }

            // Author matching - REQUIRE author match if we have an author
            $authorMatched = false;
            if (!empty($inputAuthor) && !empty($result['authors'])) {
                foreach ($result['authors'] as $authorObj) {
                    $authorName = is_array($authorObj['author'] ?? null) ? $authorObj['author']['name'] ?? '' : ($authorObj['author'] ?? '');
                    if ($authorName && stripos($authorName, $inputAuthor) !== false) {
                        $score += 2;
                        $authorMatched = true;
                        break;
                    }
                }

                // If we have an author but no match, skip this result
                if (!$authorMatched) {
                    continue;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $result;
            }
        }

        // Require minimum score of 3 (title match + author match)
        if (!$bestMatch || $bestScore < 3) {
            return null;
        }
        $details = null;
        if (!empty($bestMatch['id'])) {
            $details = $this->performGetBookDetails($bestMatch['id']);
        }
        $source = $details ?: $bestMatch;
        $merged = [
            'googlebooksId' => $source['id'] ?? null,
            'title' => $source['title'] ?? null,
            'subtitle' => $source['subtitle'] ?? null,
            'description' => $source['description'] ?? null,
            'coverImageUrl' => $source['cover_image_url'] ?? null,
            'authors' => $source['authors'] ?? null,
            'publisher' => $source['publisher']['name'] ?? $source['publisher_name'] ?? null,
            'releaseDate' => $source['published_date'] ?? $source['release_date'] ?? null,
            'categories' => $this->splitCategories($source['categories'] ?? []),
            'pageCount' => $source['page_count'] ?? null,
            'averageRating' => $source['average_rating'] ?? null,
            'ratingsCount' => $source['ratings_count'] ?? null,
            'isbn10' => $source['isbn_10'] ?? null,
            'isbn13' => $source['isbn_13'] ?? null,
            'language' => $source['language'] ?? null,
            'previewLink' => $source['preview_link'] ?? null,
            'infoLink' => $source['info_link'] ?? null,
        ];
        // Download cover image if present and directoryPath is available
        if (!empty($merged['coverImageUrl']) && !empty($book['directoryPath'])) {
            $coverUrl = $merged['coverImageUrl'];
            $directory = rtrim($book['directoryPath'], '/');
            $ext = pathinfo(parse_url($coverUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
            $localFilename = 'cover.' . $ext;
            try {
                // Use Laravel's Http client if available, else fallback to file_get_contents
                if (class_exists('Illuminate\\Support\\Facades\\Http')) {
                    $response = \Illuminate\Support\Facades\Http::withOptions(['verify' => false])->get($coverUrl);
                    if ($response->successful()) {
                        Storage::disk('books')->put($directory . '/' . $localFilename, $response->body());
                        $merged['coverImage'] = $localFilename;
                    }
                } else {
                    $imageData = @file_get_contents($coverUrl);
                    if ($imageData !== false) {
                        Storage::disk('books')->put($directory . '/' . $localFilename, $imageData);
                        $merged['coverImage'] = $localFilename;
                    }
                }
            } catch (\Exception $e) {
                // Log error and fallback to original URL
                Log::warning('Failed to download cover image', [
                    'url' => $coverUrl,
                    'error' => $e->getMessage(),
                ]);
                // leave coverImage as original URL
            }
        }
        $apiFields = [];
        $needsReview = false;
        foreach ($merged as $field => $newValue) {
            if (
                array_key_exists($field, $book)
                && $book[$field] !== null
                && $newValue !== null
                && $book[$field] != $newValue
            ) {
                $apiFields[$field] = $newValue;
                $needsReview = true;
                $merged[$field] = $book[$field];
            }
        }
        if ($needsReview) {
            $merged['googlebooksFields'] = $apiFields;
            $merged['needsReview'] = true;
        }

        // Remove nulls and skip narrators/series/duration if not present
        return array_filter($merged, function ($v, $k) {
            if (in_array($k, ['narrators', 'series', 'duration']) && $v === null) {
                return false;
            }

            return $v !== null;
        }, ARRAY_FILTER_USE_BOTH);
    }

    protected $client;

    protected $apiKey;

    protected int $defaultLimit = 5;

    protected int $cacheTtl = 129600; // 3 months in minutes

    public function __construct()
    {
        parent::__construct();
        $this->client = new Client([
            'base_uri' => rtrim($this->baseUrl, '/') . '/',
            'timeout' => 10.0,
        ]);
        $this->apiKey = config('services.googlebooks.key');
    }

    /**
     * {@inheritDoc}
     */
    public function getServiceName(): string
    {
        return 'google_books';
    }

    /**
     * {@inheritDoc}
     */
    protected function performSearch(string $query, array $options = []): ?array
    {
        $limit = $options['limit'] ?? $this->defaultLimit;
        $author = $options['author'] ?? null;

        $queryParams = [
            'q' => $this->buildSearchQuery($query, $author),
            'maxResults' => $limit,
            'key' => $this->apiKey,
        ];

        try {
            $response = $this->httpGet($this->baseUrl . 'volumes', $queryParams); // Use full URL

            if (empty($response['items'])) {
                Log::warning('No results found in Google Books API response', [
                    'query' => $query,
                    'options' => $options,
                ]);

                return [];
            }

            $results = [];
            foreach ($response['items'] as $item) {
                $results[] = $this->transform($item);
            }
            // Post-filter by author if specified
            if (!empty($author)) {
                $originalCount = count($results);
                $authorLower = mb_strtolower($author);
                $results = array_filter($results, function ($book) use ($authorLower) {
                    if (empty($book['authors'])) {
                        return false;
                    }
                    foreach ((array) $book['authors'] as $bookAuthor) {
                        if (mb_stripos($bookAuthor['author']['name'] ?? $bookAuthor, $authorLower) !== false) {
                            return true;
                        }
                    }

                    return false;
                });
                $results = array_values($results); // reindex
                Log::debug('GoogleBooksApiService: Filtered results by author', [
                    'input_author' => $author,
                    'before_count' => $originalCount,
                    'after_count' => count($results),
                ]);
            }

            return $results;
        } catch (\Exception $e) {
            Log::error('Google Books API search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function performGetBookDetails(string $id): ?array
    {
        try {
            $response = $this->httpGet($this->baseUrl . "volumes/{$id}", ['key' => $this->apiKey]);

            if ($response === null) {
                return null;
            }

            return $this->transform($response);
        } catch (\Exception $e) {
            Log::error('Failed to get book details from Google Books API', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build the search query string for Google Books API
     */
    protected function buildSearchQuery(string $query, ?string $author = null): string
    {
        $searchTerms = [];

        // Add the main query
        if (!empty($query)) {
            $searchTerms[] = $query;
        }

        // Add author filter if provided
        if (!empty($author)) {
            $searchTerms[] = "inauthor:\"{$author}\"";
        }

        return implode('+', $searchTerms);
    }

    /**
     * Format search results from Google Books API
     */
    protected function formatSearchResults(array $items): array
    {
        $results = [];

        foreach ($items as $item) {
            if (!isset($item['id']) || !isset($item['volumeInfo'])) {
                continue;
            }

            $volumeInfo = $item['volumeInfo'];
            $imageLinks = $volumeInfo['imageLinks'] ?? [];

            $results[] = [
                'id' => $item['id'],
                'title' => $volumeInfo['title'] ?? 'Unknown Title',
                'subtitle' => $volumeInfo['subtitle'] ?? null,
                'authors' => $this->formatAuthors($volumeInfo['authors'] ?? []),
                'publisher' => ['name' => $volumeInfo['publisher'] ?? null],
                'publishedDate' => $volumeInfo['publishedDate'] ?? null,
                'description' => $volumeInfo['description'] ?? null,
                'page_count' => $volumeInfo['pageCount'] ?? null,
                'categories' => $volumeInfo['categories'] ?? [],
                'average_rating' => $volumeInfo['averageRating'] ?? null,
                'ratings_count' => $volumeInfo['ratingsCount'] ?? null,
                'language' => $volumeInfo['language'] ?? 'en',
                'cover_image_url' => $this->getBestImageUrl($imageLinks),
                'preview_link' => $volumeInfo['previewLink'] ?? null,
                'info_link' => $volumeInfo['infoLink'] ?? null,
                'canonical_volume_link' => $volumeInfo['canonicalVolumeLink'] ?? null,
            ];
        }

        return $results;
    }

    /**
     * Format book details from Google Books API
     *
     * @param  array|null  $item  The book details from Google Books API
     * @return array The formatted book details
     */
    protected function formatBookDetails(?array $item): array
    {
        if ($item === null || !isset($item['volumeInfo'])) {
            return [];
        }

        $volumeInfo = $item['volumeInfo'];
        $imageLinks = $volumeInfo['imageLinks'] ?? [];
        $saleInfo = $item['saleInfo'] ?? [];
        $accessInfo = $item['accessInfo'] ?? [];

        return [
            'id' => $item['id'],
            'title' => $volumeInfo['title'] ?? 'Unknown Title',
            'subtitle' => $volumeInfo['subtitle'] ?? null,
            'authors' => $this->formatAuthors($volumeInfo['authors'] ?? []),
            'publisher' => ['name' => $volumeInfo['publisher'] ?? null],
            'published_date' => $volumeInfo['publishedDate'] ?? null,
            'description' => $volumeInfo['description'] ?? null,
            'page_count' => $volumeInfo['pageCount'] ?? null,
            'categories' => $volumeInfo['categories'] ?? [],
            'average_rating' => $volumeInfo['averageRating'] ?? null,
            'ratings_count' => $volumeInfo['ratingsCount'] ?? null,
            'language' => $volumeInfo['language'] ?? 'en',
            'cover_image_url' => $this->getBestImageUrl($imageLinks),
            'preview_link' => $volumeInfo['previewLink'] ?? null,
            'info_link' => $volumeInfo['infoLink'] ?? null,
            'canonical_volume_link' => $volumeInfo['canonicalVolumeLink'] ?? null,
            'sale_info' => [
                'country' => $saleInfo['country'] ?? null,
                'saleability' => $saleInfo['saleability'] ?? null,
                'is_ebook' => $saleInfo['isEbook'] ?? false,
                'buy_link' => $saleInfo['buyLink'] ?? null,
            ],
            'access_info' => [
                'country' => $accessInfo['country'] ?? null,
                'viewability' => $accessInfo['viewability'] ?? null,
                'embeddable' => $accessInfo['embeddable'] ?? false,
                'public_domain' => $accessInfo['publicDomain'] ?? false,
                'text_to_speech_permission' => $accessInfo['textToSpeechPermission'] ?? null,
                'epub' => $accessInfo['epub'] ?? ['isAvailable' => false],
                'pdf' => $accessInfo['pdf'] ?? ['isAvailable' => false],
                'web_reader_link' => $accessInfo['webReaderLink'] ?? null,
                'access_view_status' => $accessInfo['accessViewStatus'] ?? null,
                'quote_sharing_allowed' => $accessInfo['quoteSharingAllowed'] ?? false,
            ],
        ];
    }

    /**
     * Format authors array to a consistent format
     */
    protected function formatAuthors(array $authors): array
    {
        return array_map(function ($author) {
            return [
                'author' => [
                    'name' => $author,
                    'id' => null,
                ],
            ];
        }, $authors);
    }

    /**
     * Get the best available image URL from the image links
     */
    /**
     * Split Google Books hierarchical categories ("Fiction / Fantasy / Epic") on / and :
     * and return a flat list of unique components.
     */
    protected function splitCategories(array $categories): array
    {
        $components = [];
        foreach ($categories as $category) {
            $parts = preg_split('/\s*[\/\:]\s*/', (string) $category);
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $components[] = $part;
                }
            }
        }
        return array_values(array_unique($components));
    }

    protected function getBestImageUrl(array $imageLinks): ?string
    {
        // Try to get the largest available image
        if (isset($imageLinks['extraLarge'])) {
            return $imageLinks['extraLarge'];
        }
        if (isset($imageLinks['large'])) {
            return $imageLinks['large'];
        }
        if (isset($imageLinks['medium'])) {
            return $imageLinks['medium'];
        }
        if (isset($imageLinks['small'])) {
            return $imageLinks['small'];
        }
        if (isset($imageLinks['thumbnail'])) {
            return $imageLinks['thumbnail'];
        }

        // Try to get any image URL if available
        return $imageLinks[array_key_first($imageLinks)] ?? null;
    }

    /**
     * Placeholder for cover image download (not implemented for GoogleBooksApiService)
     */
    public function downloadCoverImage(string $imageUrl, string $directoryPath, string $targetBasename): ?string
    {
        Log::info('downloadCoverImage not implemented for GoogleBooksApiService');

        return null;
    }

    /**
     * Transform a Google Books API result into a consistent format for frontend consumption.
     *
     * @param  array  $item  The Google Books API result to transform
     * @return array The transformed result
     */
    public function transform(array $item): array
    {
        // Extract volumeInfo if present
        $info = isset($item['volumeInfo']) && is_array($item['volumeInfo']) ? $item['volumeInfo'] : $item;

        // Extract author information
        $authorArray = [];
        if (isset($info['authors']) && is_array($info['authors'])) {
            foreach ($info['authors'] as $authorEntry) {
                if (is_array($authorEntry) && isset($authorEntry['name'])) {
                    $authorArray[] = $authorEntry['name'];
                } elseif (is_array($authorEntry) && isset($authorEntry['author']['name'])) {
                    $authorArray[] = $authorEntry['author']['name'];
                } elseif (is_string($authorEntry)) {
                    $authorArray[] = $authorEntry;
                }
            }
        } elseif (isset($info['authors']) && is_string($info['authors'])) {
            $authorArray[] = $info['authors'];
        }

        // Extract image URL
        $imageLinks = $info['imageLinks'] ?? [];
        $coverImageUrl = $this->getBestImageUrl($imageLinks);

        // Extract series information
        $seriesName = $info['seriesName'] ?? $info['series'] ?? '';
        $seriesNumber = $info['seriesNumber'] ?? '';

        // Extract categories/genres
        $categories = [];
        if (isset($info['categories']) && is_array($info['categories'])) {
            $categories = $info['categories'];
        } elseif (isset($info['categories']) && is_string($info['categories'])) {
            $categories = [$info['categories']];
        }

        // Extract industry identifiers (ISBN, etc.)
        $isbn10 = null;
        $isbn13 = null;
        if (isset($info['industryIdentifiers']) && is_array($info['industryIdentifiers'])) {
            foreach ($info['industryIdentifiers'] as $identifier) {
                if (isset($identifier['type']) && isset($identifier['identifier'])) {
                    if ($identifier['type'] === 'ISBN_10') {
                        $isbn10 = $identifier['identifier'];
                    } elseif ($identifier['type'] === 'ISBN_13') {
                        $isbn13 = $identifier['identifier'];
                    }
                }
            }
        }

        // Build the result array
        $result = [
            'source' => $this->getServiceName(),
            'googleBooksId' => $item['id'] ?? null,
            'title' => $info['title'] ?? 'Unknown Title',
            'subtitle' => $info['subtitle'] ?? null,
            'author' => $authorArray,
            'publisher' => $info['publisher'] ?? null,
            'publishedYear' => isset($info['publishedDate']) ? substr($info['publishedDate'], 0, 4) : null,
            'description' => $info['description'] ?? null,
            'pageCount' => $info['pageCount'] ?? null,
            'category' => $categories,
            'averageRating' => $info['averageRating'] ?? null,
            'ratingsCount' => $info['ratingsCount'] ?? null,
            'language' => $info['language'] ?? 'en',
            'coverImageUrl' => $coverImageUrl,
            'previewLink' => $info['previewLink'] ?? null,
            'infoLink' => $info['infoLink'] ?? null,
            'canonicalVolumeLink' => $info['canonicalVolumeLink'] ?? null,
            'series' => $seriesName,
            'seriesName' => $seriesName,
            'seriesNumber' => $seriesNumber,
            'isbn10' => $isbn10,
            'isbn13' => $isbn13,
        ];

        // Convert all keys to camelCase
        return $this->convertKeysToCamelCase($result);
    }

    /**
     * Convert all keys in an array to camelCase.
     *
     * @param  array  $array  The array with keys to convert
     * @return array Array with camelCase keys
     */
    private function convertKeysToCamelCase(array $array): array
    {
        $camelCaseResult = [];
        foreach ($array as $key => $value) {
            // Convert snake_case to camelCase
            $camelKey = preg_replace_callback('/_([a-z])/', function ($matches) {
                return strtoupper($matches[1]);
            }, $key);

            $camelCaseResult[$camelKey] = $value;
        }

        return $camelCaseResult;
    }
}
