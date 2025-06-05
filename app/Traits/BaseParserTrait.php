<?php

namespace App\Traits;

/**
 * Base trait for parsing API responses with common functionality
 */
trait BaseParserTrait
{
    /**
     * Format authors to a consistent structure
     *
     * @param array|string $authors Author data or string of author names
     * @return array
     */
    protected function formatAuthors($authors): array
    {
        if (is_string($authors)) {
            return array_map(function ($name) {
                return [
                    'author' => [
                        'name' => trim($name),
                    ]
                ];
            }, array_filter(explode(',', $authors)));
        }

        if (!is_array($authors)) {
            return [];
        }

        return array_map(function ($author) {
            if (is_string($author)) {
                return [
                    'author' => [
                        'name' => $author,
                    ]
                ];
            }

            return [
                'author' => [
                    'id' => $author['id'] ?? null,
                    'name' => $author['name'] ?? 'Unknown Author',
                    'role' => $author['role'] ?? null,
                ]
            ];
        }, $authors);
    }

    /**
     * Format narrators to a consistent structure
     *
     * @param array|string $narrators Narrator data or string of narrator names
     * @return array
     */
    protected function formatNarrators($narrators): array
    {
        if (is_string($narrators)) {
            return array_map(function ($name) {
                return [
                    'narrator' => [
                        'name' => trim($name),
                    ]
                ];
            }, array_filter(explode(',', $narrators)));
        }

        if (!is_array($narrators)) {
            return [];
        }

        return array_map(function ($narrator) {
            if (is_string($narrator)) {
                return [
                    'narrator' => [
                        'name' => $narrator,
                    ]
                ];
            }

            return [
                'narrator' => [
                    'id' => $narrator['id'] ?? null,
                    'name' => $narrator['name'] ?? 'Unknown Narrator',
                ]
            ];
        }, $narrators);
    }

    /**
     * Format genres to a consistent structure
     *
     * @param array|string $genres Genre data or string of genre names
     * @return array
     */
    protected function formatGenres($genres): array
    {
        if (is_string($genres)) {
            return array_map(function ($name) {
                return [
                    'genre' => [
                        'name' => trim($name),
                    ]
                ];
            }, array_filter(explode(',', $genres)));
        }

        if (!is_array($genres)) {
            return [];
        }

        return array_map(function ($genre) {
            if (is_string($genre)) {
                return [
                    'genre' => [
                        'name' => $genre,
                    ]
                ];
            }

            return [
                'genre' => [
                    'id' => $genre['id'] ?? null,
                    'name' => $genre['name'] ?? 'Unknown Genre',
                    'parent_id' => $genre['parent_id'] ?? null,
                ]
            ];
        }, $genres);
    }

    /**
     * Format series information
     *
     * @param array|string $series Series data or string of series name
     * @param int|string|null $number Series number/position
     * @return array
     */
    protected function formatSeries($series, $number = null): array
    {
        if (empty($series)) {
            return [];
        }

        if (is_string($series)) {
            return [
                [
                    'series' => [
                        'name' => $series,
                        'number' => $number !== null ? (string)$number : null,
                    ]
                ]
            ];
        }

        if (is_array($series)) {
            // If it's already in the correct format, return as is
            if (isset($series['series'])) {
                return [$series];
            }

            // If it's an array of series entries
            if (isset($series[0]) && is_array($series[0])) {
                return array_map(function ($item) {
                    return [
                        'series' => [
                            'id' => $item['id'] ?? null,
                            'name' => $item['name'] ?? 'Unknown Series',
                            'number' => $item['number'] ?? null,
                        ]
                    ];
                }, $series);
            }

            // Single series entry
            return [
                [
                    'series' => [
                        'id' => $series['id'] ?? null,
                        'name' => $series['name'] ?? 'Unknown Series',
                        'number' => $series['number'] ?? $number,
                    ]
                ]
            ];
        }

        return [];
    }

    /**
     * Format book data to a consistent structure
     */
    protected function formatBookData(array $data): array
    {
        return [
            'id' => $data['id'] ?? null,
            'title' => $data['title'] ?? 'Unknown Title',
            'subtitle' => $data['subtitle'] ?? null,
            'description' => $data['description'] ?? null,
            'published_date' => $data['published_date'] ?? null,
            'publisher' => $data['publisher'] ?? null,
            'language' => $data['language'] ?? 'en',
            'isbn' => $data['isbn'] ?? null,
            'isbn13' => $data['isbn13'] ?? $data['isbn_13'] ?? null,
            'asin' => $data['asin'] ?? null,
            'page_count' => $data['page_count'] ?? $data['pages'] ?? null,
            'format' => $data['format'] ?? null,
            'edition' => $data['edition'] ?? null,
            'cover_image_url' => $data['cover_image_url'] ?? $data['cover_url'] ?? $data['image_url'] ?? null,
            'cover_image_thumbnail' => $data['cover_image_thumbnail'] ?? $data['thumbnail_url'] ?? null,
            'authors' => $this->formatAuthors($data['authors'] ?? []),
            'narrators' => $this->formatNarrators($data['narrators'] ?? []),
            'series' => $this->formatSeries($data['series'] ?? null, $data['series_number'] ?? null),
            'genres' => $this->formatGenres($data['genres'] ?? $data['categories'] ?? []),
            'rating' => $data['rating'] ?? $data['average_rating'] ?? null,
            'ratings_count' => $data['ratings_count'] ?? $data['ratingsCount'] ?? 0,
            'metadata' => array_merge(
                $data['metadata'] ?? [],
                [
                    'source' => $data['metadata']['source'] ?? 'unknown',
                    'url' => $data['url'] ?? $data['metadata']['url'] ?? null,
                    'created_at' => $data['created_at'] ?? now()->toDateTimeString(),
                    'updated_at' => $data['updated_at'] ?? now()->toDateTimeString(),
                ]
            ),
        ];
    }

    /**
     * Format search results to a consistent format
     */
    protected function formatSearchResults(array $items): array
    {
        return array_map(function ($item) {
            return $this->formatBookData($item);
        }, $items);
    }

    /**
     * Extract the first name from a full name
     */
    protected function extractFirstName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName));
        return $parts[0] ?? '';
    }

    /**
     * Extract the last name from a full name
     */
    protected function extractLastName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName));
        return count($parts) > 1 ? end($parts) : '';
    }

    /**
     * Normalize a string for comparison
     */
    protected function normalizeString(?string $str): string
    {
        if ($str === null) {
            return '';
        }

        return trim(mb_strtolower($str));
    }

    /**
     * Calculate similarity between two strings (0-1)
     */
    protected function calculateSimilarity(string $str1, string $str2): float
    {
        $str1 = $this->normalizeString($str1);
        $str2 = $this->normalizeString($str2);

        if ($str1 === $str2) {
            return 1.0;
        }

        $len1 = mb_strlen($str1);
        $len2 = mb_strlen($str2);

        if ($len1 < 1 || $len2 < 1) {
            return 0.0;
        }

        $maxLen = max($len1, $len2);
        $distance = levenshtein($str1, $str2);

        return 1 - ($distance / $maxLen);
    }
}
