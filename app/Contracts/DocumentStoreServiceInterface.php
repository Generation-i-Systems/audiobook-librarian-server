<?php

namespace App\Contracts;

interface DocumentStoreServiceInterface
{
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

    // AUTHORS
    public function createAuthor(array $data);
    public function listAuthors();
    public function deleteAuthor(string $id): void;
    public function searchAuthorsByName(string $term): array;

    // MESSAGES
    public function createMessage(array $messageData): ?string;
    public function getMessages(?string $userId = null, bool $includeAcknowledged = false, int $limit = 100): array;

    // JOBS
    public function listJobs(?string $type = null, ?string $status = null, int $limit = 50, string $orderBy = 'updated_at', string $direction = 'DESC', ?string $startAfterId = null): array;
    public function deleteJob(string $jobId): bool;

    // QUEUES
    public function getBookQueue(string $userId): array;
    public function getQueueCollection($name);

    // READING PROGRESS
    /**
     * Reset reading progress for a user and book.
     *
     * @param string $userId
     * @param string $bookId
     * @return bool Success status
     */
    public function resetReadingProgress(string $userId, string $bookId): bool;

    // GENERIC
    public function getDocument(string $collection, string $docId): ?array;
    public function getClient();
}
