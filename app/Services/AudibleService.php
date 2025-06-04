<?php

namespace App\Services;

use App\Contracts\BookServiceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class AudibleService extends BaseBookService
{
    /**
     * Attempt to look up the book in Audible and return additional metadata.
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
        // Use detail structure if available, fallback to search result
        $source = $details ?: $bestMatch;
        $merged = [
            'audible_id' => $source['id'] ?? $source['asin'] ?? null,
            'title' => $source['title'] ?? null,
            'subtitle' => $source['subtitle'] ?? null,
            'description' => $source['description'] ?? $source['publisher_summary'] ?? null,
            'cover_image' => $source['cover_image_url'] ?? ($source['product_images']['large'] ?? null),
            'authors' => $source['authors'] ?? null,
            'narrators' => $source['narrators'] ?? null,
            'publisher' => $source['publisher']['name'] ?? $source['publisher_name'] ?? null,
            'release_date' => $source['published_date'] ?? $source['release_date'] ?? null,
            'series' => $source['series'] ?? null,
            'categories' => $source['categories'] ?? null,
            'duration' => $source['duration'] ?? ($source['runtime_length_min'] ? ($source['runtime_length_min'] . ':00') : null),
            'rating' => $source['rating'] ?? null,
            'ratings_count' => $source['ratings_count'] ?? null,
            'sample_url' => $source['sample_url'] ?? null,
            'url' => $source['url'] ?? $source['product_url'] ?? null,
            'language' => $source['language'] ?? null,
        ];
        // Download cover image if present and directory_path is available
        if (!empty($merged['cover_image']) && !empty($book['directory_path'])) {
            $coverUrl = $merged['cover_image'];
            $directory = rtrim($book['directory_path'], '/');
            $ext = pathinfo(parse_url($coverUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
            $localFilename = $directory . '/cover.' . $ext;
            try {
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
                if (class_exists('Illuminate\\Support\\Facades\\Log')) {
                    \Illuminate\Support\Facades\Log::warning('Failed to download cover image', ['url' => $coverUrl, 'error' => $e->getMessage()]);
                }
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
            $merged['audible_fields'] = $apiFields;
            $merged['needsReview'] = true;
        }
        // Remove nulls and skip ISBN/pages if not present
        return array_filter($merged, function($v, $k) {
            if (in_array($k, ['isbn_10', 'isbn_13', 'pages']) && $v === null) {
                return false;
            }
            return $v !== null;
        }, ARRAY_FILTER_USE_BOTH);
    }

    protected string $baseUrl = 'https://api.audible.com/1.0/catalog';
    protected string $imageBaseUrl = 'https://m.media-amazon.com/images/I/';

    /**
     * @var array Default parameters for API requests
     */
    protected array $defaultParams = [
        'response_groups' => 'contributors,product_desc,product_attrs',
        'num_results' => 10,
    ];

    /**
     * @var array Default headers for API requests
     */
    protected array $defaultHeaders = [
        'Accept' => 'application/json',
    ];

    /**
     * Get the service name
     *
     * @return string
     */
    public function getServiceName(): string
    {
        return 'audible';
    }

    /**
     * Check if the service is available
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        // For now, just return true. In a real app, you might check API keys or other requirements
        return true;
    }

    /**
     * @inheritDoc
     */
    protected function performSearch(string $query, array $options = []): ?array
    {
        $author = $options['author'] ?? null;
        $limit = $options['limit'] ?? 5;

        $params = array_merge($this->defaultParams, [
            'num_results' => $limit,
            'products_sort_by' => 'Relevance',
            'title' => $query,
        ]);

        if ($author) {
            $params['author'] = $author;
        }

        // First try with title search
        $response = $this->httpGet("{$this->baseUrl}/products", $params);

        // If no results, try with keywords
        if (empty($response['products']) && !empty($query)) {
            unset($params['title']);
            $params['keywords'] = $query;
            $response = $this->httpGet("{$this->baseUrl}/products", $params);
        }

        if (empty($response['products'])) {
            Log::error('Audible API search returned no results', [
                'params' => $params,
                'response' => $response,
            ]);
            return null;
        }

        return $this->formatSearchResults($response['products']);
    }

    /**
     * @inheritDoc
     */
    protected function performGetBookDetails(string $id): ?array
    {
        $response = $this->httpGet(
            "{$this->baseUrl}/products/{$id}",
            ['response_groups' => 'product_desc,contributors,product_attrs,media,reviews,rating,series']
        );

        $book = $response['product'] ?? null;
        if (!$book) {
            Log::error('Audible API returned no book data', [
                'response' => $response,
            ]);
            return null;
        }

        return $this->formatBookDetails($book);
    }

    /**
     * Format search results from Audible API
     */
    protected function formatSearchResults(array $products): array
    {
        $results = [];

        foreach ($products as $product) {
            // Format authors
            $authors = [];
            if (!empty($product['authors'])) {
                foreach ($product['authors'] as $author) {
                    if (is_string($author)) {
                        $authors[] = [
                            'author' => [
                                'name' => $author,
                                'id' => null
                            ]
                        ];
                    } elseif (is_array($author) && !empty($author['name'])) {
                        $authors[] = [
                            'author' => [
                                'name' => $author['name'],
                                'id' => $author['id'] ?? null
                            ]
                        ];
                    }
                }
            }


            // Format narrators
            $narrators = [];
            if (!empty($product['narrators'])) {
                foreach ($product['narrators'] as $narrator) {
                    if (is_string($narrator)) {
                        $narrators[] = [
                            'author' => [
                                'name' => $narrator,
                                'id' => null
                            ]
                        ];
                    } elseif (is_array($narrator) && !empty($narrator['name'])) {
                        $narrators[] = [
                            'author' => [
                                'name' => $narrator['name'],
                                'id' => $narrator['id'] ?? null
                            ]
                        ];
                    }
                }
            }

            // Build the result array with null coalescing for all fields
            $result = [
                'id' => $product['asin'] ?? null,
                'title' => $product['title'] ?? 'Unknown Title',
                'subtitle' => $product['subtitle'] ?? null,
                'authors' => $authors,
                'narrators' => $narrators,
                'cover_image_url' => $this->getBestImageUrl($product['product_images'] ?? []),
                'release_date' => $product['release_date'] ?? null,
                'publisher' => ['name' => $product['publisher_name'] ?? null],
                'description' => $product['publisher_summary'] ?? null,
                'language' => $product['language'] ?? 'english',
            ];

            // Handle duration if available
            if (isset($product['runtime_length_min'])) {
                $result['duration'] = $product['runtime_length_min'] . ':00';
            } elseif (isset($product['runtime_length_min_max']) && is_numeric($product['runtime_length_min_max'])) {
                $result['duration'] = $product['runtime_length_min_max'] . ':00';
            } else {
                $result['duration'] = null;
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * Format book details into a consistent format
     */
    protected function formatBookDetails(array $book): array
    {
        // Extract authors and narrators
        $authors = [];
        $narrators = [];

        if (!empty($book['authors'])) {
            foreach ($book['authors'] as $author) {
                $authorData = $this->extractPersonData($author);
                if ($authorData) {
                    $authors[] = ['author' => $authorData];
                }
            }
        }

        if (!empty($book['narrators'])) {
            foreach ($book['narrators'] as $narrator) {
                $narratorData = $this->extractPersonData($narrator);
                if ($narratorData) {
                    $narrators[] = ['author' => $narratorData];
                }
            }
        }

        // Extract series information
        $series = [];
        if (!empty($book['series'])) {
            foreach ($book['series'] as $s) {
                if (is_array($s) && !empty($s['title'])) {
                    $series[$s['title']] = $s['sequence'] ?? 1;
                }
            }
        }

        // Format the result
        return [
            'id' => $book['asin'] ?? null,
            'title' => $book['title'] ?? 'Unknown Title',
            'subtitle' => $book['subtitle'] ?? null,
            'authors' => $authors,
            'narrators' => $narrators,
            'publisher' => ['name' => $book['publisher_name'] ?? null],
            'published_date' => $book['release_date'] ?? null,
            'description' => $book['publisher_summary'] ?? null,
            'isbn' => $book['asin'] ?? null, // ASIN is used as ISBN for Audible
            'page_count' => null, // Not typically available from Audible
            'cover_image_url' => $this->getBestImageUrl($book['product_images'] ?? []),
            'language' => strtolower($book['language'] ?? 'english'),
            'series' => $series,
            'categories' => $this->extractGenres($book['category_ladders'] ?? []),
            'duration' => $book['runtime_length_min'] ? ($book['runtime_length_min'] . ':00') : null,
            'rating' => $book['rating'] ?? null,
            'ratings_count' => $book['ratings_count'] ?? null,
            'sample_url' => $book['sample_url'] ?? null,
            'url' => $book['product_url'] ?? null
        ];
    }

    /**
     * Extract person data from different possible structures
     */
    protected function extractPersonData($person): ?array
    {
        if (is_string($person)) {
            return ['name' => $person, 'id' => null];
        }

        if (is_array($person)) {
            return [
                'name' => $person['name'] ?? null,
                'id' => $person['id'] ?? null
            ];
        }

        return null;
    }

    /**
     * Extract genres from category ladders
     */
    protected function extractGenres(array $ladders): array
    {
        $genres = [];
        foreach ($ladders as $ladder_items) { // e.g., [['name' => 'Fiction'], ['name' => 'Sci-Fi']]
            if (is_array($ladder_items)) {
                foreach ($ladder_items as $item) { // e.g., ['name' => 'Fiction']
                    if (is_array($item) && !empty($item['name'])) {
                        $genres[] = $item['name'];
                    }
                }
            }
        }
        return array_unique($genres);
    }

    /**
     * Get the best available image URL from the product images
     */
    protected function getBestImageUrl(array $images): ?string
    {
        if (empty($images)) {
            return null;
        }

        // Prefer the largest available image
        $sizes = [
            '2000x2000', '1600x1600', '1200x1200', '800x800',
            '500x500', '400x400', '300x300', '200x200', '100x100'
        ];

        foreach ($sizes as $size) {
            if (!empty($images[$size])) {
                return $this->ensureHttps($images[$size]);
            }
        }

        // If no specific size found, return the first available image
        return $this->ensureHttps(reset($images));
    }

    /**
     * Ensure URL uses HTTPS
     */
    protected function ensureHttps(string $url): string
    {
        if (strpos($url, 'http://') === 0) {
            return 'https://' . substr($url, 7);
        }

        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }

        return $url;
    }
}
