<?php

namespace App\Observers;

use App\Models\Book;
use App\Traits\HandlesLibraryJson;
use Illuminate\Support\Facades\Log;

class BookObserver
{
    use HandlesLibraryJson;

    /**
     * Handle the Book "saved" event.
     * This fires after both create and update operations.
     */
    public function saved(Book $book): void
    {
        // Only update library.json if the book has a directory path
        if (empty($book->directory_path)) {
            return;
        }

        // Only update if the book data has actually changed
        // (not just timestamps or relationships)
        if ($book->wasRecentlyCreated || $book->wasChanged()) {
            try {
                // Ensure relationships are loaded
                if (!$book->relationLoaded('authors')) {
                    $book->load(['authors', 'narrators', 'genres', 'series', 'publisher']);
                }

                $this->updateLibraryJson($book);

                Log::debug('Updated library.json for book', [
                    'book_id' => $book->id,
                    'title' => $book->title,
                    'directory_path' => $book->directory_path
                ]);
            } catch (\Exception $e) {
                // Don't fail the save operation if library.json update fails
                Log::warning('Failed to update library.json', [
                    'book_id' => $book->id,
                    'title' => $book->title,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Handle the Book "deleted" event.
     * Clean up library.json when a book is deleted.
     */
    public function deleted(Book $book): void
    {
        if (empty($book->directory_path)) {
            return;
        }

        try {
            $jsonPath = rtrim($book->directory_path, '/') . '/library.json';

            if (file_exists($jsonPath)) {
                unlink($jsonPath);
                Log::debug('Deleted library.json for book', [
                    'book_id' => $book->id,
                    'title' => $book->title,
                    'path' => $jsonPath
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to delete library.json', [
                'book_id' => $book->id,
                'title' => $book->title,
                'error' => $e->getMessage()
            ]);
        }
    }
}
