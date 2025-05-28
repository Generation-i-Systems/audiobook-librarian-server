<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AudibleService
{
    protected string $baseUrl = 'https://api.audible.com/1.0/catalog';
    protected string $imageBaseUrl = 'https://m.media-amazon.com/images/I/';
    protected array $defaultParams = [
        'response_groups' => 'product_desc,contributors,product_attrs,media,reviews,rating',
        'num_results' => 10,
    ];
    protected array $defaultHeaders = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' .
            '(KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Accept' => 'application/json',
        'Accept-Language' => 'en-US,en;q=0.9',
    ];

    // Cache for storing API responses to reduce redundant calls
    protected array $cache = [];

    /**
     * Search for books by title and/or author
     *
     * @param string $query The search query
     * @param string|null $author Optional author filter
     * @param int $limit Maximum number of results to return
     * @return array|null Array of search results or null on failure
     */
    public function searchBooks(string $query, ?string $author = null, int $limit = 5): ?array
    {
        try {
            $cacheKey = 'search_' . md5($query . ($author ?? '') . $limit);
            if (isset($this->cache[$cacheKey])) {
                return $this->cache[$cacheKey];
            }

            $params = array_merge($this->defaultParams, [
                'num_results' => $limit,
                'products_sort_by' => 'Relevance',
                'title' => $query,
            ]);

            if ($author) {
                $params['author'] = $author;
            }

            Log::debug('Audible API search request', [
                'url' => "{$this->baseUrl}/products",
                'params' => $params,
                'headers' => $this->defaultHeaders
            ]);

            // First try with title search
            $response = Http::withHeaders($this->defaultHeaders)
                ->timeout(10)
                ->get("{$this->baseUrl}/products", $params);

            $data = $response->json();
            
            Log::debug('Audible API search response', [
                'status' => $response->status(),
                'response' => $data
            ]);

            // If no results, try with keywords
            if (empty($data['products']) && !empty($query)) {
                Log::debug('No results with title search, trying with keywords');
                
                unset($params['title']);
                $params['keywords'] = $query;
                
                Log::debug('Audible API keywords search request', [
                    'url' => "{$this->baseUrl}/products",
                    'params' => $params
                ]);
                
                $response = Http::withHeaders($this->defaultHeaders)
                    ->timeout(10)
                    ->get("{$this->baseUrl}/products", $params);
                    
                $data = $response->json();
                
                Log::debug('Audible API keywords search response', [
                    'status' => $response->status(),
                    'response' => $data
                ]);
            }

            if (!$response->successful() || empty($data['products'])) {
                $errorMessage = 'Audible API search failed';
                Log::error($errorMessage, [
                    'status' => $response->status(),
                    'params' => $params,
                    'response' => $data ?? $response->body(),
                ]);
                
                // Try a direct search using the ASIN if the query looks like one
                if (preg_match('/^[A-Z0-9]{10}$/', $query)) {
                    Log::debug('Trying direct ASIN lookup', ['asin' => $query]);
                    $book = $this->getBookDetails($query);
                    if ($book) {
                        return [$book];
                    }
                }
                
                return null;
            }

            $results = $this->formatSearchResults($data['products']);
            $this->cache[$cacheKey] = $results;

            return $results;
        } catch (\Exception $e) {
            Log::error('Audible search error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Get book details by ASIN (Audible ID)
     *
     * @param string $asin The Audible ASIN
     * @return array|null Book details or null on failure
     */
    public function getBookDetails(string $asin): ?array
    {
        try {
            if (isset($this->cache["details_$asin"])) {
                return $this->cache["details_$asin"];
            }

            $response = Http::withHeaders($this->defaultHeaders)
                ->timeout(15)
                ->get(
                    "{$this->baseUrl}/products/{$asin}",
                    [
                        'response_groups' => implode(',', [
                            'product_desc',
                            'contributors',
                            'product_attrs',
                            'media',
                            'reviews',
                            'rating',
                            'product_plan_details',
                            'product_extended_attrs',
                            'series',
                        ]),
                    ]
                );

            if (!$response->successful()) {
                // Try with a more basic request if the detailed one fails
                $response = Http::withHeaders($this->defaultHeaders)
                    ->timeout(15)
                    ->get("{$this->baseUrl}/products/{$asin}", [
                        'response_groups' => 'product_desc,contributors,product_attrs',
                    ]);

                if (!$response->successful()) {
                    Log::error('Audible API details failed', [
                        'asin' => $asin,
                        'status' => $response->status(),
                        'response' => $response->body(),
                    ]);
                    return null;
                }
            }

            $data = $response->json();
            $book = $data['product'] ?? null;

            if (!$book) {
                Log::error('Audible API returned invalid book data', [
                    'asin' => $asin,
                    'response' => $data,
                ]);
                return null;
            }

            $formatted = $this->formatBookDetails($book);
            $this->cache["details_$asin"] = $formatted;

            return $formatted;
        } catch (\Exception $e) {
            Log::error('Audible details error', [
                'asin' => $asin,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Format search results from Audible API
     *
     * @param array $products Array of product data from Audible API
     * @return array Formatted search results
     */
    protected function formatSearchResults(array $products): array
    {
        $results = [];

        foreach ($products as $product) {
            if (empty($product['asin'])) {
                continue;
            }

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
     * Extract people (authors, narrators) from API response
     *
     * @param mixed $people People data from API
     * @param string $role Role of the people (author, narrator)
     * @return array Formatted people array
     */
    protected function extractPeople($people, string $role = 'author'): array
    {
        $result = [];
        
        if (empty($people)) {
            return $result;
        }
        
        // If it's already an array of authors/narrators in the correct format
        if (is_array($people) && isset($people[0]['author'])) {
            return array_map(function($item) {
                return [
                    'author' => [
                        'name' => $item['author']['name'] ?? null,
                        'id' => $item['author']['id'] ?? null
                    ]
                ];
            }, $people);
        }
        
        if (!is_array($people)) {
            $people = [$people];
        }
        
        foreach ($people as $person) {
            $data = $this->extractPersonData($person);
            if (!empty($data) && !empty($data['author']['name'])) {
                $result[] = [
                    'author' => [
                        'name' => $data['author']['name'],
                        'id' => $data['author']['id'] ?? null
                    ]
                ];
            }
        }
        
        return $result;
    }

    /**
     * Extract person data from different possible structures
     *
     * @param mixed $person Person data in various formats
     * @return array Formatted author data
     */
    protected function extractPersonData($person): array
    {
        if (is_string($person)) {
            return [
                'author' => [
                    'name' => $person,
                    'id' => null,
                ],
            ];
        }

        if (!is_array($person)) {
            return [];
        }

        // Handle direct name field
        if (!empty($person['name'])) {
            return [
                'author' => [
                    'name' => $person['name'],
                    'id' => $person['asin'] ?? $person['id'] ?? null
                ],
            ];
        }

        // Handle nested author format
        if (!empty($person['author']['name'])) {
            return [
                'author' => [
                    'name' => $person['author']['name'],
                    'id' => $person['author']['id'] ?? $person['author']['asin'] ?? null
                ],
            ];
        }

        // Handle case where person is an array with role information
        if (!empty($person['role'])) {
            return [
                'author' => [
                    'name' => $person['name'] ?? null,
                    'id' => $person['id'] ?? $person['asin'] ?? null
                ],
            ];
        }

        return [];
    }

    /**
     * Extract genres from category ladders
     *
     * @param array $ladders Category ladders from API
     * @return array Formatted genres
     */
    protected function extractGenres(array $ladders): array
    {
        $genres = [];

        if (!is_array($ladders)) {
            return $genres;
        }

        foreach ($ladders as $ladder) {
            if (!is_array($ladder)) {
                continue;
            }

            foreach ($ladder as $category) {
                if (!empty($category['name'])) {
                    $genres[] = [
                        'genre' => [
                            'name' => $category['name'],
                        ],
                    ];
                }
            }
        }

        return $genres;
    }

    /**
     * Extract and categorize contributors (authors and narrators)
     *
     * @param array $contributors Contributors data from API
     * @return array Categorized contributors
     */
    protected function extractContributors(array $contributors): array
    {
        $result = [
            'authors' => [],
            'narrators' => [],
        ];

        if (empty($contributors)) {
            return $result;
        }

        foreach ($contributors as $contributor) {
            if (!is_array($contributor)) {
                continue;
            }

            $name = $contributor['name'] ?? null;
            $role = strtolower($contributor['role'] ?? '');

            if (empty($name)) {
                continue;
            }

            $person = [
                'author' => [
                    'name' => $name,
                    'id' => $contributor['asin'] ?? $contributor['id'] ?? null
                ],
            ];

            if (strpos($role, 'narrat') !== false) {
                $result['narrators'][] = $person;
            } else {
                $result['authors'][] = $person;
            }
        }

        return $result;
    }

    /**
     * Format book details into a consistent format
     *
     * @param array $book Raw book data from API
     * @return array Formatted book data
     */
    protected function formatBookDetails(array $book): array
    {
        try {
            // Extract contributors first as they might have more complete data
            $contributors = $this->extractContributors($book['contributors'] ?? []);

            // Extract authors and narrators
            $authors = $this->extractPeople($book['authors'] ?? [], 'author');
            $narrators = $this->extractPeople($book['narrators'] ?? [], 'narrator');

            // Merge with contributors, giving priority to direct fields
            if (!empty($contributors['authors'])) {
                $authors = array_merge($authors, $contributors['authors']);
            }
            if (!empty($contributors['narrators'])) {
                $narrators = array_merge($narrators, $contributors['narrators']);
            }

            // Remove duplicates by name
            $uniqueAuthors = [];
            foreach ($authors as $author) {
                if (!empty($author['author']['name'])) {
                    $name = strtolower($author['author']['name']);
                    $uniqueAuthors[$name] = $author;
                }
            }
            $authors = array_values($uniqueAuthors);

            $uniqueNarrators = [];
            foreach ($narrators as $narrator) {
                if (!empty($narrator['author']['name'])) {
                    $name = strtolower($narrator['author']['name']);
                    $uniqueNarrators[$name] = $narrator;
                }
            }
            $narrators = array_values($uniqueNarrators);

            // Extract genres
            $genres = $this->extractGenres($book['category_ladders'] ?? []);

            // Get the best available image
            $coverImageUrl = $this->getBestImageUrl($book['product_images'] ?? $book['images'] ?? []);

            // Log if we couldn't find authors/narrators for debugging
            if (empty($authors) || empty($narrators)) {
                Log::debug('Audible book details missing authors/narrators', [
                    'asin' => $book['asin'] ?? null,
                    'authors_found' => !empty($authors),
                    'narrators_found' => !empty($narrators),
                    'contributors' => $book['contributors'] ?? []
                ]);
            }

            // Handle series information
            $series = null;
            $seriesSequence = null;
            if (!empty($book['series']) && is_array($book['series'])) {
                $firstSeries = $book['series'][0] ?? [];
                $series = $firstSeries['title'] ?? null;
                $seriesSequence = $firstSeries['sequence'] ?? null;
            }

            return [
                'id' => $book['asin'] ?? null,
                'title' => $book['title'] ?? 'Unknown Title',
                'subtitle' => $book['subtitle'] ?? null,
                'description' => $book['publisher_summary'] ??
                    $book['merchandising_summary'] ??
                    $book['product_description'] ?? null,
                'publisher' => ['name' => $book['publisher_name'] ?? null],
                'release_date' => $book['release_date'] ?? $book['publication_datetime'] ?? null,
                'cover_image_url' => $coverImageUrl,
                'authors' => $authors,
                'narrators' => $narrators,
                'genres' => $genres,
                'language' => $book['language'] ?? null,
                'duration' => !empty($book['runtime_length_min'])
                    ? $this->formatDuration($book['runtime_length_min'])
                    : null,
                'rating' => [
                    'average_rating' => $book['rating'] ?? ($book['average_rating'] ?? null),
                    'ratings_count' => $book['ratings_count'] ?? ($book['review_count'] ?? null),
                ],
                'details' => [
                    'format' => 'Audiobook',
                    'series' => $series,
                    'series_sequence' => $seriesSequence,
                    'publisher_summary' => $book['publisher_summary'] ?? null,
                    'whats_included' => $book['whats_included'] ?? null,
                    'about_author' => $book['about_author'] ?? null,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Error formatting Audible book details', [
                'error' => $e->getMessage(),
                'book_data' => array_keys($book),
            ]);

            // Return a minimal book object with the error
            return [
                'id' => $book['asin'] ?? null,
                'title' => $book['title'] ?? 'Unknown Title',
                'error' => 'Error processing book details: ' . $e->getMessage(),
                'authors' => [],
                'narrators' => [],
                'genres' => [],
            ];
        }
    }

    /**
     * Get the best available image URL from the product images
     *
     * @param array $images Array of image URLs with different formats/sizes
     * @return string|null Best available image URL or null if none found
     */
    protected function getBestImageUrl(array $images): ?string
    {
        if (empty($images)) {
            return null;
        }

        $preferredFormats = [
            '2000x2000',
            '1600x1600',
            '1200x1200',
            '800x800',
            '600x600',
            '500x500',
            '400x400',
            '300x300',
            '200x200',
            '100x100',
            '500',
            '400',
            '300',
            '200',
            '100',
            'large',
            'medium',
            'small',
        ];

        foreach ($preferredFormats as $size) {
            if (isset($images[$size])) {
                return $this->ensureHttps($images[$size]);
            }
            if (isset($images["_$size"])) {
                return $this->ensureHttps($images["_$size"]);
            }
            if (isset($images["_{$size}x{$size}"])) {
                return $this->ensureHttps($images["_{$size}x{$size}"]);
            }
        }

        foreach ($images as $url) {
            if (
                is_string($url)
                && (str_contains($url, '.jpg') || str_contains($url, '.png'))
            ) {
                return $this->ensureHttps($url);
            }
        }

        $first = reset($images);
        return is_string($first) ? $this->ensureHttps($first) : null;
    }

    /**
     * Format duration in minutes to HH:MM:SS format
     *
     * @param int $minutes Duration in minutes
     * @return string Formatted duration (HH:MM:00)
     */
    protected function formatDuration(int $minutes): string
    {
        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;
        return sprintf('%02d:%02d:00', $hours, $remainingMinutes);
    }

    /**
     * Ensure URL uses HTTPS
     *
     * @param string $url URL to check
     * @return string URL with HTTPS
     */
    protected function ensureHttps(string $url): string
    {
        return str_replace('http://', 'https://', $url);
    }
}
