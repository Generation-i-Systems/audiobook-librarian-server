<?php

namespace App\Services;

use App\Contracts\BookServiceInterface;
use App\Services\AudiobookBayApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
     * @inheritDoc
     */
    public function getServiceName(): string
    {
        return 'audiobookbay';
    }

    /**
     * @inheritDoc
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
                    Log::warning('AudiobookBayService:performSearch - Null result from apiService->searchAudiobooks', ['query' => $query, 'options' => $searchOptions]);
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
                        'metadata' => $resultItem['metadata'] ?? []
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
     * @inheritDoc
     */
    public function getBookDetails(string $id): ?array
    {
        return $this->performGetBookDetails($id);
    }

    /**
     * @inheritDoc
     */
    public function performGetBookDetails(string $idOrSlug): ?array
    {
        $cacheKey = 'audiobookbay_service_book_details_' . md5($idOrSlug);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($idOrSlug) {
            try {
                $details = $this->apiService->getAudiobookDetails($idOrSlug);

                if (is_null($details)) {
                    Log::warning('AudiobookBayService:performGetBookDetails - Null result from apiService->getAudiobookDetails', ['idOrSlug' => $idOrSlug]);
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
        });
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
            'categories' => $this->formatCategories($details['categories'] ?? ($details['metadata']['categories'] ?? [])),
            'language' => $details['language'] ?? null,
            'series' => (!empty($details['series']['name'])) ? ($details['series']['name'] . (!empty($details['series']['number']) ? ' #' . $details['series']['number'] : '')) : null,
            'series_number' => $details['series']['number'] ?? null,
            'duration_seconds' => $this->parseDuration($details['metadata']['duration'] ?? $details['duration'] ?? null),
            'metadata' => array_merge($details['metadata'] ?? [], ['source' => 'AudiobookBay', 'url' => $details['url'] ?? null]),
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
                ]
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
                ]
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
                ]
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
            $duration += (int)$matches[1] * 3600;
        }
        if (preg_match('/(\d+)\s*m(?:in(?:utes?)?)?/i', $durationStr, $matches)) {
            $duration += (int)$matches[1] * 60;
        }
        if (preg_match('/(\d+)\s*s(?:ec(?:onds?)?)?/i', $durationStr, $matches)) {
            $duration += (int)$matches[1];
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
}
