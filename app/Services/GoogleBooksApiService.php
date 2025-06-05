<?php

namespace App\Services;

use App\Contracts\BookServiceInterface;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleBooksApiService extends BaseBookService implements BookServiceInterface
{
    /**
     * Attempt to look up the book in Google Books and return additional metadata.
     *
     * @param array $book
     * @return array|null
     */
    public function searchAndMerge(array $book): ?array
    {
        $inputTitle = trim($book['title'] ?? '');
        $inputAuthor = trim(($book['authors'][0]['author']['name'] ?? '') ?: ($book['author'] ?? ''));
        if (!$inputTitle) {
            return null;
        }
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
            if (!empty($result['title']) && stripos($result['title'], $inputTitle) !== false) {
                $score += 3;
            } elseif (!empty($result['title']) && similar_text(strtolower($result['title']), strtolower($inputTitle), $pct) && $pct > 80) {
                $score += 2;
            }
            if (!empty($inputAuthor) && !empty($result['authors'])) {
                foreach ($result['authors'] as $authorObj) {
                    $authorName = is_array($authorObj['author'] ?? null) ? $authorObj['author']['name'] ?? '' : ($authorObj['author'] ?? '');
                    if ($authorName && stripos($authorName, $inputAuthor) !== false) {
                        $score += 2;
                        break;
                    }
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $result;
            }
        }
        if (!$bestMatch) {
            return null;
        }
        $details = null;
        if (!empty($bestMatch['id'])) {
            $details = $this->performGetBookDetails($bestMatch['id']);
        }
        $source = $details ?: $bestMatch;
        $merged = [
            'googlebooks_id' => $source['id'] ?? null,
            'title' => $source['title'] ?? null,
            'subtitle' => $source['subtitle'] ?? null,
            'description' => $source['description'] ?? null,
            'cover_image' => $source['cover_image_url'] ?? null,
            'authors' => $source['authors'] ?? null,
            'publisher' => $source['publisher']['name'] ?? $source['publisher_name'] ?? null,
            'release_date' => $source['published_date'] ?? $source['release_date'] ?? null,
            'categories' => $source['categories'] ?? null,
            'page_count' => $source['page_count'] ?? null,
            'isbn_10' => $source['isbn_10'] ?? null,
            'isbn_13' => $source['isbn_13'] ?? null,
            'language' => $source['language'] ?? null,
            'preview_link' => $source['preview_link'] ?? null,
            'info_link' => $source['info_link'] ?? null,
        ];
        // Download cover image if present and directory_path is available
        if (!empty($merged['cover_image']) && !empty($book['directory_path'])) {
            $coverUrl = $merged['cover_image'];
            $directory = rtrim($book['directory_path'], '/');
            $ext = pathinfo(parse_url($coverUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
            $localFilename = $directory . '/cover.' . $ext;
            try {
                // Use Laravel's Http client if available, else fallback to file_get_contents
                if (class_exists('Illuminate\\Support\\Facades\\Http')) {
                    $response = \Illuminate\Support\Facades\Http::withOptions(['verify' => false])->get($coverUrl);
                    if ($response->successful()) {
                        file_put_contents($localFilename, $response->body());
                        $merged['cover_image'] = $localFilename;
                    }
                } else {
                    $imageData = @file_get_contents($coverUrl);
                    if ($imageData !== false) {
                        file_put_contents($localFilename, $imageData);
                        $merged['cover_image'] = $localFilename;
                    }
                }
            } catch (\Exception $e) {
                // Log error and fallback to original URL
                if (class_exists('Illuminate\\Support\\Facades\\Log')) {
                    \Illuminate\Support\Facades\Log::warning('Failed to download cover image', ['url' => $coverUrl, 'error' => $e->getMessage()]);
                }
                // leave cover_image as original URL
            }
        }
        $apiFields = [];
        $needsReview = false;
        foreach ($merged as $field => $newValue) {
            if (array_key_exists($field, $book) && $book[$field] !== null && $newValue !== null && $book[$field] != $newValue) {
                $apiFields[$field] = $newValue;
                $needsReview = true;
                $merged[$field] = $book[$field];
            }
        }
        if ($needsReview) {
            $merged['googlebooks_fields'] = $apiFields;
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
    protected string $baseUrl = 'https://www.googleapis.com/books/v1';
    protected int $defaultLimit = 5;
    protected int $cacheTtl = 129600; // 3 months in minutes

    public function __construct()
    {
        parent::__construct();

        $this->client = new Client([
            'base_uri' => $this->baseUrl . '/',
            'timeout' => 10.0,
        ]);

        $this->apiKey = config('services.googlebooks.key');
    }

    /**
     * @inheritDoc
     */
    public function getServiceName(): string
    {
        return 'google_books';
    }

    /**
     * @inheritDoc
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
            $response = $this->httpGet('volumes', $queryParams);

            if (empty($response['items'])) {
                Log::warning('No results found in Google Books API response', [
                    'query' => $query,
                    'options' => $options
                ]);
                return [];
            }

            return $this->formatSearchResults($response['items']);
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
     * @inheritDoc
     */
    protected function performGetBookDetails(string $id): ?array
    {
        try {
            $response = $this->httpGet("volumes/{$id}", ['key' => $this->apiKey]);
            return $this->formatBookDetails($response);
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
            ];
        }

        return $results;
    }

    /**
     * Format book details from Google Books API
     */
    protected function formatBookDetails(array $item): array
    {
        if (!isset($item['volumeInfo'])) {
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
                ]
            ];
        }, $authors);
    }

    /**
     * Get the best available image URL from the image links
     */
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
}
