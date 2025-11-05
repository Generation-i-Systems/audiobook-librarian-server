<?php

namespace App\Traits;

use App\Models\Book;
use Illuminate\Support\Facades\Log;

trait HandlesLibraryJson
{
    /**
     * Generate or update librarian.json for a book
     *
     * @param Book|array $book Book model or book data array
     * @return bool True on success, false on failure
     */
    public function updateLibraryJson($book): bool
    {
        try {
            // If we have a Book model, load its relationships
            if ($book instanceof Book) {
                $book = $book->loadMissing([
                    'authors',
                    'narrators',
                    'genres',
                    'series',
                    'publisher'
                ])->toArray();
            }

            // Ensure we have the required data
            if (empty($book['directory_path'])) {
                Log::warning('Cannot generate librarian.json: Missing directory path', [
                    'book_id' => $book['id'] ?? null
                ]);
                return false;
            }

            $bookDir = $book['directory_path'];

            // Skip if directory doesn't exist
            if (!is_dir($bookDir)) {
                Log::warning('Book directory does not exist for librarian.json', [
                    'book_id' => $book['id'] ?? null,
                    'directory' => $bookDir
                ]);
                return false;
            }

            $jsonPath = rtrim($bookDir, '/') . '/librarian.json';

            // Prepare book data for JSON
            $jsonData = $this->prepareBookDataForJson($book);

            $jsonString = json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($jsonString === false) {
                throw new \RuntimeException('Failed to encode book data to JSON: ' . json_last_error_msg());
            }

            $result = file_put_contents($jsonPath, $jsonString);

            if ($result === false) {
                throw new \RuntimeException("Failed to write JSON file: $jsonPath");
            }

            // Update the book's last_library_json_update timestamp if we have a model
            if (isset($book['id'])) {
                Book::where('id', $book['id'])->update([
                    'last_library_json_update' => now()
                ]);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to generate librarian.json', [
                'book_id' => $book['id'] ?? null,
                'path' => $jsonPath ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Prepare book data for JSON serialization
     */
    protected function prepareBookDataForJson(array $book): array
    {
        return [
            'id' => $book['id'],
            'title' => $book['title'],
            'description' => $book['description'] ?? null,
            'release_date' => $book['release_date'] ?? null,
            'isbn' => $book['isbn'] ?? null,
            'language' => $book['language'] ?? 'en',
            'duration' => $book['duration'] ?? null,
            'duration_formatted' => $book['duration_formatted'] ?? null,
            'file_size' => $book['file_size'] ?? null,
            'file_size_formatted' => $book['file_size_formatted'] ?? null,
            'cover_image' => $book['cover_image'] ?? null,
            'authors' => array_map(function ($author) {
                return [
                    'id' => $author['id'],
                    'name' => $author['name'],
                    'sort_name' => $author['sort_name'] ?? null,
                ];
            }, $book['authors'] ?? []),
            'narrators' => array_map(function ($narrator) {
                return [
                    'id' => $narrator['id'],
                    'name' => $narrator['name'],
                    'sort_name' => $narrator['sort_name'] ?? null,
                ];
            }, $book['narrators'] ?? []),
            'genres' => array_map(function ($genre) {
                return [
                    'id' => $genre['id'],
                    'name' => $genre['name'],
                    'slug' => $genre['slug'] ?? null,
                ];
            }, $book['genres'] ?? []),
            'series' => !empty($book['series']) ? [
                'id' => $book['series']['id'],
                'name' => $book['series']['name'],
                'number_in_series' => $book['pivot']['number_in_series'] ?? null,
                'is_collection' => $book['series']['is_collection'] ?? false,
            ] : null,
            'publisher' => !empty($book['publisher']) ? [
                'id' => $book['publisher']['id'],
                'name' => $book['publisher']['name'],
            ] : null,
            'created_at' => $book['created_at'] ?? null,
            'updated_at' => $book['updated_at'] ?? null,
            'metadata' => [
                'source' => $book['source'] ?? 'import',
                'imported_at' => $book['created_at'] ?? now()->toDateTimeString(),
                'version' => '1.0',
            ]
        ];
    }
}
