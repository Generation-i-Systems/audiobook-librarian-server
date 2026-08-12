<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Book;

/**
 * Resolves an event/position's real server book_id when the client-supplied id doesn't exist
 * (a phantom/local-only id) — used both at sync ingestion time (EventController) and by the
 * one-time repair command for already-stored rows (see books:repair-phantom-book-ids).
 */
class BookIdResolver
{
    /**
     * Resolve a book_id, falling back when it doesn't exist locally.
     *
     * The client's local library path sometimes carries the server book id as a trailing
     * "_<id>" suffix on the leaf folder (e.g. "09 The Longest Battle_13667") — added for its
     * own local disambiguation — even when the bookId field itself is a stale/local-only id
     * that doesn't exist on the server. That suffix is authoritative and exact, so it's tried
     * before the fuzzy directory_path LIKE match, which breaks the moment the client appends
     * anything to the folder name.
     *
     * Falls back further to an exact title+author match when path-based resolution also fails
     * — files that never went through our download manager (e.g. imported from elsewhere, or
     * synced against a title/author-keyed backend like AbLibrarian Lite) may have a local path
     * that doesn't resemble the server's directory structure at all, so title+author is the
     * only reliable signal left.
     *
     * Returns null when none of the id, the path, or the title+author resolve to a real book —
     * the caller decides what to do with an unresolvable id (ingestion keeps it as-is; the
     * repair command skips it).
     */
    public function resolve(int $bookId, ?string $bookPath, ?string $fallbackTitle, ?string $fallbackAuthor): ?int
    {
        if (Book::where('id', $bookId)->exists()) {
            return $bookId;
        }

        if ($bookPath !== null) {
            if (preg_match('/_(\d+)$/', basename($bookPath), $matches) === 1) {
                $suffixedId = (int) $matches[1];
                if (Book::where('id', $suffixedId)->exists()) {
                    return $suffixedId;
                }
            }

            $book = Book::where('directory_path', 'like', '%' . basename($bookPath) . '%')->first();
            if ($book !== null) {
                return $book->id;
            }
        }

        if ($fallbackTitle !== null && $fallbackAuthor !== null) {
            $book = Book::where('title', $fallbackTitle)
                ->whereHas('authors', fn ($query) => $query->where('name', $fallbackAuthor))
                ->first();
            if ($book !== null) {
                return $book->id;
            }
        }

        return null;
    }
}
