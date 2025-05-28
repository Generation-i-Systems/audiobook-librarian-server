<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\HardcoverTokenExpiring;
use Carbon\Carbon;

class HardcoverService
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
     * Search for books by title and author
     */
    public function searchBooks(string $title, ?string $author = null, int $limit = 5): ?array
    {
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

        return $result['data']['books'] ?? null;
    }

    /**
     * Get book details by ID
     */
    public function getBookDetails(string $bookId): ?array
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

        $result = $this->makeRequest($query, ['bookId' => $bookId]);
        return $result['data']['books_by_pk'] ?? null;
    }

    /**
     * Find a book by title and author
     */
    public function findBookByTitleAndAuthor(string $title, string $author): ?array
    {
        $books = $this->searchBooks($title, $author, 1);
        return $books[0] ?? null;
    }

    /**
     * Get book cover image URL by title and author
     */
    public function getBookCover(string $title, string $author): ?string
    {
        $book = $this->findBookByTitleAndAuthor($title, $author);
        return $book['cover_image_url'] ?? null;
    }

    /**
     * Get book description by title and author
     */
    public function getBookDescription(string $title, string $author): ?string
    {
        $book = $this->findBookByTitleAndAuthor($title, $author);
        return $book['description'] ?? null;
    }

    /**
     * Get book genres by title and author
     */
    public function getBookGenres(string $title, string $author): array
    {
        $book = $this->findBookByTitleAndAuthor($title, $author);

        if (empty($book['genres'])) {
            return [];
        }

        return array_map(fn($genre) => $genre['genre']['name'], $book['genres']);
    }
}
