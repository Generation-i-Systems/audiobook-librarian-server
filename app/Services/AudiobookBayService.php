<?php

namespace App\Services;

use App\Contracts\BookServiceInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AudiobookBayService extends BaseBookService implements BookServiceInterface
{
    protected int $defaultLimit = 10;

    protected int $cacheTtl = 86400; // 24 hours in seconds

    protected AudiobookBayApiService $apiService;

    public function __construct(AudiobookBayApiService $apiService)
    {
        $this->apiService = $apiService;
    }

    /**
     * {@inheritDoc}
     */
    public function getServiceName(): string
    {
        return 'audiobookbay';
    }

    /**
     * {@inheritDoc}
     */
    public function performSearch(string $query, array $options = []): ?array
    {
        $limit = $options['limit'] ?? $this->defaultLimit;
        $page = $options['page'] ?? 1;

        $cacheKey = 'audiobookbay_service_search_' . md5($query . '_' . $limit . '_' . $page);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($query, $options, $limit) {
            try {
                $searchOptions = [
                    'page' => $options['page'] ?? 1,
                ];

                $results = $this->apiService->searchAudiobooks($query, $searchOptions);

                if (is_null($results)) {
                    Log::warning('AudiobookBayService:performSearch - Null result from apiService->searchAudiobooks', [
                        'query' => $query,
                        'options' => $searchOptions,
                    ]);

                    return [];
                }

                $formattedResults = [];
                foreach ($results as $resultItem) {
                    $formattedResults[] = [
                        'id' => basename(parse_url($resultItem['url'] ?? '', PHP_URL_PATH) ?? ''),
                        'title' => $resultItem['title'] ?? '',
                        'author' => $resultItem['authors'][0]['name'] ?? '',
                        'narrator' => $resultItem['narrators'][0]['name'] ?? '',
                        'size' => $resultItem['metadata']['size'] ?? '',
                        'format' => $resultItem['metadata']['format'] ?? '',
                        'link' => $resultItem['url'] ?? '',
                        'cover' => $resultItem['cover_image_url'] ?? '',
                        'description' => $resultItem['description'] ?? '',
                        'metadata' => $resultItem['metadata'] ?? [],
                    ];
                }

                if ($limit > 0 && count($formattedResults) > $limit) {
                    $formattedResults = array_slice($formattedResults, 0, $limit);
                }

                return $formattedResults;
            } catch (\Exception $e) {
                Log::error('AudiobookBayService: Error in performSearch', [
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }

    /**
     * {@inheritDoc}
     */
    public function getBookDetails(string $id, array $options = []): ?array
    {
        Log::error('[AUDIOBOOKBAY-DETAIL] getBookDetails', [
            'id' => $id,
        ]);

        return $this->performGetBookDetails($id);
    }

    /**
     * {@inheritDoc}
     */
    public function performGetBookDetails(string $idOrSlug): ?array
    {
        $cacheKey = 'audiobookbay_service_book_details_' . md5($idOrSlug);

        try {
            Log::debug('[AUDIOBOOKBAY-DETAIL] performGetBookDetails', [
                'idOrSlug' => $idOrSlug,
            ]);
            $details = $this->apiService->getAudiobookDetails($idOrSlug);

            if (is_null($details)) {
                Log::warning(
                    'AudiobookBayService:performGetBookDetails - Null result from apiService->getAudiobookDetails',
                    [
                        'idOrSlug' => $idOrSlug,
                    ]
                );

                return null;
            }

            return $this->formatBookDetails($details);
        } catch (\Exception $e) {
            Log::error('AudiobookBayService: Error in performGetBookDetails', [
                'idOrSlug' => $idOrSlug,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Placeholder for cover image download (not implemented for AudiobookBayService)
     */
    public function downloadCoverImage(string $imageUrl, string $directoryPath, string $targetBasename): ?string
    {
        Log::info('downloadCoverImage not implemented for AudiobookBayService');

        return null;
    }

    /**
     * Attempt to look up the book in AudiobookBay and return additional metadata.
     *
     * Matching rules:
     * - Author name must appear in the result title
     * - Title (minus author) must closely match searched title
     * - Numbers in the search (e.g. book number) must match exactly
     */
    public function searchAndMerge(array $book): ?array
    {
        $inputTitle = trim($book['title'] ?? '');
        $inputAuthor = '';
        if (
            isset($book['authors']) && is_array($book['authors']) && isset($book['authors'][0]['author']['name']) &&
            is_string($book['authors'][0]['author']['name'])
        ) {
            $inputAuthor = trim($book['authors'][0]['author']['name']);
        } elseif (isset($book['author']) && is_string($book['author'])) {
            $inputAuthor = trim($book['author']);
        }
        $inputNumber = null;
        if (isset($book['series']) && is_array($book['series']) && isset($book['series'][0]['series']['number'])) {
            $inputNumber = $book['series'][0]['series']['number'];
        }
        if (!$inputTitle) {
            return null;
        }
        $query = $inputTitle;
        $options = ['limit' => 10];
        $results = $this->apiService->searchAudiobooks($query, $options) ?? [];
        if (empty($results)) {
            return null;
        }
        $bestMatch = null;
        $bestScore = 0;
        foreach ($results as $result) {
            $resultTitle = $result['title'] ?? '';
            $resultAuthors = $result['authors'] ?? [];
            $resultNumber = null;
            if (
                isset($result['series']) && is_array($result['series']) &&
                isset($result['series'][0]['series']['number'])
            ) {
                $resultNumber = $result['series'][0]['series']['number'];
            }
            // Author match: author name must appear in result title (case-insensitive)
            if ($inputAuthor && stripos($resultTitle, $inputAuthor) === false) {
                continue;
            }
            // Remove author from result title for comparison
            $titleNoAuthor = $resultTitle;
            if ($inputAuthor) {
                $titleNoAuthor = trim(str_ireplace($inputAuthor, '', $resultTitle));
            }
            // Title similarity (Levenshtein or similar)
            $sim = \App\Services\AudiobookBayApiService::calculateSimilarity($inputTitle, $titleNoAuthor);
            if ($sim < 0.7) {
                continue;
            }
            // Number match (if present)
            if ($inputNumber !== null && $resultNumber !== null && $inputNumber != $resultNumber) {
                continue;
            }
            // Prefer higher similarity
            if ($sim > $bestScore) {
                $bestScore = $sim;
                $bestMatch = $result;
            }
        }
        if (!$bestMatch) {
            return null;
        }
        // Get full details for best match
        $details = $this->apiService->getAudiobookDetails($bestMatch['id'] ?? $bestMatch['url'] ?? '');
        if (!$details) {
            return null;
        }
        // Merge fields: prefer existing book fields, but add/overwrite with ABB details if missing
        $merged = array_merge($details, $book);
        // Track which fields differ
        $apiFields = [];
        $needsReview = false;
        foreach ($merged as $field => $newValue) {
            if (
                array_key_exists($field, $book) && $book[$field] !== null && $newValue !== null &&
                $book[$field] != $newValue
            ) {
                $apiFields[$field] = $newValue;
                $needsReview = true;
                $merged[$field] = $book[$field];
            }
        }
        if ($needsReview) {
            $merged['audiobookbay_fields'] = $apiFields;
            $merged['needsReview'] = true;
        }

        // Remove nulls from array
        return array_filter($merged, fn($v) => $v !== null);
    }

    /**
     * Format book details (from apiService) to a consistent format for BookServiceInterface.
     */
    protected function formatBookDetails(array $details): array
    {
        return [
            'id' => $details['id'] ?? basename(parse_url($details['url'] ?? '', PHP_URL_PATH) ?? ''),
            'title' => $details['title'] ?? 'Unknown Title',
            'subtitle' => $details['subtitle'] ?? null,
            'authors' => $this->formatAuthors($details['authors'] ?? []),
            'narrators' => $this->formatNarrators($details['narrators'] ?? []),
            'description' => $details['description'] ?? null,
            'published_date' => $details['published_date'] ?? null,
            'publisher' => $details['publisher'] ?? null,
            'cover_image_url' => $details['cover_image_url'] ?? null,
            'categories' => $this->formatCategories($details['categories'] ??
                ($details['metadata']['categories'] ?? [])),
            'language' => $details['language'] ?? null,
            'series' => (!empty($details['series']['name'])) ?
                ($details['series']['name'] . (!empty($details['series']['number']) ?
                    ' #' . $details['series']['number'] : '')) :
                null,
            'series_number' => $details['series']['number'] ?? null,
            'duration_seconds' => $this->parseDuration($details['metadata']['duration'] ??
                $details['duration'] ?? null),
            'metadata' => array_merge(
                $details['metadata'] ?? [],
                [
                    'source' => 'AudiobookBay',
                    'url' => $details['url'] ??
                        null
                ]
            ),
        ];
    }

    /**
     * Format authors array (from apiService's parsed data) to a consistent structure.
     */
    protected function formatAuthors(array $authorsArray): array
    {
        if (empty($authorsArray)) {
            return [];
        }

        return array_map(function ($authorObj) {
            return [
                'author' => [
                    'name' => trim($authorObj['name'] ?? ''),
                    'id' => $authorObj['id'] ?? null,
                ],
            ];
        }, $authorsArray);
    }

    /**
     * Format narrators array (from apiService's parsed data) to a consistent structure.
     */
    protected function formatNarrators(array $narratorsArray): array
    {
        if (empty($narratorsArray)) {
            return [];
        }

        return array_map(function ($narratorObj) {
            return [
                'author' => [
                    'name' => trim($narratorObj['name'] ?? ''),
                    'id' => $narratorObj['id'] ?? null,
                ],
            ];
        }, $narratorsArray);
    }

    /**
     * Format categories array to a consistent structure.
     */
    protected function formatCategories(array $categoriesInput): array
    {
        if (empty($categoriesInput)) {
            return [];
        }

        return array_map(function ($category) {
            $categoryName = is_array($category) ? ($category['name'] ?? $category['genre'] ?? '') : $category;

            return [
                'genre' => [
                    'name' => trim($categoryName),
                ],
            ];
        }, $categoriesInput);
    }

    /**
     * Helper to parse duration string (e.g., "2h 30m", "PT2H30M") to seconds.
     * This should ideally come from a more robust parser or be standardized in apiService if possible.
     */
    protected function parseDuration(?string $durationStr): ?int
    {
        if (empty($durationStr)) {
            return null;
        }
        $duration = 0;
        if (preg_match('/(\d+)\s*h(?:ours?)?/i', $durationStr, $matches)) {
            $duration += (int) $matches[1] * 3600;
        }
        if (preg_match('/(\d+)\s*m(?:in(?:utes?)?)?/i', $durationStr, $matches)) {
            $duration += (int) $matches[1] * 60;
        }
        if (preg_match('/(\d+)\s*s(?:ec(?:onds?)?)?/i', $durationStr, $matches)) {
            $duration += (int) $matches[1];
        }
        // ISO8601 Duration PThHmMsS (basic support)
        if ($duration === 0 && str_starts_with(strtoupper($durationStr), 'PT')) {
            try {
                $interval = new \DateInterval($durationStr);
                $duration = ($interval->h * 3600) + ($interval->i * 60) + $interval->s;
            } catch (\Exception $e) { /* Ignore if not valid DateInterval */
            }
        }

        return $duration > 0 ? $duration : null;
    }

    /**
     * Check if the service is available.
     */
    public function isAvailable(): bool
    {
        return isset($this->apiService);
    }

    /**
     * Expose the internal AudiobookBayApiService instance.
     * Needed for legacy/test code compatibility.
     */
    public function getApiService(): AudiobookBayApiService
    {
        return $this->apiService;
    }
}
