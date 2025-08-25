<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HardcoverApiService
{
    protected string $apiUrl;

    protected ?string $apiKey;

    public function __construct(?string $apiKey = null, ?string $apiUrl = null)
    {
        $this->apiUrl = $apiUrl ?? config('services.hardcover.api_url', 'https://api.hardcover.app/v1/graphql');
        $this->apiKey = $apiKey ?? config('services.hardcover.api_key');
    }

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
        $bestMatch = null;
        $bestScore = 0;
        foreach ($results as $result) {
            $score = 0;
            if (strcasecmp(trim($result['title']), trim($title)) === 0) {
                $score += 2;
            } elseif (stripos($result['title'], $title) !== false) {
                $score += 1;
            }
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
        $details = null;
        if (!empty($bestMatch['id'])) {
            $details = $this->getBookDetails($bestMatch['id']);
        }
        $merged = [
            'hardcoverId' => $bestMatch['id'] ?? null,
            'title' => $bestMatch['title'] ?? null,
            'description' => $details['description'] ?? $bestMatch['description'] ?? null,
            'coverImage' => $details['cover_image_url'] ?? $bestMatch['cover_image_url'] ?? null,
            'pages' => $details['pages'] ?? $bestMatch['pages'] ?? null,
            'releaseDate' => $details['release_date'] ?? $bestMatch['release_date'] ?? null,
            'isbn_10' => $details['isbn_10'] ?? $bestMatch['isbn_10'] ?? null,
            'isbn_13' => $details['isbn_13'] ?? $bestMatch['isbn_13'] ?? null,
            'publisher' => $details['publisher']['name'] ?? $bestMatch['publisher']['name'] ?? null,
        ];

        return array_filter($merged, fn ($v) => $v !== null);
    }

    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    protected function makeGraphQlRequest(string $query, array $variables = []): ?array
    {
        if (empty($this->apiKey)) {
            Log::error('Hardcover API key not set');

            return null;
        }
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-api-key' => $this->apiKey,
            ])->post($this->apiUrl, [
                        'query' => $query,
                        'variables' => $variables,
                    ]);
            if ($response->failed()) {
                Log::error('Hardcover API request failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('Hardcover API request exception', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

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
