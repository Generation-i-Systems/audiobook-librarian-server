<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Api\Traits\BookTransformTrait;
use App\Http\Controllers\Controller;
use App\Services\ControllerDatabaseService as ControllerDatabase;
use App\Services\LibriVoxBrowseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BookAuthorController extends Controller
{
    use BookTransformTrait;

    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(
        DocumentStoreServiceInterface $documentStoreService,
        private readonly LibriVoxBrowseService $libriVoxBrowseService,
    ) {
        $this->documentStoreService = $documentStoreService;
    }

    /**
     * Get all authors with optional genre filtering, pagination, and sorting
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function authors(Request $request)
    {
        if ((string) config('library_profiles.active_source_mode', 'local') === 'librivox') {
            return $this->librivoxAuthors($request);
        }

        $genreId = $request->input('genre_id');
        $genreName = $request->input('genre_name');
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 50)));
        $sort = $request->input('sort', 'name_asc');
        $search = $request->input('search');
        $since = $request->input('since') ? (int) $request->input('since') : null;
        $includeNeedsReview = $request->boolean('includeNeedsReview', $request->boolean('include_needs_review', false));

        // Validate sort parameter
        $allowedSorts = ['name_asc', 'name_desc', 'book_count_asc', 'book_count_desc'];
        if (!in_array($sort, $allowedSorts)) {
            $sort = 'name_asc';
        }

        $isFavorite = $request->boolean('favorites', false);
        $userId = Auth::id();

        // Build the base query
        $query = \App\Models\Author::query()
            ->select([
                'authors.id',
                'authors.name',
            ])
            ->selectRaw('MAX(authors.updated_at) as updated_at')
            ->selectSub(function ($q) use ($includeNeedsReview) {
                $q->from('author_book')
                    ->join('books', 'author_book.book_id', '=', 'books.id')
                    ->whereColumn('author_book.author_id', 'authors.id');
                if (!$includeNeedsReview) {
                    $q->where('books.needs_review', false);
                }
                $q->selectRaw('COUNT(DISTINCT books.id)');
            }, 'book_count')
            ->selectRaw('EXISTS(SELECT 1 FROM user_author_favorites WHERE user_id = ? AND author_id = authors.id) as isFavorite', [$userId])
            ->join('author_book', 'authors.id', '=', 'author_book.author_id')
            ->join('books', 'author_book.book_id', '=', 'books.id');

        if ($isFavorite && $userId) {
            $query->join('user_author_favorites', function ($join) use ($userId) {
                $join->on('authors.id', '=', 'user_author_favorites.author_id')
                    ->where('user_author_favorites.user_id', '=', $userId);
            });
        }


        if ($since) {
            $query->where('authors.updated_at', '>=', date('Y-m-d H:i:s', $since));
        }

        // Exclude needs_review books unless explicitly included
        if (!$includeNeedsReview) {
            $query->where('books.needs_review', false);
        }

        // Add genre filtering if specified
        if ($genreId || $genreName) {
            $query->join('book_genre', 'books.id', '=', 'book_genre.book_id')
                ->join('genres', function ($join) {
                    $join->on('book_genre.genre_id', '=', 'genres.id')
                        ->whereNull('genres.deleted_at');
                });

            if ($genreId) {
                $query->where('genres.id', $genreId);
            } elseif ($genreName) {
                $query->where('genres.name', $genreName);
            }

            // Add book count in specific genre
            $query->selectRaw(
                'COUNT(DISTINCT CASE WHEN genres.id = ? OR genres.name = ? THEN books.id END) as book_count_in_genre',
                [$genreId, $genreName]
            );
        } else {
            // No genre filter, so book_count_in_genre equals total book_count
            $query->selectRaw('COUNT(DISTINCT books.id) as book_count_in_genre');
        }

        // Add search functionality
        if ($search) {
            $query->where('authors.name', 'LIKE', '%' . $search . '%');
        }

        // Group by author to avoid duplicates
        $query->groupBy('authors.id', 'authors.name');

        // Add sorting
        switch ($sort) {
            case 'name_desc':
                $query->orderBy('authors.name', 'desc');
                break;
            case 'book_count_asc':
                $query->orderBy('book_count', 'asc');
                break;
            case 'book_count_desc':
                $query->orderBy('book_count', 'desc');
                break;
            case 'name_asc':
            default:
                $query->orderBy('authors.name', 'asc');
                break;
        }

        // Get total count before pagination - need to remove GROUP BY for accurate count
        $countQuery = \App\Models\Author::query()
            ->join('author_book', 'authors.id', '=', 'author_book.author_id')
            ->join('books', 'author_book.book_id', '=', 'books.id');

        if (!$includeNeedsReview) {
            $countQuery->where('books.needs_review', false);
        }

        if ($isFavorite && $userId) {
            $countQuery->join('user_author_favorites', function ($join) use ($userId) {
                $join->on('authors.id', '=', 'user_author_favorites.author_id')
                    ->where('user_author_favorites.user_id', '=', $userId);
            });
        }


        // Add same genre filtering as main query if present
        if ($genreId || $genreName) {
            $countQuery->join('book_genre', 'books.id', '=', 'book_genre.book_id')
                ->join('genres', function ($join) {
                    $join->on('book_genre.genre_id', '=', 'genres.id')
                        ->whereNull('genres.deleted_at');
                });
            if ($genreId) {
                $countQuery->where('genres.id', $genreId);
            } elseif ($genreName) {
                $countQuery->where('genres.name', $genreName);
            }
        }

        // Add search functionality if present
        if ($search) {
            $countQuery->where('authors.name', 'LIKE', '%' . $search . '%');
        }

        if ($since) {
            $countQuery->where('authors.updated_at', '>=', date('Y-m-d H:i:s', $since));
        }

        $total = $countQuery->distinct()->count('authors.id');

        // Calculate pagination info
        $totalPages = (int) ceil($total / $perPage);
        $hasNext = $page < $totalPages;
        $hasPrev = $page > 1;

        // If requested page is beyond the last page, return empty results
        if ($totalPages > 0 && $page > $totalPages) {
            return response()->json([
                'authors' => [],
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => $totalPages,
                    'has_next' => false,
                    'has_prev' => true,
                ],
            ]);
        }

        // Execute query with pagination
        $offset = ($page - 1) * $perPage;
        $authors = $query->offset($offset)->limit($perPage)->get();

        // Get genres and series for each author
        $authorsWithDetails = $authors->map(function (\App\Models\Author $author) use ($includeNeedsReview) {
            // Get genres for this author
            $authorBooksQuery = $author->books();
            if (!$includeNeedsReview) {
                $authorBooksQuery->where('books.needs_review', false);
            }
            $author->genres = $authorBooksQuery
                ->join('book_genre', 'books.id', '=', 'book_genre.book_id')
                ->join('genres', function ($join) {
                    $join->on('book_genre.genre_id', '=', 'genres.id')
                        ->whereNull('genres.deleted_at');
                })
                ->select('genres.name')
                ->distinct()
                ->pluck('name')
                ->toArray();

            // Compute total book count for this author independent of outer joins
            $totalBookCount = \App\Models\Book::query()
                ->join('author_book', 'books.id', '=', 'author_book.book_id')
                ->where('author_book.author_id', $author->id)
                ->when(!$includeNeedsReview, function ($q) {
                    $q->where('books.needs_review', false);
                })
                ->distinct('books.id')
                ->count('books.id');

            // Get series for this author with book counts
            $authorSeries = \App\Models\Series::query()
                ->select('series.id', 'series.name')
                ->selectRaw('COUNT(DISTINCT books.id) as books_in_series')
                ->join('book_series', 'series.id', '=', 'book_series.series_id')
                ->join('books', 'book_series.book_id', '=', 'books.id')
                ->join('author_book', 'books.id', '=', 'author_book.book_id')
                ->where('author_book.author_id', $author->id)
                ->when(!$includeNeedsReview, function ($q) {
                    $q->where('books.needs_review', false);
                })
                ->groupBy('series.id', 'series.name')
                ->get()
                ->map(function ($series) {
                    return [
                        'id' => $series->id,
                        'name' => $series->name,
                        'books_in_series' => $series->books_in_series,
                    ];
                });

            return [
                'id' => $author->id,
                'name' => $author->name,
                'biography' => null, // Column doesn't exist in database
                'book_count' => $totalBookCount,
                'book_count_in_genre' => $author->book_count_in_genre ?? $author->book_count,
                'image_url' => null, // Column doesn't exist in database
                'genres' => $author->genres,
                'series' => $authorSeries->toArray(),
                'isFavorite' => (bool) $author->isFavorite,
            ];
        });

        return response()->json([
            'authors' => $authorsWithDetails,
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

    private function librivoxAuthors(Request $request)
    {
        $result = $this->libriVoxBrowseService->listAuthors($request->only([
            'genre_id', 'genre_name', 'page', 'per_page', 'sort', 'search', 'language',
        ]));

        $pagination = $result['pagination'];
        $page = $pagination['current_page'];
        $totalPages = $pagination['total_pages'];

        return response()->json([
            'authors' => $result['authors'],
            'pagination' => [
                'current_page' => $page,
                'per_page' => $pagination['per_page'],
                'total' => $pagination['total_items'],
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1,
            ],
        ]);
    }

    public function authorDetails(Request $request, int $authorId)
    {
        $includeNeedsReview = $request->boolean('includeNeedsReview', $request->boolean('include_needs_review', false));
        $userId = Auth::id();

        /** @var \App\Models\Author|null $author */
        $author = \App\Models\Author::query()->find($authorId);

        if (!$author) {
            return response()->json([
                'error' => 'Author not found',
                'message' => 'The specified author could not be found',
            ], 404);
        }

        $totalBookCount = \App\Models\Book::query()
            ->join('author_book', 'books.id', '=', 'author_book.book_id')
            ->where('author_book.author_id', $authorId)
            ->when(!$includeNeedsReview, function ($q) {
                $q->where('books.needs_review', false);
            })
            ->distinct('books.id')
            ->count('books.id');

        $genres = $author->books()
            ->when(!$includeNeedsReview, function ($q) {
                $q->where('books.needs_review', false);
            })
            ->join('book_genre', 'books.id', '=', 'book_genre.book_id')
            ->join('genres', function ($join) {
                $join->on('book_genre.genre_id', '=', 'genres.id')
                    ->whereNull('genres.deleted_at');
            })
            ->select('genres.name')
            ->distinct()
            ->orderBy('genres.name')
            ->pluck('name')
            ->toArray();

        $authorSeries = \App\Models\Series::query()
            ->select('series.id', 'series.name')
            ->selectRaw('COUNT(DISTINCT books.id) as series_book_count')
            ->join('book_series', 'series.id', '=', 'book_series.series_id')
            ->join('books', 'book_series.book_id', '=', 'books.id')
            ->join('author_book', 'books.id', '=', 'author_book.book_id')
            ->where('author_book.author_id', $authorId)
            ->when(!$includeNeedsReview, function ($q) {
                $q->where('books.needs_review', false);
            })
            ->groupBy('series.id', 'series.name')
            ->orderBy('series.name')
            ->get()
            ->map(function (\App\Models\Series $series) {
                /** @var int $count */
                $count = $series->getAttribute('series_book_count');

                return [
                    'id' => $series->id,
                    'name' => $series->name,
                    'books_in_series' => $count,
                ];
            })
            ->toArray();

        $isFavorite = false;
        if ($userId) {
            $isFavorite = ControllerDatabase::table('user_author_favorites')
                ->where('user_id', $userId)
                ->where('author_id', $authorId)
                ->exists();
        }

        return response()->json([
            'id' => $author->id,
            'name' => $author->name,
            'biography' => null,
            'book_count' => $totalBookCount,
            'book_count_in_genre' => $totalBookCount,
            'image_url' => null,
            'genres' => $genres,
            'series' => $authorSeries,
            'isFavorite' => $isFavorite,
        ]);
    }

    /**
     * Get all books for a given author.
     */
    public function booksByAuthor($authorId, Request $request)
    {
        $documentStore = $this->documentStoreService;
        $perPage = $request->input('per_page', 100);
        $withCover = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);
        $author = $documentStore->getAuthor($authorId);
        if (!$author) {
            return response()->json([
                'error' => 'Author not found',
                'message' => 'The specified author could not be found',
            ], 404);
        }

        $userId = Auth::id();
        $booksData = $documentStore->listBooks(1, $perPage, ['author_id' => $authorId], true, 'title', 'asc', false, $userId);
        $books = $booksData['data'];

        $transformedBooks = array_map(fn ($book) => $this->getBookWithCover($book, $withCover, $inlineCovers), $books);

        return response()->json([
            'author' => ['id' => $author['id'], 'name' => $author['name']],
            'data' => $transformedBooks,
            'meta' => [
                'current_page' => 1,
                'from' => ($booksData['total'] > 0) ? 1 : null,
                'last_page' => $booksData['lastPage'],
                'per_page' => $perPage,
                'to' => count($transformedBooks),
                'total' => $booksData['total'],
            ],
        ]);
    }

    /**
     * Get all series for a given author.
     */
    public function seriesByAuthor($authorId, Request $request)
    {
        $documentStore = $this->documentStoreService;
        $author = $documentStore->getAuthor($authorId);
        if (!$author) {
            return response()->json(['error' => 'Author not found'], 404);
        }
        $books = array_filter($documentStore->listBooks(), fn ($book) => ($book['author_id'] ?? null) == $authorId);
        $seriesIds = array_unique(array_column($books, 'series_id'));
        $series = array_filter($documentStore->listSeries(), fn ($ser) => in_array($ser['id'], $seriesIds));
        $series = array_values($series);
        usort($series, fn ($a, $b) => strcmp($a['name'], $b['name']));
        $series = array_map(fn ($s) => ['id' => $s['id'], 'name' => $s['name']], $series);

        return response()->json([
            'author' => ['id' => $author['id'], 'name' => $author['name']],
            'series' => $series,
        ]);
    }

    /**
     * Get all books for a given author within a specific genre.
     */
    public function booksByAuthorAndGenre($authorId, $genreId, Request $request)
    {
        $perPage = $request->input('per_page', 100);
        $page = max(1, (int) $request->input('page', 1));
        $withCover = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);

        $documentStore = $this->documentStoreService;
        $author = $documentStore->getAuthor($authorId);
        $genre = $documentStore->getGenre($genreId);

        if (!$author || !$genre) {
            return response()->json([
                'error' => 'Author or Genre not found',
                'message' => 'The specified author or genre could not be found',
            ], 404);
        }

        $userId = Auth::id();
        $booksData = $documentStore->listBooks(
            $page,
            $perPage,
            ['author_id' => $authorId, 'genre_id' => $genreId],
            true,
            'title',
            'asc',
            false,
            $userId
        );

        $books = $booksData['data'];
        $transformedBooks = array_map(fn ($book) => $this->getBookWithCover($book, $withCover, $inlineCovers), $books);

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

    /**
     * Autocomplete author names using fuzzy search.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function autocompleteAuthors(Request $request)
    {
        $query = $request->input('query', '');
        $limit = (int) $request->input('limit', 10);
        if (!$query) {
            return response()->json(['data' => []]);
        }
        $authors = $this->documentStoreService->autocompleteAuthors($query, $limit);

        return response()->json(['data' => $authors]);
    }
}
