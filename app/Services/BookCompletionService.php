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
    // A book_positions row marked completed=true whose position is far short of the book's
    // known duration can't be a genuine finish — e.g. a stray/erroneous BOOK_FINISH event
    // firing moments after playback starts. Requiring most of the book's duration filters
    // these out without rejecting legitimate completions that stop slightly before the
    // literal end (trailing silence/credits).
    // Also referenced by UserActivityService for the same sanity check on its admin display.
    public const MIN_COMPLETION_FRACTION = 0.9;

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
        $positionRows = BookPosition::query()
            ->where('user_id', $userId)
            ->where('completed', true)
            ->get(['book_id', 'position_ms', 'last_event_timestamp_ms']);

        $durationSecondsByBookId = Book::query()
            ->whereIn('id', $positionRows->pluck('book_id')->unique())
            ->pluck('duration', 'id');

        $positionDates = $positionRows
            ->filter(function (BookPosition $position) use ($durationSecondsByBookId): bool {
                $durationSeconds = $durationSecondsByBookId->get((int) $position->book_id);
                if ($durationSeconds === null || $durationSeconds <= 0) {
                    return true;
                }

                return $position->position_ms >= $durationSeconds * 1000 * self::MIN_COMPLETION_FRACTION;
            })
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

        // Some synced progress rows (book_progress/book_positions) reference a book_id that
        // doesn't correspond to any real book — e.g. a client sending its own local-only book
        // id before that book was ever matched to the server catalog. Silently keeping those
        // around skews "most recent completions" (a phantom row can rank above a real one) and
        // produces empty Book lookups downstream, so they're filtered out here once, centrally.
        $validIds = $this->validBookIds($merged->keys()->all());

        // $merged is typed as an Eloquent Collection here (it originates from a Model
        // query's ->get()->mapWithKeys(), which preserves the runtime class via `new
        // static(...)`), even though its items are Carbon dates, not models. Eloquent
        // Collection's `only()` override calls ->getKey() on each item expecting a model,
        // so it must be converted to a plain Collection before filtering by key here.
        return collect($merged->all())->only($validIds);
    }

    /** @param array<int, int> $bookIds */
    private function validBookIds(array $bookIds): array
    {
        return Book::query()->whereIn('id', $bookIds)->pluck('id')->all();
    }

    /** @return array<int, int> */
    public function getCompletedBookIdsForUser(int $userId): array
    {
        return $this->getCompletedBookDatesForUser($userId)->keys()->all();
    }

    /**
     * Books the user has started but not finished, merged across the same three sources as
     * {@see getCompletedBookDatesForUser()}: user_book_status.status='in_progress',
     * book_progress (completed=false with real playback position), and the event-sourced
     * book_positions (completed=false with real playback position).
     *
     * @return array<int, int>
     */
    public function getInProgressBookIdsForUser(int $userId): array
    {
        $statusIds = UserBookStatus::query()
            ->where('user_id', $userId)
            ->where('status', 'in_progress')
            ->whereNotNull('book_id')
            ->pluck('book_id');

        $progressIds = BookProgress::query()
            ->where('user_id', $userId)
            ->where('completed', false)
            ->where('current_position_seconds', '>', 0)
            ->whereNotNull('book_id')
            ->pluck('book_id');

        $positionIds = BookPosition::query()
            ->where('user_id', $userId)
            ->where('completed', false)
            ->where('position_ms', '>', 0)
            ->pluck('book_id');

        $merged = $statusIds->merge($progressIds)->merge($positionIds)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $this->validBookIds($merged);
    }

    /**
     * Every book the user has any engagement signal for — completed, in-progress, or any
     * other user_book_status row (queued/wishlisted/etc.) — for "don't recommend a book the
     * user already has a relationship with" exclusion logic.
     *
     * @return array<int, int>
     */
    public function getEngagedBookIdsForUser(int $userId): array
    {
        $statusIds = $this->validBookIds(
            UserBookStatus::query()
                ->where('user_id', $userId)
                ->whereNotNull('book_id')
                ->pluck('book_id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
        );

        return array_values(array_unique(array_merge(
            $statusIds,
            $this->getCompletedBookIdsForUser($userId),
            $this->getInProgressBookIdsForUser($userId),
        )));
    }

    /**
     * Normalized titles of every book the user has engaged with, for filtering out
     * "recommendations" that are really just a different edition/narration of a book the
     * user already read (different book_id, same catalog title) — see normalizeTitle().
     *
     * @return array<int, string>
     */
    public function getEngagedNormalizedTitles(int $userId): array
    {
        $ids = $this->getEngagedBookIdsForUser($userId);
        if (empty($ids)) {
            return [];
        }

        return Book::query()->whereIn('id', $ids)->pluck('title')
            ->map(fn (string $title): string => self::normalizeTitle($title))
            ->filter(fn (string $title): bool => $title !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Lowercases, strips parenthetical edition/format notes (e.g. "(Unabridged)",
     * "(GraphicAudio)"), and strips punctuation/whitespace, so different catalog entries for
     * the same underlying book ("Elantris" vs "Elantris (Tenth Anniversary Edition)") compare
     * equal.
     */
    public static function normalizeTitle(string $title): string
    {
        $normalized = strtolower($title);
        $normalized = preg_replace('/\([^)]*\)/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\[[^\]]*\]/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^a-z0-9]+/', '', $normalized) ?? $normalized;

        return $normalized;
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
