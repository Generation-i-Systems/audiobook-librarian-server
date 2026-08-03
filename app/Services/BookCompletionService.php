<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Book;
use App\Models\BookPosition;
use App\Models\BookProgress;
use App\Models\UserBookStatus;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Determines which books a user has finished and when, merging the three completion
 * sources that exist in this codebase: user_book_status.status='completed' (finished_at),
 * book_progress.completed=true (completed_at), and the modern event-sourced book_positions
 * completion path (last_event_timestamp_ms). When multiple sources have a date for the same
 * book, the latest date wins.
 */
class BookCompletionService
{
    public function completedProgressQuery(?int $userId, string $deviceId, ?Carbon $startDate = null)
    {
        $query = BookProgress::query()
            ->where('completed', true)
            ->whereNotNull('book_id');

        if ($userId !== null) {
            $query->where('user_id', $userId);
        } else {
            $query->where('device_id', $deviceId);
        }

        if ($startDate !== null) {
            $query->where('completed_at', '>=', $startDate);
        }

        return $query;
    }

    /** @return Collection<int, Carbon> book_id => finished date */
    public function getCompletedBookDatesForUser(int $userId, ?Carbon $startDate = null, ?int $genreId = null): Collection
    {
        $statusDates = UserBookStatus::query()
            ->where('user_id', $userId)
            ->where('status', 'completed')
            ->whereNotNull('book_id')
            ->whereNotNull('finished_at')
            ->get(['book_id', 'finished_at'])
            ->mapWithKeys(function (UserBookStatus $status): array {
                return [(int) $status->book_id => Carbon::parse((string) $status->finished_at)];
            });

        $progressDates = BookProgress::query()
            ->where('user_id', $userId)
            ->where('completed', true)
            ->whereNotNull('book_id')
            ->whereNotNull('completed_at')
            ->get(['book_id', 'completed_at'])
            ->mapWithKeys(function (BookProgress $progress): array {
                return [(int) $progress->book_id => Carbon::parse((string) $progress->completed_at)];
            });

        // The modern event-sourced completion path (BOOK_FINISH -> PositionMaterializer) writes
        // to book_positions, not book_progress/user_book_status. last_event_timestamp_ms is the
        // client-reported time of the finishing event.
        $positionDates = BookPosition::query()
            ->where('user_id', $userId)
            ->where('completed', true)
            ->get(['book_id', 'last_event_timestamp_ms'])
            ->mapWithKeys(function (BookPosition $position): array {
                return [(int) $position->book_id => Carbon::createFromTimestampMs((int) $position->last_event_timestamp_ms)];
            });

        $merged = $statusDates;

        foreach ($progressDates as $bookId => $date) {
            $existing = $merged->get($bookId);
            if (! $existing instanceof Carbon || $date->gt($existing)) {
                $merged->put($bookId, $date);
            }
        }

        foreach ($positionDates as $bookId => $date) {
            $existing = $merged->get($bookId);
            if (! $existing instanceof Carbon || $date->gt($existing)) {
                $merged->put($bookId, $date);
            }
        }

        if ($genreId !== null) {
            $genreBookIds = Book::whereHas('genres', function ($query) use ($genreId): void {
                $query->where('genres.id', $genreId);
            })->pluck('id')->all();

            $merged = $merged->filter(fn (Carbon $date, int $bookId): bool => in_array($bookId, $genreBookIds, true));
        }

        if ($startDate !== null) {
            $merged = $merged->filter(fn (Carbon $date): bool => $date->gte($startDate));
        }

        return $merged;
    }

    /** @return Collection<int, array{book_id:int,title:string,finished_at:Carbon}> */
    public function getCompletedBooksWithTitles(
        int $userId,
        ?Carbon $startDate,
        ?Carbon $endDate,
        ?int $genreId = null
    ): Collection {
        $dates = $this->getCompletedBookDatesForUser($userId, $startDate, $genreId);

        if ($endDate !== null) {
            $dates = $dates->filter(fn (Carbon $date): bool => $date->lte($endDate));
        }

        $titles = Book::whereIn('id', $dates->keys())->pluck('title', 'id');

        return $dates->map(fn (Carbon $date, int $bookId): array => [
            'book_id'     => $bookId,
            'title'       => (string) $titles->get($bookId, ''),
            'finished_at' => $date,
        ])->values();
    }
}
