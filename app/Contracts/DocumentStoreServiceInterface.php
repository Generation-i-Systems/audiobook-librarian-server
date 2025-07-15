<?php

namespace App\Contracts;

interface DocumentStoreServiceInterface
{
    /**
     * Autocomplete series names using fuzzy search.
     *
     * @param string $query
     * @param int $limit
     * @return array
     */
    public function autocompleteSeries(string $query, int $limit = 10): array;

    /**
     * Autocomplete author names using fuzzy search.
     *
     * @param string $query
     * @param int $limit
     * @return array
     */
    public function autocompleteAuthors(string $query, int $limit = 10): array;

    /**
     * Autocomplete narrator names using fuzzy search.
     *
     * @param string $query
     * @param int $limit
     * @return array
     */
    public function autocompleteNarrators(string $query, int $limit = 10): array;

    // BOOKS
    public function getBook(string $id);
    public function listBooks();
    public function createBook(array $data);
    public function updateBook(string $id, array $data);
    public function deleteBook(string $id);
    public function getBooksByAuthorAndGenre($author, $genre);
    public function dumpAllBooks();

    // USERS
    public function getUserById($identifier);
    public function getUserByCredentials($credentials);
    public function getUserByRememberToken($identifier, $token);
    public function createUser(array $data);
    public function updateUser(string $id, array $data);
    public function deleteUser(string $id);
    public function getUsersForMessaging(): array;

    // GENRES
    public function createGenre(array $data);
    public function listGenres();
    public function deleteGenre(string $id);

    // SERIES
    public function createSeries(array $data);
    public function findOrCreateSeriesByName(string $name);
    public function getSeries(string $id);
    public function deleteSeries(string $id);
    public function listSeries(): array;
    public function searchSeriesByName(string $term): array;

    // AUTHORS
    public function createAuthor(array $data);
    public function listAuthors();
    public function deleteAuthor(string $id): void;
    /**
     * Search for authors by name
     *
     * @param string $term
     * @return array
     */
    public function searchAuthorsByName(string $term): array;
    /**
     * Search for narrators by name
     *
     * @param string $term
     * @return array
     */
    public function searchNarratorsByName(string $term): array;

    // MESSAGES
    public function createMessage(array $messageData): ?string;
    public function getMessages(?string $userId = null, bool $includeAcknowledged = false, int $limit = 100): array;

    // JOBS
    /**
     * List jobs with optional filtering and pagination
     *
     * @param string|null $type Job type filter
     * @param string|null $status Job status filter
     * @param int $limit Maximum number of jobs to return
     * @param string $orderBy Field to order by
     * @param string $direction Sort direction (ASC or DESC)
     * @param string|null $startAfterId ID to start after (for pagination)
     * @return array
     */
    public function listJobs(
        ?string $type = null,
        ?string $status = null,
        int $limit = 50,
        string $orderBy = 'updated_at',
        string $direction = 'DESC',
        ?string $startAfterId = null
    ): array;
    public function deleteJob(string $jobId): bool;

    // QUEUES
    public function getBookQueue(string $userId): array;
    public function getQueueCollection($name);

    // SERIES BOOKS
    public function getBooksInSeries(string $seriesId): array;
    /**
     * Get the manifest of contents for a book download
     *
     * @param string $bookId
     * @return array
     */
    public function getManifestForBook(string $bookId): array;

    // READING PROGRESS
    /**
     * Reset reading progress for a user and book.
     *
     * @param string $userId
     * @param string $bookId
     * @return bool Success status
     */
    public function resetReadingProgress(string $userId, string $bookId): bool;

    // BOOKMARKS
    /**
     * Get all bookmarks for a user and book
     *
     * @param string $userId
     * @param string $bookId
     * @return array
     */
    public function getBookmarks(string $userId, string $bookId): array;

    /**
     * Get a specific bookmark by ID, filtered by user and book
     *
     * @param string $bookmarkId
     * @param string $userId
     * @param string $bookId
     * @return array|null
     */
    public function getBookmark(string $bookmarkId, string $userId, string $bookId): ?array;

    /**
     * Create a new bookmark
     *
     * @param array $data
     * @return string Bookmark ID
     */
    public function createBookmark(array $data): string;

    /**
     * Update a bookmark
     *
     * @param string $bookmarkId
     * @param array $data
     * @return bool
     */
    public function updateBookmark(string $bookmarkId, array $data): bool;

    /**
     * Delete a bookmark
     *
     * @param string $bookmarkId
     * @param string $userId
     * @param string $bookId
     * @return bool
     */
    public function deleteBookmark(string $bookmarkId, string $userId, string $bookId): bool;

    // GENERIC
    public function getDocument(string $collection, string $docId): ?array;
    public function getClient();
}
