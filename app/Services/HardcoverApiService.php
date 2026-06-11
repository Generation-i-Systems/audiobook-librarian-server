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
        $this->apiUrl = $apiUrl ?? config('hardcover.api_url', 'https://api.hardcover.app/v1/graphql');
        $this->apiKey = $apiKey ?? config('hardcover.api_token');
    }

    public function searchAndMerge(array $book): ?array
    {
        $title = $book['title'] ?? null;
        $authors = $book['authors'] ?? [];
        if (!$title) {
            return null;
        }
        $limit = 5;
        $firstAuthor = !empty($authors) ? $authors[0] : null;
        $results = $this->searchBooksByTitle($title, $limit, $firstAuthor);
        if (!$results || empty($results)) {
            return null;
        }
        // Hardcover's search engine already ranks results by relevance, so use the first hit as
        // the default and only promote a later result if it scores strictly higher.
        $bestMatch = $results[0];
        $bestScore = 0;
        foreach ($results as $result) {
            $score = 0;
            if (strcasecmp(trim($result['title'] ?? ''), trim($title)) === 0) {
                $score += 2;
            } elseif (stripos($result['title'] ?? '', $title) !== false) {
                $score += 1;
            }
            if (!empty($authors) && !empty($result['authors'])) {
                foreach ($authors as $inputAuthor) {
                    foreach ($result['authors'] as $authorObj) {
                        $authorName = is_array($authorObj['author'] ?? null) ? ($authorObj['author']['name'] ?? '') : ($authorObj['author'] ?? '');
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
        $details = null;
        if (!empty($bestMatch['id'])) {
            $details = $this->getBookDetails($bestMatch['id']);
        }

        // Genres: try taggings from details, then book_category, then search document
        $genreNames = [];
        if (!empty($details['taggings'])) {
            $genreNames = array_values(array_filter(array_map(
                fn ($t) => $t['tag']['tag'] ?? null,
                $details['taggings']
            )));
        }
        if (empty($genreNames) && !empty($details['book_category']['category'])) {
            $genreNames = [$details['book_category']['category']];
        }

        $merged = [
            'hardcoverId' => $bestMatch['id'] ?? null,
            'title'       => $bestMatch['title'] ?? null,
            'description' => $details['description'] ?? $bestMatch['description'] ?? null,
            'coverImage'  => $details['image']['url'] ?? $bestMatch['cover_image_url'] ?? null,
            'pages'       => $details['pages'] ?? $bestMatch['pages'] ?? null,
            'releaseDate' => $details['release_date'] ?? $bestMatch['release_date'] ?? null,
            'genres'      => $genreNames ?: null,
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
            Log::error('HardcoverApiService: API key not configured', [
                'message' => 'HARDCOVER_API_KEY environment variable is not set',
                'hint' => 'Add HARDCOVER_API_KEY to your .env file',
            ]);

            return null;
        }
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->apiKey,
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

            $json = $response->json();
            if (!empty($json['errors'])) {
                Log::error('Hardcover API GraphQL error', ['errors' => $json['errors']]);
                return null;
            }
            return $json;
        } catch (\Exception $e) {
            Log::error('HardcoverApiService: API request exception', [
                'query' => substr($query, 0, 200), // Truncate query for logging
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    public function searchBooksByTitle(string $title, int $limit = 10, ?string $author = null): ?array
    {
        $query = '
            query SearchBooks($query: String!, $limit: Int!) {
                search(query: $query, query_type: "Book", per_page: $limit) {
                    results
                }
            }
        ';
        $searchQuery = $author ? "{$title} {$author}" : $title;
        $result = $this->makeGraphQlRequest($query, [
            'query' => $searchQuery,
            'limit' => $limit,
        ]);

        // Response: data.search.results.hits[].document
        $hits = $result['data']['search']['results']['hits'] ?? [];
        if (empty($hits)) {
            return null;
        }

        $books = [];
        foreach ($hits as $hit) {
            $doc = $hit['document'] ?? $hit;
            $books[] = [
                'id'             => $doc['id'] ?? null,
                'title'          => $doc['title'] ?? null,
                'description'    => $doc['description'] ?? null,
                'cover_image_url' => $doc['image']['url'] ?? null,
                'release_date'   => $doc['release_date'] ?? null,
                'pages'          => $doc['pages'] ?? null,
                'authors'        => array_map(
                    fn ($name) => ['author' => ['name' => $name]],
                    $doc['author_names'] ?? []
                ),
                // genres is empty in search index — getBookDetails fills this
                'genres'         => [],
            ];
        }

        return $books;
    }

    public function getBookDetails(string|int $bookId): ?array
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
                }
            }
        ';
        $result = $this->makeGraphQlRequest($query, ['bookId' => (int) $bookId]);

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
