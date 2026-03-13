<?php

namespace App\Http\Controllers\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Api\Traits\BookTransformTrait;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BookApiController extends Controller
{
    use BookTransformTrait;

    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }

    /**
     * Batch fetch book metadata
     */
    public function batch(Request $request)
    {
        $validated = $request->validate([
            'bookIds' => 'nullable|array',
            'bookIds.*' => 'integer',
            'queries' => 'nullable|array',
            'queries.*.title' => 'required_with:queries|string',
            'queries.*.author' => 'nullable|string',
        ]);

        $bookIds = $validated['bookIds'] ?? [];
        $queries = $validated['queries'] ?? [];

        $results = [];
        $processedIds = [];

        // 1. Fetch by IDs
        if (!empty($bookIds)) {
            $books = \App\Models\Book::whereIn('id', $bookIds)->get();
            foreach ($books as $book) {
                // @phpstan-ignore-next-line
                $results[] = $this->transformBookForBatch($book);
                $processedIds[] = $book->id;
            }

            // Find missing IDs in ClientBooks
            $missingIds = array_diff($bookIds, $processedIds);
            if (!empty($missingIds)) {
                $clientBooks = \App\Models\ClientBook::whereIn('id', $missingIds)->get();
                foreach ($clientBooks as $clientBook) {
                    $results[] = $this->transformClientBookForBatch($clientBook);
                }
            }
        }

        // 2. Process Queries
        foreach ($queries as $query) {
            $title = $query['title'];
            $author = $query['author'] ?? null;

            // Try main books
            $q = \App\Models\Book::where('title', 'LIKE', $title);
            if ($author) {
                $q->whereHas('authors', function ($sq) use ($author) {
                    $sq->where('name', 'LIKE', "%{$author}%");
                });
            }
            $book = $q->first();

            if ($book) {
                // Avoid duplicates if we already found it by ID
                if (!in_array($book->id, $processedIds)) {
                    // @phpstan-ignore-next-line
                    $results[] = $this->transformBookForBatch($book);
                    $processedIds[] = $book->id;
                }
                continue;
            }

            // Try Client Books
            $q = \App\Models\ClientBook::where('title', $title);
            if ($author) {
                $q->where('author', $author);
            }
            $clientBook = $q->first();

            if ($clientBook) {
                $results[] = $this->transformClientBookForBatch($clientBook);
            }
        }

        return response()->json($results);
    }

    private function transformBookForBatch($book)
    {
        return $this->getBookWithCover($book->toArray(), true, false);
    }

    private function transformClientBookForBatch($clientBook)
    {
        return [
            'id' => $clientBook->id,
            'title' => $clientBook->title,
            'author' => $clientBook->author,
            'coverImage' => $clientBook->cover_url, // Map to expected field
            'is_client_book' => true,
            'source' => 'client',
        ];
    }

    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $page = (int) $request->input('page', 1);
        $withCover = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);
        $includeNeedsReview = $request->boolean('includeNeedsReview', $request->boolean('include_needs_review', false));

        $filters = [
            'search' => $request->input('search'),
            'genre' => $request->input('genre'),
            'author' => $request->input('author'),
            'series' => $request->input('series'),
            'title' => $request->input('title'),
            'publication_date' => $request->input('publication_date'),
            'date_added' => $request->input('date_added'),
            'status' => $request->input('status'),
            'is_recommended' => $request->has('is_recommended') ? $request->boolean('is_recommended') : null,
            'is_completed' => $request->has('is_completed') ? $request->boolean('is_completed') : null,
            'device_id' => $request->input('device_id'),
        ];

        $sort = $request->input('sort', 'title');
        $order = $request->input('order', 'asc');

        // Respect includeNeedsReview override
        $filters['include_needs_review'] = $includeNeedsReview;
        $userId = Auth::id();

        $booksData = $this->documentStoreService->listBooks(
            $page,
            $perPage,
            $filters,
            true,
            $sort,
            $order,
            false,
            $userId
        );

        // Transform books to match OpenAPI spec
        $transformedBooks = [];
        if (isset($booksData['data']) && is_array($booksData['data'])) {
            $booksArray = array_filter($booksData['data'], 'is_array');
            // If not including needs_review, filter them out here as a safety net
            if (!$includeNeedsReview) {
                $booksArray = array_filter($booksArray, function ($book) {
                    return empty($book['needs_review']);
                });
            }

            $transformedBooks = array_map(function ($book) use ($withCover, $inlineCovers) {
                return $this->getBookWithCover($book, $withCover, $inlineCovers);
            }, $booksArray);
        }

        if ($booksData['total'] > 0) {
            $from = (($page - 1) * $perPage) + 1;
        } else {
            $from = null;
        }

        // @phpstan-ignore-next-line
        $total = (int) $booksData['total'] ?? 0;
        $to = ($total > 0) ? min($page * $perPage, $total) : null;
        $lastPage = $booksData['lastPage'] ?? max(1, ceil($total / $perPage));

        return response()->json([
            'data' => $transformedBooks,
            'meta' => [
                'current_page' => $booksData['currentPage'] ?? $page,
                'from' => $from,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'to' => $to,
                'total' => $total,
            ],
        ]);
    }


    public function show($id, Request $request)
    {
        $userId = Auth::id();
        $book = $this->documentStoreService->getBook($id, $userId);

        if (!$book) {
            return response()->json([
                'error' => 'Book not found',
                'message' => 'The specified book could not be found',
            ], 404);
        }

        // Hide needs_review books unless explicitly requested
        $includeNeedsReview = $request->boolean('includeNeedsReview', $request->boolean('include_needs_review', false));
        $isNeedsReview = !empty($book['needs_review']) || !empty($book['needsReview']);
        if ($isNeedsReview && !$includeNeedsReview) {
            return response()->json([
                'error' => 'Book not available',
                'message' => 'This book is pending review',
            ], 404);
        }

        $withCover = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);

        // Transform book to match OpenAPI spec format
        return response()->json($this->getBookWithCover($book, $withCover, $inlineCovers));
    }

    public function browse(Request $request)
    {
        $type = $request->input('type'); // 'genre', 'author', 'series'
        $perPage = $request->input('per_page', 100);
        $search = $request->input('search');
        $since = $request->input('since') ? (int) $request->input('since') : null;

        $items = match ($type) {
            'genre' => $this->documentStoreService->listGenres($since),
            'author' => $this->documentStoreService->listAuthors($since),
            'series' => $this->documentStoreService->listSeries($since),
            default => null,
        };

        if ($items === null) {
            return response()->json([
                'error' => 'Invalid browse type',
                'message' => 'The browse type must be one of: genre, author, series',
            ], 400);
        }

        if ($search) {
            $items = array_filter($items, function ($item) use ($search) {
                return stripos($item['name'], $search) !== false;
            });
        }
        $items = array_values($items);
        $total = count($items);
        $page = (int) $request->input('page', 1);
        $paginatedItems = array_slice($items, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'data' => $paginatedItems,
            'meta' => [
                'current_page' => $page,
                'from' => ($total > 0) ? (($page - 1) * $perPage) + 1 : null,
                'last_page' => max(1, ceil($total / $perPage)),
                'per_page' => $perPage,
                'to' => ($total > 0) ? min($page * $perPage, $total) : null,
                'total' => $total,
                'since' => $since, // Echo back the since parameter
                'timestamp' => time(), // Current server timestamp for client to use next time
            ],
        ]);
    }


    public function search(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $page = (int) $request->input('page', 1);
        $withCover = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);
        $includeNeedsReview = $request->boolean('includeNeedsReview', $request->boolean('include_needs_review', false));

        $filters = [
            'search' => $request->input('search'),
            'title' => $request->input('title'),
            'author' => $request->input('author'),
            'series' => $request->input('series'),
            'publication_date' => $request->input('publication_date'),
            'date_added' => $request->input('date_added'),
            'status' => $request->input('status'),
            'is_recommended' => $request->has('is_recommended') ? $request->boolean('is_recommended') : null,
            'is_completed' => $request->has('is_completed') ? $request->boolean('is_completed') : null,
            'device_id' => $request->input('device_id'),
        ];

        $filters['include_needs_review'] = $includeNeedsReview;
        $userId = Auth::id();

        $booksData = $this->documentStoreService->listBooks($page, $perPage, $filters, true, 'title', 'asc', false, $userId);
        $books = $booksData['data'];
        $total = $booksData['total'];

        // Transform books to match OpenAPI spec
        $transformedBooks = array_map(function ($book) use ($withCover, $inlineCovers) {
            return $this->getBookWithCover($book, $withCover, $inlineCovers);
        }, $books);

        return response()->json([
            'data' => $transformedBooks,
            'meta' => [
                'current_page' => $page,
                'from' => ($total > 0) ? (($page - 1) * $perPage) + 1 : null,
                'last_page' => max(1, ceil($total / $perPage)),
                'per_page' => $perPage,
                'to' => ($total > 0) ? min($page * $perPage, $total) : null,
                'total' => $total,
            ],
        ]);
    }

    /**
     * Enhanced books endpoint with proper SQL-based filtering
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function booksEnhanced(Request $request)
    {
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 15)));
        $withCover = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);

        // Filtering parameters
        $filters = [
            'genre_id' => $request->input('genre_id'),
            'genre' => $request->input('genre_name'),
            'author_id' => $request->input('author_id'),
            'author' => $request->input('author_name'),
            'series_id' => $request->input('series_id'),
            'series' => $request->input('series_name'),
            'search' => $request->input('search'),
            'status' => $request->input('status'),
            'is_recommended' => $request->has('is_recommended') ? $request->boolean('is_recommended') : null,
            'is_completed' => $request->has('is_completed') ? $request->boolean('is_completed') : null,
            'device_id' => $request->input('device_id'),
        ];

        // Map enhanced sort names to internal sort names
        $sortMap = [
            'title_asc' => ['title', 'asc'],
            'title_desc' => ['title', 'desc'],
            'created_at_asc' => ['created_at', 'asc'],
            'created_at_desc' => ['created_at', 'desc'],
            'author_asc' => ['author', 'asc'],
            'author_desc' => ['author', 'desc'],
            'progress_asc' => ['progress', 'asc'],
            'progress_desc' => ['progress', 'desc'],
            'last_listened_asc' => ['last_listened', 'asc'],
            'last_listened_desc' => ['last_listened', 'desc'],
            'queue_order_asc' => ['queue_order', 'asc'],
            'queue_order_desc' => ['queue_order', 'desc'],
        ];

        $sortParam = $request->input('sort', 'title_asc');
        $sortConfig = $sortMap[$sortParam] ?? ['title', 'asc'];

        $sort = $sortConfig[0];
        $order = $sortConfig[1];
        $userId = Auth::id();

        $booksData = $this->documentStoreService->listBooks(
            $page,
            $perPage,
            $filters,
            true,
            $sort,
            $order,
            false,
            $userId
        );

        $total = $booksData['total'];
        $books = $booksData['data'];

        // Transform books to match API spec
        $transformedBooks = array_map(function ($book) use ($withCover, $inlineCovers) {
            $result = $this->getBookWithCover($book, $withCover, $inlineCovers);

            // Add full relationship objects for the enhanced endpoint
            // listBooks returns arrays with specific keys for these relationships
            if (isset($book['authors_data'])) {
                $result['authors'] = $book['authors_data'];
            } elseif (isset($book['authors'])) {
                $result['authors'] = $book['authors'];
            }

            if (isset($book['genres_data'])) {
                $result['genres'] = $book['genres_data'];
            } elseif (isset($book['genres'])) {
                $result['genres'] = $book['genres'];
            }

            if (isset($book['series_data'])) {
                $result['series'] = $book['series_data'];
            } elseif (isset($book['series'])) {
                $result['series'] = $book['series'];
            }

            if (isset($book['narrators_data'])) {
                $result['narrators'] = $book['narrators_data'];
            } elseif (isset($book['narrators'])) {
                $result['narrators'] = $book['narrators'];
            }

            return $result;
        }, $books);

        // Calculate pagination info
        $totalPages = ceil($total / $perPage);
        $hasNext = $page < $totalPages;
        $hasPrev = $page > 1;

        return response()->json([
            'books' => $transformedBooks,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_next' => $hasNext,
                'has_prev' => $hasPrev,
            ],
        ]);
    }
}
