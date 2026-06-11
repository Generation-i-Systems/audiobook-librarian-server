<?php

namespace App\Services;

use App\Contracts\BookServiceInterface;
use App\Mail\HardcoverTokenExpiring;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class HardcoverService extends BaseBookService implements BookServiceInterface
{
    protected string $apiUrl;

    protected ?string $apiToken;

    protected ?string $tokenExpiresAt;

    protected string $notificationEmail;

    protected int $expirationWarningDays = 30;

    public function __construct()
    {
        $this->apiUrl = config('hardcover.api_url');
        $this->apiToken = config('hardcover.api_token');
        $this->tokenExpiresAt = config('hardcover.token_expires_at');
        $this->notificationEmail = config('hardcover.notification_email');
    }

    /**
     * Make a GraphQL request to the Hardcover API
     */
    public function makeRequest(string $query, array $variables = []): ?array
    {
        $this->checkTokenExpiration();

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->apiToken,
            ])->post($this->apiUrl, [
                        'query' => $query,
                        'variables' => $variables,
                    ]);

            if ($response->successful()) {
                return $response->json();
            }

            if ($response->status() === 401) {
                $this->handleTokenExpiration();

                return null;
            }

            Log::error('Hardcover API request failed', [
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Hardcover API request exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Check if the token is expired or about to expire
     */
    protected function checkTokenExpiration(): void
    {
        if (!$this->tokenExpiresAt) {
            return;
        }

        $expirationDate = Carbon::parse($this->tokenExpiresAt);
        $now = now();
        $daysUntilExpiration = $now->diffInDays($expirationDate, false);

        // If token is expired
        if ($daysUntilExpiration < 0) {
            $this->handleTokenExpiration();

            return;
        }

        // If token expires within the warning period
        if ($daysUntilExpiration <= $this->expirationWarningDays) {
            $this->sendExpirationWarning($daysUntilExpiration);
        }
    }

    /**
     * Handle token expiration
     */
    protected function handleTokenExpiration(): void
    {
        Log::error('Hardcover API token has expired');

        // Send notification email
        try {
            Mail::to($this->notificationEmail)->send(new HardcoverTokenExpiring(0));
        } catch (\Exception $e) {
            Log::error('Failed to send token expiration email', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send expiration warning email
     */
    protected function sendExpirationWarning(int $daysUntilExpiration): void
    {
        static $warningSent = false;

        // Only send the warning once per day
        if ($warningSent) {
            return;
        }

        try {
            Mail::to($this->notificationEmail)
                ->send(new HardcoverTokenExpiring($daysUntilExpiration));
            $warningSent = true;
        } catch (\Exception $e) {
            Log::error('Failed to send token expiration warning', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getServiceName(): string
    {
        return 'Hardcover';
    }

    /**
     * {@inheritDoc}
     * Override to propagate null on failure for Feature tests expectations.
     */
    public function searchBooks(string $query, array $options = []): ?array
    {
        return $this->performSearch($query, $options);
    }

    /**
     * {@inheritDoc}
     */
    protected function performSearch(string $query, array $options = []): ?array
    {
        $title = $query;
        $author = $options['author'] ?? null;
        $limit = $options['limit'] ?? 5;

        \Illuminate\Support\Facades\Log::info('HardcoverService: performSearch called', [
            'title' => $title,
            'author' => $author,
            'limit' => $limit,
        ]);

        // Hardcover uses Typesense search API, not where operators
        // Combine title and author into single search query
        $searchQuery = $title;
        if ($author) {
            $searchQuery = "$title $author";
        }

        // Use the search endpoint instead of books with where clause
        // Note: search endpoint doesn't accept limit parameter, use per_page instead
        $query = '
            query SearchBooks($query: String!, $per_page: Int!) {
                search(
                    query: $query
                    query_type: "book"
                    per_page: $per_page
                ) {
                    results
                }
            }
        ';

        $variables = [
            'query' => $searchQuery,
            'per_page' => $limit,
        ];

        \Illuminate\Support\Facades\Log::debug('Making search request', [
            'query' => $query,
            'variables' => $variables,
        ]);

        $result = $this->makeRequest($query, $variables);

        if ($result === null) {
            // API call failed
            return null;
        }

        \Illuminate\Support\Facades\Log::debug('Search request result', [
            'result' => $result,
        ]);

        // Search endpoint returns results with hits array containing documents
        $searchData = $result['data']['search']['results'] ?? null;

        if (empty($searchData)) {
            Log::warning('No search data in Hardcover API response', [
                'query' => $query,
                'options' => $options,
            ]);

            return [];
        }

        // Extract hits array from search results
        $hits = $searchData['hits'] ?? [];

        if (empty($hits)) {
            Log::info('No books found in Hardcover search', [
                'found' => $searchData['found'] ?? 0,
                'query' => $query,
            ]);

            return [];
        }

        // Extract document from each hit
        $books = array_map(function ($hit) {
            return $hit['document'] ?? [];
        }, $hits);

        // Filter out any empty documents
        $books = array_filter($books);

        if (empty($books)) {
            Log::warning('No valid book documents in Hardcover search results');

            return [];
        }

        return $this->formatSearchResults($books);
    }

    /**
     * {@inheritDoc}
     */
    protected function performGetBookDetails(string $id): ?array
    {
        $query = '
            query GetBookDetails($bookId: Int!) {
                books_by_pk(id: $bookId) {
                    id
                    title
                    description
                    pages
                    release_date
                    image {
                        url
                    }
                    taggings {
                        tag {
                            tag
                        }
                    }
                    contributions(where: {role: {_eq: "Author"}}) {
                        author {
                            id
                            name
                        }
                    }
                }
            }
        ';

        $result = $this->makeRequest($query, ['bookId' => (int) $id]);
        $book = $result['data']['books_by_pk'] ?? null;

        if (!$book) {
            Log::warning('Book not found in Hardcover API', ['id' => $id]);

            return null;
        }

        return $this->formatBookDetails($book);
    }

    /**
     * Format search results from Hardcover API
     */
    protected function formatSearchResults(array $items): array
    {
        $results = [];

        foreach ($items as $item) {
            if (empty($item['id'])) {
                continue;
            }

            // Search API returns author_names as simple array, convert to expected format
            $authorNames = $item['author_names'] ?? [];
            $formattedAuthors = array_map(function ($name) {
                return [
                    'author' => [
                        'id' => null,
                        'name' => $name,
                    ],
                ];
            }, $authorNames);

            // Get cover image URL from nested image object
            $coverUrl = null;
            if (isset($item['image']['url'])) {
                $coverUrl = $item['image']['url'];
            }

            // Get ISBNs from array
            $isbns = $item['isbns'] ?? [];
            $isbn10 = null;
            $isbn13 = null;
            foreach ($isbns as $isbn) {
                if (strlen($isbn) === 10) {
                    $isbn10 = $isbn;
                } elseif (strlen($isbn) === 13) {
                    $isbn13 = $isbn;
                }
            }

            $results[] = [
                'source' => 'Hardcover',
                'id' => $item['id'],
                'title' => $item['title'] ?? 'Unknown Title',
                'subtitle' => $item['subtitle'] ?? null,
                'author' => $authorNames, // Simple array of author names
                'authors' => $formattedAuthors, // Nested format
                'publisher' => null, // Not in search results
                'published_date' => $item['release_date'] ?? null,
                'publishedYear' => $item['release_year'] ?? (!empty($item['release_date']) ? substr($item['release_date'], 0, 4) : null),
                'description' => $item['description'] ?? null,
                'page_count' => $item['pages'] ?? null,
                'genres' => $item['genres'] ?? [],
                'cover_image_url' => $coverUrl,
                'coverImageUrl' => $coverUrl, // Alias for consistency
                'isbn_10' => $isbn10,
                'isbn_13' => $isbn13,
            ];
        }

        return $results;
    }

    /**
     * Format book details from Hardcover API
     */
    protected function formatBookDetails(array $item): array
    {
        // Extract genre names from taggings (confirmed working Hardcover schema)
        $genreNames = array_values(array_filter(array_map(
            fn ($t) => $t['tag']['tag'] ?? null,
            $item['taggings'] ?? []
        )));

        return [
            'id' => $item['id'],
            'title' => $item['title'] ?? 'Unknown Title',
            'authors' => $this->formatAuthors($item['contributions'] ?? []),
            'published_date' => $item['release_date'] ?? null,
            'description' => $item['description'] ?? null,
            'page_count' => $item['pages'] ?? null,
            'cover_image_url' => $item['image']['url'] ?? null,
            'coverImageUrl' => $item['image']['url'] ?? null,
            'genres' => array_map(fn ($name) => ['genre' => ['name' => $name]], $genreNames),
            'genre' => $genreNames,
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
                    'id' => $author['author']['id'] ?? null,
                    'name' => $author['author']['name'] ?? 'Unknown Author',
                ],
            ];
        }, $authors);
    }

    /**
     * Format narrators array to a consistent format
     */
    protected function formatNarrators(array $narrators): array
    {
        return array_map(function ($narrator) {
            return [
                'author' => [
                    'id' => $narrator['author']['id'] ?? null,
                    'name' => $narrator['author']['name'] ?? 'Unknown Narrator',
                ],
            ];
        }, $narrators);
    }

    /**
     * Format genres to a consistent structure
     */
    protected function formatGenres(array $genres): array
    {
        return array_map(function ($g) {
            if (isset($g['genre'])) {
                return ['genre' => ['name' => $g['genre']['name'] ?? 'Unknown']];
            }
            // already normalized or name-only
            return ['genre' => ['name' => $g['name'] ?? 'Unknown']];
        }, $genres);
    }

    /**
     * Override availability check to validate configuration
     */
    public function isAvailable(): bool
    {
        return !empty($this->apiToken) && !empty($this->apiUrl);
    }

    /**
     * Search for a book by title/author and merge search + detail results into one array.
     * Returns keys: hardcoverId, title, description, coverImage, pages, releaseDate, genres (string[]).
     */
    public function searchAndMerge(array $book): ?array
    {
        $title = $book['title'] ?? null;
        $authors = $book['authors'] ?? [];
        if (!$title) {
            return null;
        }

        $author = !empty($authors) ? (is_array($authors[0]) ? ($authors[0]['author']['name'] ?? $authors[0]) : $authors[0]) : null;
        $results = $this->searchBooks($title, ['author' => $author, 'limit' => 5]);
        if (empty($results)) {
            return null;
        }

        // Trust Hardcover's search ranking; only promote a later result on a clear title+author win
        $bestMatch = $results[0];
        $bestScore = 0;
        foreach ($results as $result) {
            $score = 0;
            if (strcasecmp(trim($result['title'] ?? ''), trim($title)) === 0) {
                $score += 2;
            } elseif (stripos($result['title'] ?? '', $title) !== false) {
                $score += 1;
            }
            if ($author && !empty($result['author'])) {
                foreach ((array) $result['author'] as $authorName) {
                    if (stripos($authorName, $author) !== false) {
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

        $details = null;
        if (!empty($bestMatch['id'])) {
            $details = $this->getBookDetails((string) $bestMatch['id']);
        }

        $genreNames = [];
        if ($details) {
            // genre field is pre-extracted in formatBookDetails (plain string array)
            $genreNames = $details['genre'] ?? [];
        }

        return array_filter([
            'hardcoverId' => $bestMatch['id'] ?? null,
            'title' => $bestMatch['title'] ?? null,
            'description' => $details['description'] ?? $bestMatch['description'] ?? null,
            'coverImage' => $details['cover_image_url'] ?? $bestMatch['cover_image_url'] ?? $bestMatch['coverImageUrl'] ?? null,
            'pages' => $details['page_count'] ?? $bestMatch['page_count'] ?? null,
            'releaseDate' => $details['published_date'] ?? $bestMatch['published_date'] ?? null,
            'genres' => !empty($genreNames) ? $genreNames : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Get books by author (public API)
     */
    public function getBooksByAuthor(string $authorName, int $limit = 10): ?array
    {
        $query = '
        query GetBooksByAuthor($authorName: String!, $limit: Int!) {
            books(
                where: {
                    contributions: {
                        author: {name: {_eq: $authorName}},
                        role: {_eq: "AUTHOR"}
                    }
                },
                limit: $limit,
                order_by: {release_date: desc}
            ) {
                id
                title
                release_date
                cover_image_url: cover_image_url(size: SMALL)
            }
        }
    ';
        $result = $this->makeRequest($query, [
            'authorName' => $authorName,
            'limit' => $limit,
        ]);

        return $result['data']['books'] ?? null;
    }

    /**
     * Download a cover image for a book to the specified directory and basename.
     */
    public function downloadCoverImage(string $imageUrl, string $directoryPath, string $targetBasename): ?string
    {
        try {
            if (empty($imageUrl)) {
                return null;
            }

            if (!is_dir($directoryPath)) {
                if (!mkdir($directoryPath, 0775, true) && !is_dir($directoryPath)) {
                    return null;
                }

                // Set directory ownership to eric:audio
                if (is_dir($directoryPath)) {
                    @chmod($directoryPath, 0775);
                }
            }

            $ext = pathinfo(parse_url($imageUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'jpg';
            $targetPath = rtrim($directoryPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $targetBasename . '.' . $ext;

            $response = Http::timeout(20)->get($imageUrl);
            if (!$response->successful()) {
                return null;
            }

            file_put_contents($targetPath, $response->body());

            // Set file permissions and ownership
            if (file_exists($targetPath)) {
                @chmod($targetPath, 0664);
            }

            return $targetPath;
        } catch (\Throwable $e) {
            Log::warning('downloadCoverImage failed', [
                'url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
