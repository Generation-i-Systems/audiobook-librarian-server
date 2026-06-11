<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DocumentStoreServiceInterface;
use App\Contracts\DocumentStatsServiceInterface;
use App\Models\Author;
use App\Models\Badge;
use App\Models\Book;
use App\Models\BookProgress;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Series;
use App\Models\User;
use App\Traits\HandlesLibraryJson;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\ListeningEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MySqlService implements DocumentStoreServiceInterface, DocumentStatsServiceInterface
{
    use HandlesLibraryJson;

    private ?BookDataTransformer $bookDataTransformer = null;
    private ?BookMutationService $bookMutationService = null;
    private ?LibraryRepairIssueStore $libraryRepairIssueStore = null;
    private ?UserLibraryStateService $userLibraryStateService = null;
    private ?UserAccountService $userAccountService = null;
    private ?UserReadingStatsService $userReadingStatsService = null;
    private ?WorkflowMessagingService $workflowMessagingService = null;
    private ?UserActivityService $userActivityService = null;
    private ?GenericDocumentService $genericDocumentService = null;
    private ?TaxonomyService $taxonomyService = null;
    private ?AdminMaintenanceService $adminMaintenanceService = null;
    private ?TokenMaintenanceService $tokenMaintenanceService = null;
    private ?LegacyCompatibilityService $legacyCompatibilityService = null;
    private ?LibriVoxApiService $libriVoxApiService = null;

    private function getTrashService(): BookTrashService
    {
        return app(BookTrashService::class);
    }

    private function getBookDataTransformer(): BookDataTransformer
    {
        return $this->bookDataTransformer ??= app(BookDataTransformer::class);
    }

    private function getBookMutationService(): BookMutationService
    {
        return $this->bookMutationService ??= app(BookMutationService::class);
    }

    private function getLibraryRepairIssueStore(): LibraryRepairIssueStore
    {
        return $this->libraryRepairIssueStore ??= app(LibraryRepairIssueStore::class);
    }

    private function getUserLibraryStateService(): UserLibraryStateService
    {
        return $this->userLibraryStateService ??= app(UserLibraryStateService::class);
    }

    private function getUserAccountService(): UserAccountService
    {
        return $this->userAccountService ??= app(UserAccountService::class);
    }

    private function getUserReadingStatsService(): UserReadingStatsService
    {
        return $this->userReadingStatsService ??= app(UserReadingStatsService::class);
    }

    private function getWorkflowMessagingService(): WorkflowMessagingService
    {
        return $this->workflowMessagingService ??= app(WorkflowMessagingService::class);
    }

    private function getUserActivityService(): UserActivityService
    {
        return $this->userActivityService ??= app(UserActivityService::class);
    }

    private function getGenericDocumentService(): GenericDocumentService
    {
        return $this->genericDocumentService ??= app(GenericDocumentService::class);
    }

    private function getTaxonomyService(): TaxonomyService
    {
        return $this->taxonomyService ??= app(TaxonomyService::class);
    }

    private function getAdminMaintenanceService(): AdminMaintenanceService
    {
        return $this->adminMaintenanceService ??= app(AdminMaintenanceService::class);
    }

    private function getTokenMaintenanceService(): TokenMaintenanceService
    {
        return $this->tokenMaintenanceService ??= app(TokenMaintenanceService::class);
    }

    private function getLegacyCompatibilityService(): LegacyCompatibilityService
    {
        return $this->legacyCompatibilityService ??= app(LegacyCompatibilityService::class);
    }

    private function getLibriVoxApiService(): LibriVoxApiService
    {
        return $this->libriVoxApiService ??= app(LibriVoxApiService::class);
    }

    public function getBook(string $id, ?int $userId = null): ?array
    {
        $sourceMode = (string) config('library_profiles.active_source_mode', 'local');

        if ($sourceMode === 'librivox') {
            return $this->getLibrivoxBook($id);
        }

        $query = Book::with(['authors', 'narrators', 'genres', 'series', 'chapters']);

        if ($userId) {
            $query->withUserData($userId);
        }

        $book = $query->find($id);

        if ($book) {
            return $this->getBookDataTransformer()->toDocumentStoreBook($book, $userId);
        }

        // hybrid: fall back to LibriVox catalog
        if ($sourceMode === 'hybrid') {
            return $this->getLibrivoxBook($id);
        }

        return null;
    }

    private function getLibrivoxBook(string $id): ?array
    {
        $adapter = new LibriVoxBookAdapter();

        /** @var \App\Models\LibriVox\Book|null $book */
        $book = \App\Models\LibriVox\Book::with(['authors', 'genres', 'chapters'])->find($id)
            ?? \App\Models\LibriVox\Book::with(['authors', 'genres', 'chapters'])->where('librivox_id', $id)->first();

        if ($book) {
            return $adapter->toDocumentStoreBook($book);
        }

        $apiBook = $this->getLibriVoxApiService()->getById($id);
        if ($apiBook !== null) {
            return $adapter->fromApiArray($apiBook);
        }

        return null;
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
        return $this->getLibraryRepairIssueStore()->listIssues($filters, $limit, $page);
    }

    public function countLibraryRepairIssues(array $filters = []): int
    {
        return $this->getLibraryRepairIssueStore()->countIssues($filters);
    }

    public function getLibraryRepairIssue(int $issueId): ?array
    {
        return $this->getLibraryRepairIssueStore()->getIssue($issueId);
    }

    public function resolveLibraryRepairIssue(int $issueId, ?string $resolutionNotes = null): bool
    {
        return $this->getLibraryRepairIssueStore()->resolveIssue($issueId, $resolutionNotes);
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
        $sourceMode = (string) config('library_profiles.active_source_mode', 'local');

        if ($sourceMode === 'librivox') {
            return $this->listLibrivoxBooks($page, $perPage, $filters, $order);
        }

        if ($sourceMode === 'hybrid') {
            return $this->listHybridBooks($page, $perPage, $filters, $sort, $order, $includeAllBooks, $userId);
        }

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

        if ($userId && !empty($filters['tag']) && Schema::hasTable('book_tags')) {
            $tag = trim((string) $filters['tag']);

            if ($tag !== '') {
                $query->whereHas('userTags', function ($q) use ($userId, $tag): void {
                    $q->where('user_id', $userId)->whereJsonContains('tags', $tag);
                });
            }
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
                    ->orderByRaw('CAST(book_series.series_number AS DECIMAL(10,2)) ASC')
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

        $transformedData = $books
            ->map(fn (Book $book) => $this->getBookDataTransformer()->toBookListItem($book, $userId))
            ->toArray();

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

    /** @return \Illuminate\Database\Eloquent\Builder<\App\Models\LibriVox\Book> */
    private function buildLibrivoxQuery(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $query = \App\Models\LibriVox\Book::with(['authors', 'genres', 'chapters']);

        if (!empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term): void {
                $q->where('title', 'like', "%{$term}%")
                  ->orWhereHas('authors', fn ($q) => $q->where('name', 'like', "%{$term}%"));
            });
        }
        if (!empty($filters['title'])) {
            $query->where('title', 'like', '%' . $filters['title'] . '%');
        }
        if (!empty($filters['genre'])) {
            $query->whereHas('genres', fn ($q) => $q->where('name', $filters['genre']));
        }
        if (!empty($filters['genre_id'])) {
            $query->whereHas('genres', fn ($q) => $q->where('librivox_genres.id', $filters['genre_id']));
        }
        if (!empty($filters['author'])) {
            $query->whereHas('authors', fn ($q) => $q->where('name', 'like', '%' . $filters['author'] . '%'));
        }
        if (!empty($filters['author_id'])) {
            $query->whereHas('authors', fn ($q) => $q->where('librivox_authors.id', $filters['author_id']));
        }
        if (!empty($filters['language'])) {
            $query->where('language', $filters['language']);
        }

        return $query;
    }

    private function listLibrivoxBooks(int $page, int $perPage, array $filters, string $order): array
    {
        $perPage = min($perPage, 100);
        $order = in_array(strtolower($order), ['asc', 'desc']) ? strtolower($order) : 'asc';
        $adapter = new LibriVoxBookAdapter();

        if (!\App\Models\LibriVox\Book::exists()) {
            return $this->listLibrivoxBooksFromApi($page, $perPage, $filters, $adapter);
        }

        $query = $this->buildLibrivoxQuery($filters)->orderBy('title', $order);

        $total = $query->count();
        $books = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        $data = $books->map(fn ($b) => $adapter->toDocumentStoreBook($b))->all();

        return [
            'data'         => $data,
            'total'        => $total,
            'perPage'      => $perPage,
            'per_page'     => $perPage,
            'currentPage'  => $page,
            'current_page' => $page,
            'lastPage'     => max(1, (int) ceil($total / $perPage)),
            'last_page'    => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function listLibrivoxBooksFromApi(int $page, int $perPage, array $filters, LibriVoxBookAdapter $adapter): array
    {
        $offset = ($page - 1) * $perPage;
        $search = (string) ($filters['search'] ?? $filters['title'] ?? '');
        $language = (string) ($filters['language'] ?? '');

        if ($search !== '') {
            $raw = $this->getLibriVoxApiService()->searchBooks($search, []) ?? [];
            $total = count($raw);
            $slice = array_slice($raw, $offset, $perPage);
        } else {
            $raw = $this->getLibriVoxApiService()->listBooks($perPage, $offset, null, $language ?: null) ?? [];
            $slice = $raw['books'] ?? [];
            $total = $perPage * $page;
        }

        $data = array_map(fn ($b) => $adapter->fromApiArray($b), $slice);

        return [
            'data'         => $data,
            'total'        => $total,
            'perPage'      => $perPage,
            'per_page'     => $perPage,
            'currentPage'  => $page,
            'current_page' => $page,
            'lastPage'     => max(1, (int) ceil($total / $perPage)),
            'last_page'    => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * Hybrid mode: interleave local and LibriVox books sorted by title.
     * Fetches both counts, then fills each page from whichever source owns that slice.
     */
    private function listHybridBooks(
        int $page,
        int $perPage,
        array $filters,
        string $sort,
        string $order,
        bool $includeAllBooks,
        ?int $userId
    ): array {
        // Run local query (reuses full filter/sort logic by calling self recursively with local mode)
        $origMode = config('library_profiles.active_source_mode');
        config(['library_profiles.active_source_mode' => 'local']);
        $localResult = $this->listBooks($page, $perPage, $filters, true, $sort, $order, $includeAllBooks, $userId);
        config(['library_profiles.active_source_mode' => $origMode]);

        $localTotal = (int) ($localResult['total'] ?? 0);
        $localPages = max(1, (int) ceil($localTotal / $perPage));

        if ($page <= $localPages) {
            // This page is within local results; append LibriVox total to the combined count
            $lvTotal = $this->buildLibrivoxQuery($filters)->count();

            return array_merge($localResult, [
                'total'    => $localTotal + $lvTotal,
                'lastPage' => max(1, (int) ceil(($localTotal + $lvTotal) / $perPage)),
                'last_page' => max(1, (int) ceil(($localTotal + $lvTotal) / $perPage)),
            ]);
        }

        // Page is beyond local books — serve LibriVox pages
        $lvPage = $page - $localPages;
        $lvResult = $this->listLibrivoxBooks($lvPage, $perPage, $filters, $order);
        $lvTotal = (int) ($lvResult['total'] ?? 0);

        return array_merge($lvResult, [
            'total'        => $localTotal + $lvTotal,
            'currentPage'  => $page,
            'current_page' => $page,
            'lastPage'     => max(1, (int) ceil(($localTotal + $lvTotal) / $perPage)),
            'last_page'    => max(1, (int) ceil(($localTotal + $lvTotal) / $perPage)),
        ]);
    }

    public function getAllBooks(?int $limit = null, int $offset = 0): array
    {
        $query = Book::with(['authors', 'narrators', 'genres', 'series', 'chapters']);

        if ($limit !== null) {
            $query->limit($limit)->offset($offset);
        }

        return $query->get()->map(
            fn (Book $book) => $this->getBookDataTransformer()->toExportedBook($book)
        )->toArray();
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
                ->map(fn (Book $book) => $this->getBookDataTransformer()->toRecentBook($book))
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
            $hasGenreEmoji = Schema::hasColumn('genres', 'emoji');
            $hasGenreIconPath = Schema::hasColumn('genres', 'icon_path');

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

            if ($hasGenreEmoji) {
                $query->addSelect('genres.emoji')
                    ->groupBy('genres.emoji');
            }

            if ($hasGenreIconPath) {
                $query->addSelect('genres.icon_path')
                    ->groupBy('genres.icon_path');
            }

            if ($since) {
                $query->where('genres.updated_at', '>=', date('Y-m-d H:i:s', $since));
            }

            $rows = $query->get();

            return $rows->map(fn ($row) => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'emoji' => $hasGenreEmoji ? ($row->emoji ?: null) : null,
                'iconPath' => $hasGenreIconPath ? ($row->icon_path ?: null) : null,
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
        return $this->getTaxonomyService()->autocompleteAuthors($query, $limit);
    }

    public function autocompleteNarrators(string $query, int $limit = 10): array
    {
        return $this->getTaxonomyService()->autocompleteNarrators($query, $limit);
    }

    public function autocompleteSeries(string $query, int $limit = 10): array
    {
        return $this->getTaxonomyService()->autocompleteSeries($query, $limit);
    }

    public function listNarrators(): array
    {
        return Narrator::orderBy('name')->get()->toArray();
    }

    // --- Placeholder Implementations ---

    public function createBook(array $data)
    {
        try {
            return $this->getBookMutationService()->createBook($data);
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
            $book = $this->getBookMutationService()->updateBook($id, $data);

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
        return $this->getUserAccountService()->getUserById($identifier);
    }

    public function getUserByCredentials($credentials)
    {
        return $this->getUserAccountService()->getUserByCredentials($credentials);
    }

    public function getUserByRememberToken($identifier, $token)
    {
        return $this->getUserAccountService()->getUserByRememberToken($identifier, $token);
    }

    public function createUser(array $data)
    {
        return $this->getUserAccountService()->createUser($data);
    }

    public function updateUser(string $id, array $data)
    {
        return $this->getUserAccountService()->updateUser($id, $data);
    }

    public function deleteUser(string $id)
    {
        return $this->getUserAccountService()->deleteUser($id);
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
        return $this->getUserAccountService()->getUserByEmail($email);
    }

    public function getUserByAppleId(string $appleId): ?array
    {
        return $this->getUserAccountService()->getUserByAppleId($appleId);
    }

    public function getUserByDiscordId(string $discordId): ?array
    {
        return $this->getUserAccountService()->getUserByDiscordId($discordId);
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
        return $this->getUserAccountService()->userExistsByEmail($email);
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
        return $this->getUserAccountService()->userExistsByUsername($username);
    }

    public function validateUserCredentials($user, array $credentials): bool
    {
        return $this->getUserAccountService()->validateUserCredentials($user, $credentials);
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
        return $this->getUserAccountService()->getUserByUsername($username);
    }

    /**
     * Get all admin users.
     *
     * @return array List of admin users
     */
    public function getAdminUsers(): array
    {
        return $this->getUserAccountService()->getAdminUsers();
    }

    public function isAdmin(string $userId): bool
    {
        return $this->getUserAccountService()->isAdmin($userId);
    }

    public function updateRememberToken(string $identifier, string $token): void
    {
        $this->getUserAccountService()->updateRememberToken($identifier, $token);
    }

    public function getJob(string $jobId): ?array
    {
        return $this->getWorkflowMessagingService()->getJob($jobId);
    }

    public function getJobs(): array
    {
        return $this->getWorkflowMessagingService()->getJobs();
    }

    public function listJobs(
        ?string $type = null,
        ?string $status = null,
        int $limit = 50,
        string $orderBy = 'updated_at',
        string $direction = 'DESC',
        ?string $startAfterId = null
    ): array {
        return $this->getWorkflowMessagingService()->listJobs(
            $type,
            $status,
            $limit,
            $orderBy,
            $direction,
            $startAfterId
        );
    }

    public function updateJob(string $jobId, array $data): bool
    {
        return $this->getWorkflowMessagingService()->updateJob($jobId, $data);
    }

    public function updateJobStatus(string $jobId, string $type, string $status, array $metadata = []): bool
    {
        return $this->getLegacyCompatibilityService()->updateJobStatus($jobId, $type, $status, $metadata);
    }

    public function getJobCount(): int
    {
        return $this->getWorkflowMessagingService()->getJobCount();
    }

    public function clearJobs(bool $confirmed = false): bool
    {
        return $this->getWorkflowMessagingService()->clearJobs($confirmed);
    }

    public function jobExistsByDirectoryPath(string $directoryPath): bool
    {
        return $this->getLegacyCompatibilityService()->jobExistsByDirectoryPath($directoryPath);
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
        return $this->getTaxonomyService()->updateGenre($id, $data);
    }

    public function getSeriesByName(string $name): ?array
    {
        return $this->getTaxonomyService()->getSeriesByName($name);
    }

    public function findOrCreateSeriesByName(string $name)
    {
        return $this->getTaxonomyService()->findOrCreateSeriesByName($name);
    }

    public function getSeries(string $id)
    {
        return $this->getTaxonomyService()->getSeries($id);
    }

    public function searchSeriesByName(string $term): array
    {
        return $this->getTaxonomyService()->searchSeriesByName($term);
    }

    public function createAuthor(array $data)
    {
        return $this->getTaxonomyService()->createAuthor($data);
    }

    public function searchAuthorsByName(string $term): array
    {
        return $this->getTaxonomyService()->searchAuthorsByName($term);
    }

    public function searchNarratorsByName(string $term): array
    {
        return $this->getTaxonomyService()->searchNarratorsByName($term);
    }

    public function searchGenresByName(string $term): array
    {
        return $this->getTaxonomyService()->searchGenresByName($term);
    }

    public function getMessages(?string $userId = null, bool $includeAcknowledged = false, int $limit = 100): array
    {
        return $this->getWorkflowMessagingService()->getMessages($userId, $includeAcknowledged, $limit);
    }

    public function getUsersForMessaging(): array
    {
        return $this->getWorkflowMessagingService()->getUsersForMessaging();
    }

    /**
     * Get all users in the system.
     *
     * @return array List of all users
     */
    public function getAllUsers(): array
    {
        return $this->getAdminMaintenanceService()->getAllUsers();
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
        return $this->getUserActivityService()->getUserActivityData($userId);
    }

    /**
     * Get badge tips for a user.
     *
     * @param string $userId
     * @return array
     */
    public function getBadgeTips(string $userId): array
    {
        return $this->getUserActivityService()->getBadgeTips($userId);
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
        return $this->getUserLibraryStateService()->getBookQueue($userId);
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
        return $this->getUserLibraryStateService()->addBookToQueue($userId, $bookId);
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
        return $this->getUserLibraryStateService()->removeBookFromQueue($userId, $bookId);
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
        return $this->getUserLibraryStateService()->updateBookQueue($userId, $bookIds);
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
        return $this->getUserLibraryStateService()->getBookmarks($userId, $bookId);
    }

    public function getBookmark(string $bookmarkId, string $userId, string $bookId): ?array
    {
        return $this->getUserLibraryStateService()->getBookmark($bookmarkId, $userId, $bookId);
    }

    public function createBookmark(array $data): string
    {
        return $this->getUserLibraryStateService()->createBookmark($data);
    }

    public function updateBookmark(string $bookmarkId, array $data): bool
    {
        return $this->getUserLibraryStateService()->updateBookmark($bookmarkId, $data);
    }

    public function deleteBookmark(string $bookmarkId, string $userId, string $bookId): bool
    {
        return $this->getUserLibraryStateService()->deleteBookmark($bookmarkId, $userId, $bookId);
    }

    public function deleteBookmarkById(string $bookmarkId, string $userId): bool
    {
        return $this->getUserLibraryStateService()->deleteBookmarkById($bookmarkId, $userId);
    }

    // EXTERNAL READS / PREVIOUSLY READ
    public function getExternalReads(string $userId, string $bookId): array
    {
        return $this->getUserLibraryStateService()->getExternalReads($userId, $bookId);
    }

    public function getExternalRead(string $externalReadId, string $userId, string $bookId): ?array
    {
        return $this->getUserLibraryStateService()->getExternalRead($externalReadId, $userId, $bookId);
    }

    public function createExternalRead(array $data): string
    {
        return $this->getUserLibraryStateService()->createExternalRead($data);
    }

    public function updateExternalRead(string $externalReadId, array $data): bool
    {
        return $this->getUserLibraryStateService()->updateExternalRead($externalReadId, $data);
    }

    public function deleteExternalRead(string $externalReadId, string $userId, string $bookId): bool
    {
        return $this->getUserLibraryStateService()->deleteExternalRead($externalReadId, $userId, $bookId);
    }

    public function getDocument(string $collection, string $docId): ?array
    {
        return $this->getGenericDocumentService()->getDocument($collection, $docId);
    }

    public function updateDocument(string $collection, string $id, array $data): bool
    {
        return $this->getGenericDocumentService()->updateDocument($collection, $id, $data);
    }

    public function followExists(string $userId, string $followableType, string $followableId): bool
    {
        return $this->getLegacyCompatibilityService()->followExists($userId, $followableType, $followableId);
    }

    public function getQueueCollection($name)
    {
        return $this->getLegacyCompatibilityService()->getQueueCollection($name);
    }

    public function getClient()
    {
        return $this->getLegacyCompatibilityService()->getClient();
    }

    public function cleanupOldJobs(int $daysOld): int
    {
        return $this->getTokenMaintenanceService()->cleanupOldJobs($daysOld);
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
        return $this->getTokenMaintenanceService()->createApiToken($tokenData);
    }

    public function getApiTokenByValue(string $tokenValue): ?array
    {
        return $this->getTokenMaintenanceService()->getApiTokenByValue($tokenValue);
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
        return $this->getTokenMaintenanceService()->deleteApiTokenByValue($tokenValue);
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
        return $this->getTaxonomyService()->updateAuthor($id, $data);
    }

    /**
     * Get pending account requests.
     *
     * @return array List of pending account requests
     */
    public function getPendingAccountRequests(): array
    {
        return $this->getUserAccountService()->getPendingAccountRequests();
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
        return $this->getUserAccountService()->getAccountRequest($id);
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
        return $this->getUserAccountService()->approveAccountRequest($id);
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
        return $this->getUserAccountService()->rejectAccountRequest($id);
    }

    public function createFollow(string $userId, string $followableType, string $followableId): bool
    {
        return $this->getWorkflowMessagingService()->createFollow($userId, $followableType, $followableId);
    }

    public function createGenre(array $data)
    {
        return $this->getTaxonomyService()->createGenre($data);
    }

    public function createMessage(array $messageData): ?string
    {
        return $this->getWorkflowMessagingService()->createMessage($messageData);
    }

    public function acknowledgeMessage(string $messageId): bool
    {
        return $this->getWorkflowMessagingService()->acknowledgeMessage($messageId);
    }

    public function createNarrator(array $data)
    {
        return $this->getTaxonomyService()->createNarrator($data);
    }

    public function createSeries(string $name, bool $isCollection = false)
    {
        return $this->getTaxonomyService()->createSeries($name, $isCollection);
    }

    public function updateSeries(int $id, array $data)
    {
        return $this->getTaxonomyService()->updateSeries($id, $data);
    }

    public function createJob(array $data)
    {
        return $this->getWorkflowMessagingService()->createJob($data);
    }

    public function deleteFollow(string $userId, string $followableType, string $followableId): bool
    {
        return $this->getWorkflowMessagingService()->deleteFollow($userId, $followableType, $followableId);
    }

    public function deleteJob(string $jobId): bool
    {
        return $this->getWorkflowMessagingService()->deleteJob($jobId);
    }

    public function deleteMessage(string $messageId): bool
    {
        return $this->getAdminMaintenanceService()->deleteMessage($messageId);
    }

    public function deleteNarrator(string $narratorId): bool
    {
        return $this->getTaxonomyService()->deleteNarrator($narratorId);
    }

    public function deleteSeries(string $seriesId): bool
    {
        return $this->getTaxonomyService()->deleteSeries($seriesId);
    }

    public function deleteGenre(string $genreId): bool
    {
        return $this->getTaxonomyService()->deleteGenre($genreId);
    }

    public function deleteAuthor(string $authorId): void
    {
        $this->getTaxonomyService()->deleteAuthor($authorId);
    }

    public function deleteBook(string $bookId, bool $deleteFiles = true): bool
    {
        return $this->getAdminMaintenanceService()->deleteBook($bookId, $deleteFiles);
    }

    /**
     * @inheritDoc
     */
    public function recordReadingSession(string $userId, string $bookId, array $data): array
    {
        return $this->getUserReadingStatsService()->recordReadingSession($userId, $bookId, $data);
    }

    /**
     * @inheritDoc
     */
    public function getDailyStats(string $userId, ?string $from = null, ?string $to = null): array
    {
        return $this->getUserReadingStatsService()->getDailyStats($userId, $from, $to);
    }

    /**
     * @inheritDoc
     */
    public function getBookStats(string $userId, string $bookId): array
    {
        return $this->getUserReadingStatsService()->getBookStats($userId, $bookId);
    }

    /**
     * @inheritDoc
     */
    public function getUserStats(string $userId): array
    {
        return $this->getUserReadingStatsService()->getUserStats($userId);
    }

    /**
     * @inheritDoc
     */
    public function getStreaks(string $userId): array
    {
        return $this->getUserReadingStatsService()->getStreaks($userId);
    }

    public function createReview(array $data): string
    {
        $review = \App\Models\Review::create($data);
        return (string) $review->id;
    }

    public function linkNonLibraryBooks(): int
    {
        return $this->getLegacyCompatibilityService()->linkNonLibraryBooks();
    }
}
