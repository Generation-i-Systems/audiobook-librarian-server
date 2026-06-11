<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Api\Traits\BookTransformTrait;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
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
    public function batch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bookIds'          => 'nullable|array',
            'bookIds.*'        => 'integer',
            'queries'          => 'nullable|array',
            'queries.*.title'  => 'required_with:queries|string',
            'queries.*.author' => 'nullable|string',
        ]);

        $bookIds = $validated['bookIds'] ?? [];
        $queries = $validated['queries'] ?? [];

        $results = [];
        $processedIds = [];

        if (!empty($bookIds)) {
            $books = \App\Models\Book::whereIn('id', $bookIds)->get();
            foreach ($books as $book) {
                // @phpstan-ignore-next-line
                $results[] = $this->transformBookForBatch($book);
                $processedIds[] = $book->id;
            }

            $missingIds = array_diff($bookIds, $processedIds);
            if (!empty($missingIds)) {
                $clientBooks = \App\Models\ClientBook::whereIn('id', $missingIds)->get();
                foreach ($clientBooks as $clientBook) {
                    $results[] = $this->transformClientBookForBatch($clientBook);
                }
            }
        }

        foreach ($queries as $query) {
            $title  = $query['title'];
            $author = $query['author'] ?? null;

            $q = \App\Models\Book::where('title', 'LIKE', $title);
            if ($author) {
                $q->whereHas('authors', function ($sq) use ($author) {
                    $sq->where('name', 'LIKE', "%{$author}%");
                });
            }
            $book = $q->first();

            if ($book) {
                if (!in_array($book->id, $processedIds)) {
                    // @phpstan-ignore-next-line
                    $results[] = $this->transformBookForBatch($book);
                    $processedIds[] = $book->id;
                }
                continue;
            }

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

    private function transformBookForBatch($book): array
    {
        return $this->getBookWithCover($book->toArray(), true, false);
    }

    private function transformClientBookForBatch($clientBook): array
    {
        return [
            'id'           => $clientBook->id,
            'title'        => $clientBook->title,
            'author'       => $clientBook->author,
            'coverImage'   => $clientBook->cover_url,
            'is_client_book' => true,
            'source'       => 'client',
        ];
    }

    /**
     * List books.
     *
     * Pass ?enhanced=true to enable:
     * - ID-based filters: genre_id, author_id, series_id
     * - Name-specific aliases: genre_name, author_name, series_name
     * - Combined sort tokens: title_asc, created_at_desc, last_listened_desc, etc.
     * - Structured relationship objects (authors/genres/narrators/series with IDs)
     * - Pagination block with has_next / has_prev / total_pages
     */
    public function index(Request $request): JsonResponse
    {
        $enhanced     = $request->boolean('enhanced', false);
        $page         = max(1, (int) $request->input('page', 1));
        $perPage      = $enhanced
            ? min(100, max(1, (int) $request->input('per_page', 15)))
            : (int) $request->input('per_page', 15);
        $withCover    = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);
        $includeNeedsReview = $request->boolean(
            'includeNeedsReview',
            $request->boolean('include_needs_review', false)
        );

        $filters = [
            'search'           => $request->input('search'),
            'genre'            => $request->input('genre'),
            'author'           => $request->input('author'),
            'series'           => $request->input('series'),
            'tag'              => $request->input('tag'),
            'title'            => $request->input('title'),
            'publication_date' => $request->input('publication_date'),
            'date_added'       => $request->input('date_added'),
            'language'         => $request->input('language'),
            'status'           => $request->input('status'),
            'is_recommended'   => $request->has('is_recommended') ? $request->boolean('is_recommended') : null,
            'is_completed'     => $request->has('is_completed') ? $request->boolean('is_completed') : null,
            'device_id'        => $request->input('device_id'),
        ];

        if ($enhanced) {
            $filters['genre_id']  = $request->input('genre_id');
            $filters['author_id'] = $request->input('author_id');
            $filters['series_id'] = $request->input('series_id');
            if ($request->has('genre_name')) {
                $filters['genre'] = $request->input('genre_name');
            }
            if ($request->has('author_name')) {
                $filters['author'] = $request->input('author_name');
            }
            if ($request->has('series_name')) {
                $filters['series'] = $request->input('series_name');
            }
        }

        $filters['include_needs_review'] = $includeNeedsReview;
        $userId = Auth::id();

        [$sort, $order] = $this->resolveSortParams($request);

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

        $transformedBooks = [];
        if (isset($booksData['data']) && is_array($booksData['data'])) {
            $booksArray = array_filter($booksData['data'], 'is_array');
            if (!$includeNeedsReview) {
                $booksArray = array_filter($booksArray, fn ($book) => empty($book['needs_review']));
            }

            $transformedBooks = array_map(
                fn ($book) => $this->getBookWithCover($book, $withCover, $inlineCovers, $enhanced),
                $booksArray
            );
        }

        // @phpstan-ignore-next-line
        $total    = (int) ($booksData['total'] ?? 0);
        $lastPage = $booksData['lastPage'] ?? max(1, (int) ceil($total / $perPage));

        if ($enhanced) {
            return response()->json([
                'data'       => array_values($transformedBooks),
                'pagination' => [
                    'current_page' => $page,
                    'per_page'     => $perPage,
                    'total'        => $total,
                    'total_pages'  => $lastPage,
                    'has_next'     => $page < $lastPage,
                    'has_prev'     => $page > 1,
                ],
            ]);
        }

        $from = $total > 0 ? (($page - 1) * $perPage) + 1 : null;
        $to   = $total > 0 ? min($page * $perPage, $total) : null;

        return response()->json([
            'data' => array_values($transformedBooks),
            'meta' => [
                'current_page' => $booksData['currentPage'] ?? $page,
                'from'         => $from,
                'last_page'    => $lastPage,
                'per_page'     => $perPage,
                'to'           => $to,
                'total'        => $total,
            ],
        ]);
    }

    /**
     * Resolve sort column and direction from request.
     * Accepts combined tokens like "title_asc" OR classic ?sort=title&order=asc.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveSortParams(Request $request): array
    {
        $sortMap = [
            'title_asc'          => ['title', 'asc'],
            'title_desc'         => ['title', 'desc'],
            'created_at_asc'     => ['created_at', 'asc'],
            'created_at_desc'    => ['created_at', 'desc'],
            'author_asc'         => ['author', 'asc'],
            'author_desc'        => ['author', 'desc'],
            'progress_asc'       => ['progress', 'asc'],
            'progress_desc'      => ['progress', 'desc'],
            'last_listened_asc'  => ['last_listened', 'asc'],
            'last_listened_desc' => ['last_listened', 'desc'],
            'queue_order_asc'    => ['queue_order', 'asc'],
            'queue_order_desc'   => ['queue_order', 'desc'],
        ];

        $sortParam = (string) $request->input('sort', 'title');

        if (isset($sortMap[$sortParam])) {
            return $sortMap[$sortParam];
        }

        $order = strtolower((string) $request->input('order', 'asc'));

        return [$sortParam, in_array($order, ['asc', 'desc']) ? $order : 'asc'];
    }

    public function show(string $id, Request $request): JsonResponse
    {
        $userId = Auth::id();
        $book   = $this->documentStoreService->getBook($id, $userId);

        if (!$book) {
            return response()->json([
                'error'   => 'Book not found',
                'message' => 'The specified book could not be found',
            ], 404);
        }

        $includeNeedsReview = $request->boolean(
            'includeNeedsReview',
            $request->boolean('include_needs_review', false)
        );
        if ((!empty($book['needs_review']) || !empty($book['needsReview'])) && !$includeNeedsReview) {
            return response()->json([
                'error'   => 'Book not available',
                'message' => 'This book is pending review',
            ], 404);
        }

        return response()->json(
            $this->getBookWithCover($book, $request->boolean('with_cover', true), $request->boolean('inlineCovers', false), $request->boolean('enhanced', false))
        );
    }

    public function browse(Request $request): JsonResponse
    {
        $type    = $request->input('type');
        $perPage = (int) $request->input('per_page', 100);
        $search  = $request->input('search');
        $since   = $request->input('since') ? (int) $request->input('since') : null;

        $items = match ($type) {
            'genre'  => $this->documentStoreService->listGenres($since),
            'author' => $this->documentStoreService->listAuthors($since),
            'series' => $this->documentStoreService->listSeries($since),
            default  => null,
        };

        if ($items === null) {
            return response()->json([
                'error'   => 'Invalid browse type',
                'message' => 'The browse type must be one of: genre, author, series',
            ], 400);
        }

        if ($search) {
            $items = array_filter($items, fn ($item) => stripos($item['name'], $search) !== false);
        }

        $items = array_values($items);
        $total = count($items);
        $page  = (int) $request->input('page', 1);

        return response()->json([
            'data' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'meta' => [
                'current_page' => $page,
                'from'         => $total > 0 ? (($page - 1) * $perPage) + 1 : null,
                'last_page'    => max(1, (int) ceil($total / $perPage)),
                'per_page'     => $perPage,
                'to'           => $total > 0 ? min($page * $perPage, $total) : null,
                'total'        => $total,
                'since'        => $since,
                'timestamp'    => time(),
            ],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $perPage      = (int) $request->input('per_page', 20);
        $page         = (int) $request->input('page', 1);
        $withCover    = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);
        $includeNeedsReview = $request->boolean(
            'includeNeedsReview',
            $request->boolean('include_needs_review', false)
        );

        $filters = [
            'search'           => $request->input('search'),
            'title'            => $request->input('title'),
            'author'           => $request->input('author'),
            'series'           => $request->input('series'),
            'tag'              => $request->input('tag'),
            'publication_date' => $request->input('publication_date'),
            'date_added'       => $request->input('date_added'),
            'status'           => $request->input('status'),
            'is_recommended'   => $request->has('is_recommended') ? $request->boolean('is_recommended') : null,
            'is_completed'     => $request->has('is_completed') ? $request->boolean('is_completed') : null,
            'device_id'        => $request->input('device_id'),
            'include_needs_review' => $includeNeedsReview,
        ];

        $userId    = Auth::id();
        $booksData = $this->documentStoreService->listBooks($page, $perPage, $filters, true, 'title', 'asc', false, $userId);
        $books     = $booksData['data'];
        $total     = $booksData['total'];

        $transformedBooks = array_map(
            fn ($book) => $this->getBookWithCover($book, $withCover, $inlineCovers),
            $books
        );

        return response()->json([
            'data' => $transformedBooks,
            'meta' => [
                'current_page' => $page,
                'from'         => $total > 0 ? (($page - 1) * $perPage) + 1 : null,
                'last_page'    => max(1, (int) ceil($total / $perPage)),
                'per_page'     => $perPage,
                'to'           => $total > 0 ? min($page * $perPage, $total) : null,
                'total'        => $total,
            ],
        ]);
    }
}
