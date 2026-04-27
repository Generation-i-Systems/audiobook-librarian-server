<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LibriVoxApiService extends BaseBookService
{
    private const BASE_URL = 'https://librivox.org/api/feed';

    private const CACHE_TTL = 86400; // 24 hours — librivox content is immutable

    // Genre names must exactly match the LibriVox API's genre filter values.
    public const GENRES = [
        'Action & Adventure',
        'Action & Adventure Fiction',
        'Ancient',
        'Animals & Nature',
        'Art, Design & Architecture',
        'Biography & Autobiography',
        'Children\'s Fiction',
        'Children\'s Non-fiction',
        'Comedy',
        'Crime & Mystery Fiction',
        'Detective Fiction',
        'Drama',
        'Essays',
        'Fantastic Fiction',
        'Fantasy Fiction',
        'General Fiction',
        'Historical Fiction',
        'Horror & Supernatural Fiction',
        'Humor',
        'Language learning',
        'Letters',
        'Literary Fiction',
        'Modern',
        'Music',
        'Myths, Legends & Fairy Tales',
        'Nature',
        'Philosophy',
        'Plays',
        'Poetry',
        'Political Science',
        'Psychology',
        'Religion',
        'Romance',
        'Satire',
        'Science',
        'Science Fiction',
        'Self-Help',
        'Short Stories',
        'Short works',
        'Suspense, Espionage, Political & Thrillers',
        'Travel & Geography',
        'War & Military',
        'War & Military Fiction',
        'Westerns',
    ];

    public function getServiceName(): string
    {
        return 'librivox';
    }

    /**
     * Fetch a paginated list of audiobooks from LibriVox.
     *
     * @return array{books: array<int, array<string, mixed>>}|null
     */
    public function listBooks(int $limit = 50, int $offset = 0, ?int $since = null, ?string $language = null): ?array
    {
        $params = ['format' => 'json', 'extended' => '1', 'limit' => $limit, 'offset' => $offset];
        if ($since !== null) {
            $params['since'] = $since;
        }
        if ($language !== null && $language !== '') {
            $params['language'] = $language;
        }

        $cacheKey = 'librivox_list_' . md5(json_encode($params) ?: '');

        return Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->apiGet('/audiobooks/', $params));
    }

    /**
     * Fetch audiobooks filtered by genre name.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listBooksByGenre(string $genre, int $limit = 50, int $offset = 0, ?string $language = null): array
    {
        $params = ['format' => 'json', 'extended' => '1', 'limit' => $limit, 'offset' => $offset, 'genre' => $genre];
        if ($language !== null && $language !== '') {
            $params['language'] = $language;
        }
        $cacheKey = 'librivox_genre_' . md5(json_encode($params) ?: '');

        $result = Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->apiGet('/audiobooks/', $params));

        return $result['books'] ?? [];
    }

    /**
     * Count all books in a genre by paging through the API.
     * Result is cached for 24 hours. Returns exact count or $cap if more exist.
     */
    public function countBooksByGenre(string $genre, ?string $language = null, int $cap = 500): int
    {
        $cacheKey = 'librivox_genre_count_' . md5($genre . '|' . ($language ?? ''));

        $result = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($genre, $language, $cap): int {
            $total = 0;
            $pageSize = 50;
            $offset = 0;

            do {
                $params = ['format' => 'json', 'limit' => $pageSize, 'offset' => $offset, 'genre' => $genre];
                if ($language !== null && $language !== '') {
                    $params['language'] = $language;
                }
                $apiResult = $this->apiGet('/audiobooks/', $params);
                $count = count($apiResult['books'] ?? []);
                $total += $count;
                $offset += $pageSize;
            } while ($count === $pageSize && $total < $cap);

            return $total;
        });

        return (int) $result;
    }

    /**
     * Fetch audiobooks by a LibriVox author ID.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listBooksByAuthor(string $authorId, int $limit = 50, int $offset = 0, ?string $language = null): array
    {
        $params = ['format' => 'json', 'extended' => '1', 'limit' => $limit, 'offset' => $offset, 'author_id' => $authorId];
        if ($language !== null && $language !== '') {
            $params['language'] = $language;
        }
        $cacheKey = 'librivox_author_books_' . md5(json_encode($params) ?: '');

        $result = Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->apiGet('/audiobooks/', $params));

        return $result['books'] ?? [];
    }

    /**
     * Search LibriVox authors by last name.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchAuthors(string $lastName, int $limit = 30, int $offset = 0): array
    {
        $params = ['format' => 'json', 'limit' => $limit, 'offset' => $offset, 'last_name' => $lastName];
        $cacheKey = 'librivox_authors_' . md5(json_encode($params) ?: '');

        $result = Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->apiGet('/authors/', $params));

        return $result['authors'] ?? [];
    }

    /**
     * Fetch a single audiobook by its LibriVox ID.
     *
     * @return array<string, mixed>|null
     */
    public function getById(string $librivoxId): ?array
    {
        $cacheKey = 'librivox_book_' . $librivoxId;

        $result = Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->apiGet('/audiobooks/', [
            'id' => $librivoxId, 'format' => 'json', 'extended' => '1',
        ]));

        return $result['books'][0] ?? null;
    }

    /**
     * Fetch the chapter/section list for a book.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSections(string $librivoxId): array
    {
        $cacheKey = 'librivox_sections_' . $librivoxId;

        $result = Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->apiGet('/audiobooks/', [
            'id' => $librivoxId, 'format' => 'json', 'extended' => '1',
        ]));

        return $result['books'][0]['sections'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<int, array<string, mixed>>|null
     */
    protected function performSearch(string $query, array $options = []): ?array
    {
        $params = ['format' => 'json', 'extended' => '1', 'limit' => 20];

        if (!empty($options['author'])) {
            $params['author'] = $options['author'];
        } else {
            $params['title'] = '^' . $query;
        }

        $result = $this->apiGet('/audiobooks/', $params);

        return $result['books'] ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function performGetBookDetails(string $id): ?array
    {
        return $this->getById($id);
    }

    public function downloadCoverImage(string $imageUrl, string $directoryPath, string $targetBasename): ?string
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null
     */
    private function apiGet(string $path, array $params): ?array
    {
        $url = self::BASE_URL . $path;

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'User-Agent' => 'AudiobookLibrarian/1.0',
            ])->timeout(20)->get($url, $params);

            if (!$response->successful()) {
                Log::warning('LibriVox API request failed', ['url' => $url, 'params' => $params, 'status' => $response->status()]);
                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('LibriVox API exception', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
