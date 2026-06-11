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

class BookSeriesGenreController extends Controller
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
     * Get all series with optional author filtering, pagination, and sorting
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function series(Request $request)
    {
        if ((string) config('library_profiles.active_source_mode', 'local') === 'librivox') {
            return response()->json([
                'series' => [],
                'pagination' => [
                    'current_page' => max(1, (int) $request->input('page', 1)),
                    'per_page' => min(100, max(1, (int) $request->input('per_page', 50))),
                    'total' => 0,
                    'total_pages' => 0,
                    'has_next' => false,
                    'has_prev' => false,
                ],
            ]);
        }

        $authorId = $request->input('author_id');
        $authorName = $request->input('author_name');
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 50)));
        $sort = $request->input('sort', 'name_asc');
        $search = $request->input('search');
        $since = $request->input('since') ? (int) $request->input('since') : null;
        $includeNeedsReview = $request->boolean('includeNeedsReview', $request->boolean('include_needs_review', false));
        $isFavorite = $request->boolean('favorites', false);
        $userId = Auth::id();

        // Validate sort parameter
        $allowedSorts = ['name_asc', 'name_desc', 'book_count_asc', 'book_count_desc'];
        if (!in_array($sort, $allowedSorts)) {
            $sort = 'name_asc';
        }

        // Build the base query
        $query = \App\Models\Series::query()
            ->select([
                'series.id',
                'series.name',
            ])
            ->selectRaw('MAX(series.updated_at) as updated_at')
            ->withCount('books as book_count')
            ->selectRaw('EXISTS(SELECT 1 FROM user_series_favorites WHERE user_id = ? AND series_id = series.id) as isFavorite', [$userId])
            ->join('book_series', 'series.id', '=', 'book_series.series_id')
            ->join('books', 'book_series.book_id', '=', 'books.id');

        if ($isFavorite && $userId) {
            $query->join('user_series_favorites', function ($join) use ($userId) {
                $join->on('series.id', '=', 'user_series_favorites.series_id')
                    ->where('user_series_favorites.user_id', '=', $userId);
            });
        }


        if ($since) {
            $query->where('series.updated_at', '>=', date('Y-m-d H:i:s', $since));
        }

        if (!$includeNeedsReview) {
            $query->where('books.needs_review', false);
        }

        // Add author filtering if specified
        if ($authorId || $authorName) {
            $query->join('author_book', 'books.id', '=', 'author_book.book_id')
                ->join('authors', 'author_book.author_id', '=', 'authors.id');

            if ($authorId) {
                $query->where('authors.id', $authorId);
            } elseif ($authorName) {
                $query->where('authors.name', $authorName);
            }

            // Add book count by specific author
            $query->selectRaw(
                'COUNT(DISTINCT CASE WHEN (authors.id = ? OR authors.name = ?) THEN books.id END) as book_count_by_author',
                [$authorId, $authorName]
            );
        } else {
            // No author filter, so book_count_by_author equals total book_count
            $query->selectRaw('COUNT(DISTINCT books.id) as book_count_by_author');
        }

        // Add search functionality
        if ($search) {
            $query->where('series.name', 'LIKE', '%' . $search . '%');
        }

        // Group by series to avoid duplicates
        $query->groupBy('series.id', 'series.name');

        // Add sorting
        switch ($sort) {
            case 'name_desc':
                $query->orderBy('series.name', 'desc');
                break;
            case 'book_count_asc':
                $query->orderByRaw('COUNT(DISTINCT books.id) ASC');
                break;
            case 'book_count_desc':
                $query->orderByRaw('COUNT(DISTINCT books.id) DESC');
                break;
            case 'name_asc':
            default:
                $query->orderBy('series.name', 'asc');
                break;
        }

        // Get total count before pagination - need to remove GROUP BY for accurate count
        $countQuery = \App\Models\Series::query()
            ->join('book_series', 'series.id', '=', 'book_series.series_id')
            ->join('books', 'book_series.book_id', '=', 'books.id');

        if (!$includeNeedsReview) {
            $countQuery->where('books.needs_review', false);
        }

        if ($isFavorite && $userId) {
            $countQuery->join('user_series_favorites', function ($join) use ($userId) {
                $join->on('series.id', '=', 'user_series_favorites.series_id')
                    ->where('user_series_favorites.user_id', '=', $userId);
            });
        }


        // Add same author filtering as main query if present
        if ($authorId || $authorName) {
            $countQuery->join('author_book', 'books.id', '=', 'author_book.book_id')
                ->join('authors', 'author_book.author_id', '=', 'authors.id');
            if ($authorId) {
                $countQuery->where('authors.id', $authorId);
            } elseif ($authorName) {
                $countQuery->where('authors.name', $authorName);
            }
        }

        // Add search functionality if present
        if ($search) {
            $countQuery->where('series.name', 'LIKE', '%' . $search . '%');
        }

        if ($since) {
            $countQuery->where('series.updated_at', '>=', date('Y-m-d H:i:s', $since));
        }

        $total = $countQuery->distinct()->count('series.id');

        // Calculate pagination info
        $totalPages = (int) ceil($total / $perPage);
        $hasNext = $page < $totalPages;
        $hasPrev = $page > 1;

        // If requested page is beyond the last page, return empty results
        if ($totalPages > 0 && $page > $totalPages) {
            return response()->json([
                'series' => [],
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
        $series = $query->offset($offset)->limit($perPage)->get();

        // Get authors for each series if needed for response
        $seriesWithAuthors = $series->map(function ($series) use ($includeNeedsReview) {
            $series->authors = $series->books()
                ->join('author_book', 'books.id', '=', 'author_book.book_id')
                ->join('authors', 'author_book.author_id', '=', 'authors.id')
                ->select('authors.name')
                ->distinct()
                ->when(!$includeNeedsReview, function ($q) {
                    $q->where('books.needs_review', false);
                })
                ->pluck('name')
                ->toArray();

            // Compute total book count for this series respecting needs_review filter
            $totalBookCount = \App\Models\Book::query()
                ->join('book_series', 'books.id', '=', 'book_series.book_id')
                ->where('book_series.series_id', $series->id)
                ->when(!$includeNeedsReview, function ($q) {
                    $q->where('books.needs_review', false);
                })
                ->distinct('books.id')
                ->count('books.id');

            return [
                'id' => $series->id,
                'name' => $series->name,
                'description' => null, // Column doesn't exist in database
                'book_count' => $totalBookCount,
                'book_count_by_author' => $series->book_count_by_author ?? $series->book_count,
                'authors' => $series->authors,
                'isFavorite' => (bool) $series->isFavorite,
            ];
        });

        return response()->json([
            'series' => $seriesWithAuthors,
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

    public function seriesDetails(Request $request, int $seriesId)
    {
        $includeNeedsReview = $request->boolean('includeNeedsReview', $request->boolean('include_needs_review', false));
        $userId = Auth::id();

        /** @var \App\Models\Series|null $series */
        $series = \App\Models\Series::query()->find($seriesId);

        if (!$series) {
            return response()->json([
                'error' => 'Series not found',
                'message' => 'The specified series could not be found',
            ], 404);
        }

        $totalBookCount = \App\Models\Book::query()
            ->join('book_series', 'books.id', '=', 'book_series.book_id')
            ->where('book_series.series_id', $seriesId)
            ->when(!$includeNeedsReview, function ($q) {
                $q->where('books.needs_review', false);
            })
            ->distinct('books.id')
            ->count('books.id');

        $authors = $series->books()
            ->when(!$includeNeedsReview, function ($q) {
                $q->where('books.needs_review', false);
            })
            ->join('author_book', 'books.id', '=', 'author_book.book_id')
            ->join('authors', 'author_book.author_id', '=', 'authors.id')
            ->select('authors.name')
            ->distinct()
            ->orderBy('authors.name')
            ->pluck('name')
            ->toArray();

        $isFavorite = false;
        if ($userId) {
            $isFavorite = ControllerDatabase::table('user_series_favorites')
                ->where('user_id', $userId)
                ->where('series_id', $seriesId)
                ->exists();
        }

        return response()->json([
            'id' => $series->id,
            'name' => $series->name,
            'description' => null,
            'book_count' => $totalBookCount,
            'book_count_by_author' => $totalBookCount,
            'authors' => $authors,
            'isFavorite' => $isFavorite,
        ]);
    }

    /**
     * Get all books for a given series.
     */
    public function booksBySeries($seriesId, Request $request)
    {
        $documentStore = $this->documentStoreService;
        $perPage = $request->input('per_page', 100);
        $withCover = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);
        $series = $documentStore->getSeries($seriesId);
        if (!$series) {
            return response()->json([
                'error' => 'Series not found',
                'message' => 'The specified series could not be found',
            ], 404);
        }

        $userId = Auth::id();
        $booksData = $documentStore->listBooks(1, $perPage, ['series_id' => $seriesId], true, 'title', 'asc', false, $userId);
        $books = $booksData['data'];

        $transformedBooks = array_map(fn ($book) => $this->getBookWithCover($book, $withCover, $inlineCovers), $books);

        return response()->json([
            'series' => ['id' => $series['id'], 'name' => $series['name']],
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
     * Get a list of all genres.
     */
    public function listGenres(Request $request)
    {
        if ((string) config('library_profiles.active_source_mode', 'local') === 'librivox') {
            return response()->json($this->listLibrivoxGenres($request));
        }

        $since = $request->input('since') ? (int) $request->input('since') : null;
        $genres = $this->documentStoreService->listGenresWithStats($since);

        // Filter out genres that have no books associated with them
        $genres = array_filter($genres, function (array $genre) {
            return ($genre['bookCount'] ?? 0) > 0;
        });

        usort($genres, function (array $a, array $b) {
            return strcmp($a['name'] ?? '', $b['name'] ?? '');
        });

        $genres = array_map(function (array $genre) {
            $visuals = $this->defaultGenreVisuals()[$genre['name']] ?? null;
            $emoji = $genre['emoji'] ?? ($visuals['emoji'] ?? null);
            $iconPath = $genre['iconPath'] ?? ($visuals['icon_path'] ?? null);

            return [
                'id' => $genre['id'],
                'name' => $genre['name'],
                'emoji' => $emoji,
                'iconPath' => $iconPath,
                'icon_url' => $iconPath,
            ];
        }, $genres);

        return response()->json($genres);
    }

    private function defaultGenreVisuals(): array
    {
        return [
            'Action' => ['emoji' => '🎬', 'icon_path' => '/images/genres/action.svg'],
            'Church' => ['emoji' => '⛪', 'icon_path' => '/images/genres/church.svg'],
            'Classic' => ['emoji' => '📚', 'icon_path' => '/images/genres/classic.svg'],
            'Computer' => ['emoji' => '💻', 'icon_path' => '/images/genres/computer.svg'],
            'Fantasy' => ['emoji' => '🧙', 'icon_path' => '/images/genres/fantasy.svg'],
            'General Fiction' => ['emoji' => '📖', 'icon_path' => '/images/genres/general-fiction.svg'],
            'Historical Fiction' => ['emoji' => '🏺', 'icon_path' => '/images/genres/historical-fiction.svg'],
            'History' => ['emoji' => '🏛️', 'icon_path' => '/images/genres/history.svg'],
            'Kids' => ['emoji' => '🧸', 'icon_path' => '/images/genres/kids.svg'],
            'LitRPG' => ['emoji' => '🎮', 'icon_path' => '/images/genres/litrpg.svg'],
            'Mystery' => ['emoji' => '🔎', 'icon_path' => '/images/genres/mystery.svg'],
            'Non Fiction' => ['emoji' => '🧠', 'icon_path' => '/images/genres/non-fiction.svg'],
            'Other' => ['emoji' => '🗂️', 'icon_path' => '/images/genres/other.svg'],
            'Religion' => ['emoji' => '🙏', 'icon_path' => '/images/genres/religion.svg'],
            'Romance' => ['emoji' => '💖', 'icon_path' => '/images/genres/romance.svg'],
            'Science' => ['emoji' => '🔬', 'icon_path' => '/images/genres/science.svg'],
            'Science Fiction' => ['emoji' => '🚀', 'icon_path' => '/images/genres/science-fiction.svg'],
        ];
    }

    private function listLibrivoxGenres(Request $request): array
    {
        return $this->libriVoxBrowseService->listGenres(
            (string) $request->input('language', 'English')
        );
    }

    /**
     * Get a paginated, filterable list of authors in a genre.
     */
    public function authorsByGenre(Request $request, $genreId)
    {
        $perPage = $request->input('per_page', 20);
        $search = $request->input('search');
        $genre = $this->documentStoreService->getGenre($genreId);
        if (!$genre) {
            return response()->json([
                'error' => 'Genre not found',
                'message' => 'The specified genre could not be found',
            ], 404);
        }
        $books = array_filter($this->documentStoreService->listBooks(), function ($book) use ($genreId) {
            return ($book['genre_id'] ?? null) == $genreId;
        });
        $authorIds = array_unique(array_column($books, 'author_id'));
        $authors = array_filter($this->documentStoreService->listAuthors(), function ($author) use ($authorIds, $search) {
            $match = in_array($author['id'], $authorIds);
            if ($search) {
                $match = $match && (stripos($author['name'], $search) !== false);
            }

            return $match;
        });
        $authors = array_values($authors);
        usort($authors, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        $total = count($authors);
        $page = (int) $request->input('page', 1);
        $offset = ($page - 1) * $perPage;
        $paginatedAuthors = array_slice($authors, $offset, $perPage);

        return response()->json([
            'genre' => [
                'id' => $genre['id'],
                'name' => $genre['name'],
                'emoji' => $genre['emoji'] ?? null,
                'iconPath' => $genre['icon_path'] ?? $genre['iconPath'] ?? null,
            ],
            'data' => $paginatedAuthors,
            'meta' => [
                'current_page' => $page,
                'from' => ($total > 0) ? $offset + 1 : null,
                'last_page' => max(1, ceil($total / $perPage)),
                'per_page' => $perPage,
                'to' => ($total > 0) ? min($offset + $perPage, $total) : null,
                'total' => $total,
            ],
        ]);
    }

    /**
     * Get all authors with books in a given genre.
     */
    public function authorsByGenreSimple($genreId, Request $request)
    {
        $documentStore = $this->documentStoreService;
        $genre = $documentStore->getGenre($genreId);
        if (!$genre) {
            return response()->json([
                'error' => 'Genre not found',
                'message' => 'The specified genre could not be found',
            ], 404);
        }
        $books = array_filter($documentStore->listBooks(), function ($book) use ($genreId) {
            return ($book['genre_id'] ?? null) == $genreId;
        });
        $authorIds = array_unique(array_column($books, 'author_id'));
        $authors = array_filter($documentStore->listAuthors(), fn ($author) => in_array($author['id'], $authorIds));
        $authors = array_values($authors);
        usort($authors, fn ($a, $b) => strcmp($a['name'], $b['name']));
        $authors = array_map(fn ($a) => ['id' => $a['id'], 'name' => $a['name']], $authors);

        return response()->json([
            'genre' => [
                'id' => $genre['id'],
                'name' => $genre['name'],
                'emoji' => $genre['emoji'] ?? null,
                'iconPath' => $genre['icon_path'] ?? $genre['iconPath'] ?? null,
            ],
            'authors' => $authors,
        ]);
    }

    /**
     * Autocomplete book series names using fuzzy search.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function autocompleteSeries(Request $request)
    {
        $query = $request->input('query', '');
        $limit = (int) $request->input('limit', 10);
        if (!$query) {
            return response()->json(['data' => []]);
        }
        $series = $this->documentStoreService->autocompleteSeries($query, $limit);

        return response()->json(['data' => $series]);
    }

    /**
     * Autocomplete narrator names using fuzzy search.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function autocompleteNarrators(Request $request)
    {
        $query = $request->input('query', '');
        $limit = (int) $request->input('limit', 10);
        if (!$query) {
            return response()->json(['data' => []]);
        }
        $narrators = $this->documentStoreService->autocompleteNarrators($query, $limit);

        return response()->json(['data' => $narrators]);
    }
}
