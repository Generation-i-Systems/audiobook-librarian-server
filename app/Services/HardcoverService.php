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

        // Build the GraphQL query based on whether author is provided
        if ($author) {
            $query = '
                query SearchBooks($title: String!, $author: String!, $limit: Int!) {
                    books(
                        where: {
                            _and: [
                                {title: {_ilike: $title}}
                                {contributions: {author: {name: {_ilike: $author}}}}
                            ]
                        },
                        limit: $limit
                    ) {
                        id
                        title
                        subtitle
                        description
                        release_date
                        cover_image_url: cover_image_url(size: LARGE)
                        genres {
                            genre {
                                name
                            }
                        }
                        authors: contributions(where: {role: {_eq: "AUTHOR"}}) {
                            author {
                                name
                                id
                            }
                        }
                    }
                }
            ';
            $variables = [
                'title' => "%$title%",
                'author' => "%$author%",
                'limit' => $limit,
            ];
        } else {
            $query = '
                query SearchBooks($title: String!, $limit: Int!) {
                    books(
                        where: {
                            title: {_ilike: $title}
                        },
                        limit: $limit
                    ) {
                        id
                        title
                        subtitle
                        description
                        release_date
                        cover_image_url: cover_image_url(size: LARGE)
                        genres {
                            genre {
                                name
                            }
                        }
                        authors: contributions(where: {role: {_eq: "AUTHOR"}}) {
                            author {
                                name
                                id
                            }
                        }
                    }
                }
            ';
            $variables = [
                'title' => "%$title%",
                'limit' => $limit,
            ];
        }

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

        $books = $result['data']['books'] ?? [];

        if (empty($books)) {
            Log::warning('No results found in Hardcover API response', [
                'query' => $query,
                'options' => $options,
            ]);

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
            query GetBookDetails($bookId: uuid!) {
                books_by_pk(id: $bookId) {
                    id
                    title
                    subtitle
                    description
                    pages
                    release_date
                    isbn_10
                    isbn_13
                    cover_image_url: cover_image_url(size: LARGE)
                    publisher {
                        name
                    }
                    genres {
                        genre {
                            name
                        }
                    }
                    authors: contributions(where: {role: {_eq: "AUTHOR"}}) {
                        author {
                            id
                            name
                        }
                    }
                    narrators: contributions(where: {role: {_eq: "NARRATOR"}}) {
                        author {
                            id
                            name
                        }
                    }
                }
            }
        ';

        $result = $this->makeRequest($query, ['bookId' => $id]);
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

            $formattedAuthors = $this->formatAuthors($item['authors'] ?? []);

            // Extract simple author names for the 'author' field
            $authorNames = array_map(function($a) {
                return $a['author']['name'] ?? '';
            }, $formattedAuthors);
            $authorNames = array_filter($authorNames);

            $results[] = [
                'source' => 'Hardcover',
                'id' => $item['id'],
                'title' => $item['title'] ?? 'Unknown Title',
                'subtitle' => $item['subtitle'] ?? null,
                'author' => array_values($authorNames), // Simple array of author names
                'authors' => $formattedAuthors, // Nested format
                'publisher' => $item['publisher'] ?? null,
                'published_date' => $item['release_date'] ?? null,
                'publishedYear' => $item['release_date'] ? substr($item['release_date'], 0, 4) : null,
                'description' => $item['description'] ?? null,
                'page_count' => $item['pages'] ?? null,
                'genres' => $item['genres'] ?? [],
                'cover_image_url' => $item['cover_image_url'] ?? null,
                'coverImageUrl' => $item['cover_image_url'] ?? null, // Alias for consistency
                'isbn_10' => $item['isbn_10'] ?? null,
                'isbn_13' => $item['isbn_13'] ?? null,
            ];
        }

        return $results;
    }

    /**
     * Format book details from Hardcover API
     */
    protected function formatBookDetails(array $item): array
    {
        return [
            'id' => $item['id'],
            'title' => $item['title'] ?? 'Unknown Title',
            'subtitle' => $item['subtitle'] ?? null,
            'authors' => $this->formatAuthors($item['authors'] ?? []),
            'narrators' => $this->formatNarrators($item['narrators'] ?? []),
            'publisher' => $item['publisher'] ?? null,
            'published_date' => $item['release_date'] ?? null,
            'description' => $item['description'] ?? null,
            'page_count' => $item['pages'] ?? null,
            'isbn_10' => $item['isbn_10'] ?? null,
            'isbn_13' => $item['isbn_13'] ?? null,
            'cover_image_url' => $item['cover_image_url'] ?? null,
            'genres' => $this->formatGenres($item['genres'] ?? []),
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
            }

            $ext = pathinfo(parse_url($imageUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'jpg';
            $targetPath = rtrim($directoryPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $targetBasename . '.' . $ext;

            $response = Http::timeout(20)->get($imageUrl);
            if (!$response->successful()) {
                return null;
            }

            file_put_contents($targetPath, $response->body());

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
