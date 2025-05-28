<?php

namespace App\Services;

use App\Contracts\BookServiceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class AudibleService extends BaseBookService
{
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

        foreach ($ladders as $ladder) {
            if (is_array($ladder) && !empty($ladder['root'])) {
                $genres[] = $ladder['root'];
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
