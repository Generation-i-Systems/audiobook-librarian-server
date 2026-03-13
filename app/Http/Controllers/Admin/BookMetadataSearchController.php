<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AudibleService;
use App\Services\AudiobookBayService;
use App\Services\HardcoverService;
use App\Traits\BookImportTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BookMetadataSearchController extends Controller
{
    use BookImportTrait;

    protected AudibleService $audibleService;

    public function __construct(
        \App\Services\GoogleBooksApiService $googleBooksApiService,
        AudibleService $audibleService
    ) {
        $this->setGoogleBooksApiService($googleBooksApiService);
        $this->audibleService = $audibleService;
    }

    /**
     * Search Google Books for books matching the given criteria.
     *
     * @deprecated Use searchBooks with source=googlebooks instead
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function googleBooks(Request $request)
    {
        $title = $request->query('title');
        $author = $request->query('author', '');
        $series = $request->query('series', '');
        $seriesNumber = $request->query('seriesNumber', '');
        if (!$title) {
            return response()->json([
                'error' => 'Title is required.',
            ], 400);
        }

        $limit = min((int) $request->query('limit', 10), 40); // Default 10, max 40

        Log::info('googleBooks search called', [
            'title' => $title,
            'author' => $author,
            'series' => $series,
            'seriesNumber' => $seriesNumber,
        ]);

        try {
            // Build the search query
            // Ensure we're properly formatting the author query parameter
            $authorQuery = '';
            if (!empty($author)) {
                // Properly format author name for the API query
                $authorQuery = " inauthor:\"{$author}\"";
                Log::debug('Adding author to Google Books query', ['author' => $author, 'authorQuery' => $authorQuery]);
            }
            $query = trim("intitle:\"{$title}\"" . $authorQuery);
            Log::debug('Google Books search query', ['query' => $query]);

            // Search Google Books API
            $results = $this->googleBooksApiService->searchBooks($query, ['limit' => $limit]);

            Log::info('googleBooks search results', ['count' => count($results)]);

            return response()->json($results);
        } catch (\Exception $e) {
            Log::error('googleBooks search failed', [
                'error' => $e->getMessage(),
                'title' => $title,
                'author' => $author,
            ]);

            return response()->json([
                'error' => 'Google Books search failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Unified search endpoint for all book APIs (Audible, Google Books, etc.)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function searchBooks(Request $request)
    {
        // Use input() so tests passing params in body (not query) still work
        $title = $request->input('title');
        $author = $request->input('author', '');
        $apiId = $request->input('api_id', '');
        $source = $request->input('source', 'audible'); // Default to audible if not specified
        $series = $request->input('series', '');
        $seriesNumber = $request->input('seriesNumber', '');
        $limit = min((int) $request->input('limit', 10), 40); // Default 10, max 40

        // Debug all request parameters
        Log::debug('Book search request parameters', [
            'all_params' => $request->all(),
            'query_params' => $request->query(),
            'title' => $title,
            'author' => $author,
            'author_type' => gettype($author),
            'author_empty' => empty($author),
            'api_id' => $apiId,
            'source' => $source,
        ]);

        // Validate required parameters with source-specific flexibility
        if (!$apiId) {
            $requiresTitle = true;
            if (strtolower($source) === 'googlebooks' && !empty($author)) {
                // Allow author-only search for Google Books (test expectation)
                $requiresTitle = false;
            }
            if ($requiresTitle && empty($title)) {
                return response()->json([
                    'error' => 'Title or API ID is required.',
                ], 400);
            }
        }

        Log::info('book search called', [
            'source' => $source,
            'title' => $title,
            'author' => $author,
            'api_id' => $apiId,
            'series' => $series,
            'seriesNumber' => $seriesNumber,
        ]);

        Log::debug('SearchBooks: About to enter switch statement', [
            'source_lower' => strtolower($source),
            'has_apiId' => !empty($apiId),
        ]);

        try {
            $results = [];

            switch (strtolower($source)) {
                case 'audible':
                    Log::debug('SearchBooks: Entered audible case', ['apiId' => $apiId]);
                    if ($apiId) {
                        $bookDetails = $this->audibleService->getBookDetails($apiId);
                        if ($bookDetails) {
                            $results[] = $bookDetails; // Already transformed by the service
                        }
                    } else {
                        Log::debug('SearchBooks: About to call searchBooksWithFiltering');
                        // Debug Audible search parameters
                        Log::debug('Audible search parameters', [
                            'title' => $title,
                            'author' => $author,
                            'author_empty' => empty($author),
                            'limit' => $limit,
                        ]);

                        // Make sure author is properly handled
                        $authorParam = !empty($author) ? $author : null;

                        $results = $this->audibleService->searchBooksWithFiltering($title, $authorParam, [
                            'limit' => $limit,
                        ]);
                        Log::debug('SearchBooks: searchBooksWithFiltering completed', ['results_count' => count($results)]);
                    }
                    break;

                case 'googlebooks':
                    // Build the search query for Google Books
                    $authorQuery = '';
                    if (!empty($author)) {
                        // Properly format author name for the API query with quotes
                        $authorQuery = " inauthor:\"{$author}\"";
                        Log::debug('Adding author to Google Books query', [
                            'author' => $author,
                            'authorQuery' => $authorQuery,
                            /** @phpstan-ignore-next-line empty.variable */
                            'author_empty' => empty($author),
                        ]);
                    }

                    // Ensure title is properly quoted; allow author-only queries
                    $titleQuery = !empty($title) ? "intitle:\"{$title}\"" : '';
                    $query = trim($titleQuery . $authorQuery);

                    Log::debug('Google Books search query', ['query' => $query]);
                    $results = $this->googleBooksApiService->searchBooks($query, ['limit' => $limit]);
                    break;

                case 'audiobookbay':
                    $audiobookBayService = app(AudiobookBayService::class);
                    if ($apiId) {
                        $bookDetails = $audiobookBayService->getBookDetails($apiId);
                        if ($bookDetails) {
                            $results[] = $bookDetails;
                        }
                    } else {
                        $searchQuery = trim($title . ' ' . $author);
                        $searchResults = $audiobookBayService->searchBooks($searchQuery, ['limit' => $limit]);
                        $results = is_array($searchResults) ? $searchResults : [];
                    }
                    break;

                case 'hardcover':
                    $hardcoverService = app(HardcoverService::class);
                    if (!$hardcoverService->isAvailable()) {
                        return response()->json([
                            'error' => 'Hardcover service is not configured. Please set HARDCOVER_API_KEY in your .env file.',
                        ], 400);
                    }

                    if ($apiId) {
                        $bookDetails = $hardcoverService->getBookDetails($apiId);
                        if ($bookDetails) {
                            $results[] = $bookDetails;
                        }
                    } else {
                        $searchResults = $hardcoverService->searchBooks($title, [
                            'author' => $author,
                            'limit' => $limit,
                        ]);
                        $results = is_array($searchResults) ? $searchResults : [];
                    }
                    break;

                default:
                    return response()->json([
                        'error' => 'Invalid source specified. Supported sources: audible, googlebooks, audiobookbay, hardcover',
                    ], 400);
            }

            Log::info($source . ' search results: ' . count($results) . ' items');

            return response()->json($results);
        } catch (\Exception $e) {
            Log::error($source . ' search failed: ' . $e->getMessage(), [
                'exception' => $e,
                'source' => $source,
                'title' => $title,
                'author' => $author,
                'api_id' => $apiId,
            ]);

            return response()->json([
                'error' => ucfirst($source) . ' search failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search Audible for books matching the given criteria.
     *
     * @deprecated Use searchBooks with source=audible instead
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function audible(Request $request)
    {
        $title = $request->query('title');
        $author = $request->query('author', '');
        $apiId = $request->query('api_id', '');

        if (!$title && !$apiId) {
            return response()->json([
                'error' => 'Title or ASIN is required.',
            ], 400);
        }

        $limit = min((int) $request->query('limit', 10), 40); // Default 10, max 40

        Log::info('audible search called', [
            'title' => $title,
            'author' => $author,
            'api_id' => $apiId,
        ]);

        try {
            $results = [];

            // If ASIN is provided, get specific book details
            if ($apiId) {
                $bookDetails = $this->audibleService->getBookDetails($apiId);
                if ($bookDetails) {
                    // If we found a book by ASIN, return it directly as a single object
                    return response()->json($bookDetails);
                }
            } else {
                // Otherwise search by title/author using the service method that handles filtering and fallback
                $results = $this->audibleService->searchBooksWithFiltering($title, $author, [
                    'limit' => $limit,
                ]);
            }

            Log::info('audible search results: ' . count($results) . ' items');

            return response()->json($results);
        } catch (\Exception $e) {
            Log::error('audible search failed: ' . $e->getMessage(), [
                'exception' => $e,
                'title' => $title,
                'author' => $author,
                'api_id' => $apiId,
            ]);

            return response()->json([
                'error' => 'Audible search failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Format Audible API result for the frontend.
     *
     * @param  array  $book  The book data from Audible API
     * @return array Formatted book data
     */
    protected function formatAudibleResult(array $book): array
    {
        // Start with the original data
        $result = $book;

        // Ensure we have the source field
        $result['source'] = 'Audible';

        // Keep the ID as 'id' instead of renaming to 'audibleId'
        if (isset($book['asin'])) {
            $result['id'] = $book['asin'];
            // Remove the original asin field to avoid duplication
            unset($result['asin']);
        }

        // Ensure we have a properly named cover image URL
        if (isset($book['image_url'])) {
            $result['audibleCoverImageUrl'] = $book['image_url'];
            unset($result['image_url']);
        }

        // Ensure we have a properly formatted published year

        // Use audibleAuthors field for author if available
        if (isset($book['audibleAuthors'])) {
            if (is_string($book['audibleAuthors'])) {
                $result['author'] = [$book['audibleAuthors']];
            } elseif (is_array($book['audibleAuthors'])) {
                $result['author'] = $book['audibleAuthors'];
            } else {
                $result['author'] = [];
            }
        } elseif (isset($book['author'])) {
            // Fall back to author field if audibleAuthors is not available
            if (is_string($book['author'])) {
                $result['author'] = [$book['author']];
            } elseif (is_array($book['author'])) {
                $result['author'] = $book['author'];
            } else {
                $result['author'] = [];
            }
        } else {
            $result['author'] = [];
        }

        // Ensure narrator is properly formatted
        if (isset($book['narrator'])) {
            if (is_array($book['narrator'])) {
                $result['narrator'] = $book['narrator'];
            } elseif (is_string($book['narrator'])) {
                $result['narrator'] = [$book['narrator']];
                /** @phpstan-ignore-next-line elseif.alwaysFalse */
            } elseif (is_string($book['narrator'])) {
                $result['narrator'] = [$book['narrator']];
            } else {
                $result['narrator'] = [];
            }
        } else {
            $result['narrator'] = [];
        }

        // Ensure series is properly formatted
        if (isset($book['series']) && is_array($book['series'])) {
            $seriesNames = array_keys($book['series']);
            if (!empty($seriesNames)) {
                $seriesName = $seriesNames[0];
                $seriesNumber = $book['series'][$seriesName] ?? '';
                $result['seriesName'] = $seriesName;
                $result['seriesNumber'] = $seriesNumber;
                $result['series'] = $seriesName; // For compatibility with Google Books format
            }
        } else {
            $result['seriesName'] = '';
            $result['seriesNumber'] = '';
            $result['series'] = '';
        }

        // Add category field if missing
        if (!isset($result['category'])) {
            $result['category'] = isset($book['categories']) ? $book['categories'] : [];
        }

        // Format publisher as an array
        if (isset($book['publisher'])) {
            if (is_string($book['publisher'])) {
                $result['publisher'] = [$book['publisher']];
            } elseif (is_array($book['publisher'])) {
                $result['publisher'] = $book['publisher'];
            } else {
                $result['publisher'] = [];
            }
        } else {
            $result['publisher'] = [];
        }

        // Add any other missing fields that Google Books has
        if (!isset($result['description'])) {
            $result['description'] = $book['summary'] ?? '';
        }

        // Convert all keys to camelCase for consistency with Google Books
        $camelCaseResult = [];
        foreach ($result as $key => $value) {
            // Convert snake_case to camelCase
            $camelKey = preg_replace_callback('/_([a-z])/', function ($matches) {
                return strtoupper($matches[1]);
            }, $key);

            $camelCaseResult[$camelKey] = $value;
        }

        return $camelCaseResult;
    }
}
