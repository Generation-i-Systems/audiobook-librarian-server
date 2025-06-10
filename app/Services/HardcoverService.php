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
                'Authorization' => 'Bearer '.$this->apiToken,
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
        if (! $this->tokenExpiresAt) {
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
        return 'hardcover';
    }

    /**
     * {@inheritDoc}
     */
    protected function performSearch(string $query, array $options = []): ?array
    {
        $title = $query;
        $author = $options['author'] ?? null;
        $limit = $options['limit'] ?? 5;
        $query = '
            query SearchBooks($title: String!, $author: String, $limit: Int!) {
                books(
                    where: {
                        title: {_ilike: $title}
                        _or: [
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
                        }
                    }
                }
            }
        ';

        $variables = [
            'title' => "%$title%",
            'author' => $author ? "%$author%" : null,
            'limit' => $limit,
        ];

        \Illuminate\Support\Facades\Log::debug('Making search request', [
            'query' => $query,
            'variables' => $variables,
        ]);

        $result = $this->makeRequest($query, $variables);

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

        if (! $book) {
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

            $results[] = [
                'id' => $item['id'],
                'title' => $item['title'] ?? 'Unknown Title',
                'subtitle' => $item['subtitle'] ?? null,
                'authors' => $this->formatAuthors($item['authors'] ?? []),
                'publisher' => $item['publisher'] ?? null,
                'published_date' => $item['release_date'] ?? null,
                'description' => $item['description'] ?? null,
                'page_count' => $item['pages'] ?? null,
                'genres' => $item['genres'] ?? [],
                'cover_image_url' => $item['cover_image_url'] ?? null,
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
}

// Check if the service is available
public function isAvailable(): bool
{
    return !empty($this->apiToken) && !empty($this->apiUrl);
}

/**
     * Search books by title (public API)
     */
    public function searchBooks(string $title, array $options = []): ?array
    {
        return $this->performSearch($title, $options);
    }

    /**
     * Get book details by ID (public API)
     */
    public function getBookDetails(string $id): ?array
    {
        return $this->performGetBookDetails($id);
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
}
