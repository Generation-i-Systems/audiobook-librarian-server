<?php

namespace App\Services;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Author;
use App\Models\Book;
use App\Models\Bookmark;
use App\Models\ExternalRead;
use App\Models\Genre;
use App\Models\Job;
use App\Models\Message;
use App\Models\Narrator;
use App\Models\Series;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MySqlService implements DocumentStoreServiceInterface
{
    private function buildCoverImageOutput(?string $coverImage, ?string $directoryPath): ?string
    {
        if ($coverImage === null) {
            return null;
        }

        $coverImage = trim($coverImage);
        if ($coverImage === '') {
            return null;
        }

        if (str_starts_with($coverImage, 'http://') || str_starts_with($coverImage, 'https://')) {
            return $coverImage;
        }

        $baseName = basename($coverImage);
        if ($baseName === '') {
            return null;
        }

        $directoryPath = is_string($directoryPath) ? trim($directoryPath, '/') : '';
        if ($directoryPath === '') {
            return $baseName;
        }

        return $directoryPath . '/' . $baseName;
    }

    public function getBook(string $id): ?array
    {
        $book = Book::with(['authors', 'narrators', 'genres', 'series', 'chapters'])->find($id);

        if (!$book) {
            return null;
        }

        $bookArray = $book->toArray();
        $camelCasedBook = [];

        // First, copy all non-relational properties, converting keys to camelCase
        foreach ($bookArray as $key => $value) {
            if (!is_array($value)) {
                $camelCasedBook[Str::camel($key)] = $value;
            }
        }

        // Then, specifically handle the relationships with the correct keys and structures
        if (!empty($bookArray['authors'])) {
            $camelCasedBook['author'] = collect($bookArray['authors'])->pluck('name')->all();
        }

        if (!empty($bookArray['genres'])) {
            $camelCasedBook['genre'] = collect($bookArray['genres'])->pluck('name')->all();
        }

        if (!empty($bookArray['narrators'])) {
            $camelCasedBook['narrator'] = collect($bookArray['narrators'])->pluck('name')->all();
        }

        if (!empty($bookArray['series'])) {
            $camelCasedBook['series'] = collect($bookArray['series'])->map(function ($series) {
                return [
                    'seriesName' => $series['name'] ?? null,
                    'number' => $series['pivot']['series_number'] ?? null,
                    'isCollection' => $series['is_collection'] ?? false,
                ];
            })->all();
        }

        // Handle cover image separately to ensure the key is correct
        if (isset($bookArray['cover_image'])) {
            $camelCasedBook['coverImage'] = $this->buildCoverImageOutput(
                $bookArray['cover_image'],
                $camelCasedBook['directoryPath'] ?? null
            );
        }

        // Map release_date to publishedYear (extract year if date is YYYY-01-01, otherwise keep full date)
        if (isset($bookArray['release_date'])) {
            $releaseDate = $bookArray['release_date'];
            if ($releaseDate && preg_match('/^(\d{4})-01-01(?:\s|T|$)/', $releaseDate, $matches)) {
                // If the date is YYYY-01-01 (with or without time), just use the year
                $camelCasedBook['publishedYear'] = (int) $matches[1];
            } elseif ($releaseDate) {
                // Otherwise, keep the full date
                $camelCasedBook['publishedYear'] = $releaseDate;
            }
        }

        return $camelCasedBook;
    }

    /**
     * Get unique values for a specific field across all books
     *
     * @param string $field The field to get unique values for (e.g., 'genre', 'author')
     * @param string|null $subField Optional subfield for nested data (e.g., 'seriesName' when field is 'series')
     * @return array Array of unique values
     */
    public function getUniqueValues(string $field, ?string $subField = null): array
    {
        try {
            switch ($field) {
                case 'author':
                    return Author::select('name')
                        ->distinct()
                        ->orderBy('name')
                        ->pluck('name')
                        ->filter()
                        ->values()
                        ->toArray();

                case 'genre':
                    return Genre::select('name')
                        ->distinct()
                        ->orderBy('name')
                        ->pluck('name')
                        ->filter()
                        ->values()
                        ->toArray();

                case 'series':
                    if ($subField === 'seriesName') {
                        return Series::select('name')
                            ->distinct()
                            ->orderBy('name')
                            ->pluck('name')
                            ->filter()
                            ->values()
                            ->toArray();
                    }
                    return [];

                default:
                    return [];
            }
        } catch (\Exception $e) {
            Log::error("Error getting unique values for field {$field}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [];
        }
    }

    /**
     * Ultra minimal books listing to prevent memory exhaustion
     */
    public function listBooksMinimal(
        int $page = 1,
        int $perPage = 10,
        array $filters = []
    ): array {
        $perPage = min($perPage, 10); // Hard limit to 10 items
        try {
            // Raw SQL to avoid Eloquent overhead
            $offset = ($page - 1) * $perPage;
            $whereClause = '';
            $params = [];

            // Build WHERE clause
            $conditions = ['directory_exists = 1']; // Only books with existing directories

            if (!empty($filters['search'])) {
                $conditions[] = 'title LIKE ?';
                $params[] = '%' . $filters['search'] . '%';
            }

            $whereClause = 'WHERE ' . implode(' AND ', $conditions);

            $books = DB::select("
                SELECT id, title, cover_image, directory_path
                FROM books
                {$whereClause}
                ORDER BY title ASC
                LIMIT {$perPage} OFFSET {$offset}
            ", $params);

            $total = DB::scalar("SELECT COUNT(*) FROM books {$whereClause}", $params);

            // Minimal transformation
            $data = [];
            foreach ($books as $book) {
                $data[] = [
                    'id' => $book->id,
                    'title' => $book->title ?? 'Untitled',
                    'coverImage' => $book->cover_image,
                    'directoryPath' => $book->directory_path,
                    'author' => ['Loading...'],
                    'genre' => ['Loading...'],
                    'series' => [],
                ];
            }

            return ['data' => $data, 'total' => $total];
        } catch (\Exception $e) {
            return [
                'data' => [
                    [
                        'id' => '1',
                        'title' => 'Database Error - Contact Admin',
                        'author' => ['System'],
                        'genre' => ['Error'],
                        'series' => [],
                        'coverImage' => null,
                        'directoryPath' => null,
                    ],
                ],
                'total' => 1,
            ];
        }
    }

    public function listBooks(
        int $page = 1,
        int $perPage = 24,
        array $filters = [],
        bool $withRelated = true,
        string $sort = 'title',
        string $order = 'asc',
        bool $includeAllBooks = false
    ): array {
        // Limit perPage to a reasonable maximum to prevent memory issues
        $perPage = min($perPage, 100);

        // Validate order direction
        $order = in_array(strtolower($order), ['asc', 'desc']) ? strtolower($order) : 'asc';

        $query = Book::query();

        // Exclude books with missing directories from API listings (unless admin override)
        if (!$includeAllBooks) {
            $query->withExistingDirectories();
        }

        // Exclude books flagged for review from API listings by default
        // Allow override via filters['include_needs_review'] === true
        $includeNeedsReview = (bool) ($filters['include_needs_review'] ?? false);
        if (!$includeNeedsReview) {
            $query->where('needs_review', false);
        }

        // Eager load all relationships required by the OpenAPI spec
        $query->with([
            'authors' => function ($q) {
                $q->select('authors.id', 'authors.name');
            },
            'narrators' => function ($q) {
                $q->select('narrators.id', 'narrators.name');
            },
            'genres' => function ($q) {
                $q->select('genres.id', 'genres.name');
            },
            'series' => function ($q) {
                $q->select('series.id', 'series.name', 'series.is_collection')->withPivot('series_number');
            },
        ]);

        // Apply filters
        if (!empty($filters['search'])) {
            $searchTerm = $filters['search'];
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'like', '%' . $searchTerm . '%')
                    ->orWhere('description', 'like', '%' . $searchTerm . '%')
                    ->orWhereHas('authors', function ($authorQuery) use ($searchTerm) {
                        $authorQuery->where('name', 'like', '%' . $searchTerm . '%');
                    })
                    ->orWhereHas('narrators', function ($narratorQuery) use ($searchTerm) {
                        $narratorQuery->where('name', 'like', '%' . $searchTerm . '%');
                    })
                    ->orWhereHas('series', function ($seriesQuery) use ($searchTerm) {
                        $seriesQuery->where('name', 'like', '%' . $searchTerm . '%');
                    });
            });
        }

        if (!empty($filters['title'])) {
            $query->where('title', 'like', '%' . $filters['title'] . '%');
        }

        if (!empty($filters['author'])) {
            $query->whereHas('authors', function ($q) use ($filters) {
                $q->where('name', 'like', '%' . $filters['author'] . '%');
            });
        }

        if (!empty($filters['genre'])) {
            $query->whereHas('genres', function ($q) use ($filters) {
                $q->where('name', $filters['genre']);
            });
        }

        if (!empty($filters['series'])) {
            $query->whereHas('series', function ($q) use ($filters) {
                $q->where('name', 'like', '%' . $filters['series'] . '%');
            });
        }

        if (!empty($filters['publication_date'])) {
            $query->whereYear('release_date', $filters['publication_date']);
        }

        if (!empty($filters['date_added'])) {
            // Handle 'recent' as a special keyword
            if ($filters['date_added'] === 'recent') {
                // Use the same logic as getRecentBooks - default to 30 days
                $days = 30;
                $dateThreshold = now()->subDays($days);
                $query->where('created_at', '>=', $dateThreshold);

                // Force sorting by created_at desc for recent books to ensure most recent first
                // This will override any other sort parameters
                $sort = 'created_at';
                $order = 'desc';
            } else {
                // Handle as a specific date
                try {
                    $query->whereDate('created_at', $filters['date_added']);
                } catch (\Exception $e) {
                    // Log invalid date format
                    \Illuminate\Support\Facades\Log::warning(
                        "Invalid date format for date_added filter: {$filters['date_added']}"
                    );
                }
            }
        }

        // Apply sorting
        switch ($sort) {
            case 'author':
                $query->leftJoin('author_book', 'books.id', '=', 'author_book.book_id')
                    ->leftJoin('authors', 'author_book.author_id', '=', 'authors.id')
                    ->orderBy('authors.name', $order)
                    ->select('books.*')
                    ->distinct();
                break;
            case 'series':
                $query->leftJoin('book_series', 'books.id', '=', 'book_series.book_id')
                    ->leftJoin('series', 'book_series.series_id', '=', 'series.id')
                    ->orderBy('series.name', $order)
                    ->select('books.*')
                    ->distinct();
                break;
            case 'series_number':
                $query->leftJoin('book_series', 'books.id', '=', 'book_series.book_id')
                    ->orderByRaw('CAST(book_series.series_number AS DECIMAL(10,2)) ' . $order)
                    ->select('books.*')
                    ->distinct();
                break;
            case 'genre':
                $query->leftJoin('book_genre', 'books.id', '=', 'book_genre.book_id')
                    ->leftJoin('genres', 'book_genre.genre_id', '=', 'genres.id')
                    ->orderBy('genres.name', $order)
                    ->select('books.*')
                    ->distinct();
                break;
            case 'created_at':
            case 'date_added':
                $query->orderBy('created_at', $order);
                break;
            case 'release_date':
                $query->orderBy('release_date', $order);
                break;
            case 'title':
            default:
                $query->orderBy('title', $order);
                break;
        }

        // Get total count before pagination
        $total = $query->count();

        // Apply pagination
        $books = $query->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        // Transform data to match OpenAPI Book schema
        $transformedData = $books->map(function ($book) {
            $coverUrl = null;
            if ($book->cover_image) {
                // Use the current request's hostname and protocol for the cover URL
                // This ensures URLs match the original request (e.g., https://books.thelin.org)
                $request = request();
                $coverUrl = $request->getSchemeAndHttpHost() . '/api/v1/books/' . $book->id . '/cover';
            }

            $durationFormatted = null;
            if ($book->duration) {
                // Assuming duration is stored in seconds
                $durationFormatted = gmdate('H:i:s', $book->duration);
            }

            // Format series data as an array of objects with name, series_number, and is_collection
            $seriesData = [];
            if ($book->series->isNotEmpty()) {
                foreach ($book->series as $series) {
                    $seriesData[] = [
                        'name' => $series->name,
                        'series_number' => $series->pivot->series_number,
                        'is_collection' => $series->is_collection ?? false,
                    ];
                }
            }

            return [
                'id' => $book->id,
                'title' => $book->title,
                'author' => $book->authors->pluck('name')->toArray(),
                'narrator' => $book->narrators->pluck('name')->toArray(),
                'series' => $seriesData,
                // OpenAPI spec shows string, but array is more flexible
                'genre' => $book->genres->pluck('name')->toArray(),
                'year' => $book->release_date ? (int) $book->release_date->format('Y') : null,
                'duration' => $durationFormatted,
                'description' => $book->description,
                // Add coverImage field for BookApiController::getBookWithCover
                'coverImage' => $this->buildCoverImageOutput($book->cover_image, $book->directory_path),
                'directoryPath' => $book->directory_path,
                'cover_url' => $coverUrl,
                'needs_review' => (bool) $book->needs_review,
                'file_count' => $book->audio_file_count,
                'total_size' => $book->total_size,
                'created_at' => $book->created_at ? $book->created_at->toIso8601String() : null,
                'updated_at' => $book->updated_at ? $book->updated_at->toIso8601String() : null,
            ];
        })->toArray();

        return [
            'data' => $transformedData,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => max(1, ceil($total / $perPage)),
        ];
    }

    public function getAllBooks($limit = null, $offset = 0)
    {
        $query = Book::with(['authors', 'narrators', 'genres', 'series', 'chapters']);

        if ($limit !== null) {
            $query->limit($limit)->offset($offset);
        }

        return $query->get()->map(function ($book) {
            $bookArray = $book->toArray();
            $bookArray['_id'] = (string) $book->id;

            // Transform series to match MongoDB format
            if (!empty($bookArray['series'])) {
                $series = [];
                foreach ($bookArray['series'] as $s) {
                    $series[$s['name']] = $s['pivot']['series_number'] ?? null;
                }
                $bookArray['series'] = $series;
            }

            return $bookArray;
        })->toArray();
    }

    public function dumpAllBooks()
    {
        // This is memory intensive, but matches the existing interface.
        return Book::with(['authors', 'narrators', 'genres', 'series', 'chapters'])->get()->toArray();
    }

    /**
     * Get recently added books
     *
     * @param int $limit Maximum number of recent books to return
     * @param int $days Number of days to look back for recent books
     * @return array
     */
    public function getRecentBooks(int $limit = 5, int $days = 7): array
    {
        try {
            return Book::query()
                ->select([
                    'id',
                    'title',
                    'cover_image',
                    'directory_path',
                    'created_at',
                    'description',
                    'duration',
                    'release_date',
                    'audio_file_count',
                    'total_size',
                ])
                ->where('needs_review', false)
                ->with([
                    'authors' => function ($q) {
                        $q->select('id', 'name');
                    },
                    'narrators' => function ($q) {
                        $q->select('id', 'name');
                    },
                ])
                ->where('created_at', '>=', now()->subDays($days))
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function (Book $book) {
                    $releaseDate = null;
                    if ($book->release_date) {
                        if (is_object($book->release_date) && method_exists($book->release_date, 'toDateString')) {
                            $releaseDate = $book->release_date->toDateString();
                        } else {
                            $releaseDate = (string) $book->release_date;
                        }
                    }

                    return [
                        'id' => (string) $book->id,
                        'title' => (string) $book->title,
                        'directoryPath' => $book->directory_path,
                        'coverImage' => $this->buildCoverImageOutput($book->cover_image, $book->directory_path),
                        'createdAt' => $book->created_at ? $book->created_at->toIso8601String() : null,
                        'description' => (string) ($book->description ?? ''),
                        'duration' => (int) ($book->duration ?? 0),
                        'releaseDate' => $releaseDate,
                        'audioFileCount' => (int) ($book->audio_file_count ?? 0),
                        'totalSize' => (int) ($book->total_size ?? 0),
                        'authors' => $book->authors->pluck('name')->values()->all(),
                        'narrators' => $book->narrators->pluck('name')->values()->all(),
                    ];
                })
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Error fetching recent books: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Return books flagged as needs_review with optional reason filter.
     *
     * @param string|null $reason
     * @param int $limit
     * @param int $page
     * @return array
     */
    public function listNeedsReviewBooks(?string $reason = null, int $limit = 100, int $page = 1): array
    {
        try {
            $query = Book::query()
                ->select('id', 'title', 'directory_path', 'needs_review_reasons', 'created_at')
                ->where('needs_review', true)
                ->orderBy('created_at', 'desc');

            if ($reason !== null && $reason !== '') {
                $query->where(function ($q) use ($reason) {
                    try {
                        $q->whereJsonContains('needs_review_reasons', $reason);
                    } catch (\Throwable $t) {
                        $q->where('needs_review_reasons', 'like', '%"' . addcslashes($reason, '\\"') . '"%');
                    }
                });
            }

            return $query
                ->forPage(max(1, $page), max(1, $limit))
                ->get()
                ->map(function (Book $book) {
                    return [
                        'id' => (string) $book->id,
                        'title' => (string) $book->title,
                        'directoryPath' => (string) ($book->directory_path ?? ''),
                        'needsReviewReasons' => (array) ($book->needs_review_reasons ?? []),
                        'createdAt' => $book->created_at ? $book->created_at->toIso8601String() : null,
                    ];
                })
                ->toArray();
        } catch (\Exception $e) {
            Log::error('MySqlService listNeedsReviewBooks failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Count books needing review, optionally filtered by reason.
     *
     * @param string|null $reason
     * @return int
     */
    public function countNeedsReviewBooks(?string $reason = null): int
    {
        try {
            $query = Book::query()->where('needs_review', true);

            if ($reason !== null && $reason !== '') {
                $query->where(function ($q) use ($reason) {
                    try {
                        $q->whereJsonContains('needs_review_reasons', $reason);
                    } catch (\Throwable $t) {
                        $q->where('needs_review_reasons', 'like', '%"' . addcslashes($reason, '\\"') . '"%');
                    }
                });
            }

            return $query->count();
        } catch (\Exception $e) {
            Log::error('MySqlService countNeedsReviewBooks failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Return distinct needs_review reasons across all flagged books.
     *
     * @return array
     */
    public function listNeedsReviewReasons(): array
    {
        try {
            $all = Book::query()
                ->where('needs_review', true)
                ->pluck('needs_review_reasons')
                ->all();

            $reasons = [];
            foreach ($all as $arr) {
                if (is_string($arr)) {
                    $decoded = json_decode($arr, true);
                    if (is_array($decoded)) {
                        $reasons = array_merge($reasons, $decoded);
                        continue;
                    }
                }
                if (is_array($arr)) {
                    $reasons = array_merge($reasons, $arr);
                }
            }

            // Extract base reason (before any "Parsed:" or "Document:" details)
            $baseReasons = [];
            foreach ($reasons as $reason) {
                $reasonStr = strval($reason);
                // Split on "Parsed:" or "Document:" and take the first part
                $parts = preg_split('/(Parsed:|Document:)/', $reasonStr);
                $baseReason = trim($parts[0]);
                if (!empty($baseReason)) {
                    $baseReasons[] = $baseReason;
                }
            }

            $baseReasons = array_values(array_unique(array_filter($baseReasons)));
            sort($baseReasons, SORT_NATURAL | SORT_FLAG_CASE);
            return $baseReasons;
        } catch (\Exception $e) {
            Log::error('MySqlService listNeedsReviewReasons failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Rename a series across all books
     *
     * @param string $oldName
     * @param string $newName
     * @return int Number of books updated
     */
    public function renameSeries(string $oldName, string $newName): int
    {
        try {
            // Find the old series by name
            $oldSeries = Series::where('name', $oldName)->first();

            if (!$oldSeries) {
                return 0;
            }

            // Check if new series already exists
            $newSeries = Series::where('name', $newName)->first();

            if ($newSeries) {
                // Merge: move all books from old series to new series
                $books = $oldSeries->books;
                $count = 0;

                foreach ($books as $book) {
                    // Get the series number from the old series
                    $seriesNumber = $book->series()
                        ->where('series.id', $oldSeries->id)
                        ->first()
                        ->pivot
                        ->series_number ?? null;

                    // Detach from old series
                    $book->series()->detach($oldSeries->id);

                    // Attach to new series (if not already attached)
                    if (!$book->series()->where('series.id', $newSeries->id)->exists()) {
                        $book->series()->attach($newSeries->id, [
                            'series_number' => $seriesNumber,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    $count++;
                }

                // Delete the old series if it has no more books
                if ($oldSeries->books()->count() === 0) {
                    $oldSeries->delete();
                }

                return $count;
            } else {
                // Rename: just update the series name
                $oldSeries->name = $newName;
                $oldSeries->save();

                return $oldSeries->books()->count();
            }
        } catch (\Exception $e) {
            Log::error('MySqlService renameSeries failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function listAuthors()
    {
        return Author::orderBy('name')->get()->toArray();
    }

    public function listGenres()
    {
        return Genre::orderBy('name')->get()->toArray();
    }

    public function listGenresWithStats(): array
    {
        try {
            $rows = DB::table('genres')
                ->leftJoin('book_genre', 'genres.id', '=', 'book_genre.genre_id')
                ->leftJoin('books', 'book_genre.book_id', '=', 'books.id')
                ->leftJoin('author_book', 'books.id', '=', 'author_book.book_id')
                ->groupBy('genres.id', 'genres.name')
                ->orderBy('genres.name')
                ->select(
                    'genres.id',
                    'genres.name',
                    DB::raw('COUNT(DISTINCT books.id) as book_count'),
                    DB::raw('COUNT(DISTINCT author_book.author_id) as author_count')
                )
                ->get();

            return $rows->map(function ($row) {
                return [
                    'id' => (string) $row->id,
                    'name' => (string) $row->name,
                    'bookCount' => (int) $row->book_count,
                    'authorCount' => (int) $row->author_count,
                ];
            })->toArray();
        } catch (\Exception $e) {
            Log::error('MySqlService listGenresWithStats failed: ' . $e->getMessage());

            return [];
        }
    }

    public function listAuthorsWithStats(): array
    {
        try {
            $rows = DB::table('authors')
                ->leftJoin('author_book', 'authors.id', '=', 'author_book.author_id')
                ->leftJoin('books', 'author_book.book_id', '=', 'books.id')
                ->groupBy('authors.id', 'authors.name')
                ->orderBy('authors.name')
                ->select(
                    'authors.id',
                    'authors.name',
                    DB::raw('COUNT(DISTINCT books.id) as book_count')
                )
                ->get();

            return $rows->map(function ($row) {
                return [
                    'id' => (string) $row->id,
                    'name' => (string) $row->name,
                    'bookCount' => (int) $row->book_count,
                ];
            })->toArray();
        } catch (\Exception $e) {
            Log::error('MySqlService listAuthorsWithStats failed: ' . $e->getMessage());

            return [];
        }
    }

    public function getGenreAuthorsHierarchy(string $genreId): array
    {
        try {
            $genre = Genre::find($genreId);
            if (!$genre) {
                return [
                    'genre' => null,
                    'authors' => [],
                ];
            }

            $rows = DB::table('authors')
                ->join('author_book', 'authors.id', '=', 'author_book.author_id')
                ->join('book_genre', 'author_book.book_id', '=', 'book_genre.book_id')
                ->where('book_genre.genre_id', $genreId)
                ->groupBy('authors.id', 'authors.name')
                ->orderBy('authors.name')
                ->select(
                    'authors.id',
                    'authors.name',
                    DB::raw('COUNT(DISTINCT author_book.book_id) as book_count')
                )
                ->get();

            $authors = $rows->map(function ($row) {
                return [
                    'id' => (string) $row->id,
                    'name' => (string) $row->name,
                    'bookCount' => (int) $row->book_count,
                ];
            })->toArray();

            return [
                'genre' => [
                    'id' => (string) $genre->id,
                    'name' => (string) $genre->name,
                ],
                'authors' => $authors,
            ];
        } catch (\Exception $e) {
            Log::error('MySqlService getGenreAuthorsHierarchy failed: ' . $e->getMessage());

            return [
                'genre' => null,
                'authors' => [],
            ];
        }
    }

    public function getAuthorHierarchy(string $authorId, ?string $genreId = null): array
    {
        try {
            $author = Author::find($authorId);
            if (!$author) {
                return [
                    'author' => null,
                    'genre' => null,
                    'series' => [],
                    'standaloneBooks' => [],
                ];
            }

            $genreData = null;
            if ($genreId) {
                $genre = Genre::find($genreId);
                if ($genre) {
                    $genreData = [
                        'id' => (string) $genre->id,
                        'name' => (string) $genre->name,
                    ];
                }
            }

            $seriesQuery = DB::table('series')
                ->join('book_series', 'series.id', '=', 'book_series.series_id')
                ->join('books', 'book_series.book_id', '=', 'books.id')
                ->join('author_book', 'books.id', '=', 'author_book.book_id')
                ->where('author_book.author_id', $authorId);

            if ($genreId) {
                $seriesQuery->join('book_genre', 'books.id', '=', 'book_genre.book_id')
                    ->where('book_genre.genre_id', $genreId);
            }

            $seriesRows = $seriesQuery
                ->groupBy('series.id', 'series.name')
                ->orderBy('series.name')
                ->select(
                    'series.id',
                    'series.name',
                    DB::raw('COUNT(DISTINCT books.id) as book_count')
                )
                ->get();

            $series = $seriesRows->map(function ($row) {
                return [
                    'id' => (string) $row->id,
                    'name' => (string) $row->name,
                    'bookCount' => (int) $row->book_count,
                ];
            })->toArray();

            $booksQuery = Book::query()
                ->select('id', 'title', 'directory_path')
                ->whereHas('authors', function ($q) use ($authorId) {
                    $q->where('authors.id', $authorId);
                })
                ->whereDoesntHave('series');

            if ($genreId) {
                $booksQuery->whereHas('genres', function ($q) use ($genreId) {
                    $q->where('genres.id', $genreId);
                });
            }

            $standaloneBooks = $booksQuery
                ->orderBy('title')
                ->get()
                ->map(function (Book $book) {
                    return [
                        'id' => (string) $book->id,
                        'title' => (string) $book->title,
                        'directoryPath' => $book->directory_path,
                    ];
                })
                ->toArray();

            return [
                'author' => [
                    'id' => (string) $author->id,
                    'name' => (string) $author->name,
                ],
                'genre' => $genreData,
                'series' => $series,
                'standaloneBooks' => $standaloneBooks,
            ];
        } catch (\Exception $e) {
            Log::error('MySqlService getAuthorHierarchy failed: ' . $e->getMessage());

            return [
                'author' => null,
                'genre' => null,
                'series' => [],
                'standaloneBooks' => [],
            ];
        }
    }

    public function mergeAuthors(string $primaryAuthorId, array $secondaryAuthorIds): int
    {
        $secondaryAuthorIds = array_values(array_unique(array_filter(
            $secondaryAuthorIds,
            function ($id) use ($primaryAuthorId) {
                return $id !== $primaryAuthorId;
            }
        )));

        if (empty($secondaryAuthorIds)) {
            return 0;
        }

        try {
            return DB::transaction(function () use ($primaryAuthorId, $secondaryAuthorIds) {
                $bookIds = DB::table('author_book')
                    ->whereIn('author_id', $secondaryAuthorIds)
                    ->pluck('book_id')
                    ->unique();

                foreach ($bookIds as $bookId) {
                    $exists = DB::table('author_book')
                        ->where('book_id', $bookId)
                        ->where('author_id', $primaryAuthorId)
                        ->exists();

                    if (!$exists) {
                        DB::table('author_book')->insert([
                            'book_id' => $bookId,
                            'author_id' => $primaryAuthorId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                DB::table('author_book')->whereIn('author_id', $secondaryAuthorIds)->delete();

                Author::whereIn('id', $secondaryAuthorIds)->delete();

                return $bookIds->count();
            });
        } catch (\Exception $e) {
            Log::error('MySqlService mergeAuthors failed: ' . $e->getMessage());

            return 0;
        }
    }

    public function mergeGenres(string $primaryGenreId, array $secondaryGenreIds): int
    {
        $secondaryGenreIds = array_values(array_unique(array_filter(
            $secondaryGenreIds,
            function ($id) use ($primaryGenreId) {
                return $id !== $primaryGenreId;
            }
        )));

        if (empty($secondaryGenreIds)) {
            return 0;
        }

        try {
            return DB::transaction(function () use ($primaryGenreId, $secondaryGenreIds) {
                $bookIds = DB::table('book_genre')
                    ->whereIn('genre_id', $secondaryGenreIds)
                    ->pluck('book_id')
                    ->unique();

                foreach ($bookIds as $bookId) {
                    $exists = DB::table('book_genre')
                        ->where('book_id', $bookId)
                        ->where('genre_id', $primaryGenreId)
                        ->exists();

                    if (!$exists) {
                        DB::table('book_genre')->insert([
                            'book_id' => $bookId,
                            'genre_id' => $primaryGenreId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                DB::table('book_genre')->whereIn('genre_id', $secondaryGenreIds)->delete();

                Genre::whereIn('id', $secondaryGenreIds)->delete();

                return $bookIds->count();
            });
        } catch (\Exception $e) {
            Log::error('MySqlService mergeGenres failed: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * Get a genre by ID
     *
     * @param string $id The genre ID
     * @return array|null The genre data or null if not found
     */
    public function getGenre(string $id): ?array
    {
        try {
            // First check if this is a built-in genre from config
            $configGenres = config('genres', []);
            foreach ($configGenres as $genre) {
                if (($genre['id'] ?? '') === $id) {
                    return $genre;
                }
            }

            // If not found in config, try to get from database
            $genre = Genre::find($id);

            return $genre ? $genre->toArray() : null;
        } catch (\Exception $e) {
            Log::error('MySqlService getGenre failed: ' . $e->getMessage());
            return null;
        }
    }

    public function listSeries(): array
    {
        return Series::orderBy('name')->get()->toArray();
    }

    public function getBooksInSeries(
        string $seriesId,
        string $orderBy = 'title',
        string $direction = 'asc',
        int $limit = 20,
        ?string $startAfter = null
    ): array {
        // Validate order direction
        $direction = in_array(strtolower($direction), ['asc', 'desc']) ? strtolower($direction) : 'asc';

        $query = Book::where('series_id', $seriesId)
            ->with(['authors', 'narrators', 'genres', 'series'])
            ->orderBy($orderBy, $direction);

        if ($startAfter) {
            $query->where('id', $direction === 'asc' ? '>' : '<', $startAfter);
        }

        return $query->limit($limit)->get()->toArray();
    }

    public function autocompleteAuthors(string $query, int $limit = 10): array
    {
        return Author::where('name', 'like', "%$query%")->limit($limit)->get()->toArray();
    }

    public function autocompleteNarrators(string $query, int $limit = 10): array
    {
        return Narrator::where('name', 'like', "%$query%")->limit($limit)->get()->toArray();
    }

    public function autocompleteSeries(string $query, int $limit = 10): array
    {
        return Series::where('name', 'like', "%$query%")->limit($limit)->get()->toArray();
    }

    public function listNarrators(): array
    {
        return Narrator::orderBy('name')->get()->toArray();
    }

    // --- Placeholder Implementations ---

    private function normalizeCoverImageValue(?string $coverImage): ?string
    {
        if ($coverImage === null) {
            return null;
        }

        $coverImage = trim($coverImage);
        if ($coverImage === '') {
            return null;
        }

        if (str_starts_with($coverImage, 'file://')) {
            $parsedPath = parse_url($coverImage, PHP_URL_PATH);
            if (is_string($parsedPath) && $parsedPath !== '') {
                $coverImage = $parsedPath;
            }
        }

        $parsedUrl = parse_url($coverImage);
        if (
            is_array($parsedUrl)
            && isset($parsedUrl['scheme'])
            && in_array($parsedUrl['scheme'], ['http', 'https'], true)
        ) {
            return $coverImage;
        }

        $coverImage = str_replace('\\', '/', $coverImage);

        $baseName = basename($coverImage);
        if ($baseName === '') {
            return null;
        }

        return $baseName;
    }

    private function normalizeRelatedIds(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['id'])) {
                $ids[] = $item['id'];
                continue;
            }

            if (is_string($item) || is_int($item)) {
                $value = trim((string) $item);
                if ($value !== '') {
                    $ids[] = $value;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function isLikelyIdList(array $items): bool
    {
        if (empty($items)) {
            return false;
        }

        $ids = $this->normalizeRelatedIds($items);
        if (empty($ids)) {
            return false;
        }

        foreach ($ids as $id) {
            if (!is_string($id) || $id === '' || !is_numeric($id)) {
                return false;
            }
        }

        return true;
    }

    public function createBook(array $data)
    {
        try {
            $normalizedCover = $this->normalizeCoverImageValue($data['cover_image'] ?? $data['coverImage'] ?? null);
            $book = Book::create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'release_date' => $data['release_date'] ?? null,
                'cover_image' => $normalizedCover,
                'language' => $data['language'] ?? 'en',
                'source' => $data['source'] ?? 'unknown',
                'series_id' => $data['series_id'] ?? null,
                'mongo_id' => $data['mongo_id'] ?? null,
                'directory_path' => $data['directory_path'] ?? null,
                'duration' => $data['duration'] ?? null,
                'publisher' => $data['publisher'] ?? null,
                'needs_review' => $data['needs_review'] ?? false,
                'needs_review_reasons' => $data['needs_review_reasons'] ?? null,
                'audio_file_count' => $data['audio_file_count'] ?? null,
                'mongo_record' => $data['mongo_record'] ?? null,
                'file_tags' => $data['file_tags'] ?? null,
                'audible_info' => $data['audible_info'] ?? null,
                'google_books_info' => $data['google_books_info'] ?? null,
                'hardcover_info' => $data['hardcover_info'] ?? null,
                'audiobook_bay_info' => $data['audiobook_bay_info'] ?? null,
            ]);

            // Handle authors (support both IDs and names)
            $authorData = $data['authors'] ?? $data['author'] ?? null;
            if (is_array($authorData)) {
                if ($this->isLikelyIdList($authorData)) {
                    $book->authors()->sync($this->normalizeRelatedIds($authorData));
                } else {
                    $authorIds = [];
                    foreach ($authorData as $authorName) {
                        if (is_string($authorName) || is_int($authorName)) {
                            $name = trim((string) $authorName);
                            if ($name === '') {
                                continue;
                            }
                            $author = Author::firstOrCreate(['name' => $name]);
                            $authorIds[] = $author->id;
                        }
                    }
                    $book->authors()->sync($authorIds);
                }
            }

            // Handle narrators (support both IDs and names)
            $narratorData = $data['narrators'] ?? $data['narrator'] ?? null;
            if (is_array($narratorData)) {
                if ($this->isLikelyIdList($narratorData)) {
                    $book->narrators()->sync($this->normalizeRelatedIds($narratorData));
                } else {
                    $narratorIds = [];
                    foreach ($narratorData as $narratorName) {
                        if (is_string($narratorName) || is_int($narratorName)) {
                            $name = trim((string) $narratorName);
                            if ($name === '') {
                                continue;
                            }
                            $narrator = Narrator::firstOrCreate(['name' => $name]);
                            $narratorIds[] = $narrator->id;
                        }
                    }
                    $book->narrators()->sync($narratorIds);
                }
            }

            // Handle genres (support both IDs and names)
            $genreData = $data['genres'] ?? $data['genre'] ?? null;
            if (is_array($genreData)) {
                if ($this->isLikelyIdList($genreData)) {
                    $book->genres()->sync($this->normalizeRelatedIds($genreData));
                } else {
                    $genreIds = [];
                    foreach ($genreData as $genreName) {
                        if (is_string($genreName) || is_int($genreName)) {
                            $name = trim((string) $genreName);
                            if ($name === '') {
                                continue;
                            }
                            $genre = Genre::firstOrCreate(['name' => $name]);
                            $genreIds[] = $genre->id;
                        }
                    }
                    $book->genres()->sync($genreIds);
                }
            }

            if (!empty($data['chapters'])) {
                foreach ($data['chapters'] as $chapterData) {
                    $book->chapters()->create($chapterData);
                }
            }

            return $book;
        } catch (\Exception $e) {
            Log::error(
                'MySqlService createBook failed: ' . $e->getMessage() . ' for book ' . ($data['title'] ?? 'Unknown')
            );
            throw $e; // Re-throw the exception to be caught by the calling command
        }
    }

    public function updateBook(string $id, array $data)
    {
        try {
            $book = Book::findOrFail($id);

            $normalizedCover = $this->normalizeCoverImageValue($data['cover_image'] ?? $data['coverImage'] ?? null);

            // Handle publishedYear -> release_date mapping
            if (isset($data['publishedYear']) && !empty($data['publishedYear']) && is_numeric($data['publishedYear'])) {
                $data['release_date'] = $data['publishedYear'] . '-01-01';
            }

            $book->update([
                'title' => $data['title'] ?? $book->title,
                'description' => $data['description'] ?? $book->description,
                'language' => $data['language'] ?? $book->language,
                'source' => $data['source'] ?? $book->source,
                'series_id' => $data['series_id'] ?? $book->series_id,
                'mongo_id' => $data['mongo_id'] ?? $book->mongo_id,
                'release_date' => $data['release_date'] ?? $book->release_date,
                'cover_image' => $normalizedCover ?? $book->cover_image,
                'directory_path' => $data['directory_path'] ?? $data['directoryPath'] ?? $book->directory_path,
                'duration' => $data['duration'] ?? $book->duration,
                'publisher' => $data['publisher'] ?? $book->publisher,
                'needs_review' => $data['needs_review'] ?? $book->needs_review,
                'needs_review_reasons' => $data['needs_review_reasons'] ?? $book->needs_review_reasons,
                'audio_file_count' => $data['audio_file_count'] ?? $book->audio_file_count,
                'mongo_record' => $data['mongo_record'] ?? $book->mongo_record,
                'file_tags' => $data['file_tags'] ?? $book->file_tags,
                'audible_info' => $data['audible_info'] ?? $book->audible_info,
                'google_books_info' => $data['google_books_info'] ?? $book->google_books_info,
                'hardcover_info' => $data['hardcover_info'] ?? $book->hardcover_info,
                'audiobook_bay_info' => $data['audiobook_bay_info'] ?? $book->audiobook_bay_info,
            ]);

            // Handle authors (support both 'authors' and 'author' keys)
            $authorData = $data['authors'] ?? $data['author'] ?? null;
            if (is_array($authorData)) {
                if ($this->isLikelyIdList($authorData)) {
                    $book->authors()->sync($this->normalizeRelatedIds($authorData));
                } else {
                    $authorIds = [];
                    foreach ($authorData as $authorName) {
                        if (is_string($authorName) || is_int($authorName)) {
                            $name = trim((string) $authorName);
                            if ($name === '') {
                                continue;
                            }

                            if (is_numeric($name)) {
                                $existingAuthor = Author::find($name);
                                if ($existingAuthor) {
                                    $authorIds[] = $existingAuthor->id;
                                    continue;
                                }
                            }

                            $author = Author::firstOrCreate(['name' => $name]);
                            $authorIds[] = $author->id;
                        }
                    }
                    $book->authors()->sync($authorIds);
                }
            }

            // Handle narrators (support both 'narrators' and 'narrator' keys)
            $narratorData = $data['narrators'] ?? $data['narrator'] ?? null;
            if (is_array($narratorData)) {
                if ($this->isLikelyIdList($narratorData)) {
                    $book->narrators()->sync($this->normalizeRelatedIds($narratorData));
                } else {
                    $narratorIds = [];
                    foreach ($narratorData as $narratorName) {
                        if (is_string($narratorName) || is_int($narratorName)) {
                            $name = trim((string) $narratorName);
                            if ($name === '') {
                                continue;
                            }

                            if (is_numeric($name)) {
                                $existingNarrator = Narrator::find($name);
                                if ($existingNarrator) {
                                    $narratorIds[] = $existingNarrator->id;
                                    continue;
                                }
                            }

                            $narrator = Narrator::firstOrCreate(['name' => $name]);
                            $narratorIds[] = $narrator->id;
                        }
                    }
                    $book->narrators()->sync($narratorIds);
                }
            }

            // Handle genres (support both 'genres' and 'genre' keys)
            $genreData = $data['genres'] ?? $data['genre'] ?? null;
            if (is_array($genreData)) {
                if ($this->isLikelyIdList($genreData)) {
                    $book->genres()->sync($this->normalizeRelatedIds($genreData));
                } else {
                    $genreIds = [];
                    foreach ($genreData as $genreName) {
                        if (is_string($genreName) || is_int($genreName)) {
                            $name = trim((string) $genreName);
                            if ($name === '') {
                                continue;
                            }

                            if (is_numeric($name)) {
                                $existingGenre = Genre::find($name);
                                if ($existingGenre) {
                                    $genreIds[] = $existingGenre->id;
                                    continue;
                                }
                            }

                            $genre = Genre::firstOrCreate(['name' => $name]);
                            $genreIds[] = $genre->id;
                        }
                    }
                    $book->genres()->sync($genreIds);
                }
            }

            // Handle series (support both legacy 'series_name' and new 'series' array structure)
            if (array_key_exists('series_name', $data)) {
                if ($data['series_name']) {
                    $series = Series::firstOrCreate(['name' => $data['series_name']]);
                    $book->series()->associate($series);
                } else {
                    $book->series()->dissociate();
                }
                $book->save();
            } elseif (isset($data['series']) && is_array($data['series'])) {
                // Handle new series array structure from BookController
                $seriesSyncData = [];
                foreach ($data['series'] as $seriesEntry) {
                    $seriesName = $seriesEntry['seriesName'] ?? $seriesEntry['name'] ?? null;
                    if ($seriesName) {
                        $series = Series::firstOrCreate(['name' => $seriesName]);
                        $seriesSyncData[$series->id] = [
                            'series_number' => $seriesEntry['number'] ?? null
                        ];
                    }
                }
                $book->series()->sync($seriesSyncData);
            }

            if (isset($data['chapters'])) {
                $book->chapters()->delete();
                foreach ($data['chapters'] as $chapterData) {
                    $book->chapters()->create($chapterData);
                }
            }

            return $book->toArray();
        } catch (\Exception $e) {
            Log::error(
                'MySqlService updateBook failed: ' . $e->getMessage() . ' for book ' . ($data['title'] ?? 'Unknown')
            );
            throw $e; // Re-throw the exception to be caught by the calling command
        }
    }

    public function getBooksByAuthorAndGenre(
        $author,
        $genre,
        string $orderBy = 'title',
        string $direction = 'asc',
        int $limit = 20,
        ?string $startAfter = null
    ): array {
        // Validate order direction
        $direction = in_array(strtolower($direction), ['asc', 'desc']) ? strtolower($direction) : 'asc';
        $query = Book::whereHas('authors', function ($q) use ($author) {
            $q->where('name', $author);
        })->whereHas('genres', function ($q) use ($genre) {
            $q->where('name', $genre);
        })->with(['authors', 'narrators', 'genres', 'series'])
            ->orderBy($orderBy, $direction);

        if ($startAfter) {
            $query->where('id', $direction === 'asc' ? '>' : '<', $startAfter);
        }

        return $query->limit($limit)->get()->toArray();
    }

    public function getUserById($identifier)
    {
        // Start with base columns that definitely exist
        $columns = [
            'id',
            'name',
            'username',
            'email',
            'role',
            'email_verified_at',
            'created_at',
            'updated_at',
        ];

        // Add photo_url if the column exists
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'photo_url')) {
            $columns[] = 'photo_url';
        }

        // Add google_id if the column exists
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'google_id')) {
            $columns[] = 'google_id';
        }

        $user = User::select($columns)->find($identifier);

        if (!$user) {
            return null;
        }

        // Convert to array and ensure consistent attribute naming
        $result = [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->role,
            'email_verified_at' => $user->email_verified_at,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];

        // Handle camelCase attribute naming from CamelCaseAttributeAccess trait
        $photoUrl = $user->photo_url ?? $user->photoUrl ?? null;
        if ($photoUrl) {
            $result['photo_url'] = $photoUrl;
        }

        $googleId = $user->google_id ?? $user->googleId ?? null;
        if ($googleId) {
            $result['google_id'] = $googleId;
        }

        return $result;
    }

    public function getUserByCredentials($credentials)
    {
        if (empty($credentials['email']) || empty($credentials['password'])) {
            return null;
        }

        $user = User::where('email', $credentials['email'])->first();

        if ($user && Hash::check($credentials['password'], $user->getAuthPassword())) {
            return $user->toArray();
        }

        return null;
    }

    public function getUserByRememberToken($identifier, $token)
    {
        $user = User::where('id', $identifier)->where('remember_token', $token)->first();
        return $user ? $user->toArray() : null;
    }

    public function createUser(array $data)
    {
        // Generate username from email if not provided (for Google auth, etc.)
        $username = $data['username'] ?? explode('@', $data['email'])[0];

        // Ensure username is unique
        $originalUsername = $username;
        $counter = 1;
        while (User::where('username', $username)->exists()) {
            $username = $originalUsername . $counter;
            $counter++;
        }

        $user = User::create([
            'name' => $data['name'],
            'username' => $username,
            'email' => $data['email'],
            'password' => $data['password'], // Hashed automatically by model cast
            'role' => $data['role'] ?? 'user',
            'email_verified_at' => $data['email_verified_at'] ?? null,
        ]);

        return (string) $user->id;
    }

    public function updateUser(string $id, array $data)
    {
        $user = User::findOrFail($id);
        $user->update($data);

        return $user;
    }

    public function deleteUser(string $id)
    {
        return User::where('id', $id)->delete();
    }

    /**
     * Get a user by their email address
     *
     * @param string $email The email address to search for
     * @return array|null The user data or null if not found
     */
    public function getUserByEmail(string $email): ?array
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            return null;
        }

        // Ensure hidden attributes like password are included for auth flow
        return $user->makeVisible(['password'])->toArray();
    }

    /**
     * Check if a user with the given email exists
     *
     * @param string $email The email address to check
     * @return bool True if a user with this email exists
     */
    public function userExistsByEmail(string $email): bool
    {
        return User::where('email', $email)->exists();
    }

    /**
     * Check if a user with the given username exists
     *
     * @param string $username The username to check
     * @return bool True if a user with this username exists
     */
    public function userExistsByUsername(string $username): bool
    {
        return User::where('username', $username)->exists();
    }

    public function validateUserCredentials($user, array $credentials): bool
    {
        if (!isset($credentials['password'])) {
            return false;
        }

        // If $user is an array (from getUserByCredentials), convert it to a model
        if (is_array($user)) {
            $user = User::find($user['id'] ?? null);
            if (!$user) {
                return false;
            }
        }

        // Check if the provided password matches the hashed password in the database
        return Hash::check($credentials['password'], $user->password);
    }

    /**
     * Get a user by their username
     *
     * @param string $username The username to search for
     * @return array|null The user data or null if not found
     */
    public function getUserByUsername(string $username): ?array
    {
        $user = User::where('username', $username)->first();
        if (!$user) {
            return null;
        }
        return $user->makeVisible(['password'])->toArray();
    }

    /**
     * Get all admin users
     *
     * @return array List of admin users
     */
    public function getAdminUsers(): array
    {
        return User::where('role', 'admin')->get()->toArray();
    }

    public function isAdmin(string $userId): bool
    {
        $user = User::find($userId);
        return $user && $user->role === 'admin';
    }

    public function updateRememberToken(string $identifier, string $token): void
    {
        $user = User::find($identifier);
        if ($user) {
            $user->setRememberToken($token);
            $user->save();
        }
    }

    public function getJob(string $jobId): ?array
    {
        $job = Job::find($jobId);

        return $job ? $job->toArray() : null;
    }

    public function listJobs(
        ?string $type = null,
        ?string $status = null,
        int $limit = 50,
        string $orderBy = 'updated_at',
        string $direction = 'DESC',
        ?string $startAfterId = null
    ): array {
        $query = Job::query();

        if ($type) {
            $query->where('type', $type);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($startAfterId) {
            $startJob = Job::find($startAfterId);
            if ($startJob) {
                $query->where('created_at', '<', $startJob->created_at);
            }
        }

        return $query->orderBy($orderBy, $direction)
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public function updateJob(string $jobId, array $data): bool
    {
        $job = Job::findOrFail($jobId);
        return $job->update($data);
    }

    public function getJobCount(): int
    {
        return Job::count();
    }

    public function clearJobs(): bool
    {
        return Job::truncate();
    }

    public function jobExistsByDirectoryPath(string $directoryPath): bool
    {
        return Job::where('directory_path', $directoryPath)->exists();
    }

    public function bookExistsByDirectoryPath(string $directoryPath): bool
    {
        return Book::where('directory_path', $directoryPath)->exists();
    }

    /**
     * Update a genre
     *
     * @param string $id The genre ID
     * @param array $data The updated genre data
     * @return bool True if the update was successful
     */
    public function updateGenre(string $id, array $data): bool
    {
        try {
            $genre = Genre::find($id);
            if (!$genre) {
                return false;
            }

            return $genre->update($data);
        } catch (\Exception $e) {
            Log::error('MySqlService updateGenre failed: ' . $e->getMessage());
            return false;
        }
    }

    public function getSeriesByName(string $name): ?array
    {
        $series = Series::where('name', $name)->first();

        return $series ? $series->toArray() : null;
    }

    public function findOrCreateSeriesByName(string $name)
    {
        $series = $this->getSeriesByName($name);
        if ($series) {
            return $series;
        }

        $id = $this->createSeries($name);

        return ['id' => $id, 'name' => $name];
    }

    public function getSeries(string $id)
    {
        return Series::find($id);
    }

    public function searchSeriesByName(string $term): array
    {
        return $this->autocompleteSeries($term);
    }

    public function createAuthor(array $data)
    {
        return Author::create($data);
    }

    public function searchAuthorsByName(string $term): array
    {
        return $this->autocompleteAuthors($term);
    }

    public function searchNarratorsByName(string $term): array
    {
        return $this->autocompleteNarrators($term);
    }

    public function searchGenresByName(string $term): array
    {
        if (empty($term)) {
            return [];
        }

        $genres = Genre::where('name', 'LIKE', '%' . $term . '%')
            ->orderBy('name')
            ->limit(20)
            ->pluck('name')
            ->toArray();

        return $genres;
    }

    public function getMessages(?string $userId = null, bool $includeAcknowledged = false, int $limit = 100): array
    {
        $query = Message::query();

        if ($userId) {
            $query->where('recipient_id', $userId);
        }

        if (!$includeAcknowledged) {
            $query->whereNull('acknowledged_at');
        }

        return $query->with('sender')
            ->limit($limit)
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    public function getUsersForMessaging(): array
    {
        return User::all()->toArray();
    }

    /**
     * Get all users in the system
     *
     * @return array List of all users
     */
    public function getAllUsers(): array
    {
        try {
            // Get all users with necessary fields
            $users = User::all([
                'id',
                'name',
                'username',
                'email',
                'photo_url',
                'role',
                'email_verified_at',
                'created_at',
                'updated_at',
            ]);

            // Convert to array and ensure consistent attribute naming
            return $users->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'photo_url' => $user->photo_url, // This will use the accessor
                    'role' => $user->role,
                    'email_verified_at' => $user->email_verified_at,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                    // Add google_id if it exists
                    'google_id' => $user->google_id ?? null,
                ];
            })->toArray();
        } catch (\Exception $e) {
            Log::error('MySqlService getAllUsers failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get a user's book queue
     *
     * @param string $userId The user ID
     * @return array List of books in the user's queue
     */
    public function getBookQueue(string $userId): array
    {
        try {
            $user = User::find($userId);

            if (!$user) {
                return [];
            }

            return $user->queuedBooks()->with(['authors', 'narrators', 'genres', 'series'])->get()->toArray();
        } catch (\Exception $e) {
            Log::error('MySqlService getBookQueue failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Add a book to a user's queue
     *
     * @param string $userId The user ID
     * @param string $bookId The book ID to add
     * @return bool Success status
     */
    public function addBookToQueue(string $userId, string $bookId): bool
    {
        try {
            $user = User::find($userId);
            if (!$user) {
                return false;
            }

            $book = Book::find($bookId);
            if (!$book) {
                return false;
            }

            // Check if book is already in queue
            if ($user->queuedBooks()->where('book_id', $bookId)->exists()) {
                return true; // Book already in queue, nothing to do
            }

            // Get the current highest position
            $maxPosition = $user->queuedBooks()->max('position') ?? -1;

            // Add book to queue with the next position and current timestamp
            $user->queuedBooks()->attach($bookId, [
                'position' => $maxPosition + 1,
                'added_at' => now()->timestamp,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService addBookToQueue failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove a book from a user's queue
     *
     * @param string $userId The user ID
     * @param string $bookId The book ID to remove
     * @return bool Success status
     */
    public function removeBookFromQueue(string $userId, string $bookId): bool
    {
        try {
            $user = User::find($userId);
            if (!$user) {
                return false;
            }

            $book = Book::find($bookId);
            if (!$book) {
                return false;
            }

            $user->queuedBooks()->detach($bookId);

            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService removeBookFromQueue failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update a user's book queue with a new list of book IDs
     *
     * @param string $userId The user ID
     * @param array $bookIds List of book IDs for the queue
     * @return bool Success status
     */
    public function updateBookQueue(string $userId, array $bookIds): bool
    {
        try {
            $user = User::find($userId);
            if (!$user) {
                return false;
            }

            // Sync will remove all existing relationships and create new ones
            // The second parameter allows adding pivot data if needed
            $pivotData = [];
            foreach ($bookIds as $index => $bookId) {
                $pivotData[$bookId] = [
                    'position' => $index,
                    'added_at' => now()->timestamp,
                ];
            }

            $user->queuedBooks()->sync($pivotData);

            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService updateBookQueue failed: ' . $e->getMessage());
            return false;
        }
    }

    public function resetReadingProgress(string $userId, string $bookId): bool
    {
        try {
            // Delete all reading progress records for this user and book
            DB::table('reading_progress')
                ->where('user_id', $userId)
                ->where('book_id', $bookId)
                ->delete();

            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService resetReadingProgress failed: ' . $e->getMessage());
            return false;
        }
    }

    public function getBookmarks(string $userId, string $bookId): array
    {
        return Bookmark::where('user_id', $userId)->where('book_id', $bookId)->get()->toArray();
    }

    public function getBookmark(string $bookmarkId, string $userId, string $bookId): ?array
    {
        $bookmark = Bookmark::where('id', $bookmarkId)->where('user_id', $userId)->where('book_id', $bookId)->first();

        return $bookmark ? $bookmark->toArray() : null;
    }

    public function createBookmark(array $data): string
    {
        $bookmark = Bookmark::create($data);

        return $bookmark->id;
    }

    public function updateBookmark(string $bookmarkId, array $data): bool
    {
        $bookmark = Bookmark::findOrFail($bookmarkId);

        return $bookmark->update($data);
    }

    public function deleteBookmark(string $bookmarkId, string $userId, string $bookId): bool
    {
        $bookmark = Bookmark::where('id', $bookmarkId)
            ->where('user_id', $userId)
            ->where('book_id', $bookId)
            ->firstOrFail();

        return $bookmark->delete();
    }

    // EXTERNAL READS / PREVIOUSLY READ
    public function getExternalReads(string $userId, string $bookId): array
    {
        return ExternalRead::where('user_id', $userId)
            ->where('book_id', $bookId)
            ->orderBy('started_at')
            ->get()
            ->toArray();
    }

    public function getExternalRead(string $externalReadId, string $userId, string $bookId): ?array
    {
        $entry = ExternalRead::where('id', $externalReadId)
            ->where('user_id', $userId)
            ->where('book_id', $bookId)
            ->first();

        return $entry ? $entry->toArray() : null;
    }

    public function createExternalRead(array $data): string
    {
        $entry = ExternalRead::create($data);

        return (string) $entry->id;
    }

    public function updateExternalRead(string $externalReadId, array $data): bool
    {
        $entry = ExternalRead::findOrFail($externalReadId);

        return $entry->update($data);
    }

    public function deleteExternalRead(string $externalReadId, string $userId, string $bookId): bool
    {
        $entry = ExternalRead::where('id', $externalReadId)
            ->where('user_id', $userId)
            ->where('book_id', $bookId)
            ->firstOrFail();

        return $entry->delete();
    }

    public function getDocument(string $collection, string $docId): ?array
    {
        $modelMap = [
            'users' => User::class,
            'messages' => Message::class,
            'genres' => Genre::class,
            'authors' => Author::class,
            'series' => Series::class,
            'books' => Book::class,
            'jobs' => Job::class,
            'bookmarks' => Bookmark::class,
            'external_reads' => ExternalRead::class,
        ];

        if (!isset($modelMap[$collection])) {
            return null;
        }

        $modelClass = $modelMap[$collection];

        try {
            $instance = $modelClass::find($docId);
            return $instance ? $instance->toArray() : null;
        } catch (\Exception $e) {
            Log::error(
                "Failed to get document from {$collection} (ID: {$docId}): " . $e->getMessage()
            );
            return null;
        }
    }

    public function updateDocument(string $collection, string $id, array $data): bool
    {
        $modelMap = [
            'users' => User::class,
            'messages' => Message::class,
            'genres' => Genre::class,
            'authors' => Author::class,
            'series' => Series::class,
            'books' => Book::class,
            'jobs' => Job::class,
            'bookmarks' => Bookmark::class,
            'external_reads' => ExternalRead::class,
        ];

        if (!isset($modelMap[$collection])) {
            return false;
        }

        $modelClass = $modelMap[$collection];

        try {
            $instance = $modelClass::findOrFail($id);
            return $instance->update($data);
        } catch (\Exception $e) {
            Log::error(
                "Failed to update document in {$collection} (ID: {$id}): " . $e->getMessage()
            );
            return false;
        }
    }



    public function followExists(string $userId, string $followableType, string $followableId): bool
    {
        return false;
    }

    public function getQueueCollection($name)
    {
        return null;
    }

    public function getClient()
    {
        return null; // MySQL does not have a direct "client" object like NoSQL databases
    }

    public function cleanupOldJobs(int $daysOld): int
    {
        $deletedCount = Job::where('created_at', '<=', now()->subDays($daysOld))
            ->whereIn('status', ['completed', 'failed', 'cancelled'])
            ->delete();

        return $deletedCount;
    }

    public function findOrCreateMany(string $type, array $items): array
    {
        $createdIds = [];
        foreach ($items as $item) {
            $method = 'findOrCreate' . ucfirst($type);
            if (is_array($item) && isset($item['name'])) {
                $model = $this->$method(['name' => $item['name']]);
            } elseif (is_string($item)) {
                $model = $this->$method(['name' => $item]);
            } else {
                // Log a warning or throw an exception if this case should not happen
                Log::warning("Unexpected item format in findOrCreateMany: " . json_encode($item));
                continue;
            }
            $createdIds[] = $model->id;
        }
        return $createdIds;
    }

    public function getManifestForBook(string $bookId): array
    {
        $book = Book::with(['authors', 'narrators', 'genres', 'series'])->findOrFail($bookId);

        return $book->toArray();
    }

    public function findOrCreateAuthors(array $data): Author
    {
        $name = trim((string) ($data['name'] ?? ''));
        return Author::firstOrCreate(['name' => $name]);
    }

    public function findOrCreateGenres(array $data): Genre
    {
        $name = trim((string) ($data['name'] ?? ''));
        return Genre::firstOrCreate(['name' => $name]);
    }

    public function findOrCreateNarrators(array $data): Narrator
    {
        $name = trim((string) ($data['name'] ?? ''));
        return Narrator::firstOrCreate(['name' => $name]);
    }

    public function findOrCreateSeries(array $data): Series
    {
        $name = trim((string) ($data['name'] ?? ''));
        return Series::firstOrCreate(['name' => $name]);
    }

    /**
     * Create an API token for a user
     *
     * @param array $tokenData The token data including user_id, token, etc.
     * @return string|null The token ID or null on failure
     */
    public function createApiToken(array $tokenData): ?string
    {
        try {
            $id = DB::table('api_tokens')->insertGetId($tokenData);
            return (string) $id;
        } catch (\Exception $e) {
            Log::error('MySqlService createApiToken failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete an API token by its value
     *
     * @param string $tokenValue The token value to delete
     * @return bool True if token was deleted, false otherwise
     */
    public function deleteApiTokenByValue(string $tokenValue): bool
    {
        try {
            $deleted = DB::table('api_tokens')
                ->where('token', $tokenValue)
                ->delete();

            return $deleted > 0;
        } catch (\Exception $e) {
            Log::error('MySqlService deleteApiTokenByValue failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get an author by ID
     *
     * @param string $id The author ID
     * @return array|null The author data or null if not found
     */
    public function getAuthor(string $id): ?array
    {
        $author = Author::find($id);
        return $author ? $author->toArray() : null;
    }

    /**
     * Update an author
     *
     * @param string $id The author ID
     * @param array $data The updated author data
     * @return bool True if the update was successful
     */
    public function updateAuthor(string $id, array $data): bool
    {
        try {
            $author = Author::find($id);
            if (!$author) {
                return false;
            }

            return $author->update($data);
        } catch (\Exception $e) {
            Log::error('MySqlService updateAuthor failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get pending account requests
     *
     * @return array List of pending account requests
     */
    public function getPendingAccountRequests(): array
    {
        try {
            return DB::table('account_requests')
                ->where('status', 'pending')
                ->orderBy('created_at', 'desc')
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            Log::error('MySqlService getPendingAccountRequests failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get a specific account request by ID
     *
     * @param string $id The account request ID
     * @return array|null The account request data or null if not found
     */
    public function getAccountRequest(string $id): ?array
    {
        try {
            $request = DB::table('account_requests')->where('id', $id)->first();
            return $request ? (array) $request : null;
        } catch (\Exception $e) {
            Log::error('MySqlService getAccountRequest failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Approve an account request
     *
     * @param string $id The account request ID
     * @return bool True if the request was approved successfully
     */
    public function approveAccountRequest(string $id): bool
    {
        try {
            DB::beginTransaction();

            // Get the account request
            $request = DB::table('account_requests')->where('id', $id)->first();
            if (!$request) {
                DB::rollBack();
                return false;
            }

            // Update the status to approved
            DB::table('account_requests')
                ->where('id', $id)
                ->update(['status' => 'approved', 'updated_at' => now()]);

            // Create a new user from the account request data
            $userData = [
                'name' => $request->name ?? '',
                'email' => $request->email ?? '',
                'username' => $request->username ?? '',
                'password' => Hash::make($request->password ?? Str::random(10)),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            User::create($userData);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('MySqlService approveAccountRequest failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Reject an account request
     *
     * @param string $id The account request ID
     * @return bool True if the request was rejected successfully
     */
    public function rejectAccountRequest(string $id): bool
    {
        try {
            $updated = DB::table('account_requests')
                ->where('id', $id)
                ->update([
                    'status' => 'rejected',
                    'updated_at' => now(),
                ]);

            return $updated > 0;
        } catch (\Exception $e) {
            Log::error('MySqlService rejectAccountRequest failed: ' . $e->getMessage());
            return false;
        }
    }



    public function createFollow(string $userId, string $followableType, string $followableId): bool
    {
        try {
            $follow = Follow::create([
                'user_id' => $userId,
                'followable_type' => $followableType,
                'followable_id' => $followableId,
            ]);
            return $follow ? true : false;
        } catch (\Exception $e) {
            Log::error('MySqlService createFollow failed: ' . $e->getMessage());
            return false;
        }
    }

    public function createGenre(array $data)
    {
        return Genre::create($data);
    }

    public function createMessage(array $messageData): ?string
    {
        try {
            $message = Message::create([
                'user_id' => $messageData['user_id'],
                'content' => $messageData['content'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return $message ? true : false;
        } catch (\Exception $e) {
            Log::error('MySqlService createMessage failed: ' . $e->getMessage());
            return false;
        }
    }

    public function createNarrator(array $data)
    {
        return Narrator::create($data);
    }

    public function createSeries(string $name, bool $isCollection = false)
    {
        try {
            $series = Series::create([
                'name' => $name,
                'is_collection' => $isCollection,
            ]);
            return $series->id;
        } catch (\Exception $e) {
            Log::error('MySqlService createSeries failed: ' . $e->getMessage());
            return null;
        }
    }

    public function updateSeries(int $id, array $data)
    {
        try {
            $series = Series::find($id);
            if (!$series) {
                return false;
            }
            $series->update($data);
            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService updateSeries failed: ' . $e->getMessage());
            return false;
        }
    }

    public function createJob(array $data)
    {
        try {
            $job = Job::create([
                'user_id' => $data['user_id'],
                'content' => $data['content'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return $job ? true : false;
        } catch (\Exception $e) {
            Log::error('MySqlService createJob failed: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteFollow(string $userId, string $followableType, string $followableId): bool
    {
        try {
            $follow = Follow::where('user_id', $userId)
                ->where('followable_type', $followableType)
                ->where('followable_id', $followableId)
                ->first();
            if (!$follow) {
                return false;
            }
            $follow->delete();
            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService deleteFollow failed: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteJob(string $jobId): bool
    {
        try {
            $job = Job::where('id', $jobId)->first();
            if (!$job) {
                return false;
            }
            $job->delete();
            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService deleteJob failed: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteMessage(string $messageId): bool
    {
        try {
            $message = Message::where('id', $messageId)->first();
            if (!$message) {
                return false;
            }
            $message->delete();
            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService deleteMessage failed: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteNarrator(string $narratorId): bool
    {
        try {
            $narrator = Narrator::where('id', $narratorId)->first();
            if (!$narrator) {
                return false;
            }
            $narrator->delete();
            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService deleteNarrator failed: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteSeries(string $seriesId): bool
    {
        try {
            $series = Series::where('id', $seriesId)->first();
            if (!$series) {
                return false;
            }
            $series->delete();
            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService deleteSeries failed: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteGenre(string $genreId): bool
    {
        try {
            $genre = Genre::where('id', $genreId)->first();
            if (!$genre) {
                return false;
            }
            $genre->delete();
            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService deleteGenre failed: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteAuthor(string $authorId): void
    {
        try {
            $author = Author::where('id', $authorId)->first();
            if (!$author) {
                return;
            }
            $author->delete();
        } catch (\Exception $e) {
            Log::error('MySqlService deleteAuthor failed: ' . $e->getMessage());
        }
    }

    public function deleteBook(string $bookId, bool $deleteFiles = true): bool
    {
        try {
            $book = Book::where('id', $bookId)->first();
            if (!$book) {
                return false;
            }

            // Delete physical files if requested
            if ($deleteFiles && $book->directory_path) {
                $bookStoragePath = config('filesystems.disks.books.root') ?? env('BOOK_STORAGE_PATH');
                if ($bookStoragePath) {
                    $directoryPath = $book->directory_path;

                    if (str_starts_with($directoryPath, '/')) {
                        $fullPath = $directoryPath;
                    } else {
                        $fullPath = $bookStoragePath . '/' . $directoryPath;
                    }

                    if (File::isDirectory($fullPath)) {
                        try {
                            File::deleteDirectory($fullPath);
                            Log::info('Deleted book directory', ['book_id' => $bookId, 'path' => $fullPath]);
                        } catch (\Exception $e) {
                            Log::warning('Failed to delete book directory', [
                                'book_id' => $bookId,
                                'path' => $fullPath,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            } else {
                Log::info('Skipping file deletion for book', ['book_id' => $bookId, 'delete_files' => $deleteFiles]);
            }

            $book->delete();
            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService deleteBook failed: ' . $e->getMessage());
            return false;
        }
    }

    public function deleteQueue(string $queueId): bool
    {
        try {
            $queue = Queue::where('id', $queueId)->first();
            if (!$queue) {
                return false;
            }
            $queue->delete();
            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService deleteQueue failed: ' . $e->getMessage());
            return false;
        }
    }
}
