<?php

namespace App\Contracts;

interface BookServiceInterface
{
    /**
     * Search for books by title and/or author
     *
     * @param string $query Search query (title, author, or keywords)
     * @param array $options Additional search options (e.g., ['author' => 'Author Name', 'limit' => 5])
     * @return array|null Array of search results or null on failure
     */
    public function searchBooks(string $query, array $options = []): ?array;

    /**
     * Get book details by unique identifier
     *
     * @param string $id The unique identifier (ISBN, ASIN, etc.)
     * @return array|null Book details or null on failure
     */
    public function getBookDetails(string $id): ?array;

    /**
     * Get the service name (e.g., 'google_books', 'audible')
     *
     * @return string
     */
    public function getServiceName(): string;

    /**
     * Check if the service is available
     *
     * @return bool
     */
    public function isAvailable(): bool;
}
