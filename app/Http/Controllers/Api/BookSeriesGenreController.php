<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Api\Traits\BookTransformTrait;
use App\Http\Controllers\Controller;
use App\Models\Author;
use App\Models\Book;
use App\Models\Series;
use App\Services\BookDataTransformer;
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
        private readonly BookDataTransformer $bookDataTransformer,
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
        $query = Series::query()
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

        $tagSpec = array_filter([$request->input('tag'), $request->input('tags')]);
        $tagService = app(\App\Services\UserTagFilterService::class);
        $parsedTags = $tagService::parseTagSpecs($tagSpec);

        if (!empty($parsedTags['required']) || !empty($parsedTags['banned'])) {
            $query->whereHas('books', function ($b) use ($tagService, $tagSpec, $userId) {
                $tagService->applyRequestTagFilters($b, $tagSpec, $userId);
            });
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
        $countQuery = Series::query()
            ->join('book_series', 'series.id', '=', 'book_series.series_id')
            ->join('books', 'book_series.book_id', '=', 'books.id');

        if (!$includeNeedsReview) {
            $countQuery->where('books.needs_review', false);
        }

        if (!empty($parsedTags['required']) || !empty($parsedTags['banned'])) {
            $countQuery->whereHas('books', function ($b) use ($tagService, $tagSpec, $userId) {
                $tagService->applyRequestTagFilters($b, $tagSpec, $userId);
            });
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
        $seriesWithAuthors = $series->map(function ($series) use ($includeNeedsReview, $tagService, $tagSpec, $userId) {
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
                ->tap(function ($q) use ($tagService, $tagSpec, $userId) {
                    $tagService->applyRequestTagFilters($q, $tagSpec, $userId);
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
                'cover_urls' => $this->seriesCoverUrls($series, function ($q) use ($includeNeedsReview) {
                    if (!$includeNeedsReview) {
                        $q->where('books.needs_review', false);
                    }
                }),
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
        $series = Series::query()->find($seriesId);

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

        $genreIds = array_values(array_filter(array_map('intval', (array) $request->input('genre_ids', []))));

        $filters = ['series_id' => $seriesId];
        if (!empty($genreIds)) {
            $filters['genre_id'] = $genreIds;
        }
        if ($request->has('tag')) {
            $filters['tag'] = $request->input('tag');
        }
        if ($request->has('tags')) {
            $filters['tags'] = $request->input('tags');
        }

        $userId = Auth::id();
        $booksData = $documentStore->listBooks(1, $perPage, $filters, true, 'title', 'asc', false, $userId);
        $books = $booksData['data'];

        $transformedBooks = array_map(fn ($book) => $this->getBookWithCover($book, $withCover, $inlineCovers), $books);

        return response()->json([
            'series' => ['id' => $series['id'], 'name' => $series['name']],
            'genres' => $this->seriesGenreOptions((int) $series['id']),
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
     * The distinct genres across a series' non-needs_review books, for the series-detail
     * screen's genre filter chips. Always the full set, regardless of any genre_ids filter
     * applied to the book list itself, so the chip row doesn't shrink as chips are selected.
     *
     * @return list<array{id: int, name: string}>
     */
    private function seriesGenreOptions(int $seriesId): array
    {
        return \App\Models\Genre::query()
            ->whereHas('books', function ($q) use ($seriesId): void {
                $q->whereHas('series', fn ($s) => $s->where('series.id', $seriesId))
                    ->where('books.needs_review', false);
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($genre) => ['id' => $genre->id, 'name' => $genre->name])
            ->values()
            ->all();
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

        $tagSpec = array_filter([$request->input('tag'), $request->input('tags')]);
        $tagService = app(\App\Services\UserTagFilterService::class);
        $parsedTags = $tagService::parseTagSpecs($tagSpec);

        if (!empty($parsedTags['required']) || !empty($parsedTags['banned'])) {
            $matchingBookQuery = \App\Models\Book::query();
            if (!$request->boolean('includeNeedsReview', false)) {
                $matchingBookQuery->where('needs_review', false);
            }
            $tagService->applyRequestTagFilters($matchingBookQuery, $tagSpec, Auth::id());
            $matchingBookIds = $matchingBookQuery->pluck('id')->all();

            $validGenreIds = \App\Models\Genre::query()
                ->whereHas('books', fn ($b) => $b->whereIn('books.id', $matchingBookIds))
                ->pluck('id')
                ->all();

            $genres = array_filter($genres, fn (array $genre) => in_array($genre['id'], $validGenreIds));
        }

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
        // documentStoreService->listBooks() with no args returns a paginated wrapper
        // (['data' => ..., 'total' => ...]), not a flat book list, and defaults to only
        // the first 24 books — neither is suitable for "every author in this genre", so
        // this queries authors directly instead of filtering a (wrong/partial) book list.
        $tagSpec = array_filter([$request->input('tag'), $request->input('tags')]);
        $tagService = app(\App\Services\UserTagFilterService::class);
        $authorIds = Author::query()
            ->whereHas('books', function ($q) use ($genre, $tagSpec, $tagService): void {
                $q->whereHas('genres', fn ($g) => $g->where('genres.id', $genre['id']));
                $tagService->applyRequestTagFilters($q, $tagSpec, Auth::id());
            })
            ->pluck('id')
            ->all();
        $authors = array_filter($this->documentStoreService->listAuthors(), function ($author) use ($authorIds, $search) {
            $match = in_array($author['id'], $authorIds);
            if ($search) {
                $match = $match && (stripos($author['name'], $search) !== false);
            }

            return $match;
        });
        $authors = array_values($authors);

        if ($request->input('sort') === 'random') {
            shuffle($authors);
        } else {
            usort($authors, function ($a, $b) {
                return strcmp($a['name'], $b['name']);
            });
        }

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
     * Get a paginated, sortable list of books in a genre.
     */
    public function booksByGenre(Request $request, $genreId)
    {
        $documentStore = $this->documentStoreService;
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 24)));
        $withCover = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);

        $genre = $documentStore->getGenre($genreId);
        if (!$genre) {
            return response()->json([
                'error' => 'Genre not found',
                'message' => 'The specified genre could not be found',
            ], 404);
        }

        $sort = $this->resolveGenreBooksSort((string) $request->input('sort', 'recent'));
        $order = (string) $request->input('order', $sort === 'title' ? 'asc' : 'desc');

        $userId = Auth::id();
        $filters = ['genre_id' => $genre['id']];
        if ($request->has('tag')) {
            $filters['tag'] = $request->input('tag');
        }
        if ($request->has('tags')) {
            $filters['tags'] = $request->input('tags');
        }

        $booksData = $documentStore->listBooks(
            $page,
            $perPage,
            $filters,
            true,
            $sort,
            $order,
            false,
            $userId
        );
        $books = $booksData['data'];

        $transformedBooks = array_map(fn ($book) => $this->getBookWithCover($book, $withCover, $inlineCovers), $books);

        $total = (int) ($booksData['total'] ?? 0);

        return response()->json([
            'genre' => ['id' => $genre['id'], 'name' => $genre['name']],
            'data' => array_values($transformedBooks),
            'meta' => [
                'current_page' => $page,
                'from' => $total > 0 ? (($page - 1) * $perPage) + 1 : null,
                'last_page' => $booksData['lastPage'] ?? max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'to' => $total > 0 ? min($page * $perPage, $total) : null,
                'total' => $total,
            ],
        ]);
    }

    /**
     * Maps a client-facing sort name to the underlying listBooks() sort key.
     */
    private function resolveGenreBooksSort(string $requested): string
    {
        return match ($requested) {
            'alpha', 'title' => 'title',
            'random' => 'random',
            default => 'created_at', // 'recent' and anything unrecognized
        };
    }

    /**
     * Get a paginated, sortable list of series with a book in a genre.
     */
    public function seriesByGenre(Request $request, $genreId)
    {
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 24)));
        $sort = (string) $request->input('sort', 'alpha');

        $genre = $this->documentStoreService->getGenre($genreId);
        if (!$genre) {
            return response()->json([
                'error' => 'Genre not found',
                'message' => 'The specified genre could not be found',
            ], 404);
        }

        $genreId = $genre['id'];
        $tagSpec = array_filter([$request->input('tag'), $request->input('tags')]);
        $tagService = app(\App\Services\UserTagFilterService::class);

        // Excludes needs_review books, matching listBooks()'s default and the series-detail
        // screen's book list, so the count shown here matches what opening the series shows.
        $inGenre = fn ($q) => $q->whereHas('genres', fn ($g) => $g->where('genres.id', $genreId))
            ->where('books.needs_review', false)
            ->tap(fn ($b) => $tagService->applyRequestTagFilters($b, $tagSpec, Auth::id()));

        $query = Series::query()
            ->whereHas('books', $inGenre)
            ->withCount(['books as book_count_in_genre' => $inGenre]);

        switch ($sort) {
            case 'random':
                $query->inRandomOrder();
                break;
            case 'recent':
                $query->withMax(['books as latest_book_added_at' => $inGenre], 'created_at')
                    ->orderByDesc('latest_book_added_at');
                break;
            case 'length':
            case 'series_length':
                $query->orderByDesc('book_count_in_genre');
                break;
            case 'alpha':
            default:
                $query->orderBy('name');
                break;
        }

        $total = (clone $query)->count();
        $series = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return response()->json([
            'genre' => ['id' => $genre['id'], 'name' => $genre['name']],
            'data' => $series->map(fn (Series $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'book_count_in_genre' => $s->book_count_in_genre,
                'cover_urls' => $this->seriesCoverUrls($s, $inGenre),
            ])->values()->all(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    /**
     * The cover URLs of the first four books of a series (matching the given scope, e.g. a
     * genre + needs_review filter), in series-number order where known, for the client's
     * hybrid cover mosaic.
     *
     * @param \Closure(\Illuminate\Database\Eloquent\Builder<Book>): void $scope
     * @return list<string>
     */
    private function seriesCoverUrls(Series $s, \Closure $scope): array
    {
        $books = $s->books()
            ->when(true, $scope)
            ->orderByRaw('CAST(book_series.series_number AS DECIMAL(10,2)) ASC')
            ->limit(4)
            ->get();

        return $books
            ->map(fn (Book $book) => $this->bookDataTransformer->coverUrl($book))
            ->filter()
            ->values()
            ->all();
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
        // See authorsByGenre() above for why this can't filter documentStore->listBooks()'s
        // return value directly (it's a paginated wrapper, and defaults to only 24 books).
        $tagSpec = array_filter([$request->input('tag'), $request->input('tags')]);
        $tagService = app(\App\Services\UserTagFilterService::class);
        $authorIds = Author::query()
            ->whereHas('books', function ($q) use ($genre, $tagSpec, $tagService): void {
                $q->whereHas('genres', fn ($g) => $g->where('genres.id', $genre['id']));
                $tagService->applyRequestTagFilters($q, $tagSpec, Auth::id());
            })
            ->pluck('id')
            ->all();
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
