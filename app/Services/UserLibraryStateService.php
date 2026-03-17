<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Book;
use App\Models\Bookmark;
use App\Models\ExternalRead;
use App\Models\User;
use App\Models\UserBookStatus;
use Illuminate\Support\Facades\Log;

class UserLibraryStateService
{
    public function getBookQueue(string $userId): array
    {
        try {
            $user = User::find($userId);

            if (!$user) {
                return [];
            }

            return $user->queuedBooks()->with(['authors', 'narrators', 'genres', 'series'])->get()->toArray();
        } catch (\Exception $e) {
            Log::error('MySqlService getBookQueue failed: ' . $e->getMessage());

            return [];
        }
    }

    public function addBookToQueue(string $userId, string $bookId): bool
    {
        try {
            $user = User::find($userId);

            if (!$user) {
                return false;
            }

            $book = Book::find($bookId);

            if (!$book) {
                return false;
            }

            if ($user->queuedBooks()->where('book_id', $bookId)->exists()) {
                return true;
            }

            $maxPosition = $user->queuedBooks()->max('order') ?? -1;

            UserBookStatus::create([
                'user_id' => (int) $userId,
                'book_id' => (int) $bookId,
                'order' => (int) $maxPosition + 1,
                'status' => 'queue',
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService addBookToQueue failed: ' . $e->getMessage());

            return false;
        }
    }

    public function removeBookFromQueue(string $userId, string $bookId): bool
    {
        try {
            $user = User::find($userId);

            if (!$user) {
                return false;
            }

            $book = Book::find($bookId);

            if (!$book) {
                return false;
            }

            UserBookStatus::where('user_id', (int) $userId)
                ->where('book_id', (int) $bookId)
                ->where('status', 'queue')
                ->delete();

            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService removeBookFromQueue failed: ' . $e->getMessage());

            return false;
        }
    }

    public function updateBookQueue(string $userId, array $bookIds): bool
    {
        try {
            $user = User::find($userId);

            if (!$user) {
                return false;
            }

            $existing = UserBookStatus::where('user_id', (int) $userId)
                ->where('status', 'queue')
                ->pluck('book_id')
                ->map(fn ($id) => (string) $id)
                ->toArray();

            $target = array_values(array_map(fn ($id) => (string) $id, $bookIds));
            $toDelete = array_diff($existing, $target);

            if ($toDelete !== []) {
                UserBookStatus::where('user_id', (int) $userId)
                    ->where('status', 'queue')
                    ->whereIn('book_id', array_map('intval', $toDelete))
                    ->delete();
            }

            foreach ($target as $index => $bookId) {
                UserBookStatus::updateOrCreate(
                    [
                        'user_id' => (int) $userId,
                        'book_id' => (int) $bookId,
                    ],
                    [
                        'status' => 'queue',
                        'order' => (int) $index,
                    ]
                );
            }

            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService updateBookQueue failed: ' . $e->getMessage());

            return false;
        }
    }

    public function getBookmarks(string $userId, string $bookId): array
    {
        return Bookmark::where('user_id', $userId)->where('book_id', $bookId)->get()->toArray();
    }

    public function getBookmark(string $bookmarkId, string $userId, string $bookId): ?array
    {
        $bookmark = Bookmark::where('id', $bookmarkId)->where('user_id', $userId)->where('book_id', $bookId)->first();

        return $bookmark ? $bookmark->toArray() : null;
    }

    public function createBookmark(array $data): string
    {
        $bookmark = Bookmark::create($data);

        return (string) $bookmark->id;
    }

    public function updateBookmark(string $bookmarkId, array $data): bool
    {
        $bookmark = Bookmark::findOrFail($bookmarkId);

        return $bookmark->update($data);
    }

    public function deleteBookmark(string $bookmarkId, string $userId, string $bookId): bool
    {
        $bookmark = Bookmark::where('id', $bookmarkId)
            ->where('user_id', $userId)
            ->where('book_id', $bookId)
            ->firstOrFail();

        return $bookmark->delete();
    }

    public function deleteBookmarkById(string $bookmarkId, string $userId): bool
    {
        $bookmark = Bookmark::where('id', $bookmarkId)
            ->where('user_id', $userId)
            ->first();

        if (!$bookmark) {
            return false;
        }

        $bookmark->forceDelete();

        return true;
    }

    public function getExternalReads(string $userId, string $bookId): array
    {
        return ExternalRead::where('user_id', $userId)
            ->where('book_id', $bookId)
            ->orderBy('started_at')
            ->get()
            ->toArray();
    }

    public function getExternalRead(string $externalReadId, string $userId, string $bookId): ?array
    {
        $entry = ExternalRead::where('id', $externalReadId)
            ->where('user_id', $userId)
            ->where('book_id', $bookId)
            ->first();

        return $entry ? $entry->toArray() : null;
    }

    public function createExternalRead(array $data): string
    {
        $entry = ExternalRead::create($data);

        return (string) $entry->id;
    }

    public function updateExternalRead(string $externalReadId, array $data): bool
    {
        $entry = ExternalRead::findOrFail($externalReadId);

        return $entry->update($data);
    }

    public function deleteExternalRead(string $externalReadId, string $userId, string $bookId): bool
    {
        $entry = ExternalRead::where('id', $externalReadId)
            ->where('user_id', $userId)
            ->where('book_id', $bookId)
            ->firstOrFail();

        return $entry->delete();
    }
}
