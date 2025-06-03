<?php

namespace App\Traits;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Trait for interacting with the Hardcover.app GraphQL API
 */
trait HardcoverApiTrait
{
    /**
     * Attempt to look up the book in Hardcover and return additional metadata.
     *
     * @param array $book
     * @return array|null
     */
    /**
     * Attempt to look up the book in Hardcover and return additional metadata.
     *
     * @param array $book
     * @return array|null
     */
    public function searchAndMerge(array $book): ?array
    {
        $title = $book['title'] ?? null;
        $authors = $book['authors'] ?? [];
        if (!$title) {
            return null;
        }
        $limit = 5;
        $results = $this->searchBooksByTitle($title, $limit);
        if (!$results || empty($results)) {
            return null;
        }

        // Try to find the best match by title and author similarity
        $bestMatch = null;
        $bestScore = 0;
        foreach ($results as $result) {
            $score = 0;
            // Title similarity (case-insensitive, normalized)
            if (strcasecmp(trim($result['title']), trim($title)) === 0) {
                $score += 2;
            } elseif (stripos($result['title'], $title) !== false) {
                $score += 1;
            }
            // Author match (if available)
            if (!empty($authors) && !empty($result['authors'])) {
                foreach ($authors as $inputAuthor) {
                    foreach ($result['authors'] as $authorObj) {
                        $authorName = is_array($authorObj['author'] ?? null) ? $authorObj['author']['name'] ?? '' : ($authorObj['author'] ?? '');
                        if ($authorName && stripos($authorName, $inputAuthor) !== false) {
                            $score += 2;
                            break 2;
                        }
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

        // Optionally fetch more details by ID (if available)
        $details = null;
        if (!empty($bestMatch['id'])) {
            $details = $this->getBookDetails($bestMatch['id']);
        }
        $merged = [
            'hardcover_id' => $bestMatch['id'] ?? null,
            'title' => $bestMatch['title'] ?? null,
            'description' => $details['description'] ?? $bestMatch['description'] ?? null,
            'cover_image' => $details['cover_image_url'] ?? $bestMatch['cover_image_url'] ?? null,
            'pages' => $details['pages'] ?? $bestMatch['pages'] ?? null,
            'release_date' => $details['release_date'] ?? $bestMatch['release_date'] ?? null,
            'isbn_10' => $details['isbn_10'] ?? $bestMatch['isbn_10'] ?? null,
            'isbn_13' => $details['isbn_13'] ?? $bestMatch['isbn_13'] ?? null,
            'publisher' => $details['publisher']['name'] ?? $bestMatch['publisher']['name'] ?? null,
        ];
        // Remove nulls
        return array_filter($merged, fn($v) => $v !== null);
    }

    /**
     * Base URL for the Hardcover API
     *
     * @var string
     */
    protected string $hardcoverApiUrl = 'https://api.hardcover.app/v1/graphql';

    /**
     * API key for authentication
     * 
     * @var string|null
     */
    protected ?string $hardcoverApiKey = null;

    /**
     * Set the API key for authentication
     * 
     * @param string $apiKey
     * @return void
     */
    public function setHardcoverApiKey(string $apiKey): void
    {
        $this->hardcoverApiKey = $apiKey;
    }

    /**
     * Make a GraphQL request to the Hardcover API
     * 
     * @param string $query
     * @param array $variables
     * @return array|null
     */
    protected function makeGraphQlRequest(string $query, array $variables = []): ?array
    {
        if (empty($this->hardcoverApiKey)) {
            Log::error('Hardcover API key not set');
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->hardcoverApiKey,
            ])->post($this->hardcoverApiUrl, [
                'query' => $query,
                'variables' => $variables,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Hardcover API request failed', [
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error('Hardcover API request exception', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Search for books by title
     * 
     * @param string $title
     * @param int $limit
     * @return array|null
     */
    public function searchBooksByTitle(string $title, int $limit = 10): ?array
    {
        $query = '
            query SearchBooks($title: String!, $limit: Int!) {
                books(where: {title: {_ilike: $title}}, limit: $limit) {
                    id
                    title
                    pages
                    release_date
                    description
                    cover_image_url: cover_image_url(size: MEDIUM)
                    authors: contributions(where: {role: {_eq: "AUTHOR"}}) {
                        author {
                            name
                        }
                    }
                }
            }
        ';

        $result = $this->makeGraphQlRequest($query, [
            'title' => "%$title%",
            'limit' => $limit,
        ]);

        return $result['data']['books'] ?? null;
    }

    /**
     * Get book details by ID
     * 
     * @param string $bookId
     * @return array|null
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
                    editions {
                        id
                        title
                        edition_format
                        pages
                        release_date
                        isbn_10
                        isbn_13
                        publisher {
                            name
                        }
                    }
                }
            }
        ';

        $result = $this->makeGraphQlRequest($query, ['bookId' => $bookId]);
        return $result['data']['books_by_pk'] ?? null;
    }

    /**
     * Get books by author
     * 
     * @param string $authorName
     * @param int $limit
     * @return array|null
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

        $result = $this->makeGraphQlRequest($query, [
            'authorName' => $authorName,
            'limit' => $limit,
        ]);

        return $result['data']['books'] ?? null;
    }
}
