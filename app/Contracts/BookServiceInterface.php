<?php

namespace App\Contracts;

interface BookServiceInterface
{
    /**
     * Search for books by title and/or author
     *
     * @param  string  $query  Search query (title, author, or keywords)
     * @param  array  $options  Additional search options (e.g., ['author' => 'Author Name', 'limit' => 5])
     * @return array|null Array of search results or null on failure
     */
    public function searchBooks(string $query, array $options = []): ?array;

    /**
     * Get book details by unique identifier
     *
     * @param  string  $id  The unique identifier (ISBN, ASIN, etc.)
     * @return array|null Book details or null on failure
     */
    public function getBookDetails(string $id): ?array;

    /**
     * Get the service name (e.g., 'google_books', 'audible')
     */
    public function getServiceName(): string;

    /**
     * Check if the service is available
     */
    public function isAvailable(): bool;

    /**
     * Download a cover image for a book to the specified directory and basename.
     *
     * @param  string  $imageUrl  URL of the image to download
     * @param  string  $directoryPath  Directory to save the image
     * @param  string  $targetBasename  Basename for the saved file (no extension)
     * @return string|null Path to the saved file or null on failure
     */
    public function downloadCoverImage(string $imageUrl, string $directoryPath, string $targetBasename): ?string;
}
