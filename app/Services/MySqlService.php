<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DocumentStoreServiceInterface;
use App\Contracts\DocumentStatsServiceInterface;
use App\Models\Author;
use App\Models\Badge;
use App\Models\Book;
use App\Models\Bookmark;
use App\Models\BookProgress;
use App\Models\ExternalRead;
use App\Models\Genre;
use App\Models\Job;
use App\Models\LibraryRepairIssue;
use App\Models\ListeningStatistic;
use App\Models\Message;
use App\Models\Narrator;
use App\Models\ReadingSession;
use App\Models\Series;
use App\Models\User;
use App\Models\UserBookStatus;
use App\Traits\HandlesLibraryJson;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\ListeningEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MySqlService implements DocumentStoreServiceInterface, DocumentStatsServiceInterface
{
    use HandlesLibraryJson;

    private ?bool $libraryRepairIssuesTableExists = null;

    private function getTrashService(): BookTrashService
    {
        return app(BookTrashService::class);
    }

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

    public function getBook(string $id, ?int $userId = null): ?array
    {
        $query = Book::with(['authors', 'narrators', 'genres', 'series', 'chapters']);

        if ($userId) {
            $query->withUserData($userId);
        }

        $book = $query->find($id);

        if (!$book) {
            return null;
        }

        $bookArray = $book->toArray();
        $camelCasedBook = [];

        // First, copy all non-relational properties, converting keys to camelCase
        foreach ($bookArray as $key => $value) {
            if (!is_array($value)) {
                if ($key === 'cover_image') {
                    $camelCasedBook['coverImage'] = $this->buildCoverImageOutput($value, $book->directory_path);
                } else {
                    $camelCasedBook[Str::camel($key)] = $value;
                }
            }
        }

        // Handle user data if present
        if ($userId) {
            $camelCasedBook = array_merge($camelCasedBook, $this->transformUserData($book));
        }

        // Handle array-type fields that are not relationships
        // (Book model's toArray already converts to camelCase via CamelCaseAttributeAccess trait)
        if (isset($bookArray['needsReviewReasons'])) {
            $camelCasedBook['needsReviewReasons'] = $bookArray['needsReviewReasons'];
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
            $camelCasedBook['series'] = collect($bookArray['series'])->map(function ($s) {
                return [
                    'seriesName' => $s['name'],
                    'number' => $s['pivot']['series_number'] ?? null,
                    'isCollection' => $s['is_collection'] ?? false,
                ];
            })->all();
        }

        if (!empty($bookArray['chapters'])) {
            $camelCasedBook['chapters'] = $bookArray['chapters'];
        }

        return $camelCasedBook;
    }

    public function findBookByDirectoryPath(string $directoryPath): ?array
    {
        $directoryPath = trim($directoryPath, '/');

        if ($directoryPath === '') {
            return null;
        }

        $book = Book::query()
            ->select(['id', 'title', 'directory_path'])
            ->where('directory_path', $directoryPath)
            ->first();

        if (!$book) {
            return null;
        }

        return [
            'id' => (string) $book->id,
            'title' => $book->title,
            'directoryPath' => $book->directory_path,
        ];
    }

    /**
     * Get unique values for a specific field across all books.
     *
     * @param string $field The field to get unique values for (e.g., 'genre', 'author')
     * @param string|null $subField Optional subfield for nested data (e.g., 'seriesName' when field is 'series')
     *
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

    public function updateReadingProgress(string $userId, string $bookId, int $currentPositionSeconds): bool
    {
        return $this->setReadingProgress($userId, $bookId, $currentPositionSeconds);
    }

    public function setReadingProgress(string $userId, string $bookId, int $currentPositionSeconds): bool
    {
        try {
            $bookIdInt = (int) $bookId;

            if ($bookIdInt <= 0) {
                return false;
            }

            $deviceId = $userId;

            $progress = BookProgress::query()->firstOrNew([
                'book_id' => $bookIdInt,
                'device_id' => $deviceId,
            ]);

            $progress->book_id = $bookIdInt;
            $progress->device_id = $deviceId;
            $progress->current_position_seconds = max(0, $currentPositionSeconds);
            $progress->last_listened_at = now();

            $progress->save();

            try {
                ListeningEvent::create([
                    'id' => (string) Str::uuid(),
                    'user_id' => $userId,
                    'book_id' => $bookIdInt,
                    'event_type' => 'SESSION_END',
                    'timestamp_ms' => now()->timestamp * 1000,
                    'position_ms' => ($progress->current_position_seconds ?? 0) * 1000,
                    'metadata' => [
                        'source' => 'mysql_service',
                    ],
                    'device_id' => $deviceId,
                    'timezone' => 'UTC',
                    'sync_status' => 'SYNCED',
                    'created_at' => now()->timestamp * 1000,
                    'synced_at' => now()->timestamp * 1000,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to record listening event in MySqlService: ' . $e->getMessage());
            }

            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService setReadingProgress failed: ' . $e->getMessage(), [
                'userId' => $userId,
                'bookId' => $bookId,
            ]);

            return false;
        }
    }

    public function getReadingProgress(string $userId, string $bookId): int
    {
        $bookIdInt = (int) $bookId;

        if ($bookIdInt <= 0) {
            return 0;
        }

        $deviceId = $userId;

        $progress = BookProgress::query()
            ->where('book_id', $bookIdInt)
            ->where('device_id', $deviceId)
            ->first();

        if (!$progress) {
            return 0;
        }

        return (int) $progress->current_position_seconds;
    }

    public function getProgress(string $userId, string $bookId): ?int
    {
        $progress = $this->getReadingProgress($userId, $bookId);
        return $progress > 0 ? $progress : null;
    }

    public function listLibraryRepairIssues(array $filters = [], int $limit = 50, int $page = 1): array
    {
        if (!$this->ensureLibraryRepairIssuesTable()) {
            return [];
        }

        try {
            $query = LibraryRepairIssue::query()
                ->with([
                    'book.authors' => function ($q): void {
                        $q->select('authors.id', 'authors.name');
                    },
                ]);

            $this->applyLibraryRepairIssueFilters($query, $filters);

            return $query
                ->orderByDesc('created_at')
                ->forPage(max(1, $page), max(1, $limit))
                ->get()
                ->map(fn (LibraryRepairIssue $issue) => $this->transformLibraryRepairIssue($issue))
                ->toArray();
        } catch (\Throwable $e) {
            Log::error('listLibraryRepairIssues failed: ' . $e->getMessage());

            return [];
        }
    }

    public function countLibraryRepairIssues(array $filters = []): int
    {
        if (!$this->ensureLibraryRepairIssuesTable()) {
            return 0;
        }

        try {
            $query = LibraryRepairIssue::query();
            $this->applyLibraryRepairIssueFilters($query, $filters);

            return $query->count();
        } catch (\Throwable $e) {
            Log::error('countLibraryRepairIssues failed: ' . $e->getMessage());

            return 0;
        }
    }

    public function getLibraryRepairIssue(int $issueId): ?array
    {
        if (!$this->ensureLibraryRepairIssuesTable()) {
            return null;
        }

        try {
            $issue = LibraryRepairIssue::with([
                'book.authors' => function ($q): void {
                    $q->select('authors.id', 'authors.name');
                },
            ])->find($issueId);

            if (!$issue) {
                return null;
            }

            return $this->transformLibraryRepairIssue($issue);
        } catch (\Throwable $e) {
            Log::error('getLibraryRepairIssue failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * @param Builder|\Illuminate\Database\Eloquent\Relations\Relation $query
     */
    private function applyLibraryRepairIssueFilters($query, array $filters): void
    {
        if (!empty($filters['issue_type'])) {
            $query->where('issue_type', $filters['issue_type']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('directory_path', 'like', '%' . $search . '%')
                    ->orWhereHas('book', function ($bookQuery) use ($search): void {
                        $bookQuery->where('title', 'like', '%' . $search . '%');
                    });
            });
        }

        if (!empty($filters['book_id'])) {
            $query->where('book_id', $filters['book_id']);
        }

        $showResolved = $this->normalizeBooleanFilter($filters['show_resolved'] ?? null);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } elseif (!$showResolved) {
            $query->where('status', 'pending');
        }

        if (array_key_exists('auto_resolved', $filters)) {
            $query->where('auto_resolved', $this->normalizeBooleanFilter($filters['auto_resolved']));
        }
    }

    private function normalizeBooleanFilter(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function ensureLibraryRepairIssuesTable(): bool
    {
        if ($this->libraryRepairIssuesTableExists === false) {
            return false;
        }

        if ($this->libraryRepairIssuesTableExists === null) {
            $this->libraryRepairIssuesTableExists = Schema::hasTable('library_repair_issues');

            if (!$this->libraryRepairIssuesTableExists) {
                Log::notice('library_repair_issues table is missing; skipping library repair queries.');

                return false;
            }
        }

        return true;
    }

    public function resolveLibraryRepairIssue(int $issueId, ?string $resolutionNotes = null): bool
    {
        if (!$this->ensureLibraryRepairIssuesTable()) {
            return false;
        }

        try {
            /** @var LibraryRepairIssue|null $issue */
            $issue = LibraryRepairIssue::with('book')->find($issueId);

            if (!$issue) {
                return false;
            }

            $issue->status = 'resolved';
            $issue->resolution_notes = $resolutionNotes;
            $issue->resolved_at = now();
            $issue->auto_resolved = false;
            $issue->save();

            $this->clearLibraryRepairReason($issue->book);

            return true;
        } catch (\Throwable $e) {
            Log::error('resolveLibraryRepairIssue failed: ' . $e->getMessage(), [
                'issueId' => $issueId,
            ]);

            return false;
        }
    }

    private function transformLibraryRepairIssue(LibraryRepairIssue $issue): array
    {
        return [
            'id' => $issue->id,
            'issueType' => $issue->issue_type,
            'status' => $issue->status,
            'directoryPath' => $issue->directory_path,
            'metadata' => $issue->metadata ?? [],
            'autoResolved' => (bool) $issue->auto_resolved,
            'resolvedAt' => $issue->resolved_at ? $issue->resolved_at->toIso8601String() : null,
            'resolutionNotes' => $issue->resolution_notes,
            'createdAt' => $issue->created_at ? $issue->created_at->toIso8601String() : null,
            'updatedAt' => $issue->updated_at ? $issue->updated_at->toIso8601String() : null,
            'book' => $issue->book ? [
                'id' => $issue->book->id,
                'title' => $issue->book->title,
                'directoryPath' => $issue->book->directory_path,
                'authors' => $issue->book->authors->pluck('name')->all(),
                'needsReview' => (bool) $issue->book->needs_review,
                'needsReviewReasons' => (array) ($issue->book->needs_review_reasons ?? []),
            ] : null,
        ];
    }

    private function clearLibraryRepairReason(?Book $book): void
    {
        if (!$book) {
            return;
        }

        $reasons = collect($book->needs_review_reasons ?? [])
            ->reject(fn ($reason) => $reason === 'library_repair')
            ->values()
            ->all();

        $book->needs_review_reasons = $reasons;

        if (empty($reasons)) {
            $book->needs_review = false;
        }
        $book->save();
    }

    /**
     * Ultra minimal books listing to prevent memory exhaustion.
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

    private function formatIso8601DateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value)->toIso8601String();
        }

        return null;
    }

    public function listBooks(
        int $page = 1,
        int $perPage = 24,
        array $filters = [],
        bool $withRelated = true,
        string $sort = 'title',
        string $order = 'asc',
        bool $includeAllBooks = false,
        ?int $userId = null
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

        // Eager load relationships
        $query->with([
            'authors' => function ($q): void {
                $q->select('authors.id', 'authors.name');
            },
            'narrators' => function ($q): void {
                $q->select('narrators.id', 'narrators.name');
            },
            'genres' => function ($q): void {
                $q->select('genres.id', 'genres.name');
            },
            'series' => function ($q): void {
                $q->select('series.id', 'series.name', 'series.is_collection')->withPivot('series_number');
            },
        ]);

        if ($userId) {
            $query->withUserData($userId);
        }

        // Apply filters
        if (!empty($filters['search'])) {
            $searchTerm = $filters['search'];
            $query->where(function ($q) use ($searchTerm): void {
                $q->where('title', 'like', '%' . $searchTerm . '%')
                    ->orWhere('description', 'like', '%' . $searchTerm . '%')
                    ->orWhereHas('authors', function ($authorQuery) use ($searchTerm): void {
                        $authorQuery->where('name', 'like', '%' . $searchTerm . '%');
                    })
                    ->orWhereHas('narrators', function ($narratorQuery) use ($searchTerm): void {
                        $narratorQuery->where('name', 'like', '%' . $searchTerm . '%');
                    })
                    ->orWhereHas('series', function ($seriesQuery) use ($searchTerm): void {
                        $seriesQuery->where('name', 'like', '%' . $searchTerm . '%');
                    });
            });
        }

        if (!empty($filters['title'])) {
            $query->where('title', 'like', '%' . $filters['title'] . '%');
        }

        if (!empty($filters['genre_id'])) {
            $query->whereHas('genres', function ($q) use ($filters): void {
                $q->where('genres.id', $filters['genre_id']);
            });
        } elseif (!empty($filters['genre'])) {
            $query->whereHas('genres', function ($q) use ($filters): void {
                $q->where('name', $filters['genre']);
            });
        }

        if (!empty($filters['author_id'])) {
            $query->whereHas('authors', function ($q) use ($filters): void {
                $q->where('authors.id', $filters['author_id']);
            });
        } elseif (!empty($filters['author'])) {
            $query->whereHas('authors', function ($q) use ($filters): void {
                $q->where('name', 'like', '%' . $filters['author'] . '%');
            });
        }

        if (!empty($filters['series_id'])) {
            $query->whereHas('series', function ($q) use ($filters): void {
                $q->where('series.id', $filters['series_id']);
            });
        } elseif (!empty($filters['series'])) {
            $query->whereHas('series', function ($q) use ($filters): void {
                $q->where('name', 'like', '%' . $filters['series'] . '%');
            });
        }

        if (!empty($filters['publication_date'])) {
            $query->whereYear('release_date', $filters['publication_date']);
        }

        if (!empty($filters['date_added'])) {
            if ($filters['date_added'] === 'recent') {
                $days = 30;
                $dateThreshold = now()->subDays($days);
                $query->where('created_at', '>=', $dateThreshold);
                $sort = 'created_at';
                $order = 'desc';
            } else {
                try {
                    $query->whereDate('created_at', $filters['date_added']);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning(
                        "Invalid date format for date_added filter: {$filters['date_added']}"
                    );
                }
            }
        }

        // New User-specific filters
        if ($userId) {
            if (!empty($filters['status'])) {
                $query->whereHas('statuses', function ($q) use ($userId, $filters): void {
                    $q->where('user_id', $userId)->where('status', $filters['status']);
                });
            }

            if (isset($filters['is_recommended'])) {
                if ($filters['is_recommended']) {
                    $query->whereHas('recommendations', function ($q) use ($userId): void {
                        $q->where('recipient_id', $userId)->whereNull('acknowledged_at');
                    });
                } else {
                    $query->whereDoesntHave('recommendations', function ($q) use ($userId): void {
                        $q->where('recipient_id', $userId)->whereNull('acknowledged_at');
                    });
                }
            }

            if (isset($filters['is_completed'])) {
                $query->whereHas('progress', function ($q) use ($userId, $filters): void {
                    $q->where('user_id', $userId)->where('completed', (bool) $filters['is_completed']);
                });
            }

            if (!empty($filters['device_id'])) {
                $query->whereHas('progress', function ($q) use ($filters): void {
                    $q->where('device_id', $filters['device_id']);
                });
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
            case 'progress':
            case 'last_listened':
                if ($userId) {
                    $query->leftJoin('book_progress', function ($join) use ($userId) {
                        $join->on('books.id', '=', 'book_progress.book_id')
                            ->where('book_progress.user_id', '=', $userId);
                    });
                    if ($sort === 'progress') {
                        $query->orderBy('book_progress.progress_percentage', $order);
                    } else {
                        $query->orderBy('book_progress.last_listened_at', $order);
                    }
                    $query->select('books.*');
                } else {
                    $query->orderBy('title', $order);
                }
                break;
            case 'queue_order':
                if ($userId) {
                    $query->leftJoin('user_book_status', function ($join) use ($userId) {
                        $join->on('books.id', '=', 'user_book_status.book_id')
                            ->where('user_book_status.user_id', '=', $userId);
                    })->orderBy('user_book_status.order', $order)
                    ->select('books.*');
                } else {
                    $query->orderBy('title', $order);
                }
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

        // Transform data
        $transformedData = $books->map(function (Book $book) use ($userId) {
            $request = request();
            $coverUrl = null;
            if ($book->cover_image) {
                $coverUrl = $request->getSchemeAndHttpHost() . '/api/v1/books/' . $book->id . '/cover';
            }
            $durationFormatted = $book->duration ? gmdate('H:i:s', $book->duration) : null;

            $seriesData = $book->series->map(function (Series $series): array {
                $seriesNumber = null;

                // Try to get series_number from pivot
                if ($series->pivot) {
                    $seriesNumber = $series->pivot->series_number ?? null;
                }

                return [
                    'name' => $series->name,
                    'series_number' => $seriesNumber,
                    'is_collection' => $series->is_collection ?? false,
                ];
            })->toArray();

            $baseData = [
                'id' => $book->id,
                'title' => $book->title,
                'author' => $book->authors->pluck('name')->toArray(),
                'narrator' => $book->narrators->pluck('name')->toArray(),
                'series' => $seriesData,
                'genre' => $book->genres->pluck('name')->toArray(),
                'year' => $book->release_date ? (int) $book->release_date->format('Y') : null,
                'duration' => $durationFormatted,
                'description' => $book->description,
                'coverImage' => $this->buildCoverImageOutput($book->cover_image, $book->directory_path),
                'directoryPath' => $book->directory_path,
                'cover_url' => $coverUrl,
                'needs_review' => (bool) $book->needs_review,
                'file_count' => $book->audio_file_count,
                'total_size' => $book->getAttribute('total_size'),
                'created_at' => $book->created_at ? $book->created_at->toIso8601String() : null,
                'updated_at' => $book->updated_at ? $book->updated_at->toIso8601String() : null,
                // Include full relationship data for enhanced API
                'authors_data' => $book->authors->toArray(),
                'genres_data' => $book->genres->toArray(),
                'series_data' => $book->series->map(function (Series $series): array {
                    $seriesNumber = null;
                    if ($series->pivot && isset($series->pivot->series_number)) {
                        $seriesNumber = (string) $series->pivot->series_number;
                    }

                    return [
                        'id' => $series->id,
                        'name' => $series->name,
                        'is_collection' => $series->is_collection,
                        'pivot' => [
                            'series_number' => $seriesNumber,
                        ],
                    ];
                })->toArray(),
                'narrators_data' => $book->narrators->toArray(),
            ];

            if ($userId) {
                $baseData = array_merge($baseData, $this->transformUserData($book));
            }

            return $baseData;
        })->toArray();

        return [
            'data' => $transformedData,
            'total' => $total,
            'perPage' => $perPage,
            'per_page' => $perPage,
            'currentPage' => $page,
            'current_page' => $page,
            'lastPage' => max(1, (int) ceil($total / $perPage)),
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    private function transformUserData(Book $book): array
    {
        $userData = [
            'progress' => null,
            'status' => null,
            'recommendation' => null,
        ];

        if ($book->relationLoaded('progress') && $book->progress->isNotEmpty()) {
            $latestProgress = $book->progress->first();
            $userData['progress'] = [
                'position' => $latestProgress->current_position_seconds,
                'duration' => $latestProgress->total_duration_seconds,
                'percentage' => (float) $latestProgress->progress_percentage,
                'chapter' => $latestProgress->current_chapter,
                'chapterName' => $latestProgress->current_chapter_name,
                'lastListenedAt' => $latestProgress->last_listened_at?->toIso8601String(),
                'isCompleted' => (bool) $latestProgress->completed,
            ];
        }

        if ($book->relationLoaded('statuses') && $book->statuses->isNotEmpty()) {
            $status = $book->statuses->first();
            $userData['status'] = [
                'status' => $status->status,
                'order' => $status->order,
                'detail' => $status->status_detail,
                'readCount' => $status->read_count,
            ];
        }

        if ($book->relationLoaded('recommendations') && $book->recommendations->isNotEmpty()) {
            $rec = $book->recommendations->first();
            $sender = null;

            if ($rec->sender) {
                $sender = ['id' => $rec->sender->id, 'name' => $rec->sender->name];
            }

            $userData['recommendation'] = [
                'id' => $rec->id,
                'sender' => $sender,
                'message' => $rec->message,
                'sentAt' => $rec->created_at?->toIso8601String(),
            ];
        }

        return $userData;
    }

    public function getAllBooks(?int $limit = null, int $offset = 0): array
    {
        $query = Book::with(['authors', 'narrators', 'genres', 'series', 'chapters']);

        if ($limit !== null) {
            $query->limit($limit)->offset($offset);
        }

        return $query->get()->map(function (Book $book) {
            $bookArray = $book->toArray();
            $bookArray['_id'] = (string) $book->id;

            if (!isset($bookArray['directoryPath']) && isset($bookArray['directory_path'])) {
                $bookArray['directoryPath'] = $bookArray['directory_path'];
            }

            // Transform series to canonical format
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
     * Get recently added books.
     *
     * @param int $limit Maximum number of recent books to return
     * @param int $days Number of days to look back for recent books
     *
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
                    'authors' => function ($q): void {
                        $q->select('id', 'name');
                    },
                    'narrators' => function ($q): void {
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
                        $releaseDate = $book->release_date->toDateString();
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
     *
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
                $query->where(function ($q) use ($reason): void {
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
                ->map(fn (Book $book) => [
                    'id' => (string) $book->id,
                    'title' => (string) $book->title,
                    'directoryPath' => (string) ($book->directory_path ?? ''),
                    'needsReviewReasons' => (array) ($book->needs_review_reasons ?? []),
                    'createdAt' => $book->created_at ? $book->created_at->toIso8601String() : null,
                ])
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
     *
     * @return int
     */
    public function countNeedsReviewBooks(?string $reason = null): int
    {
        try {
            $query = Book::query()->where('needs_review', true);

            if ($reason !== null && $reason !== '') {
                $query->where(function ($q) use ($reason): void {
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
                $reasonStr = (string) $reason;
                // Split on "Parsed:" or "Document:" and take the first part
                $parts = preg_split('/(Parsed:|Document:)/', $reasonStr);
                $baseReason = trim($parts[0]);

                if (!empty($baseReason)) {
                    $baseReasons[] = $baseReason;
                }
            }

            $baseReasons = array_values(array_unique($baseReasons));
            sort($baseReasons, SORT_NATURAL | SORT_FLAG_CASE);

            return $baseReasons;
        } catch (\Exception $e) {
            Log::error('MySqlService listNeedsReviewReasons failed: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Rename a series across all books.
     *
     * @param string $oldName
     * @param string $newName
     *
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
                /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Book> $books */
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
            }
            // Rename: just update the series name
            $oldSeries->name = $newName;
            $oldSeries->save();

            return $oldSeries->books()->count();
        } catch (\Exception $e) {
            Log::error('MySqlService renameSeries failed: ' . $e->getMessage());

            throw $e;
        }
    }

    public function listAuthors(?int $since = null)
    {
        $query = Author::orderBy('name');
        if ($since) {
            $query->where('updated_at', '>=', date('Y-m-d H:i:s', $since));
        }
        return $query->get()->toArray();
    }

    public function listGenres(?int $since = null)
    {
        $query = Genre::orderBy('name');
        if ($since) {
            $query->where('updated_at', '>=', date('Y-m-d H:i:s', $since));
        }
        return $query->get()->toArray();
    }

    public function listGenresWithStats(?int $since = null): array
    {
        try {
            $query = DB::table('genres')
                ->leftJoin('book_genre', 'genres.id', '=', 'book_genre.genre_id')
                ->leftJoin('books', function ($join) {
                    $join->on('book_genre.book_id', '=', 'books.id')
                        ->whereNull('books.deleted_at');
                })
                ->leftJoin('author_book', 'books.id', '=', 'author_book.book_id')
                ->whereNull('genres.deleted_at')
                ->groupBy('genres.id', 'genres.name', 'genres.updated_at')
                ->orderBy('genres.name')
                ->select(
                    'genres.id',
                    'genres.name',
                    'genres.updated_at',
                    DB::raw('COUNT(DISTINCT books.id) as book_count'),
                    DB::raw('COUNT(DISTINCT author_book.author_id) as author_count')
                );

            if ($since) {
                $query->where('genres.updated_at', '>=', date('Y-m-d H:i:s', $since));
            }

            $rows = $query->get();

            return $rows->map(fn ($row) => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'updatedAt' => $row->updated_at,
                'bookCount' => (int) $row->book_count,
                'authorCount' => (int) $row->author_count,
            ])->toArray();
        } catch (\Exception $e) {
            Log::error('MySqlService listGenresWithStats failed: ' . $e->getMessage());

            return [];
        }
    }

    public function listAuthorsWithStats(?int $since = null): array
    {
        try {
            $query = DB::table('authors')
                ->leftJoin('author_book', 'authors.id', '=', 'author_book.author_id')
                ->leftJoin('books', function ($join) {
                    $join->on('author_book.book_id', '=', 'books.id')
                        ->whereNull('books.deleted_at')
                        ->where('books.directory_exists', true)
                        ->where('books.needs_review', false);
                })
                ->whereNull('authors.deleted_at')
                ->groupBy('authors.id', 'authors.name', 'authors.updated_at')
                ->orderBy('authors.name')
                ->select(
                    'authors.id',
                    'authors.name',
                    'authors.updated_at',
                    DB::raw('COUNT(DISTINCT books.id) as book_count')
                );

            if ($since) {
                $query->where('authors.updated_at', '>=', date('Y-m-d H:i:s', $since));
            }

            $rows = $query->get();

            return $rows->map(fn ($row) => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'updatedAt' => $row->updated_at,
                'bookCount' => (int) $row->book_count,
            ])->toArray();
        } catch (\Exception $e) {
            Log::error('MySqlService listAuthorsWithStats failed: ' . $e->getMessage());

            return [];
        }
    }

    public function paginateAuthorsWithStats(
        int $perPage = 25,
        ?string $search = null,
        string $sort = 'name',
        string $direction = 'asc'
    ): \Illuminate\Contracts\Pagination\LengthAwarePaginator {
        $query = DB::table('authors')
            ->leftJoin('author_book', 'authors.id', '=', 'author_book.author_id')
            ->leftJoin('books', function ($join) {
                $join->on('author_book.book_id', '=', 'books.id')
                    ->whereNull('books.deleted_at')
                    ->where('books.directory_exists', true)
                    ->where('books.needs_review', false);
            })
            ->whereNull('authors.deleted_at')
            ->groupBy('authors.id', 'authors.name', 'authors.updated_at')
            ->select(
                'authors.id',
                'authors.name',
                'authors.updated_at',
                DB::raw('COUNT(DISTINCT books.id) as book_count')
            );

        if ($search) {
            $query->where('authors.name', 'LIKE', '%' . $search . '%');
        }

        if ($sort === 'books') {
            $query->orderBy('book_count', $direction)->orderBy('authors.name', 'asc');
        } else {
            $query->orderBy('authors.name', $direction);
        }

        return $query->paginate($perPage);
    }

    public function getAuthorsByIdsWithStats(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $rows = DB::table('authors')
            ->leftJoin('author_book', 'authors.id', '=', 'author_book.author_id')
            ->leftJoin('books', function ($join) {
                $join->on('author_book.book_id', '=', 'books.id')
                    ->whereNull('books.deleted_at')
                    ->where('books.directory_exists', true)
                    ->where('books.needs_review', false);
            })
            ->whereNull('authors.deleted_at')
            ->whereIn('authors.id', $ids)
            ->groupBy('authors.id', 'authors.name', 'authors.updated_at')
            ->orderBy('authors.name')
            ->select(
                'authors.id',
                'authors.name',
                'authors.updated_at',
                DB::raw('COUNT(DISTINCT books.id) as book_count')
            )
            ->get();

        return $rows->map(fn ($row) => [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'updatedAt' => $row->updated_at,
            'bookCount' => (int) $row->book_count,
        ])->toArray();
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

            $authors = $rows->map(fn ($row) => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'bookCount' => (int) $row->book_count,
            ])->toArray();

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

            $series = $seriesRows->map(fn ($row) => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'bookCount' => (int) $row->book_count,
            ])->toArray();

            $booksQuery = Book::query()
                ->select('id', 'title', 'directory_path')
                ->whereHas('authors', function ($q) use ($authorId): void {
                    $q->where('authors.id', $authorId);
                })
                ->whereDoesntHave('series');

            if ($genreId) {
                $booksQuery->whereHas('genres', function ($q) use ($genreId): void {
                    $q->where('genres.id', $genreId);
                });
            }

            $standaloneBooks = $booksQuery
                ->orderBy('title')
                ->get()
                ->map(fn (Book $book) => [
                    'id' => (string) $book->id,
                    'title' => (string) $book->title,
                    'directoryPath' => $book->directory_path,
                ])
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
            fn ($id) => $id !== $primaryAuthorId
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
            fn ($id) => $id !== $primaryGenreId
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

    public function mergeSeries(string $primarySeriesId, array $secondarySeriesIds): int
    {
        $secondarySeriesIds = array_values(array_unique(array_filter(
            $secondarySeriesIds,
            fn ($id) => $id !== $primarySeriesId
        )));

        if (empty($secondarySeriesIds)) {
            return 0;
        }

        try {
            return DB::transaction(function () use ($primarySeriesId, $secondarySeriesIds) {
                $secondaryPivots = DB::table('book_series')
                    ->whereIn('series_id', $secondarySeriesIds)
                    ->get();

                $bookIds = $secondaryPivots->pluck('book_id')->unique();

                foreach ($secondaryPivots as $pivot) {
                    $exists = DB::table('book_series')
                        ->where('book_id', $pivot->book_id)
                        ->where('series_id', $primarySeriesId)
                        ->exists();

                    if (!$exists) {
                        DB::table('book_series')->insert([
                            'book_id' => $pivot->book_id,
                            'series_id' => $primarySeriesId,
                            'series_number' => $pivot->series_number,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                DB::table('book_series')->whereIn('series_id', $secondarySeriesIds)->delete();

                Series::whereIn('id', $secondarySeriesIds)->delete();

                return $bookIds->count();
            });
        } catch (\Exception $e) {
            Log::error('MySqlService mergeSeries failed: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * Get a genre by ID.
     *
     * @param string $id The genre ID
     *
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

    public function listSeries(?int $since = null): array
    {
        $query = Series::orderBy('name');
        if ($since) {
            $query->where('updated_at', '>=', date('Y-m-d H:i:s', $since));
        }
        return $query->get()->toArray();
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
            $bookId = $data['id'] ?? null;
            $directoryPath = $data['directory_path'] ?? $data['directoryPath'] ?? null;
            $releaseDate = $data['release_date'] ?? $data['releaseDate'] ?? null;
            $needsReview = $data['needs_review'] ?? $data['needsReview'] ?? false;
            $needsReviewReasons = $data['needs_review_reasons'] ?? $data['needsReviewReasons'] ?? null;
            $audioFileCount = $data['audio_file_count'] ?? $data['audioFileCount'] ?? null;

            $normalizedCover = $this->normalizeCoverImageValue($data['cover_image'] ?? $data['coverImage'] ?? null);

            $attributes = [
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'release_date' => $releaseDate,
                'cover_image' => $normalizedCover,
                'language' => $data['language'] ?? 'en',
                'source' => $data['source'] ?? 'unknown',
                'series_id' => $data['series_id'] ?? null,
                'mongo_id' => $data['mongo_id'] ?? null,
                'directory_path' => $directoryPath,
                'duration' => $data['duration'] ?? null,
                'publisher' => $data['publisher'] ?? null,
                'needs_review' => $needsReview,
                'needs_review_reasons' => $needsReviewReasons,
                'audio_file_count' => $audioFileCount,
                'mongo_record' => $data['mongo_record'] ?? null,
                'file_tags' => $data['file_tags'] ?? null,
                'audible_info' => $data['audible_info'] ?? null,
                'google_books_info' => $data['google_books_info'] ?? null,
                'hardcover_info' => $data['hardcover_info'] ?? null,
                'audiobook_bay_info' => $data['audiobook_bay_info'] ?? null,
            ];

            if ($bookId !== null && is_numeric($bookId)) {
                $existingBook = Book::withTrashed()->find((int) $bookId);

                if ($existingBook) {
                    if ($existingBook->trashed()) {
                        $existingBook->restore();
                    }

                    $existingBook->update($attributes);
                    $book = $existingBook;
                } else {
                    $book = new Book();
                    $book->id = (int) $bookId;
                    $book->fill($attributes);
                    $book->save();
                }
            } else {
                $book = Book::create($attributes);
            }

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
                'needs_review' => $data['needs_review'] ?? $data['needsReview'] ?? $book->needs_review,
                'needs_review_reasons' => $this->resolveNeedsReviewReasons($data, $book),
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

            // Handle series (prioritize new 'series' array structure over legacy 'series_name')
            if (isset($data['series']) && is_array($data['series'])) {
                // Handle new series array structure from BookController
                $seriesSyncData = [];

                foreach ($data['series'] as $seriesEntry) {
                    $seriesName = $seriesEntry['seriesName'] ?? $seriesEntry['name'] ?? null;

                    if ($seriesName) {
                        $series = Series::firstOrCreate(['name' => $seriesName]);
                        $seriesSyncData[$series->id] = [
                            'series_number' => $seriesEntry['number'] ?? null,
                        ];
                    }
                }
                $book->series()->sync($seriesSyncData);
            } elseif (array_key_exists('series_name', $data)) {
                if ($data['series_name']) {
                    $series = Series::firstOrCreate(['name' => $data['series_name']]);
                    $book->series()->sync([
                        $series->id => [
                            'series_number' => null,
                        ],
                    ]);
                } else {
                    $book->series()->detach();
                }
            }

            if (isset($data['chapters'])) {
                $book->chapters()->delete();

                foreach ($data['chapters'] as $chapterData) {
                    $book->chapters()->create($chapterData);
                }
            }

            $book->refresh();
            $book->load(['authors', 'narrators', 'genres', 'series', 'publisher']);

            $bookArray = $book->toArray();

            $this->updateLibraryJson($book);

            return $bookArray;
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
        int $limit = 100,
        ?string $startAfter = null,
        ?int $userId = null
    ): array {
        // Use the unified listBooks method to ensure consistent behavior including user data
        $result = $this->listBooks(
            page: 1,
            perPage: $limit,
            filters: [
                'author_id' => $author,
                'genre_id' => $genre,
            ],
            sort: $orderBy,
            order: $direction,
            userId: $userId
        );

        return $result['data'];
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
        if (empty($credentials['password'])) {
            return null;
        }

        // Support both email and username login
        $user = null;
        if (!empty($credentials['email'])) {
            $user = User::where('email', $credentials['email'])->first();
        } elseif (!empty($credentials['username'])) {
            $user = User::where('username', $credentials['username'])->first();
        }

        if (!$user) {
            return null;
        }

        if (Hash::check($credentials['password'], $user->getAuthPassword())) {
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
            'role' => $data['role'] ?? 'library-user',
            'email_verified_at' => $data['email_verified_at'] ?? null,
            'google_id' => $data['google_id'] ?? null,
            'facebook_id' => $data['facebook_id'] ?? null,
            'apple_id' => $data['apple_id'] ?? null,
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
     * Get a user by their email address.
     *
     * @param string $email The email address to search for
     *
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

    public function getUserByAppleId(string $appleId): ?array
    {
        $appleId = trim($appleId);

        if ($appleId === '') {
            return null;
        }

        $user = User::where('apple_id', $appleId)->first();

        if (!$user) {
            return null;
        }

        return $user->makeVisible(['password'])->toArray();
    }

    /**
     * Check if a user with the given email exists.
     *
     * @param string $email The email address to check
     *
     * @return bool True if a user with this email exists
     */
    public function userExistsByEmail(string $email): bool
    {
        return User::where('email', $email)->exists();
    }

    /**
     * Check if a user with the given username exists.
     *
     * @param string $username The username to check
     *
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
     * Get a user by their username.
     *
     * @param string $username The username to search for
     *
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
     * Get all admin users.
     *
     * @return array List of admin users
     */
    public function getAdminUsers(): array
    {
        return User::whereIn('role', ['admin', 'super-admin'])->get()->toArray();
    }

    public function isAdmin(string $userId): bool
    {
        $user = User::find($userId);

        return $user && in_array($user->role, ['admin', 'super-admin'], true);
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

    public function getJobs(): array
    {
        return Job::query()
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (Job $job): array {
                return [
                    'id' => (string) $job->id,
                    'type' => (string) $job->type,
                    'status' => (string) $job->status,
                    'data' => $job->payload,
                    'startedAt' => $job->created_at ? $job->created_at->toIso8601String() : null,
                ];
            })
            ->toArray();
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

    public function updateJobStatus(string $jobId, string $type, string $status, array $metadata = []): bool
    {
        $job = Job::firstOrNew(['id' => $jobId]);
        $job->type = $type;
        $job->status = $status;
        $job->metadata = array_merge($job->metadata ?? [], $metadata);
        return $job->save();
    }

    public function getJobCount(): int
    {
        return Job::count();
    }

    public function clearJobs(): bool
    {
        Job::truncate();

        return true;
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
     * Update a genre.
     *
     * @param string $id The genre ID
     * @param array $data The updated genre data
     *
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
     * Get all users in the system.
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
            return $users->map(fn ($user) => [
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
            ])->toArray();
        } catch (\Exception $e) {
            Log::error('MySqlService getAllUsers failed: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Get user activity data (progress, badges, reviews, etc.)
     *
     * @param string $userId
     * @return array
     */
    /**
     * Get user activity data (progress, badges, reviews, etc.)
     *
     * @param string $userId
     * @return array
     */
    public function getUserActivityData(string $userId): array
    {
        try {
            /** @var User|null $user */
            $user = User::with([
                'badges.badge',
                'progress.book',
                'reviews.book',
                'recommendationsReceived.book',
                'recommendationsReceived.sender',
                'bookStatuses.book'
            ])->find($userId);

            if (!$user) {
                return [];
            }

            // Get all badges to show unearned ones, sorted by progression
            $allBadges = \App\Models\Badge::active()->get()->sort(function ($a, $b) {
                if ($a->sort_order !== $b->sort_order) {
                    return $a->sort_order <=> $b->sort_order;
                }

                // Tier weight for reliable progression (Bronze -> Silver -> Gold)
                $tiers = ['bronze' => 1, 'silver' => 2, 'gold' => 3, 'platinum' => 4, 'diamond' => 5];
                $weightA = $tiers[$a->tier] ?? 99;
                $weightB = $tiers[$b->tier] ?? 99;

                if ($weightA !== $weightB) {
                    return $weightA <=> $weightB;
                }

                return strcmp($a->name, $b->name);
            });
            $earnedBadgeIds = $user->badges->pluck('badge_id')->toArray();

            $badgesByCategory = $allBadges->groupBy('category')->map(function ($badges) use ($earnedBadgeIds, $user) {
                // Filter to show all earned badges + the first unearned one (next level)
                $filteredBadges = collect([]);
                $foundNextUnearned = false;

                foreach ($badges as $badge) {
                    $isEarned = in_array($badge->id, $earnedBadgeIds);

                    if ($isEarned) {
                        $filteredBadges->push($badge);
                    } elseif (!$foundNextUnearned) {
                        $filteredBadges->push($badge);
                        $foundNextUnearned = true;
                    }
                }

                return $filteredBadges->map(function (\App\Models\Badge $badge) use ($earnedBadgeIds, $user): array {
                    $isEarned = in_array($badge->id, $earnedBadgeIds);
                    $userBadge = $isEarned ? $user->badges->firstWhere('badge_id', $badge->id) : null;

                    $iconPath = "images/badges/{$badge->key}.svg";
                    $hasIconFile = file_exists(public_path($iconPath));

                    return [
                        'id' => $badge->id,
                        'name' => $badge->name,
                        'icon' => $hasIconFile ? "/{$iconPath}" : null, // Use SVG if exists
                        'emoji' => $badge->icon, // Original emoji
                        'description' => $badge->description,
                        'tier' => $badge->tier,
                        'is_earned' => $isEarned,
                        'earned_at' => $userBadge?->earned_at,
                    ];
                })->all();
            });

            // Derive progress from ListeningEvents (New System)
            $listeningEvents = \App\Models\ListeningEvent::where('user_id', $userId)
                ->with('book')
                ->orderBy('timestamp_ms', 'desc')
                ->get()
                ->groupBy('book_id');

            $derivedProgress = $listeningEvents->map(function ($events) {
                /** @var ListeningEvent $latest */
                $latest = $events->first();
                /** @var Book|null $book */
                $book = $latest->book;

                // Calculate percentage
                $percentage = 0;
                $metadata = $latest->metadata ?? [];
                if (isset($metadata['progress_percentage'])) {
                    $percentage = $metadata['progress_percentage'];
                } elseif ($book instanceof Book && $book->duration) {
                    $percentage = ($latest->position_ms / ($book->duration * 1000)) * 100;
                }

                $isCompleted = $latest->event_type === 'BOOK_FINISH' || $latest->event_type === 'BOOK_MARK_COMPLETE' || $percentage >= 95;

                return [
                    'book_id' => $latest->book_id,
                    'book_title' => $book ? $book->title : 'Unknown Book',
                    'percentage' => (float) $percentage,
                    'last_listened_at' => \Carbon\Carbon::createFromTimestampMs($latest->timestamp_ms),
                    'completed' => $isCompleted,
                ];
            })->values();

            // Derive statuses from listening activity
            $derivedStatuses = $derivedProgress->map(function ($item) {
                return [
                    'book_id' => $item['book_id'],
                    'book_title' => $item['book_title'],
                    'status' => $item['completed'] ? 'Finished' : 'In Progress',
                    'updated_at' => $item['last_listened_at'],
                ];
            });

            return [
                'badges_by_category' => $badgesByCategory->toArray(),
                'progress' => $derivedProgress->toArray(),
                'reviews' => $user->reviews->map(fn ($r) => [
                    'book_id' => $r->book_id,
                    'book_title' => $r->book->title,
                    'comment' => $r->comment,
                    'age_rating' => $r->age_rating,
                    'content_rating' => $r->content_rating,
                    'created_at' => $r->created_at,
                ])->toArray(),
                'recommendations' => $user->recommendationsReceived->map(fn ($rec) => [
                    'book_id' => $rec->book_id,
                    'book_title' => $rec->book->title,
                    'sender_name' => $rec->sender?->name,
                    'message' => $rec->message,
                    'created_at' => $rec->created_at,
                    'acknowledged_at' => $rec->acknowledged_at,
                ])->toArray(),
                'statuses' => $derivedStatuses->toArray(),
                'tips' => $this->getBadgeTips($userId),
            ];
        } catch (\Exception $e) {
            Log::error('MySqlService getUserActivityData failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get badge tips for a user.
     *
     * @param string $userId
     * @return array
     */
    public function getBadgeTips(string $userId): array
    {
        $allBadges = \App\Models\Badge::active()->ordered()->get();
        $earnedBadgeIds = \App\Models\UserBadge::where('user_id', $userId)->pluck('badge_id')->toArray();

        $tips = [];
        $categories = $allBadges->groupBy('category');

        foreach ($categories as $category => $badges) {
            // Filter out earned badges
            $unearnedBadges = $badges->filter(function ($badge) use ($earnedBadgeIds) {
                return !in_array($badge->id, $earnedBadgeIds);
            });

            if ($unearnedBadges->isEmpty()) {
                continue;
            }

            // Get the first unearned badge in the sequence
            $nextBadge = $unearnedBadges->first();

            $iconPath = "images/badges/{$nextBadge->key}.svg";
            $hasIconFile = file_exists(public_path($iconPath));

            $tips[] = [
                'category' => $category,
                'badge_name' => $nextBadge->name,
                'description' => $nextBadge->description,
                'tip' => "Aim for the '{$nextBadge->name}' badge: {$nextBadge->description}",
                'icon' => $hasIconFile ? "/{$iconPath}" : null,
                'emoji' => $nextBadge->icon,
            ];
        }

        return $tips;
    }

    /**
     * Get a user's book queue.
     *
     * @param string $userId The user ID
     *
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
     * Add a book to a user's queue.
     *
     * @param string $userId The user ID
     * @param string $bookId The book ID to add
     *
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
            $maxPosition = $user->queuedBooks()->max('order') ?? -1;

            // Add book to queue with the next position and current timestamp
            UserBookStatus::create([
                'user_id' => (int) $userId,
                'book_id' => (int) $bookId,
                'order' => (int) $maxPosition + 1,
                'status' => 'queue',
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService addBookToQueue failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Remove a book from a user's queue.
     *
     * @param string $userId The user ID
     * @param string $bookId The book ID to remove
     *
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

            UserBookStatus::where('user_id', (int) $userId)
                ->where('book_id', (int) $bookId)
                ->where('status', 'queue')
                ->delete();

            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService removeBookFromQueue failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Update a user's book queue with a new list of book IDs.
     *
     * @param string $userId The user ID
     * @param array $bookIds List of book IDs for the queue
     *
     * @return bool Success status
     */
    public function updateBookQueue(string $userId, array $bookIds): bool
    {
        try {
            $user = User::find($userId);

            if (!$user) {
                return false;
            }

            $existing = UserBookStatus::where('user_id', (int) $userId)
                ->where('status', 'queue')
                ->pluck('book_id')
                ->map(fn ($id) => (string) $id)
                ->toArray();

            $target = array_values(array_map(fn ($id) => (string) $id, $bookIds));

            $toDelete = array_diff($existing, $target);

            if ($toDelete !== []) {
                UserBookStatus::where('user_id', (int) $userId)
                    ->where('status', 'queue')
                    ->whereIn('book_id', array_map('intval', $toDelete))
                    ->delete();
            }

            foreach ($target as $index => $bookId) {
                UserBookStatus::updateOrCreate(
                    [
                        'user_id' => (int) $userId,
                        'book_id' => (int) $bookId,
                    ],
                    [
                        'status' => 'queue',
                        'order' => (int) $index,
                    ]
                );
            }

            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService updateBookQueue failed: ' . $e->getMessage());

            return false;
        }
    }

    public function resetReadingProgress(string $userId, string $bookId): bool
    {
        try {
            $bookIdInt = (int) $bookId;

            if ($bookIdInt <= 0) {
                return false;
            }

            $deviceId = $userId;

            BookProgress::query()
                ->where('book_id', $bookIdInt)
                ->where('device_id', $deviceId)
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

        return (string) $bookmark->id;
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

    public function deleteBookmarkById(string $bookmarkId, string $userId): bool
    {
        $bookmark = Bookmark::where('id', $bookmarkId)
            ->where('user_id', $userId)
            ->first();

        if (!$bookmark) {
            return false;
        }

        $bookmark->forceDelete();

        return true;
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
            'narrators' => Narrator::class,
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
                Log::warning('Unexpected item format in findOrCreateMany: ' . json_encode($item));
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

        /** @var Author $author */
        $author = Author::firstOrCreate(['name' => $name]);

        return $author;
    }

    public function findOrCreateGenres(array $data): Genre
    {
        $name = trim((string) ($data['name'] ?? ''));

        /** @var Genre $genre */
        $genre = Genre::firstOrCreate(['name' => $name]);

        return $genre;
    }

    public function findOrCreateNarrators(array $data): Narrator
    {
        $name = trim((string) ($data['name'] ?? ''));

        /** @var Narrator $narrator */
        $narrator = Narrator::firstOrCreate(['name' => $name]);

        return $narrator;
    }

    public function findOrCreateSeries(array $data): Series
    {
        $name = trim((string) ($data['name'] ?? ''));

        /** @var Series $series */
        $series = Series::firstOrCreate(['name' => $name]);

        return $series;
    }

    /**
     * Create an API token for a user.
     *
     * @param array $tokenData the token data including user_id, token, etc
     *
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

    public function getApiTokenByValue(string $tokenValue): ?array
    {
        try {
            $row = DB::table('api_tokens')->where('token', $tokenValue)->first();

            return $row ? (array) $row : null;
        } catch (\Exception $e) {
            Log::error('MySqlService getApiTokenByValue failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Delete an API token by its value.
     *
     * @param string $tokenValue The token value to delete
     *
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
     * Get an author by ID.
     *
     * @param string $id The author ID
     *
     * @return array|null The author data or null if not found
     */
    public function getAuthor(string $id): ?array
    {
        $author = Author::find($id);

        return $author ? $author->toArray() : null;
    }

    /**
     * Update an author.
     *
     * @param string $id The author ID
     * @param array $data The updated author data
     *
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
     * Get pending account requests.
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
     * Get a specific account request by ID.
     *
     * @param string $id The account request ID
     *
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
     * Approve an account request.
     *
     * @param string $id The account request ID
     *
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
     * Reject an account request.
     *
     * @param string $id The account request ID
     *
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
            return DB::table('follows')->insert([
                'user_id' => $userId,
                'followable_type' => $followableType,
                'followable_id' => $followableId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
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
                'sender_id' => $messageData['sender_id'],
                'recipient_id' => $messageData['recipient_id'],
                'content' => $messageData['content'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return (string) $message->id;
        } catch (\Exception $e) {
            Log::error('MySqlService createMessage failed: ' . $e->getMessage());

            return null;
        }
    }

    public function acknowledgeMessage(string $messageId): bool
    {
        $id = (int) $messageId;

        if ($id <= 0) {
            return false;
        }

        try {
            $updated = Message::query()
                ->whereKey($id)
                ->whereNull('acknowledged_at')
                ->update(['acknowledged_at' => now()]);

            return $updated > 0;
        } catch (\Exception $e) {
            Log::error('MySqlService acknowledgeMessage failed: ' . $e->getMessage());

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

            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService createJob failed: ' . $e->getMessage());

            return false;
        }
    }

    public function deleteFollow(string $userId, string $followableType, string $followableId): bool
    {
        try {
            return DB::table('follows')
                ->where('user_id', $userId)
                ->where('followable_type', $followableType)
                ->where('followable_id', $followableId)
                ->delete() > 0;
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
                Log::warning('Book not found for deletion', ['book_id' => $bookId]);
                return false;
            }

            $book->delete();

            Log::info('Book deleted from database', [
                'book_id' => $bookId,
                'delete_files' => $deleteFiles,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to delete book from database', [
                'book_id' => $bookId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function recordReadingSession(string $userId, string $bookId, array $data): array
    {
        try {
            $session = ReadingSession::create([
                'user_id' => $userId,
                'book_id' => $bookId,
                'started_at' => $data['started_at'] ?? now(),
                'ended_at' => $data['ended_at'] ?? now(),
                'duration_seconds' => $data['duration_seconds'] ?? 0,
                'pages' => $data['pages'] ?? null,
                'position_start' => $data['position_start'] ?? null,
                'position_end' => $data['position_end'] ?? null,
                'device' => $data['device'] ?? null,
            ]);

            return $session->toArray();
        } catch (\Exception $e) {
            Log::error('MySqlService recordReadingSession failed: ' . $e->getMessage(), [
                'userId' => $userId,
                'bookId' => $bookId,
            ]);

            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function getDailyStats(string $userId, ?string $from = null, ?string $to = null): array
    {
        try {
            $query = ReadingSession::where('user_id', $userId);

            if ($from) {
                $query->whereDate('started_at', '>=', $from);
            }

            if ($to) {
                $query->whereDate('started_at', '<=', $to);
            }

            return $query->selectRaw('
                    DATE(started_at) as date,
                    SUM(duration_seconds) as duration_seconds,
                    COUNT(*) as sessions,
                    COUNT(DISTINCT book_id) as books
                ')
                ->whereNotNull('started_at')
                ->groupBy('date')
                ->orderBy('date')
                ->get()
                ->map(function (ReadingSession $row): array {
                    return [
                        'date' => (string) $row->getAttribute('date'),
                        'duration_seconds' => (int) $row->getAttribute('duration_seconds'),
                        'sessions' => (int) $row->getAttribute('sessions'),
                        'books' => (int) $row->getAttribute('books'),
                    ];
                })
                ->toArray();
        } catch (\Exception $e) {
            Log::error('MySqlService getDailyStats failed: ' . $e->getMessage(), [
                'userId' => $userId,
                'from' => $from,
                'to' => $to,
            ]);

            return [];
        }
    }

    /**
     * @inheritDoc
     */
    public function getBookStats(string $userId, string $bookId): array
    {
        try {
            $stats = ReadingSession::where('user_id', $userId)
                ->where('book_id', $bookId)
                ->selectRaw('
                    SUM(duration_seconds) as total_duration_seconds,
                    COUNT(*) as sessions,
                    MIN(started_at) as first_started_at,
                    MAX(ended_at) as last_ended_at
                ')
                ->first();

            if (!$stats) {
                return [
                    'total_duration_seconds' => 0,
                    'sessions' => 0,
                    'first_started_at' => null,
                    'last_ended_at' => null,
                ];
            }

            return [
                'total_duration_seconds' => (int) ($stats->getAttribute('total_duration_seconds') ?? 0),
                'sessions' => (int) ($stats->getAttribute('sessions') ?? 0),
                'first_started_at' => $this->formatIso8601DateTime($stats->getAttribute('first_started_at')),
                'last_ended_at' => $this->formatIso8601DateTime($stats->getAttribute('last_ended_at')),
            ];
        } catch (\Exception $e) {
            Log::error('MySqlService getBookStats failed: ' . $e->getMessage(), [
                'userId' => $userId,
                'bookId' => $bookId,
            ]);

            return [
                'total_duration_seconds' => 0,
                'sessions' => 0,
                'first_started_at' => null,
                'last_ended_at' => null,
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function getUserStats(string $userId): array
    {
        try {
            $stats = ReadingSession::where('user_id', $userId)
                ->selectRaw('
                    SUM(duration_seconds) as total_duration_seconds,
                    COUNT(*) as sessions,
                    COUNT(DISTINCT DATE(started_at)) as active_days
                ')
                ->whereNotNull('started_at')
                ->first();

            $streaks = $this->getStreaks($userId);

            return [
                'total_duration_seconds' => (int) ($stats?->getAttribute('total_duration_seconds') ?? 0),
                'sessions' => (int) ($stats?->getAttribute('sessions') ?? 0),
                'active_days' => (int) ($stats?->getAttribute('active_days') ?? 0),
                'streak_current' => $streaks['current'] ?? 0,
                'streak_longest' => $streaks['longest'] ?? 0,
            ];
        } catch (\Exception $e) {
            Log::error('MySqlService getUserStats failed: ' . $e->getMessage(), [
                'userId' => $userId,
            ]);

            return [
                'total_duration_seconds' => 0,
                'sessions' => 0,
                'active_days' => 0,
                'streak_current' => 0,
                'streak_longest' => 0,
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function getStreaks(string $userId): array
    {
        try {
            $dates = ReadingSession::where('user_id', $userId)
                ->selectRaw('DISTINCT DATE(started_at) as reading_date')
                ->whereNotNull('started_at')
                ->orderBy('reading_date', 'desc')
                ->pluck('reading_date')
                ->map(fn ($date) => is_string($date) ? $date : (string) $date)
                ->values()
                ->toArray();

            if (empty($dates)) {
                return [
                    'current' => 0,
                    'longest' => 0,
                    'last_active_date' => null,
                ];
            }

            $lastActiveDate = $dates[0];
            $today = now()->format('Y-m-d');
            $yesterday = now()->subDay()->format('Y-m-d');

            $currentStreak = 0;
            $longestStreak = 0;
            $tempStreak = 0;

            foreach ($dates as $i => $date) {
                if ($i === 0) {
                    $tempStreak = 1;
                } else {
                    $prevDate = $dates[$i - 1];
                    $currentDate = strtotime($date);
                    $prevDateTime = strtotime($prevDate);

                    if ($currentDate === $prevDateTime - 86400) {
                        $tempStreak++;
                    } else {
                        $tempStreak = 1;
                    }
                }

                $longestStreak = max($longestStreak, $tempStreak);
            }

            if ($lastActiveDate === $today || $lastActiveDate === $yesterday) {
                $currentStreak = $tempStreak;
            }

            return [
                'current' => $currentStreak,
                'longest' => $longestStreak,
                'last_active_date' => $dates[0] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('MySqlService getStreaks failed: ' . $e->getMessage(), [
                'userId' => $userId,
            ]);

            return [
                'current' => 0,
                'longest' => 0,
                'last_active_date' => null,
            ];
        }
    }

    public function createReview(array $data): string
    {
        $review = \App\Models\Review::create($data);
        return (string) $review->id;
    }

    public function linkNonLibraryBooks(): int
    {
        $linkedCount = 0;
        $models = [
            ExternalRead::class,
            ListeningStatistic::class,
            ReadingSession::class,
            UserBookStatus::class,
        ];

        foreach ($models as $modelClass) {
            $records = $modelClass::whereNull('book_id')
                ->whereNotNull('title')
                ->whereNotNull('author')
                ->get();

            foreach ($records as $record) {
                $book = Book::where('title', $record->title)
                    ->whereHas('authors', function ($query) use ($record) {
                        $query->where('name', 'like', '%' . $record->author . '%');
                    })
                    ->first();

                if ($book) {
                    $record->book_id = $book->id;
                    $record->save();
                    $linkedCount++;

                    // Create a message for the user if user_id is available
                    $recipientId = null;
                    if (isset($record->user_id)) {
                        $recipientId = (int) $record->user_id;
                    } elseif (isset($record->deviceId)) {
                        // In some models user_id might be stored in device_id field temporarily or vice versa
                        $recipientId = (int) $record->deviceId;
                    }

                    if ($recipientId) {
                        $content = "Your statistical data for '";
                        $content .= $record->title;
                        $content .= "' has been linked to '";
                        $content .= $book->title;
                        $content .= "' in the library.";

                        Message::create([
                            'sender_id' => null, // System message
                            'recipient_id' => $recipientId,
                            'type' => 'book_linked',
                            'content' => $content,
                            'payload' => [
                                'book_id' => $book->id,
                                'title' => $book->title,
                                'original_title' => $record->title,
                                'original_author' => $record->author,
                            ],
                        ]);
                    }
                }
            }
        }

        return $linkedCount;
    }

    private function resolveNeedsReviewReasons(array $data, Book $book)
    {
        if (array_key_exists('needs_review_reasons', $data)) {
            return $data['needs_review_reasons'];
        }

        if (array_key_exists('needsReviewReasons', $data)) {
            return $data['needsReviewReasons'];
        }

        return $book->needs_review_reasons;
    }
}
