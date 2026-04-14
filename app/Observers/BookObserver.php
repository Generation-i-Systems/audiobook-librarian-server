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
        // Only update librarian.json if the book has a directory path
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

                $result = $this->updateLibraryJson($book);
                Log::debug($result ? 'Updated librarian.json for book' : 'Skipped librarian.json update for book', [
                    'book_id' => $book->id,
                    'title' => $book->title,
                    'directory_path' => $book->directory_path,
                ]);
            } catch (\Exception $e) {
                // Don't fail the save operation if librarian.json update fails
                Log::warning('Failed to update librarian.json', [
                    'book_id' => $book->id,
                    'title' => $book->title,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Handle pivot attach events (e.g., authors/genres/series changes).
     * Relationship changes do not necessarily trigger a Book "saved" event.
     */
    public function pivotAttached(Book $book, string $relationName, array $pivotIds, array $pivotIdsAttributes): void
    {
        Log::info('Pivot attached event intercepted', [
            'book_id' => $book->id,
            'relation' => $relationName,
            'ids' => $pivotIds,
        ]);
        $this->handlePivotChange($book, $relationName);
    }

    /**
     * Handle pivot detach events (e.g., authors/genres/series changes).
     */
    public function pivotDetached(Book $book, string $relationName, array $pivotIds): void
    {
        $this->handlePivotChange($book, $relationName);
    }

    /**
     * Handle pivot update events (e.g., series_number changes).
     */
    public function pivotUpdated(Book $book, string $relationName, array $pivotIds, array $pivotIdsAttributes): void
    {
        $this->handlePivotChange($book, $relationName);
    }

    private function handlePivotChange(Book $book, string $relationName): void
    {
        if (empty($book->directory_path)) {
            return;
        }

        try {
            $result = $this->updateLibraryJson($book);
            Log::debug($result ? 'Updated librarian.json for book after pivot change' : 'Skipped librarian.json update after pivot change', [
                'book_id' => $book->id,
                'title' => $book->title,
                'relation' => $relationName,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to update librarian.json after pivot change', [
                'book_id' => $book->id,
                'title' => $book->title,
                'relation' => $relationName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the Book "deleted" event.
     * NOTE: With SoftDeletes enabled, BookDeletionService handles moving files to trash.
     * This method no longer deletes librarian.json directly to prevent data loss.
     */
    public function deleted(Book $book): void
    {
        // Do nothing - BookDeletionService handles file cleanup via trash system
        // If a book is soft-deleted, files remain in place until permanently deleted from trash
        // If force-deleted, BookDeletionService should have already moved files to trash

        Log::debug('Book deleted event fired', [
            'book_id' => $book->id,
            'title' => $book->title,
            'soft_deleted' => $book->trashed(),
        ]);
    }

    public static function clearUpdateCache(): void
    {
        // No-op: retained for test compatibility after removing request-local cache state.
    }
}
