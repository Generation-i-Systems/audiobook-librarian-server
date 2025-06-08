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
        error_log("searchAndMerge: ");
        $merged = [
            'audible_id' => $source['id'] ?? $source['asin'] ?? null,
            'title' => $source['title'] ?? null,
            'subtitle' => $source['subtitle'] ?? null,
            'description' => $source['description'] ?? $source['publisher_summary'] ?? null,
            'cover_image' => $this->getBestImageUrl($source['product_images'] ?? []) ?? ($source['cover_image_url'] ?? ($source['images']['cover500']['url'] ?? $source['images']['cover']['url'] ?? $source['image_url'] ?? null)),
            'authors' => $source['authors'] ?? null,
            'narrators' => $source['narrators'] ?? null,
            'publisher' => $source['publisher']['name'] ?? $source['publisher_name'] ?? null,
            'release_date' => $source['published_date'] ?? $source['release_date'] ?? null,
            'series' => is_array($source['series'] ?? null)
                ? ($source['series'][0]['title'] ?? (is_string($source['series'][0] ?? null) ? $source['series'][0] : null))
                : ($source['series'] ?? null),
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
        return array_filter($merged, function ($v, $k) {
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
        Log::debug('AudibleService performSearch', [
            'query' => $query,
            'options' => $options,
        ]);
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
            return null;
            Log::error('Audible API search returned no results', [
                'params' => $params,
                'response' => $response,
            ]);
            return null;
        }

        $products = $response['products'];
        $noCache = !empty($options['no_cache']);
        error_log('AudibleService: search API products: ' . json_encode($products));
        $enriched = [];
        foreach ($products as $product) {
            $asin = $product['asin'] ?? $product['id'] ?? null;
            if ($asin) {
                $details = $this->getBookDetails($asin, $options);
                error_log("Details: " . json_encode($details));
                if ($details && is_array($details) && count($details) > 0) {
                    // Merge the enriched fields into the search product
                    $product['description'] = $details['description'] ?? $product['description'] ?? null;
                    $product['series'] = $details['series'] ?? $product['series'] ?? null;
                    // Always set cover_image_url to the largest image from product_images if present
                    if (!empty($details['product_images']) && is_array($details['product_images'])) {
                        error_log("performSearch product_images: " . json_encode($details['product_images']));
                        $product['cover_image_url'] = $this->getBestImageUrl($details['product_images']);
                    } elseif (!empty($product['product_images']) && is_array($product['product_images'])) {
                        // Fallback to product_images from the search product
                        $product['cover_image_url'] = $this->getBestImageUrl($product['product_images']);
                    } else {
                        $product['cover_image_url'] = $details['cover_image_url'] ?? $product['cover_image_url'] ?? null;
                    }
                } else {
                    // No details found; fallback to product_images from the search product
                    if (!empty($product['product_images']) && is_array($product['product_images'])) {
                        $product['cover_image_url'] = $this->getBestImageUrl($product['product_images']);
                    } else {
                        // Always set cover_image_url from getBestImageUrl(product_images)
                        $bestImageUrl = $this->getBestImageUrl(isset($product['product_images']) ? $product['product_images'] : []);
                        error_log('Best image URL extracted: ' . $bestImageUrl);
                        $product['cover_image_url'] = $bestImageUrl;
                    }
                    error_log('AudibleService: about to push product, cover_image_url=' . ($product['cover_image_url'] ?? 'NULL'));
                    $enriched[] = $product;
                }
            }
        }
        error_log('AudibleService: enriched products: ' . json_encode($enriched));
        return $this->formatSearchResults($enriched);
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

        Log::debug('AudibleService performGetBookDetails', [
            'response' => print_r($response, true),
        ]);

        $book = $response['product'] ?? null;
        if (!$book) {
            Log::error('Audible API returned no book data', [
                'id' => $id,
                'response' => print_r($response, true),
            ]);
            return null;
        }

        // Parse description and strip <p> tags
        // Prefer product_images (largest available)
        $coverImage = null;
        if (!empty($book['product_images']) && is_array($book['product_images'])) {
            $coverImage = $this->getBestImageUrl($book['product_images']);
        }
        if (!$coverImage) {
            $coverImage = $book['images']['cover500']['url'] ?? $book['images']['cover']['url'] ?? $book['image_url'] ?? null;
        }

        // Parse series and series number (prefer sequence)
        $series = null;
        $seriesNumber = null;
        if (isset($book['series']) && is_array($book['series']) && count($book['series']) > 0) {
            $seriesEntry = $book['series'][0];
            if (is_array($seriesEntry)) {
                $series = $seriesEntry['title'] ?? null;
                $seriesNumber = $seriesEntry['sequence'] ?? null;
                if ($seriesNumber === null && isset($seriesEntry['number'])) {
                    $seriesNumber = $seriesEntry['number'];
                }
                if ($seriesNumber === null) {
                    $seriesNumber = 1;
                }
            } elseif (is_string($seriesEntry)) {
                $series = $seriesEntry;
                $seriesNumber = 1;
            }
        }

        // Format authors
        $authors = [];
        if (!empty($book['authors'])) {
            foreach ($book['authors'] as $author) {
                if (is_string($author)) {
                    $authors[] = ['author' => ['name' => $author, 'id' => null]];
                } elseif (is_array($author) && !empty($author['name'])) {
                    $authors[] = ['author' => ['name' => $author['name'], 'id' => $author['id'] ?? null]];
                }
            }
        }
        // Format narrators
        $narrators = [];
        if (!empty($book['narrators'])) {
            foreach ($book['narrators'] as $narrator) {
                if (is_string($narrator)) {
                    $narrators[] = ['author' => ['name' => $narrator, 'id' => null]];
                } elseif (is_array($narrator) && !empty($narrator['name'])) {
                    $narrators[] = ['author' => ['name' => $narrator['name'], 'id' => $narrator['id'] ?? null]];
                }
            }
        }
        // Ensure cover image is a string
        $coverImage = $coverImage ?: '';
        return [
            'product_images' => $book['product_images'] ?? [],
            'id' => $book['asin'] ?? $book['id'] ?? null,
            'title' => $book['title'] ?? null,
            'subtitle' => $book['subtitle'] ?? null,
            'authors' => $authors,
            'narrators' => $narrators,
            'publisher' => $book['publisher'] ?? null,
            'release_date' => $book['release_date'] ?? null,
            'description' => $description,
            'cover_image_url' => $coverImage ?: '',
            'series' => $series,
            'series_number' => $seriesNumber,
            'categories' => !empty($book['categories']) ? $book['categories'] : ['Fiction'],
            'language' => $book['language'] ?? null,
            'duration' => (isset($book['runtime_length_min']) && is_numeric($book['runtime_length_min']))
                ? ($book['runtime_length_min'] . ':00')
                : ($book['duration'] ?? null),
        ];
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

        // Extract series information and number (prefer sequence)
        $series = null;
        $seriesNumber = null;
        if (!empty($book['series'])) {
            $seriesEntry = $book['series'][0];
            if (is_array($seriesEntry)) {
                $series = $seriesEntry['title'] ?? null;
                $seriesNumber = $seriesEntry['sequence'] ?? null;
                if ($seriesNumber === null && isset($seriesEntry['number'])) {
                    $seriesNumber = $seriesEntry['number'];
                }
                if ($seriesNumber === null) {
                    $seriesNumber = 1;
                }
            } elseif (is_string($seriesEntry)) {
                $series = $seriesEntry;
                $seriesNumber = 1;
            }
        }

        error_log("formatBookDetails: ");
        return [
            'product_images' => $book['product_images'] ?? [],
            'id' => $book['asin'] ?? $book['id'] ?? null,
            'title' => $book['title'] ?? null,
            'subtitle' => $book['subtitle'] ?? null,
            'authors' => $authors,
            'narrators' => $narrators,
            'publisher' => $book['publisher'] ?? null,
            'release_date' => $book['release_date'] ?? null,
            // Strip <p> tags from description if present
            'description' => (isset($book['description']) && preg_match('/^<p>.*<\/p>$/i', trim($book['description'])))
                ? preg_replace('/^<p>(.*?)<\/p>$/is', '$1', trim($book['description']))
                : ($book['description'] ?? null),
            // Prefer product_images for cover image
            'cover_image_url' => $this->getBestImageUrl($book['product_images']) ?? ($book['cover_image_url'] ?? ($book['images']['cover500']['url'] ?? $book['images']['cover']['url'] ?? $book['image_url'] ?? null)),
            'series' => $series,
            'language' => $book['language'] ?? null,
            'duration' => $book['runtime_length_min'] ?? $book['duration'] ?? null,
            'sample_url' => $book['sample_url'] ?? null,
            'url' => $book['product_url'] ?? null
        ];
    }

    /**
     * Format search results into a consistent format
     */
    protected function formatSearchResults(array $products): array
    {
        error_log('AudibleService: formatSearchResults incoming products: ' . json_encode($products));
        if (empty($products)) {
            return [];
        }
        $results = [];

        // make the dump pretty
        Log::debug('AudibleService formatSearchResults', [
            'products' => print_r($products, true),
        ]);

        foreach ($products as $product) {
            // Format authors
            $authors = [];
            if (!empty($product['authors'])) {
                foreach ($product['authors'] as $author) {
                    if (is_string($author)) {
                        $authors[] = [
                            'author' => [
                                'name' => $author,
                                'id' => null,
                            ],
                        ];
                    } elseif (is_array($author) && !empty($author['name'])) {
                        $authors[] = [
                            'author' => [
                                'name' => $author['name'],
                                'id' => $author['id'] ?? null
                            ],
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
                                'id' => null,
                            ],
                        ];
                    } elseif (is_array($narrator) && !empty($narrator['name'])) {
                        $narrators[] = [
                            'author' => [
                                'name' => $narrator['name'],
                                'id' => $narrator['id'] ?? null
                            ],
                        ];
                    }
                }
            }

            // Extract series information and number (prefer sequence)
            $series = null;
            $seriesNumber = null;
            if (!empty($product['series'])) {
                $seriesEntry = $product['series'][0];
                if (is_array($seriesEntry)) {
                    $series = $seriesEntry['title'] ?? null;
                    $seriesNumber = $seriesEntry['sequence'] ?? null;
                    if ($seriesNumber === null && isset($seriesEntry['number'])) {
                        $seriesNumber = $seriesEntry['number'];
                    }
                    if ($seriesNumber === null) {
                        $seriesNumber = 1;
                    }
                } elseif (is_string($seriesEntry)) {
                    $series = $seriesEntry;
                    $seriesNumber = 1;
                }
            }

            error_log("formatSearchResults product_images: " . json_encode($product['product_images']));
            $coverImage = $this->getBestImageUrl($product['product_images'] ?? []);
            if (!$coverImage) {
                $coverImage = $product['cover_image_url']
                    ?? ($product['images']['cover500']['url'] ?? $product['images']['cover']['url'] ?? $product['image_url'] ?? '');
            }

            // Robust series extraction for TestAudibleSearch
            $series = null;
            // Audible API search results may not have a 'series' key, but may have publication_name and a number in title
            if (!empty($product['publication_name'])) {
                // Try to extract the part/number from the title
                $part = null;
                if (preg_match('/\b(\d{1,3})\b/', $product['title'] ?? '', $matches)) {
                    $part = (int) $matches[1];
                }
                $series = [
                    [
                        'name' => $product['publication_name'],
                        'part' => $part,
                    ],
                ];
            } elseif (!empty($product['series'])) {
                $seriesEntry = $product['series'][0];
                if (is_array($seriesEntry) && !empty($seriesEntry['title'])) {
                    $series = [
                        [
                            'name' => $seriesEntry['title'],
                            'part' => $seriesEntry['sequence'] ?? ($seriesEntry['number'] ?? null)
                        ],
                    ];
                } elseif (is_string($seriesEntry)) {
                    $series = [$seriesEntry];
                } else {
                    $series = $product['series'];
                }
            }
            // error_log("detailsProductImages: " . json_encode($product['product_images'], JSON_PRETTY_PRINT));
            // $coverImage = $this->getBestImageUrl($product['product_images'] ?? [])
            //     ?? ($product['cover_image_url'] ?? ($coverImage ?: ''));
            // error_log("coverImage: " . $coverImage);

            // Only include products that have at least a title and a cover image URL
        }
        // error_log("detailsProductImages: " . json_encode($product['product_images'], JSON_PRETTY_PRINT));
        // $coverImage = $this->getBestImageUrl($product['product_images'] ?? [])
        //     ?? ($product['cover_image_url'] ?? ($coverImage ?: ''));
        // error_log("coverImage: " . $coverImage);

        // Only include products that have at least a title and a cover image URL
        $coverImageUrl = $this->getBestImageUrl($product['product_images'] ?? [])
            ?? ($product['cover_image_url'] ?? ($coverImage ?: ''));
        $title = $product['title'] ?? '';
        error_log('AudibleService: formatSearchResults candidate: ' . json_encode($product) . ' coverImageUrl=' . $coverImageUrl . ' title=' . $title);
        if (empty($title)) {
            $title = $product['id'] ?? $product['asin'] ?? '[NO TITLE]';
            error_log('AudibleService: formatSearchResults fallback title used: ' . $title);
        }
        if (!empty($title) && !empty($coverImageUrl)) {
            error_log('AudibleService: formatSearchResults adding result: ' . json_encode($product) . ' coverImageUrl=' . $coverImageUrl . ' title=' . $title);
            $results[] = [
                'id' => $product['id'] ?? ($product['asin'] ?? null),
                'title' => $title,
                'authors' => $authors,
                'narrators' => $narrators,
                'series' => $series,
                'series_number' => $seriesNumber,
                'language' => $product['language'] ?? null,
                'duration' => isset($product['runtime_length_min']) && is_numeric($product['runtime_length_min'])
                    ? ($product['runtime_length_min'] . ':00')
                    : null,
                'publisher' => $product['publisher'] ?? null,
                'release_date' => $product['release_date'] ?? null,
                'description' => (isset($product['description']) && preg_match('/^<p>.*<\/p>$/i', trim($product['description'])))
                    ? preg_replace('/^<p>(.*?)<\/p>$/is', '$1', trim($product['description']))
                    : ($product['description'] ?? null),
                'cover_image_url' => $coverImageUrl,
            ];
        }
    }

    /**
     * Get the best available image URL from the product images
     */
    protected function getBestImageUrl($productImages)
    {
        if (empty($productImages) || !is_array($productImages)) {
            return null;
        }
        error_log("getBestImageUrl: " . json_encode($productImages));
        if (empty($productImages)) {
            return null;
        }
        // Prefer the largest available image
        $sizes = [
            '2000x2000',
            '2000',
            '1600x1600',
            '1600',
            '1200x1200',
            '1200',
            '800x800',
            '800',
            '500x500',
            '500',
            '400x400',
            '400',
            '300x300',
            '300',
            '200x200',
            '200',
            '100x100',
            '100',
        ];
        foreach ($sizes as $size) {
            if (!empty($productImages[$size])) {
                error_log("getBestImageUrl: Found size $size");
                return $this->ensureHttps($productImages[$size]);
            }
        }
        // If no specific size found, return the first available image
        return $this->ensureHttps(reset($productImages));
    }

    /**
     * Ensure URL uses HTTPS
     */
    protected function ensureHttps(string $url): string
    {
        error_log("ensureHttps: $url");
        if (strpos($url, 'http://') === 0) {
            return 'https://' . substr($url, 7);
        }
        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }
        return $url;
    }

    /**
     * Placeholder for cover image download (not implemented for AudibleService)
     */
    public function downloadCoverImage(string $imageUrl, string $directoryPath, string $targetBasename): ?string
    {
        Log::info('downloadCoverImage not implemented for AudibleService');
        return null;
    }
}
