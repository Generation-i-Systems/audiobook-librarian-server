<?php

declare(strict_types=1);

namespace App\Contracts;

interface DocumentStoreServiceInterface
{
    /**
     * Autocomplete series names using fuzzy search.
     */
    public function autocompleteSeries(string $query, int $limit = 10): array;

    /**
     * Autocomplete author names using fuzzy search.
     */
    public function autocompleteAuthors(string $query, int $limit = 10): array;

    /**
     * Autocomplete narrator names using fuzzy search.
     */
    public function autocompleteNarrators(string $query, int $limit = 10): array;

    // BOOKS
    public function getBook(string $id, ?int $userId = null);

    /**
     * Find a book by its directoryPath.
     */
    public function findBookByDirectoryPath(string $directoryPath): ?array;

    /**
     * Get all books (optionally paginated by limit/offset).
     */
    public function getAllBooks(?int $limit = null, int $offset = 0): array;

    /**
     * List books with pagination and optional filtering.
     *
     * @param int $page Page number (1-based)
     * @param int $perPage Number of items per page
     * @param array $filters Optional filters (e.g., ['author' => 'John Doe', 'genre' => 'Fiction'])
     * @param bool $withRelated Whether to load related data (authors, series)
     * @param string $sort Field to sort by
     * @param string $order Direction to sort
     * @param bool $includeAllBooks Whether to include books without files
     * @param int|null $userId Optional user ID for personal data (progress, etc)
     *
     * @return array [
     *               'data' => array,  // The paginated list of books
     *               'total' => int,   // Total number of books matching filters
     *               'per_page' => int,// Number of items per page
     *               'current_page' => int, // Current page number
     *               'last_page' => int,    // Last available page number
     *               ]
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
    ): array;

    /**
     * Return books flagged as needs_review. Optional reason filter narrows results to books whose
     * needs_review_reasons contain the provided reason string.
     *
     * @param string|null $reason
     * @param int $limit
     * @param int $page
     *
     * @return array
     */
    public function listNeedsReviewBooks(?string $reason = null, int $limit = 100, int $page = 1): array;

    /**
     * Count books needing review, optionally filtered by reason.
     *
     * @param string|null $reason
     *
     * @return int
     */
    public function countNeedsReviewBooks(?string $reason = null): int;

    /**
     * Return a list of distinct needs_review reasons across all flagged books.
     *
     * @return array
     */
    public function listNeedsReviewReasons(): array;

    /**
     * List library repair issues with optional filters.
     *
     * @param array $filters
     * @param int $limit
     * @param int $page
     *
     * @return array
     */
    public function listLibraryRepairIssues(array $filters = [], int $limit = 50, int $page = 1): array;

    /**
     * Count the number of library repair issues that match the filters.
     *
     * @param array $filters
     *
     * @return int
     */
    public function countLibraryRepairIssues(array $filters = []): int;

    /**
     * Retrieve a single library repair issue.
     *
     * @param int $issueId
     *
     * @return array|null
     */
    public function getLibraryRepairIssue(int $issueId): ?array;

    /**
     * Resolve a library repair issue.
     *
     * @param int $issueId
     * @param string|null $resolutionNotes
     *
     * @return bool
     */
    public function resolveLibraryRepairIssue(int $issueId, ?string $resolutionNotes = null): bool;

    /**
     * Rename a series across all books.
     *
     * @param string $oldName
     * @param string $newName
     *
     * @return int Number of books updated
     */
    public function renameSeries(string $oldName, string $newName): int;

    /**
     * Get recently added books.
     *
     * @param int $limit Maximum number of recent books to return
     * @param int $days Number of days to look back for recent books
     *
     * @return array Array of recent books with related data
     */
    public function getRecentBooks(int $limit = 10, int $days = 30);

    /**
     * Get unique values for a specific field across all books.
     *
     * @param string $field The field to get unique values for (e.g., 'genre', 'author')
     * @param string $subField Optional subfield for nested data (e.g., 'seriesName' when field is 'series')
     *
     * @return array Array of unique values
     */
    public function getUniqueValues(string $field, string $subField = null): array;

    public function createBook(array $data);

    public function updateBook(string $id, array $data);

    public function deleteBook(string $id, bool $deleteFiles = true);

    public function getBooksByAuthorAndGenre(
        $author,
        $genre,
        string $orderBy = 'title',
        string $direction = 'asc',
        int $limit = 20,
        ?string $startAfter = null,
        ?int $userId = null
    );

    public function dumpAllBooks();

    // USERS
    public function getUserById($identifier);

    public function getUserByCredentials($credentials);

    public function getUserByRememberToken($identifier, $token);

    /**
     * Validate a user's credentials.
     *
     * @param mixed $user The user object
     * @param array $credentials The credentials to validate
     *
     * @return bool
     */
    public function validateUserCredentials($user, array $credentials): bool;

    /**
     * Get a user by their username.
     *
     * @param string $username The username to search for
     *
     * @return array|null The user data or null if not found
     */
    public function getUserByUsername(string $username): ?array;

    /**
     * Get a user by their email address.
     *
     * @param string $email The email address to search for
     *
     * @return array|null The user data or null if not found
     */
    public function getUserByEmail(string $email): ?array;

    /**
     * Get a user by their Apple ID.
     *
     * @param string $appleId The Apple ID (sub claim) to search for
     *
     * @return array|null The user data or null if not found
     */
    public function getUserByAppleId(string $appleId): ?array;

    /**
     * Get a user by their Discord ID.
     *
     * @param string $discordId The Discord ID to search for
     *
     * @return array|null The user data or null if not found
     */
    public function getUserByDiscordId(string $discordId): ?array;

    /**
     * Check if a user with the given email exists.
     *
     * @param string $email The email address to check
     *
     * @return bool True if a user with this email exists
     */
    public function userExistsByEmail(string $email): bool;

    /**
     * Check if a user with the given username exists.
     *
     * @param string $username The username to check
     *
     * @return bool True if a user with this username exists
     */
    public function userExistsByUsername(string $username): bool;

    public function createUser(array $data);

    public function updateUser(string $id, array $data);

    public function deleteUser(string $id);

    public function isAdmin(string $userId): bool;

    public function getUsersForMessaging(): array;

    /**
     * Update the "remember me" token for the given user.
     *
     * @param string $identifier The user's identifier
     * @param string $token The new remember token
     */
    public function updateRememberToken(string $identifier, string $token): void;

    /**
     * Get all users in the system.
     *
     * @return array List of all users
     */
    public function getAllUsers(): array;

    /**
     * Get user activity data (progress, badges, reviews, etc.)
     *
     * @param string $userId
     * @return array
     */
    public function getUserActivityData(string $userId): array;

    /**
     * Get badge tips for a user.
     *
     * @param string $userId
     * @return array
     */
    public function getBadgeTips(string $userId): array;

    // GENRES
    public function createGenre(array $data);

    /**
     * Get a genre by ID.
     *
     * @param string $id The genre ID
     *
     * @return array|null The genre data or null if not found
     */
    public function getGenre(string $id): ?array;

    public function listGenres(?int $since = null);

    public function deleteGenre(string $id);

    /**
     * Update a genre.
     *
     * @param string $id The genre ID
     * @param array $data The updated genre data
     *
     * @return bool True if the update was successful
     */
    public function updateGenre(string $id, array $data): bool;

    /**
     * List all genres with aggregated statistics.
     *
     * Implementations should at minimum provide:
     * - id: string
     * - name: string
     * - authorCount: int (distinct authors with books in this genre)
     * - bookCount: int (books in this genre)
     *
     * @param int|null $since Timestamp to filter by updated_at
     * @return array
     */
    public function listGenresWithStats(?int $since = null): array;

    // SERIES
    public function createSeries(string $name, bool $isCollection = false);

    public function updateSeries(int $id, array $data);

    public function getSeriesByName(string $name): ?array;

    public function findOrCreateSeriesByName(string $name);

    public function getSeries(string $id);

    public function deleteSeries(string $id);

    public function listSeries(?int $since = null): array;

    public function searchSeriesByName(string $term): array;

    // AUTHORS
    public function createAuthor(array $data);

    /**
     * Get an author by ID.
     *
     * @param string $id The author ID
     *
     * @return array|null The author data or null if not found
     */
    public function getAuthor(string $id): ?array;

    /**
     * Update an author.
     *
     * @param string $id The author ID
     * @param array $data The updated author data
     *
     * @return bool True if the update was successful
     */
    public function updateAuthor(string $id, array $data): bool;

    public function listAuthors(?int $since = null);

    public function deleteAuthor(string $id): void;

    /**
     * List all authors with aggregated statistics.
     *
     * Implementations should at minimum provide:
     * - id: string
     * - name: string
     * - bookCount: int (total books for this author)
     *
     * @param int|null $since Timestamp to filter by updated_at
     * @return array
     */
    public function listAuthorsWithStats(?int $since = null): array;

    /**
     * Get hierarchy information for a specific genre.
     *
     * This should return authors that have at least one book in the genre and
     * their respective book counts within that genre.
     *
     * Expected shape:
     * [
     *   'genre' => ['id' => string, 'name' => string],
     *   'authors' => [
     *       ['id' => string, 'name' => string, 'bookCount' => int],
     *       ...
     *   ],
     * ]
     */
    public function getGenreAuthorsHierarchy(string $genreId): array;

    /**
     * Get hierarchy information for a specific author, optionally scoped to a genre.
     *
     * Expected shape:
     * [
     *   'author' => ['id' => string, 'name' => string],
     *   'genre' => ['id' => string, 'name' => string]|null,
     *   'series' => [
     *       ['id' => string, 'name' => string, 'bookCount' => int],
     *   ],
     *   'standaloneBooks' => [
     *       ['id' => string, 'title' => string, 'directoryPath' => ?string],
     *   ],
     * ]
     */
    public function getAuthorHierarchy(string $authorId, ?string $genreId = null): array;

    /**
     * Merge multiple authors into a single primary author.
     *
     * Implementations should move all book relationships from secondary authors
     * to the primary author, avoiding duplicate pivot rows, and delete the
     * secondary author records.
     *
     * @param string $primaryAuthorId
     * @param array $secondaryAuthorIds
     *
     * @return int Number of affected books or relationships
     */
    public function mergeAuthors(string $primaryAuthorId, array $secondaryAuthorIds): int;

    /**
     * Merge multiple genres into a single primary genre.
     *
     * Implementations should move all book relationships from secondary genres
     * to the primary genre, avoiding duplicate pivot rows, and delete the
     * secondary genre records.
     *
     * @param string $primaryGenreId
     * @param array $secondaryGenreIds
     *
     * @return int Number of affected books or relationships
     */
    public function mergeGenres(string $primaryGenreId, array $secondaryGenreIds): int;

    /**
     * Merge multiple series into a single primary series.
     *
     * Implementations should move all book relationships from secondary series
     * to the primary series, preserving series_number values, avoiding duplicate
     * pivot rows, and delete the secondary series records.
     *
     * @param string $primarySeriesId
     * @param array $secondarySeriesIds
     *
     * @return int Number of affected books or relationships
     */
    public function mergeSeries(string $primarySeriesId, array $secondarySeriesIds): int;

    /**
     * Search for authors by name.
     */
    public function searchAuthorsByName(string $term): array;

    /**
     * Search for narrators by name.
     */
    public function searchNarratorsByName(string $term): array;

    /**
     * Search for genres by name.
     */
    public function searchGenresByName(string $term): array;

    // MESSAGES
    public function createMessage(array $messageData): ?string;

    /**
     * Mark a message as acknowledged.
     */
    public function acknowledgeMessage(string $messageId): bool;

    /**
     * Create an API token for a user.
     *
     * @param array $tokenData the token data including user_id, token, etc
     *
     * @return string|null The token ID or null on failure
     */
    public function createApiToken(array $tokenData): ?string;

    /**
     * Get an API token by its raw value.
     *
     * @return array|null Token row as an array (e.g. ['id' => int, 'user_id' => int|string, 'token' => string])
     */
    public function getApiTokenByValue(string $tokenValue): ?array;

    /**
     * Delete an API token by its value.
     *
     * @param string $tokenValue The token value to delete
     *
     * @return bool True if token was deleted, false otherwise
     */
    public function deleteApiTokenByValue(string $tokenValue): bool;

    /**
     * Get all admin users.
     *
     * @return array List of admin users
     */
    public function getAdminUsers(): array;

    /**
     * Check if a follow relationship exists.
     *
     * @param string $userId The user ID
     * @param string $followableType The type of entity being followed (e.g., 'author', 'series')
     * @param string $followableId The ID of the entity being followed
     *
     * @return bool True if the follow relationship exists, false otherwise
     */
    public function followExists(string $userId, string $followableType, string $followableId): bool;

    /**
     * Create a new follow relationship.
     *
     * @param string $userId The user ID who is following
     * @param string $followableType The type of entity being followed (e.g., 'author', 'series')
     * @param string $followableId The ID of the entity being followed
     *
     * @return bool True if the follow was created successfully, false otherwise
     */
    public function createFollow(string $userId, string $followableType, string $followableId): bool;

    /**
     * Delete a follow relationship.
     *
     * @param string $userId The user ID who is following
     * @param string $followableType The type of entity being unfollowed (e.g., 'author', 'series')
     * @param string $followableId The ID of the entity being unfollowed
     *
     * @return bool True if the unfollow was successful, false otherwise
     */
    public function deleteFollow(string $userId, string $followableType, string $followableId): bool;

    public function getMessages(?string $userId = null, bool $includeAcknowledged = false, int $limit = 100): array;

    /**
     * Update a document in a specific collection.
     *
     * @param string $collection the collection name
     * @param string $id the document ID
     * @param array $data the data to update
     *
     * @return bool true on success, false on failure
     */
    public function updateDocument(string $collection, string $id, array $data): bool;

    // JOBS
    /**
     * Return all jobs.
     */
    public function getJobs(): array;

    /**
     * List jobs with optional filtering and pagination.
     *
     * @param string|null $type Job type filter
     * @param string|null $status Job status filter
     * @param int $limit Maximum number of jobs to return
     * @param string $orderBy Field to order by
     * @param string $direction Sort direction (ASC or DESC)
     * @param string|null $startAfterId ID to start after (for pagination)
     *
     * @return array List of jobs
     */
    public function listJobs(
        ?string $type = null,
        ?string $status = null,
        int $limit = 50,
        string $orderBy = 'updated_at',
        string $direction = 'DESC',
        ?string $startAfterId = null
    ): array;

    /**
     * Get the count of pending jobs.
     *
     * @return int Number of pending jobs
     */
    public function getJobCount(): int;

    /**
     * Delete all jobs.
     *
     * @return bool Success status
     */
    public function clearJobs(bool $confirmed = false): bool;

    /**
     * Check if a job exists for a specific directory path.
     *
     * @param string $directoryPath The directory path to check
     *
     * @return bool True if a job exists for this directory path
     */
    public function jobExistsByDirectoryPath(string $directoryPath): bool;

    /**
     * Check if a book exists with a specific directory path.
     *
     * @param string $directoryPath The directory path to check
     *
     * @return bool True if a book exists with this directory path
     */
    public function bookExistsByDirectoryPath(string $directoryPath): bool;

    /**
     * Delete a job by ID.
     *
     * @param string $jobId The job ID to delete
     *
     * @return bool Success status
     */
    public function deleteJob(string $jobId): bool;

    /**
     * Get a job by ID.
     *
     * @param string $jobId The job ID
     *
     * @return array|null The job data or null if not found
     */
    public function getJob(string $jobId): ?array;

    /**
     * Update a job.
     *
     * @param string $jobId The job ID
     * @param array $data The updated job data
     *
     * @return bool Success status
     */
    public function updateJob(string $jobId, array $data): bool;

    /**
     * Update job status with metadata.
     *
     * @param string $jobId The job ID
     * @param string $type The job type
     * @param string $status The job status
     * @param array $metadata Additional metadata to merge
     *
     * @return bool Success status
     */
    public function updateJobStatus(string $jobId, string $type, string $status, array $metadata = []): bool;

    /**
     * Clean up old completed/failed jobs.
     *
     * @param int $daysOld
     *
     * @return int Number of deleted jobs
     */
    public function cleanupOldJobs(int $daysOld): int;

    // QUEUES
    public function getBookQueue(string $userId): array;

    /**
     * Add a book to a user's queue.
     *
     * @param string $userId The user ID
     * @param string $bookId The book ID to add
     *
     * @return bool Success status
     */
    public function addBookToQueue(string $userId, string $bookId): bool;

    /**
     * Remove a book from a user's queue.
     *
     * @param string $userId The user ID
     * @param string $bookId The book ID to remove
     *
     * @return bool Success status
     */
    public function removeBookFromQueue(string $userId, string $bookId): bool;

    public function getQueueCollection($name);

    // SERIES BOOKS
    public function getBooksInSeries(string $seriesId): array;

    /**
     * Get the manifest of contents for a book download.
     */
    public function getManifestForBook(string $bookId): array;

    // READING PROGRESS
    /**
     * Reset reading progress for a user and book.
     *
     * @return bool Success status
     */
    public function resetReadingProgress(string $userId, string $bookId): bool;

    /**
     * Update reading progress (legacy name used by API controller).
     */
    public function updateReadingProgress(string $userId, string $bookId, int $currentPositionSeconds): bool;

    /**
     * Set reading progress (legacy name used by web controller).
     */
    public function setReadingProgress(string $userId, string $bookId, int $currentPositionSeconds): bool;

    /**
     * Get reading progress for a user/book.
     */
    public function getReadingProgress(string $userId, string $bookId): int;

    /**
     * Get reading progress for a user/book (alias for getReadingProgress).
     *
     * @param string $userId The user ID
     * @param string $bookId The book ID
     *
     * @return int|null Current position in seconds, or null if no progress
     */
    public function getProgress(string $userId, string $bookId): ?int;

    // BOOKMARKS
    /**
     * Get all bookmarks for a user and book.
     */
    public function getBookmarks(string $userId, string $bookId): array;

    /**
     * Get a specific bookmark by ID, filtered by user and book.
     */
    public function getBookmark(string $bookmarkId, string $userId, string $bookId): ?array;

    /**
     * Create a new bookmark.
     *
     * @return string Bookmark ID
     */
    public function createBookmark(array $data): string;

    /**
     * Update a bookmark.
     */
    public function updateBookmark(string $bookmarkId, array $data): bool;

    /**
     * Delete a bookmark.
     */
    public function deleteBookmark(string $bookmarkId, string $userId, string $bookId): bool;

    /**
     * Delete a bookmark by ID (without book context).
     */
    public function deleteBookmarkById(string $bookmarkId, string $userId): bool;

    // EXTERNAL READS
    /**
     * Get all external/previously-read entries for a user and book.
     *
     * @param string $userId The user ID
     * @param string $bookId The book ID
     *
     * @return array List of external read entries
     */
    public function getExternalReads(string $userId, string $bookId): array;

    /**
     * Get a specific external read entry.
     *
     * @param string $externalReadId The external read ID
     * @param string $userId The user ID
     * @param string $bookId The book ID
     *
     * @return array|null The external read data or null if not found
     */
    public function getExternalRead(string $externalReadId, string $userId, string $bookId): ?array;

    /**
     * Create a new external read entry.
     *
     * @param array $data The external read data
     *
     * @return string The created external read ID
     */
    public function createExternalRead(array $data): string;

    /**
     * Update an external read entry.
     *
     * @param string $externalReadId The external read ID
     * @param array $data The updated data
     *
     * @return bool Success status
     */
    public function updateExternalRead(string $externalReadId, array $data): bool;

    /**
     * Delete an external read entry.
     *
     * @param string $externalReadId The external read ID
     * @param string $userId The user ID
     * @param string $bookId The book ID
     *
     * @return bool Success status
     */
    public function deleteExternalRead(string $externalReadId, string $userId, string $bookId): bool;

    /**
     * Link statistical data that has no book_id to matching books by title/author.
     *
     * @return int Number of records linked
     */
    public function linkNonLibraryBooks(): int;

    // ACCOUNT REQUESTS

    /**
     * Get pending account requests.
     *
     * @return array List of pending account requests
     */
    public function getPendingAccountRequests(): array;

    /**
     * Get a specific account request by ID.
     *
     * @param string $id The account request ID
     *
     * @return array|null The account request data or null if not found
     */
    public function getAccountRequest(string $id): ?array;

    /**
     * Create a new review for a book.
     *
     * @param array $data
     * @return string Review ID
     */
    public function createReview(array $data): string;

    /**
     * Approve an account request.
     *
     * @param string $id The account request ID
     *
     * @return bool True if the request was approved successfully
     */
    public function approveAccountRequest(string $id): bool;

    /**
     * Reject an account request.
     *
     * @param string $id The account request ID
     *
     * @return bool True if the request was rejected successfully
     */
    public function rejectAccountRequest(string $id): bool;

    // GENERIC

    /**
     * Find or create multiple documents in a collection by name.
     *
     * @param string $collection The collection name (e.g., 'authors', 'genres').
     * @param array $names an array of names to find or create
     *
     * @return array an array of document IDs
     */
    public function findOrCreateMany(string $collection, array $names): array;

    public function getDocument(string $collection, string $docId): ?array;

    public function getClient();
}
