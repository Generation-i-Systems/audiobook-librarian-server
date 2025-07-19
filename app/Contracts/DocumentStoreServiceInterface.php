<?php

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
    public function getBook(string $id);

    /**
     * List books with pagination and optional filtering
     *
     * @param int $page Page number (1-based)
     * @param int $perPage Number of items per page
     * @param array $filters Optional filters (e.g., ['author' => 'John Doe', 'genre' => 'Fiction'])
     * @param bool $withRelated Whether to load related data (authors, series)
     * @return array [
     *     'data' => array,  // The paginated list of books
     *     'total' => int,   // Total number of books matching filters
     *     'per_page' => int,// Number of items per page
     *     'current_page' => int, // Current page number
     *     'last_page' => int,    // Last available page number
     * ]
     */
    public function listBooks(int $page = 1, int $perPage = 24, array $filters = [], bool $withRelated = true);

    /**
     * Get recently added books
     *
     * @param int $limit Maximum number of recent books to return
     * @param int $days Number of days to look back for recent books
     * @return array Array of recent books with related data
     */
    public function getRecentBooks(int $limit = 10, int $days = 30);
    
    /**
     * Get unique values for a specific field across all books
     * 
     * @param string $field The field to get unique values for (e.g., 'genre', 'author')
     * @param string $subField Optional subfield for nested data (e.g., 'seriesName' when field is 'series')
     * @return array Array of unique values
     */
    public function getUniqueValues(string $field, string $subField = null): array;

    public function createBook(array $data);

    public function updateBook(string $id, array $data);

    public function deleteBook(string $id);

    public function getBooksByAuthorAndGenre($author, $genre);

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
     * @return bool
     */
    public function validateUserCredentials($user, array $credentials): bool;

    /**
     * Get a user by their username
     *
     * @param string $username The username to search for
     * @return array|null The user data or null if not found
     */
    public function getUserByUsername(string $username): ?array;

    /**
     * Get a user by their email address
     *
     * @param string $email The email address to search for
     * @return array|null The user data or null if not found
     */
    public function getUserByEmail(string $email): ?array;

    /**
     * Check if a user with the given email exists
     *
     * @param string $email The email address to check
     * @return bool True if a user with this email exists
     */
    public function userExistsByEmail(string $email): bool;

    /**
     * Check if a user with the given username exists
     *
     * @param string $username The username to check
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
     * @return void
     */
    public function updateRememberToken(string $identifier, string $token): void;

    /**
     * Get all users in the system
     *
     * @return array List of all users
     */
    public function getAllUsers(): array;

    // GENRES
    public function createGenre(array $data);

    /**
     * Get a genre by ID
     *
     * @param string $id The genre ID
     * @return array|null The genre data or null if not found
     */
    public function getGenre(string $id): ?array;

    public function listGenres();

    public function deleteGenre(string $id);

    /**
     * Update a genre
     *
     * @param string $id The genre ID
     * @param array $data The updated genre data
     * @return bool True if the update was successful
     */
    public function updateGenre(string $id, array $data): bool;

    // SERIES
    public function createSeries(string $name);

    public function getSeriesByName(string $name): ?array;

    public function findOrCreateSeriesByName(string $name);

    public function getSeries(string $id);

    public function deleteSeries(string $id);

    public function listSeries(): array;

    public function searchSeriesByName(string $term): array;

    // AUTHORS
    public function createAuthor(array $data);

    /**
     * Get an author by ID
     *
     * @param string $id The author ID
     * @return array|null The author data or null if not found
     */
    public function getAuthor(string $id): ?array;

    /**
     * Update an author
     *
     * @param string $id The author ID
     * @param array $data The updated author data
     * @return bool True if the update was successful
     */
    public function updateAuthor(string $id, array $data): bool;

    public function listAuthors();

    public function deleteAuthor(string $id): void;

    /**
     * Search for authors by name
     */
    public function searchAuthorsByName(string $term): array;

    /**
     * Search for narrators by name
     */
    public function searchNarratorsByName(string $term): array;

    // MESSAGES
    public function createMessage(array $messageData): ?string;

    /**
     * Create an API token for a user
     *
     * @param array $tokenData The token data including user_id, token, etc.
     * @return string|null The token ID or null on failure
     */
    public function createApiToken(array $tokenData): ?string;

    /**
     * Delete an API token by its value
     *
     * @param string $tokenValue The token value to delete
     * @return bool True if token was deleted, false otherwise
     */
    public function deleteApiTokenByValue(string $tokenValue): bool;

    /**
     * Get all admin users
     *
     * @return array List of admin users
     */
    public function getAdminUsers(): array;

    /**
     * Check if a follow relationship exists
     *
     * @param string $userId The user ID
     * @param string $followableType The type of entity being followed (e.g., 'author', 'series')
     * @param string $followableId The ID of the entity being followed
     * @return bool True if the follow relationship exists, false otherwise
     */
    public function followExists(string $userId, string $followableType, string $followableId): bool;

    /**
     * Create a new follow relationship
     *
     * @param string $userId The user ID who is following
     * @param string $followableType The type of entity being followed (e.g., 'author', 'series')
     * @param string $followableId The ID of the entity being followed
     * @return bool True if the follow was created successfully, false otherwise
     */
    public function createFollow(string $userId, string $followableType, string $followableId): bool;

    /**
     * Delete a follow relationship
     *
     * @param string $userId The user ID who is following
     * @param string $followableType The type of entity being unfollowed (e.g., 'author', 'series')
     * @param string $followableId The ID of the entity being unfollowed
     * @return bool True if the unfollow was successful, false otherwise
     */
    public function deleteFollow(string $userId, string $followableType, string $followableId): bool;

    public function getMessages(?string $userId = null, bool $includeAcknowledged = false, int $limit = 100): array;

    /**
     * Update a document in a specific collection.
     *
     * @param string $collection The collection name.
     * @param string $id The document ID.
     * @param array $data The data to update.
     * @return bool True on success, false on failure.
     */
    public function updateDocument(string $collection, string $id, array $data): bool;

    // JOBS
    /**
     * List jobs with optional filtering and pagination
     *
     * @param  string|null  $type  Job type filter
     * @param  string|null  $status  Job status filter
     * @param  int  $limit  Maximum number of jobs to return
     * @param  string  $orderBy  Field to order by
     * @param  string  $direction  Sort direction (ASC or DESC)
     * @param  string|null  $startAfterId  ID to start after (for pagination)
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
     * Get the count of pending jobs
     *
     * @return int Number of pending jobs
     */
    public function getJobCount(): int;

    /**
     * Delete all jobs
     *
     * @return bool Success status
     */
    public function clearJobs(): bool;

    /**
     * Check if a job exists for a specific directory path
     *
     * @param string $directoryPath The directory path to check
     * @return bool True if a job exists for this directory path
     */
    public function jobExistsByDirectoryPath(string $directoryPath): bool;

    /**
     * Check if a book exists with a specific directory path
     *
     * @param string $directoryPath The directory path to check
     * @return bool True if a book exists with this directory path
     */
    public function bookExistsByDirectoryPath(string $directoryPath): bool;

    /**
     * Delete a job by ID
     *
     * @param string $jobId The job ID to delete
     * @return bool Success status
     */
    public function deleteJob(string $jobId): bool;

    /**
     * Get a job by ID
     *
     * @param string $jobId The job ID
     * @return array|null The job data or null if not found
     */
    public function getJob(string $jobId): ?array;

    /**
     * Update a job
     *
     * @param string $jobId The job ID
     * @param array $data The updated job data
     * @return bool Success status
     */
    public function updateJob(string $jobId, array $data): bool;

    /**
     * Clean up old completed/failed jobs.
     *
     * @param  int  $daysOld
     * @return int Number of deleted jobs
     */
    public function cleanupOldJobs(int $daysOld): int;

    // QUEUES
    public function getBookQueue(string $userId): array;

    /**
     * Add a book to a user's queue
     *
     * @param string $userId The user ID
     * @param string $bookId The book ID to add
     * @return bool Success status
     */
    public function addBookToQueue(string $userId, string $bookId): bool;

    /**
     * Remove a book from a user's queue
     *
     * @param string $userId The user ID
     * @param string $bookId The book ID to remove
     * @return bool Success status
     */
    public function removeBookFromQueue(string $userId, string $bookId): bool;

    public function getQueueCollection($name);

    // SERIES BOOKS
    public function getBooksInSeries(string $seriesId): array;

    /**
     * Get the manifest of contents for a book download
     */
    public function getManifestForBook(string $bookId): array;

    // READING PROGRESS
    /**
     * Reset reading progress for a user and book.
     *
     * @return bool Success status
     */
    public function resetReadingProgress(string $userId, string $bookId): bool;

    // BOOKMARKS
    /**
     * Get all bookmarks for a user and book
     */
    public function getBookmarks(string $userId, string $bookId): array;

    /**
     * Get a specific bookmark by ID, filtered by user and book
     */
    public function getBookmark(string $bookmarkId, string $userId, string $bookId): ?array;

    /**
     * Create a new bookmark
     *
     * @return string Bookmark ID
     */
    public function createBookmark(array $data): string;

    /**
     * Update a bookmark
     */
    public function updateBookmark(string $bookmarkId, array $data): bool;

    /**
     * Delete a bookmark
     */
    public function deleteBookmark(string $bookmarkId, string $userId, string $bookId): bool;

    // ACCOUNT REQUESTS

    /**
     * Get pending account requests
     *
     * @return array List of pending account requests
     */
    public function getPendingAccountRequests(): array;

    /**
     * Get a specific account request by ID
     *
     * @param string $id The account request ID
     * @return array|null The account request data or null if not found
     */
    public function getAccountRequest(string $id): ?array;

    /**
     * Approve an account request
     *
     * @param string $id The account request ID
     * @return bool True if the request was approved successfully
     */
    public function approveAccountRequest(string $id): bool;

    /**
     * Reject an account request
     *
     * @param string $id The account request ID
     * @return bool True if the request was rejected successfully
     */
    public function rejectAccountRequest(string $id): bool;

    // GENERIC

    /**
     * Find or create multiple documents in a collection by name.
     *
     * @param  string  $collection The collection name (e.g., 'authors', 'genres').
     * @param  array  $names An array of names to find or create.
     * @return array An array of document IDs.
     */
    public function findOrCreateMany(string $collection, array $names): array;

    public function getDocument(string $collection, string $docId): ?array;

    public function getClient();
}
