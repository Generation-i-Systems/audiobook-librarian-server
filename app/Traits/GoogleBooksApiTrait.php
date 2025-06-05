<?php

namespace App\Traits;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Trait for interacting with the Google Books API
 */
trait GoogleBooksApiTrait
{
    use BaseApiTrait;

    protected ?string $apiKey = null;

    /**
     * Initialize the Google Books API client
     */
    public function initGoogleBooks(array $config = []): self
    {
        $this->apiKey = $config['api_key'] ?? config('services.google.books_api_key');
        $baseUrl = $config['base_url'] ?? 'https://www.googleapis.com/books/v1';
        $this->setBaseUrl($baseUrl);
        $this->setServiceName('google_books');

        if (empty($this->apiKey)) {
            Log::warning('Google Books API key not configured');
        }

        return $this;
    }

    /**
     * Search for books
     */
    public function searchBooks(string $query, array $options = []): ?array
    {
        $params = [
            'q' => $query,
            'maxResults' => $options['limit'] ?? 10,
            'startIndex' => $options['offset'] ?? 0,
            'printType' => 'books',
            'orderBy' => $options['sort'] ?? 'relevance',
            'key' => $this->apiKey,
        ];

        // Add additional parameters from options
        if (isset($options['author'])) {
            $params['q'] .= "+inauthor:{$options['author']}";
        }

        if (isset($options['title'])) {
            $params['q'] .= "+intitle:{$options['title']}";
        }

        if (isset($options['isbn'])) {
            $params['q'] .= "+isbn:{$options['isbn']}";
        }

        return $this->httpGet('/volumes', $params);
    }

    /**
     * Get book details by volume ID
     */
    public function getBookDetails(string $volumeId): ?array
    {
        return $this->httpGet("/volumes/{$volumeId}", [
            'key' => $this->apiKey,
        ]);
    }

    /**
     * Get books by author
     */
    public function getBooksByAuthor(string $author, int $limit = 10): ?array
    {
        return $this->searchBooks("inauthor:{$author}", ['limit' => $limit]);
    }

    /**
     * Get books by ISBN
     */
    public function getBookByIsbn(string $isbn): ?array
    {
        $result = $this->searchBooks("isbn:{$isbn}", ['limit' => 1]);
        return $result['items'][0] ?? null;
    }

    /**
     * Format the API response to a standard book format
     */
    public function formatBookResponse(array $bookData): array
    {
        $volumeInfo = $bookData['volumeInfo'] ?? [];
        $saleInfo = $bookData['saleInfo'] ?? [];
        $accessInfo = $bookData['accessInfo'] ?? [];

        return [
            'id' => $bookData['id'] ?? null,
            'title' => $volumeInfo['title'] ?? 'Unknown Title',
            'subtitle' => $volumeInfo['subtitle'] ?? null,
            'description' => $volumeInfo['description'] ?? null,
            'published_date' => $volumeInfo['publishedDate'] ?? null,
            'publisher' => $volumeInfo['publisher'] ?? null,
            'language' => $volumeInfo['language'] ?? 'en',
            'isbn' => $this->extractIsbn($volumeInfo['industryIdentifiers'] ?? []),
            'page_count' => $volumeInfo['pageCount'] ?? null,
            'dimensions' => $volumeInfo['dimensions'] ?? null,
            'print_type' => $volumeInfo['printType'] ?? null,
            'categories' => $volumeInfo['categories'] ?? [],
            'average_rating' => $volumeInfo['averageRating'] ?? null,
            'ratings_count' => $volumeInfo['ratingsCount'] ?? null,
            'maturity_rating' => $volumeInfo['maturityRating'] ?? null,
            'content_version' => $volumeInfo['contentVersion'] ?? null,
            'images' => [
                'small_thumbnail' => $volumeInfo['imageLinks']['smallThumbnail'] ?? null,
                'thumbnail' => $volumeInfo['imageLinks']['thumbnail'] ?? null,
                'small' => $volumeInfo['imageLinks']['small'] ?? null,
                'medium' => $volumeInfo['imageLinks']['medium'] ?? null,
                'large' => $volumeInfo['imageLinks']['large'] ?? null,
                'extra_large' => $volumeInfo['imageLinks']['extraLarge'] ?? null,
            ],
            'sale_info' => [
                'country' => $saleInfo['country'] ?? null,
                'saleability' => $saleInfo['saleability'] ?? null,
                'is_ebook' => $saleInfo['isEbook'] ?? false,
                'list_price' => $saleInfo['listPrice'] ?? null,
                'retail_price' => $saleInfo['retailPrice'] ?? null,
                'buy_link' => $saleInfo['buyLink'] ?? null,
            ],
            'access_info' => [
                'country' => $accessInfo['country'] ?? null,
                'viewability' => $accessInfo['viewability'] ?? null,
                'embeddable' => $accessInfo['embeddable'] ?? false,
                'public_domain' => $accessInfo['publicDomain'] ?? false,
                'text_to_speech_permission' => $accessInfo['textToSpeechPermission'] ?? null,
                'epub' => $accessInfo['epub'] ?? [],
                'pdf' => $accessInfo['pdf'] ?? [],
                'web_reader_link' => $accessInfo['webReaderLink'] ?? null,
                'access_view_status' => $accessInfo['accessViewStatus'] ?? null,
                'quote_sharing_allowed' => $accessInfo['quoteSharingAllowed'] ?? false,
            ],
            'search_info' => $bookData['searchInfo'] ?? [],
            'metadata' => [
                'source' => 'google_books',
                'etag' => $bookData['etag'] ?? null,
                'self_link' => $bookData['selfLink'] ?? null,
            ],
        ];
    }

    /**
     * Extract ISBN from industry identifiers
     */
    protected function extractIsbn(array $identifiers): ?string
    {
        foreach ($identifiers as $id) {
            if (isset($id['type']) && $id['type'] === 'ISBN_13') {
                return $id['identifier'];
            }
        }

        foreach ($identifiers as $id) {
            if (isset($id['type']) && $id['type'] === 'ISBN_10') {
                return $id['identifier'];
            }
        }

        return null;
    }

    /**
     * Format search results to a standard format
     */
    public function formatSearchResults(array $apiResponse): array
    {
        if (empty($apiResponse['items'])) {
            return [];
        }

        return array_map(function ($item) {
            $volumeInfo = $item['volumeInfo'] ?? [];

            return [
                'id' => $item['id'] ?? null,
                'title' => $volumeInfo['title'] ?? 'Unknown Title',
                'subtitle' => $volumeInfo['subtitle'] ?? null,
                'authors' => $this->formatAuthors($volumeInfo['authors'] ?? []),
                'publisher' => $volumeInfo['publisher'] ?? null,
                'published_date' => $volumeInfo['publishedDate'] ?? null,
                'description' => $volumeInfo['description'] ?? null,
                'page_count' => $volumeInfo['pageCount'] ?? null,
                'categories' => $volumeInfo['categories'] ?? [],
                'average_rating' => $volumeInfo['averageRating'] ?? null,
                'ratings_count' => $volumeInfo['ratingsCount'] ?? null,
                'cover_image_url' => $volumeInfo['imageLinks']['thumbnail'] ?? null,
                'preview_link' => $volumeInfo['previewLink'] ?? null,
                'info_link' => $volumeInfo['infoLink'] ?? null,
                'canonical_volume_link' => $volumeInfo['canonicalVolumeLink'] ?? null,
                'language' => $volumeInfo['language'] ?? 'en',
                'content_version' => $volumeInfo['contentVersion'] ?? null,
                'metadata' => [
                    'source' => 'google_books',
                    'etag' => $item['etag'] ?? null,
                    'self_link' => $item['selfLink'] ?? null,
                ],
            ];
        }, $apiResponse['items']);
    }
}
