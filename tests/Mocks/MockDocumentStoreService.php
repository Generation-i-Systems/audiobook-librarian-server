<?php

declare(strict_types=1);

namespace Tests\Mocks;

use App\Contracts\DocumentStoreServiceInterface;

class MockDocumentStoreService implements DocumentStoreServiceInterface
{
    public function autocompleteSeries(string $query, int $limit = 10): array
    {
        return [];
    }

    public function autocompleteAuthors(string $query, int $limit = 10): array
    {
        return [];
    }

    public function autocompleteNarrators(string $query, int $limit = 10): array
    {
        return [];
    }

    protected $books = [];

    protected $series = [];

    protected $genres = [];

    protected $authors = [];

    protected $users = [];

    protected $messages = [];

    protected $jobs = [];

    protected $queues = [];

    protected $readingProgress = [];

    protected $narrators = [];

    protected $apiTokens = [];

    protected $accountRequests = [];

    protected $follows = [];

    protected array $libraryRepairIssues = [];

    protected int $libraryRepairIssueAutoIncrement = 1;

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
        $results = [];

        switch ($field) {
            case 'author':
                $values = [];

                foreach ($this->books as $book) {
                    if (isset($book['authors']) && is_array($book['authors'])) {
                        foreach ($book['authors'] as $author) {
                            if (isset($author['name'])) {
                                $values[$author['name']] = true;
                            }
                        }
                    }
                }
                $results = array_keys($values);
                sort($results);
                break;

            case 'genre':
                $values = [];

                foreach ($this->books as $book) {
                    if (isset($book['genres']) && is_array($book['genres'])) {
                        foreach ($book['genres'] as $genre) {
                            if (isset($genre['name'])) {
                                $values[$genre['name']] = true;
                            }
                        }
                    }
                }
                $results = array_keys($values);
                sort($results);
                break;

            case 'series':
                if ($subField === 'seriesName') {
                    $values = [];

                    foreach ($this->books as $book) {
                        if (isset($book['series']) && is_array($book['series'])) {
                            foreach ($book['series'] as $series) {
                                if (isset($series['seriesName'])) {
                                    $values[$series['seriesName']] = true;
                                }
                            }
                        }
                    }
                    $results = array_keys($values);
                    sort($results);
                }
                break;
        }

        return $results;
    }

    public function validateUserCredentials($user, array $credentials): bool
    {
        $email = $credentials['email'] ?? null;
        $password = $credentials['password'] ?? null;

        if (! $email || ! $password) {
            return false;
        }

        $user = collect($this->users)->firstWhere('email', $email);

        if (! $user) {
            return false;
        }

        return password_verify($password, $user['password'] ?? '');
    }

    public function updateRememberToken(string $identifier, string $token): void
    {
        $this->users = array_map(function ($u) use ($identifier, $token) {
            if ($u['id'] === $identifier || $u['email'] === $identifier) {
                $u['remember_token'] = $token;
            }

            return $u;
        }, $this->users);
    }

    public function getBook(string $id, ?int $userId = null)
    {
        return $this->books[$id] ?? null;
    }

    public function findBookByDirectoryPath(string $directoryPath): ?array
    {
        $directoryPath = trim($directoryPath, '/');

        if ($directoryPath === '') {
            return null;
        }

        foreach ($this->books as $book) {
            if (! is_array($book)) {
                continue;
            }

            $bookPath = $book['directoryPath'] ?? ($book['directory_path'] ?? ($book['path'] ?? null));

            if (! is_string($bookPath)) {
                continue;
            }

            if (trim($bookPath, '/') === $directoryPath) {
                return $book;
            }
        }

        return null;
    }

    public function createBook(array $data)
    {
        $id = $data['id'] ?? uniqid('book_');
        $data['id'] = $id;
        $this->books[$id] = $data;

        return $id;
    }

    public function updateBook($id, array $data)
    {
        if (! isset($this->books[$id])) {
            return false;
        }

        $this->books[$id] = array_merge($this->books[$id], $data);

        return true;
    }

    public function deleteBook($id, bool $deleteFiles = true)
    {
        if (! isset($this->books[$id])) {
            return false;
        }

        unset($this->books[$id]);

        return true;
    }

    public function searchBooks($query, $limit = 10, $offset = 0)
    {
        $results = [];

        foreach ($this->books as $book) {
            if (
                stripos($book['title'] ?? '', $query) !== false ||
                stripos($book['author'] ?? '', $query) !== false
            ) {
                $results[] = $book;
            }

            if (count($results) >= $limit) {
                break;
            }
        }

        return array_slice($results, $offset, $limit);
    }

    public function getBookByPath($path)
    {
        foreach ($this->books as $book) {
            if (($book['path'] ?? '') === $path) {
                return $book;
            }
        }

        return null;
    }

    public function getBooksByIds(array $ids)
    {
        $results = [];

        foreach ($ids as $id) {
            if (isset($this->books[$id])) {
                $results[] = $this->books[$id];
            }
        }

        return $results;
    }

    public function getBooksBySeries($seriesName)
    {
        $results = [];

        foreach ($this->books as $book) {
            if (isset($book['series']) && is_array($book['series'])) {
                foreach ($book['series'] as $series) {
                    if (isset($series['seriesName']) && $series['seriesName'] === $seriesName) {
                        $results[] = $book;
                        break;
                    }
                }
            }
        }

        return $results;
    }

    public function getBooksByAuthor($author)
    {
        $results = [];

        foreach ($this->books as $book) {
            if (isset($book['author']) && $book['author'] === $author) {
                $results[] = $book;
            }
        }

        return $results;
    }

    public function getBooksByGenre($genre)
    {
        $results = [];

        foreach ($this->books as $book) {
            if (isset($book['genres']) && in_array($genre, $book['genres'])) {
                $results[] = $book;
            }
        }

        return $results;
    }

    /**
     * @inheritdoc
     */
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
        // Validate order direction
        $order = in_array(strtolower($order), ['asc', 'desc']) ? strtolower($order) : 'asc';
        // Apply filters
        $filteredBooks = array_filter($this->books, function ($book) use ($filters) {
            // Filter by author
            if (! empty($filters['author'])) {
                $authorMatch = false;
                $authors = is_array($book['author'] ?? null) ? $book['author'] : [$book['author'] ?? ''];

                foreach ($authors as $author) {
                    $authorName = is_array($author) ? ($author['name'] ?? '') : $author;

                    if (stripos($authorName, $filters['author']) !== false) {
                        $authorMatch = true;
                        break;
                    }
                }

                if (! $authorMatch) {
                    return false;
                }
            }

            // Filter by genre
            if (! empty($filters['genre'])) {
                $genres = is_array($book['genre'] ?? null) ? $book['genre'] : [$book['genre'] ?? ''];

                if (! in_array($filters['genre'], $genres, true)) {
                    return false;
                }
            }

            // Filter by series
            if (! empty($filters['series'])) {
                $seriesMatch = false;
                $seriesList = is_array($book['series'] ?? null) ? $book['series'] : ($book['series'] ? [$book['series']] : []);

                foreach ($seriesList as $series) {
                    $seriesName = '';

                    if (is_array($series)) {
                        $seriesName = $series['seriesName'] ?? $series['name'] ?? '';
                    } elseif (is_string($series)) {
                        $seriesName = $series;
                    }

                    if (stripos($seriesName, $filters['series']) !== false) {
                        $seriesMatch = true;
                        break;
                    }
                }

                if (! $seriesMatch) {
                    return false;
                }
            }

            return true;
        });

        // Get total count before pagination
        $total = count($filteredBooks);

        // Calculate pagination
        $offset = ($page - 1) * $perPage;
        $paginatedBooks = array_slice($filteredBooks, $offset, $perPage);

        // Ensure all book fields are properly formatted
        $result = [];

        foreach ($paginatedBooks as $book) {
            $formattedBook = $this->ensureBookFields($book);

            // Load related data if requested
            if ($withRelated) {
                $formattedBook = $this->loadRelatedData($formattedBook);
            }

            $result[] = $formattedBook;
        }

        return [
            'data' => $result,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => max(1, ceil($total / $perPage)),
        ];
    }

    /**
     * @inheritdoc
     */
    public function getRecentBooks(int $limit = 10, int $days = 30): array
    {
        // Sort books by created_at in descending order
        $sortedBooks = $this->books;
        usort($sortedBooks, function ($a, $b) {
            $aTime = strtotime($a['created_at'] ?? 'now');
            $bTime = strtotime($b['created_at'] ?? 'now');

            return $bTime - $aTime;
        });

        // Filter books from the last $days days
        $cutoffDate = strtotime("-$days days");
        $recentBooks = array_filter($sortedBooks, function ($book) use ($cutoffDate) {
            $bookTime = strtotime($book['created_at'] ?? 'now');

            return $bookTime >= $cutoffDate;
        });

        // Limit the number of results
        $recentBooks = array_slice($recentBooks, 0, $limit);

        // Format the books and load related data
        $result = [];

        foreach ($recentBooks as $book) {
            $formattedBook = $this->ensureBookFields($book);
            $formattedBook = $this->loadRelatedData($formattedBook);
            $result[] = $formattedBook;
        }

        return $result;
    }

    /**
     * Ensure all required book fields are present and properly formatted.
     *
     * @param array $book
     *
     * @return array
     */
    protected function ensureBookFields(array $book): array
    {
        $defaults = [
            'id' => uniqid(),
            'title' => 'Untitled',
            'author' => [],
            'series' => [],
            'genre' => [],
            'description' => '',
            'cover_image' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $book = array_merge($defaults, $book);

        // Ensure author is an array of arrays with name
        if (! empty($book['author'])) {
            $authors = is_array($book['author']) ? $book['author'] : [$book['author']];
            $book['author'] = array_map(function ($author) {
                if (is_array($author) && isset($author['name'])) {
                    return $author;
                }

                return ['name' => (string) $author];
            }, $authors);
        }

        // Ensure series is an array of arrays with seriesName
        if (! empty($book['series'])) {
            $seriesList = is_array($book['series']) ? $book['series'] : [$book['series']];
            $book['series'] = array_map(function ($series) {
                if (is_array($series)) {
                    // Convert 'name' to 'seriesName' if needed
                    if (isset($series['name']) && ! isset($series['seriesName'])) {
                        $series['seriesName'] = $series['name'];
                        unset($series['name']);
                    }

                    return $series;
                }

                return ['seriesName' => (string) $series];
            }, $seriesList);
        }

        // Ensure genre is an array of strings
        if (! empty($book['genre'])) {
            $book['genre'] = is_array($book['genre']) ? $book['genre'] : [$book['genre']];
        }

        return $book;
    }

    /**
     * Load related data for a book.
     *
     * @param array $book
     *
     * @return array
     */
    protected function loadRelatedData(array $book): array
    {
        // In a real implementation, this would load related data from the database
        // For the mock, we'll just ensure the structure is correct

        // Ensure authors have all required fields
        if (! empty($book['author'])) {
            $book['authors'] = $book['author'];
            unset($book['author']);
        }

        // Ensure series have all required fields
        if (! empty($book['series'])) {
            $book['series'] = array_map(function ($series) {
                if (is_array($series)) {
                    return [
                        'seriesName' => $series['seriesName'] ?? $series['name'] ?? 'Unknown Series',
                        'position' => $series['position'] ?? null,
                    ];
                }

                return ['seriesName' => (string) $series];
            }, $book['series']);
        }

        return $book;
    }

    public function getBooksByAuthorAndGenre(
        $author,
        $genre,
        string $orderBy = 'title',
        string $direction = 'asc',
        int $limit = 20,
        ?string $startAfter = null,
        ?int $userId = null
    ) {
        $results = [];

        foreach ($this->books as $book) {
            if (
                (isset($book['author']) && $book['author'] === $author) &&
                (isset($book['genres']) && in_array($genre, $book['genres']))
            ) {
                $results[] = $book;
            }
        }

        return $results;
    }

    public function dumpAllBooks()
    {
        return $this->books;
    }

    public function getAllBooks(?int $limit = null, int $offset = 0): array
    {
        $books = array_values($this->books);

        if ($offset > 0) {
            $books = array_slice($books, $offset);
        }

        if ($limit !== null) {
            $books = array_slice($books, 0, $limit);
        }

        return $books;
    }

    // dumpAllBooks method already exists above

    public function getUserById($identifier)
    {
        return $this->users[$identifier] ?? null;
    }

    public function getUserByCredentials($credentials)
    {
        foreach ($this->users as $user) {
            if (isset($user['email']) && $user['email'] === ($credentials['email'] ?? '')) {
                return $user;
            }
        }

        return null;
    }

    public function getUserByRememberToken($identifier, $token)
    {
        $user = $this->getUserById($identifier);

        if ($user && isset($user['remember_token']) && $user['remember_token'] === $token) {
            return $user;
        }

        return null;
    }

    public function createUser(array $data)
    {
        $id = $data['id'] ?? uniqid('user_');
        $data['id'] = $id;
        $this->users[$id] = $data;

        return $id;
    }

    public function updateUser(string $id, array $data)
    {
        if (! isset($this->users[$id])) {
            return false;
        }

        $this->users[$id] = array_merge($this->users[$id], $data);

        return true;
    }

    public function deleteUser(string $id)
    {
        if (! isset($this->users[$id])) {
            return false;
        }

        unset($this->users[$id]);

        return true;
    }

    /**
     * Get a user by their Apple ID.
     *
     * @param string $appleId The Apple ID (sub claim) to search for
     *
     * @return array|null The user data or null if not found
     */
    public function getUserByAppleId(string $appleId): ?array
    {
        foreach ($this->users as $user) {
            if (isset($user['apple_id']) && $user['apple_id'] === $appleId) {
                return $user;
            }
        }

        return null;
    }

    public function getUserByDiscordId(string $discordId): ?array
    {
        foreach ($this->users as $user) {
            if (isset($user['discord_id']) && $user['discord_id'] === $discordId) {
                return $user;
            }
        }

        return null;
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
        foreach ($this->users as $user) {
            if (isset($user['email']) && $user['email'] === $email) {
                return $user;
            }
        }

        return null;
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
        foreach ($this->users as $user) {
            if (isset($user['email']) && $user['email'] === $email) {
                return true;
            }
        }

        return false;
    }

    public function updateReadingProgress(string $userId, string $bookId, int $currentPositionSeconds): bool
    {
        return $this->setReadingProgress($userId, $bookId, $currentPositionSeconds);
    }

    public function setReadingProgress(string $userId, string $bookId, int $currentPositionSeconds): bool
    {
        $key = $userId . '_' . $bookId;
        $this->readingProgress[$key] = max(0, $currentPositionSeconds);

        return true;
    }

    public function getReadingProgress(string $userId, string $bookId): int
    {
        $key = $userId . '_' . $bookId;

        return (int) ($this->readingProgress[$key] ?? 0);
    }

    public function getProgress(string $userId, string $bookId): ?int
    {
        $progress = $this->getReadingProgress($userId, $bookId);
        return $progress > 0 ? $progress : null;
    }

    public function updateJobStatus(string $jobId, string $type, string $status, array $metadata = []): bool
    {
        if (!isset($this->jobs[$jobId])) {
            $this->jobs[$jobId] = ['id' => $jobId, 'type' => $type, 'status' => $status, 'metadata' => []];
        }
        $this->jobs[$jobId]['type'] = $type;
        $this->jobs[$jobId]['status'] = $status;
        $this->jobs[$jobId]['metadata'] = array_merge($this->jobs[$jobId]['metadata'] ?? [], $metadata);
        return true;
    }

    public function getExternalReads(string $userId, string $bookId): array
    {
        return [];
    }

    public function getExternalRead(string $externalReadId, string $userId, string $bookId): ?array
    {
        return null;
    }

    public function createExternalRead(array $data): string
    {
        return '1';
    }

    public function updateExternalRead(string $externalReadId, array $data): bool
    {
        return true;
    }

    public function deleteExternalRead(string $externalReadId, string $userId, string $bookId): bool
    {
        return true;
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
        foreach ($this->users as $user) {
            if (isset($user['username']) && $user['username'] === $username) {
                return true;
            }
        }

        return false;
    }

    public function listLibraryRepairIssues(array $filters = [], int $limit = 50, int $page = 1): array
    {
        $filtered = $this->filterLibraryRepairIssues($this->libraryRepairIssues, $filters);

        usort($filtered, fn ($a, $b) => strcmp($b['createdAt'], $a['createdAt']));

        $offset = max(0, ($page - 1) * $limit);

        return array_slice($filtered, $offset, $limit);
    }

    public function countLibraryRepairIssues(array $filters = []): int
    {
        return count($this->filterLibraryRepairIssues($this->libraryRepairIssues, $filters));
    }

    public function getLibraryRepairIssue(int $issueId): ?array
    {
        return $this->libraryRepairIssues[$issueId] ?? null;
    }

    public function resolveLibraryRepairIssue(int $issueId, ?string $resolutionNotes = null): bool
    {
        if (! isset($this->libraryRepairIssues[$issueId])) {
            return false;
        }

        $issue = $this->libraryRepairIssues[$issueId];
        $issue['status'] = 'resolved';
        $issue['resolvedAt'] = now()->toIso8601String();
        $issue['resolutionNotes'] = $resolutionNotes;
        $issue['updatedAt'] = $issue['resolvedAt'];
        $issue['autoResolved'] ??= false;

        $this->libraryRepairIssues[$issueId] = $issue;

        return true;
    }

    public function addLibraryRepairIssue(array $issue): array
    {
        $issue = $this->ensureLibraryRepairIssueFields($issue);
        $this->libraryRepairIssues[$issue['id']] = $issue;

        return $issue;
    }

    protected function ensureLibraryRepairIssueFields(array $issue): array
    {
        $issue['id'] ??= $this->libraryRepairIssueAutoIncrement++;
        $issue['issueType'] ??= 'missing_directory';
        $issue['status'] ??= 'pending';
        $issue['directoryPath'] ??= null;
        $issue['metadata'] ??= [];
        $issue['autoResolved'] = (bool) ($issue['autoResolved'] ?? false);
        $issue['createdAt'] ??= now()->toIso8601String();
        $issue['updatedAt'] ??= $issue['createdAt'];
        $issue['resolvedAt'] ??= null;
        $issue['resolutionNotes'] ??= null;
        $issue['book'] ??= null;

        return $issue;
    }

    protected function filterLibraryRepairIssues(array $issues, array $filters): array
    {
        return array_values(array_filter($issues, function ($issue) use ($filters) {
            if (! empty($filters['issue_type']) && $issue['issueType'] !== $filters['issue_type']) {
                return false;
            }

            if (! empty($filters['status']) && $issue['status'] !== $filters['status']) {
                return false;
            }

            if (array_key_exists('auto_resolved', $filters) && $filters['auto_resolved'] !== '') {
                if ((bool) $issue['autoResolved'] !== (bool) $filters['auto_resolved']) {
                    return false;
                }
            }

            if (! empty($filters['search'])) {
                $search = strtolower($filters['search']);
                $directoryMatches = is_string($issue['directoryPath']) && str_contains(strtolower($issue['directoryPath']), $search);
                $bookMatches = ! empty($issue['book']['title']) && str_contains(strtolower($issue['book']['title']), $search);

                if (! $directoryMatches && ! $bookMatches) {
                    return false;
                }
            }

            return true;
        }));
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
        foreach ($this->users as $user) {
            if (isset($user['username']) && $user['username'] === $username) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Get all admin users.
     *
     * @return array List of admin users
     */
    public function getAdminUsers(): array
    {
        $admins = [];

        foreach ($this->users as $id => $user) {
            if (isset($user['role']) && $user['role'] === 'admin') {
                $userData = $user;
                $userData['id'] = $id;
                $admins[] = $userData;
            }
        }

        return $admins;
    }

    /**
     * Get all users in the system.
     *
     * @return array List of all users
     */
    public function getAllUsers(): array
    {
        $allUsers = [];

        foreach ($this->users as $id => $user) {
            $userData = $user;
            $userData['id'] = $id;
            $allUsers[] = $userData;
        }

        return $allUsers;
    }

    public function isAdmin(string $userId): bool
    {
        if (isset($this->users[$userId]) && isset($this->users[$userId]['role']) && $this->users[$userId]['role'] === 'admin') {
            return true;
        }

        return false;
    }

    public function getUsersForMessaging(): array
    {
        return array_values($this->users);
    }

    public function createGenre(array $data)
    {
        $id = $data['id'] ?? uniqid('genre_');
        $data['id'] = $id;
        $this->genres[$id] = $data;

        return $id;
    }

    public function listGenres(?int $since = null)
    {
        return array_values($this->genres);
    }

    public function listGenresWithStats(?int $since = null): array
    {
        $genres = $this->listGenres($since);

        return array_map(fn (array $genre) => [
            'id' => (string) ($genre['id'] ?? ''),
            'name' => (string) ($genre['name'] ?? ''),
            'bookCount' => 0,
            'authorCount' => 0,
        ], $genres);
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
        // First check if this is a built-in genre from config
        $configGenres = config('genres', []);

        foreach ($configGenres as $genre) {
            if (($genre['id'] ?? '') === $id) {
                return $genre;
            }
        }

        // If not found in config, try to get from stored genres
        return $this->genres[$id] ?? null;
    }

    public function deleteGenre(string $id)
    {
        if (! isset($this->genres[$id])) {
            return false;
        }

        unset($this->genres[$id]);

        return true;
    }

    public function createSeries(string $name, bool $isCollection = false): string
    {
        $id = 'series_' . uniqid();
        $newSeries = ['id' => $id, 'seriesName' => $name, 'is_collection' => $isCollection];
        $this->series[] = $newSeries;

        return $id;
    }

    public function updateSeries(int $id, array $data)
    {
        foreach ($this->series as $key => $series) {
            if ($series['id'] === $id || $series['id'] === 'series_' . $id) {
                $this->series[$key] = array_merge($series, $data);

                return true;
            }
        }

        return false;
    }

    public function getSeriesByName(string $name): ?array
    {
        foreach ($this->series as $series) {
            if (isset($series['seriesName']) && $series['seriesName'] === $name) {
                return $series;
            }
        }

        return null;
    }

    public function findOrCreateSeriesByName(string $name)
    {
        foreach ($this->series as $series) {
            if (isset($series['seriesName']) && $series['seriesName'] === $name) {
                return $series;
            }
        }

        // Create new series
        $id = uniqid('series_');
        $this->series[$id] = [
            'id' => $id,
            'seriesName' => $name,
        ];

        return $this->series[$id];
    }

    public function getSeries(string $id)
    {
        return $this->series[$id] ?? null;
    }

    public function deleteSeries(string $id)
    {
        if (! isset($this->series[$id])) {
            return false;
        }

        unset($this->series[$id]);

        return true;
    }

    public function listSeries(?int $since = null): array
    {
        return array_values($this->series);
    }

    public function searchSeriesByName(string $term): array
    {
        $results = [];

        foreach ($this->series as $series) {
            if (isset($series['seriesName']) && stripos($series['seriesName'], $term) !== false) {
                $results[] = $series;
            }
        }

        return $results;
    }

    public function createAuthor(array $data)
    {
        $id = $data['id'] ?? uniqid('author_');
        $data['id'] = $id;
        $this->authors[$id] = $data;

        return $id;
    }

    public function listAuthors(?int $since = null)
    {
        return array_values($this->authors);
    }

    public function listAuthorsWithStats(?int $since = null): array
    {
        $authors = $this->listAuthors($since);

        return array_map(fn (array $author) => [
            'id' => (string) ($author['id'] ?? ''),
            'name' => (string) ($author['name'] ?? ''),
            'bookCount' => 0,
        ], $authors);
    }

    public function deleteAuthor(string $id): void
    {
        if (isset($this->authors[$id])) {
            unset($this->authors[$id]);
        }
    }

    public function findOrCreateMany(string $collection, array $names): array
    {
        if (! property_exists($this, $collection)) {
            return [];
        }

        $ids = [];

        foreach ($names as $name) {
            $existingId = null;

            foreach ($this->{$collection} as $docId => $doc) {
                if (isset($doc['name']) && $doc['name'] === $name) {
                    $existingId = $docId;
                    break;
                }
            }

            if ($existingId) {
                $ids[] = $existingId;
            } else {
                $newId = uniqid($collection . '_');
                $this->{$collection}[$newId] = ['id' => $newId, 'name' => $name];
                $ids[] = $newId;
            }
        }

        return $ids;
    }

    public function searchAuthorsByName(string $term): array
    {
        $results = [];

        foreach ($this->authors as $author) {
            if (isset($author['name']) && stripos($author['name'], $term) !== false) {
                $results[] = $author;
            }
        }

        return $results;
    }

    public function searchNarratorsByName(string $term): array
    {
        $results = [];

        foreach ($this->narrators as $narrator) {
            if (isset($narrator['name']) && stripos($narrator['name'], $term) !== false) {
                $results[] = $narrator;
            }
        }

        return $results;
    }

    public function searchGenresByName(string $term): array
    {
        $results = [];

        foreach ($this->genres as $genre) {
            if (isset($genre['name']) && stripos($genre['name'], $term) !== false) {
                $results[] = $genre;
            }
        }

        return $results;
    }

    /**
     * Return books flagged as needs_review with optional reason filter.
     * This is a simple in-memory implementation for tests.
     */
    public function listNeedsReviewBooks(?string $reason = null, int $limit = 100, int $page = 1): array
    {
        $items = [];

        foreach ($this->books as $book) {
            $needsReview = (bool) ($book['needs_review'] ?? false);

            if (! $needsReview) {
                continue;
            }

            $reasons = $book['needs_review_reasons'] ?? [];

            if ($reason !== null) {
                if (! is_array($reasons) || ! in_array($reason, $reasons, true)) {
                    continue;
                }
            }

            $items[] = [
                'id' => $book['id'] ?? null,
                'title' => $book['title'] ?? '',
                'directoryPath' => $book['directory_path'] ?? $book['directoryPath'] ?? '',
                'needsReviewReasons' => $reasons,
                'createdAt' => $book['created_at'] ?? $book['createdAt'] ?? null,
            ];
        }

        // Basic pagination on the array
        $offset = max(0, ($page - 1) * $limit);

        return array_slice($items, $offset, $limit);
    }

    /**
     * Return distinct needs_review reasons from mock books.
     */
    public function listNeedsReviewReasons(): array
    {
        $set = [];

        foreach ($this->books as $book) {
            if (! empty($book['needs_review'])) {
                foreach (($book['needs_review_reasons'] ?? []) as $r) {
                    $set[$r] = true;
                }
            }
        }
        $reasons = array_keys($set);
        sort($reasons);

        return $reasons;
    }

    public function createMessage(array $messageData): ?string
    {
        $id = $messageData['id'] ?? uniqid('message_');
        $messageData['id'] = $id;
        $this->messages[$id] = $messageData;

        return $id;
    }

    public function acknowledgeMessage(string $messageId): bool
    {
        if (! isset($this->messages[$messageId])) {
            return false;
        }

        $this->messages[$messageId]['acknowledged_at'] = now()->toIso8601String();

        return true;
    }

    public function getMessages(?string $userId = null, bool $includeAcknowledged = false, int $limit = 100): array
    {
        if ($userId === null) {
            return array_slice(array_values($this->messages), 0, $limit);
        }

        $results = [];

        foreach ($this->messages as $message) {
            if (isset($message['userId']) && $message['userId'] === $userId) {
                if ($includeAcknowledged || ! ($message['acknowledged'] ?? false)) {
                    $results[] = $message;
                }
            }

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    public function listJobs(?string $type = null, ?string $status = null, int $limit = 50, string $orderBy = 'updated_at', string $direction = 'DESC', ?string $startAfterId = null): array
    {
        $results = [];

        foreach ($this->jobs as $job) {
            if (
                ($type === null || ($job['type'] ?? '') === $type) &&
                ($status === null || ($job['status'] ?? '') === $status)
            ) {
                $results[] = $job;
            }

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    public function deleteJob(string $jobId): bool
    {
        if (! isset($this->jobs[$jobId])) {
            return false;
        }

        unset($this->jobs[$jobId]);

        return true;
    }

    public function getBookQueue(string $userId): array
    {
        return $this->queues[$userId] ?? [];
    }

    public function getQueueCollection($name)
    {
        return $this->queues[$name] ?? [];
    }

    public function resetReadingProgress(string $userId, string $bookId): bool
    {
        $key = $userId . '_' . $bookId;

        if (isset($this->readingProgress[$key])) {
            unset($this->readingProgress[$key]);

            return true;
        }

        return false;
    }

    public function getDocument(string $collection, string $docId): ?array
    {
        $collections = [
            'books' => $this->books,
            'users' => $this->users,
            'series' => $this->series,
            'genres' => $this->genres,
            'authors' => $this->authors,
            'messages' => $this->messages,
            'jobs' => $this->jobs,
        ];

        return $collections[$collection][$docId] ?? null;
    }

    public function getClient()
    {
        // Return a mock client object
        return new class () {
            public function collection($name)
            {
                return $this;
            }

            public function document($id)
            {
                return $this;
            }

            public function set($data)
            {
                return true;
            }

            public function get()
            {
                return null;
            }
        };
    }

    public function addSeries($name)
    {
        $id = uniqid('series_');
        $this->series[$id] = ['id' => $id, 'seriesName' => $name];

        return $id;
    }

    public function addAuthor($name)
    {
        $id = uniqid('author_');
        $this->authors[$id] = ['id' => $id, 'name' => $name];

        return $id;
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
        return $this->authors[$id] ?? null;
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
        if (! isset($this->authors[$id])) {
            return false;
        }

        $this->authors[$id] = array_merge($this->authors[$id], $data);

        return true;
    }

    public function getManifestForBook(string $bookId): array
    {
        return $this->books[$bookId] ?? [];
    }

    public function getBookmarks(string $userId, string $bookId): array
    {
        return [];
    }

    public function getBookmark(string $bookmarkId, string $userId, string $bookId): ?array
    {
        return null;
    }

    public function createBookmark(array $data): string
    {
        $id = uniqid('bookmark_');

        return $id;
    }

    public function updateBookmark(string $bookmarkId, array $data): bool
    {
        return true;
    }

    public function deleteBookmark(string $bookmarkId, string $userId, string $bookId): bool
    {
        return true;
    }

    public function deleteBookmarkById(string $bookmarkId, string $userId): bool
    {
        return true;
    }

    public function updateBookQueue(string $userId, array $bookIds): void
    {
        $this->queues[$userId] = $bookIds;
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
        if (! isset($this->queues[$userId])) {
            $this->queues[$userId] = [];
        }

        // Check if book is already in queue
        if (in_array($bookId, $this->queues[$userId])) {
            return true; // Book already in queue
        }

        // Add book to queue
        $this->queues[$userId][] = $bookId;

        return true;
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
        if (! isset($this->queues[$userId])) {
            return false; // Queue doesn't exist
        }

        $key = array_search($bookId, $this->queues[$userId]);

        if ($key === false) {
            return false; // Book not in queue
        }

        // Remove book from queue
        unset($this->queues[$userId][$key]);
        $this->queues[$userId] = array_values($this->queues[$userId]); // Re-index array

        return true;
    }

    public function getJob(string $jobId): ?array
    {
        return $this->jobs[$jobId] ?? null;
    }

    public function updateJob(string $jobId, array $data): bool
    {
        if (isset($this->jobs[$jobId])) {
            $this->jobs[$jobId] = array_merge($this->jobs[$jobId], $data);

            return true;
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function getJobs(): array
    {
        return array_values($this->jobs);
    }

    /**
     * @inheritDoc
     */
    public function getJobCount(): int
    {
        return count($this->jobs);
    }

    /**
     * @inheritDoc
     */
    public function updateGenre(string $id, array $data): bool
    {
        if (isset($this->genres[$id])) {
            $this->genres[$id] = array_merge($this->genres[$id], $data);

            return true;
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function followExists(string $userId, string $followableType, string $followableId): bool
    {
        if (! isset($this->follows[$userId])) {
            return false;
        }

        return in_array(
            ['type' => $followableType, 'id' => $followableId],
            $this->follows[$userId],
            true
        );
    }

    /**
     * @inheritDoc
     */
    public function createFollow(string $userId, string $followableType, string $followableId): bool
    {
        if (! isset($this->follows[$userId])) {
            $this->follows[$userId] = [];
        }

        $follow = ['type' => $followableType, 'id' => $followableId];

        if (! in_array($follow, $this->follows[$userId], true)) {
            $this->follows[$userId][] = $follow;

            return true;
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function deleteFollow(string $userId, string $followableType, string $followableId): bool
    {
        if (! isset($this->follows[$userId])) {
            return false;
        }

        $follow = ['type' => $followableType, 'id' => $followableId];

        foreach ($this->follows[$userId] as $key => $existingFollow) {
            if ($existingFollow === $follow) {
                unset($this->follows[$userId][$key]);
                $this->follows[$userId] = array_values($this->follows[$userId]);

                return true;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function getFollowers(string $followableType, string $followableId): array
    {
        $followers = [];
        $target = ['type' => $followableType, 'id' => $followableId];

        foreach ($this->follows as $followerId => $follows) {
            if (in_array($target, $follows, true)) {
                $followers[] = $followerId;
            }
        }

        return $followers;
    }

    /**
     * @inheritDoc
     */
    public function getFollowing(string $userId, string $followableType = null): array
    {
        if (! isset($this->follows[$userId])) {
            return [];
        }

        if ($followableType === null) {
            return $this->follows[$userId];
        }

        return array_filter(
            $this->follows[$userId],
            fn ($follow) => $follow['type'] === $followableType
        );
    }

    /**
     * @inheritDoc
     */
    public function cleanupOldJobs(int $daysOld): int
    {
        $cutoff = time() - ($daysOld * 24 * 60 * 60);
        $deleted = 0;

        foreach ($this->jobs as $id => $job) {
            if (isset($job['created_at']) && $job['created_at'] < $cutoff) {
                unset($this->jobs[$id]);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @inheritDoc
     */
    public function clearJobs(bool $confirmed = false): bool
    {
        if (! $confirmed) {
            throw new \RuntimeException('Refusing to clear jobs without explicit destructive-operation confirmation.');
        }

        $hadJobs = ! empty($this->jobs);
        $this->jobs = [];

        return $hadJobs;
    }

    /**
     * @inheritDoc
     */
    public function updateDocument(string $collection, string $id, array $data): bool
    {
        $collection = strtolower($collection);

        if (! property_exists($this, $collection) || ! is_array($this->$collection)) {
            return false;
        }

        if (! isset($this->{$collection}[$id])) {
            return false;
        }

        $this->{$collection}[$id] = array_merge($this->{$collection}[$id], $data);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function jobExistsByDirectoryPath(string $directoryPath): bool
    {
        foreach ($this->jobs as $job) {
            if (isset($job['directory_path']) && $job['directory_path'] === $directoryPath) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function bookExistsByDirectoryPath(string $directoryPath): bool
    {
        foreach ($this->books as $book) {
            if (isset($book['directory_path']) && $book['directory_path'] === $directoryPath) {
                return true;
            }
        }

        return false;
    }

    public function getBooksInSeries(string $seriesId): array
    {
        return [];
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
        $id = uniqid('token_');
        $tokenData['id'] = $id;
        $this->apiTokens[$id] = $tokenData;

        return $id;
    }

    public function getApiTokenByValue(string $tokenValue): ?array
    {
        foreach ($this->apiTokens as $token) {
            if (isset($token['token']) && $token['token'] === $tokenValue) {
                return $token;
            }
        }

        return null;
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
        foreach ($this->apiTokens as $id => $token) {
            if (isset($token['token']) && $token['token'] === $tokenValue) {
                unset($this->apiTokens[$id]);

                return true;
            }
        }

        return false;
    }

    /**
     * Get pending account requests.
     *
     * @return array List of pending account requests
     */
    public function getPendingAccountRequests(): array
    {
        $pendingRequests = [];

        foreach ($this->accountRequests as $id => $request) {
            if (isset($request['status']) && $request['status'] === 'pending') {
                $pendingRequests[$id] = $request;
            }
        }

        return array_values($pendingRequests);
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
        return $this->accountRequests[$id] ?? null;
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
        if (! isset($this->accountRequests[$id])) {
            return false;
        }

        // Update status to approved
        $this->accountRequests[$id]['status'] = 'approved';

        // Create a user from the account request data
        $userData = [
            'name' => $this->accountRequests[$id]['name'] ?? '',
            'email' => $this->accountRequests[$id]['email'] ?? '',
            'username' => $this->accountRequests[$id]['username'] ?? '',
            'password' => $this->accountRequests[$id]['password'] ?? '',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $this->createUser($userData);

        return true;
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
        if (! isset($this->accountRequests[$id])) {
            return false;
        }

        // Update status to rejected
        $this->accountRequests[$id]['status'] = 'rejected';

        return true;
    }

    /**
     * Count books that need review, optionally filtered by reason.
     *
     * @param string|null $reason
     *
     * @return int
     */
    public function countNeedsReviewBooks(?string $reason = null): int
    {
        $count = 0;

        foreach ($this->books as $book) {
            $needsReview = (bool) ($book['needs_review'] ?? false);

            if (! $needsReview) {
                continue;
            }

            if ($reason !== null) {
                $reasons = $book['needs_review_reasons'] ?? [];

                if (! is_array($reasons) || ! in_array($reason, $reasons, true)) {
                    continue;
                }
            }

            $count++;
        }

        return $count;
    }

    public function getGenreAuthorsHierarchy(string $genreId): array
    {
        $genre = $this->genres[$genreId] ?? null;

        if (! $genre) {
            return [
                'genre' => null,
                'authors' => [],
            ];
        }

        return [
            'genre' => [
                'id' => (string) ($genre['id'] ?? $genreId),
                'name' => (string) ($genre['name'] ?? ''),
            ],
            'authors' => [],
        ];
    }

    public function getAuthorHierarchy(string $authorId, ?string $genreId = null): array
    {
        $author = $this->authors[$authorId] ?? null;

        if (! $author) {
            return [
                'author' => null,
                'genre' => null,
                'series' => [],
                'standaloneBooks' => [],
            ];
        }

        $genre = null;

        if ($genreId !== null && isset($this->genres[$genreId])) {
            $g = $this->genres[$genreId];
            $genre = [
                'id' => (string) ($g['id'] ?? $genreId),
                'name' => (string) ($g['name'] ?? ''),
            ];
        }

        return [
            'author' => [
                'id' => (string) ($author['id'] ?? $authorId),
                'name' => (string) ($author['name'] ?? ''),
            ],
            'genre' => $genre,
            'series' => [],
            'standaloneBooks' => [],
        ];
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
        $updated = 0;

        foreach ($this->books as $id => $book) {
            if (! isset($book['series']) || ! is_array($book['series'])) {
                continue;
            }

            $changed = false;

            foreach ($book['series'] as $key => $series) {
                $seriesName = is_array($series) ? ($series['seriesName'] ?? '') : $series;

                if ($seriesName === $oldName) {
                    if (is_array($book['series'][$key])) {
                        $book['series'][$key]['seriesName'] = $newName;
                    } else {
                        $book['series'][$key] = $newName;
                    }
                    $changed = true;
                }
            }

            if ($changed) {
                $this->books[$id] = $book;
                $updated++;
            }
        }

        return $updated;
    }

    public function mergeAuthors(string $primaryAuthorId, array $secondaryAuthorIds): int
    {
        $affected = 0;

        foreach ($secondaryAuthorIds as $secondaryId) {
            if (isset($this->authors[$secondaryId])) {
                unset($this->authors[$secondaryId]);
                $affected++;
            }
        }

        return $affected;
    }

    public function mergeGenres(string $primaryGenreId, array $secondaryGenreIds): int
    {
        $affected = 0;

        foreach ($secondaryGenreIds as $secondaryId) {
            if (isset($this->genres[$secondaryId])) {
                unset($this->genres[$secondaryId]);
                $affected++;
            }
        }

        return $affected;
    }

    public function mergeSeries(string $primarySeriesId, array $secondarySeriesIds): int
    {
        $affected = 0;

        foreach ($secondarySeriesIds as $secondaryId) {
            if (isset($this->series[$secondaryId])) {
                unset($this->series[$secondaryId]);
                $affected++;
            }
        }

        return $affected;
    }

    /**
     * @inheritDoc
     */
    public function getUserActivityData(string $userId): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getBadgeTips(string $userId): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function createReview(array $data): string
    {
        return uniqid('review_');
    }

    /**
     * @inheritDoc
     */
    public function linkNonLibraryBooks(): int
    {
        return 0;
    }
}
