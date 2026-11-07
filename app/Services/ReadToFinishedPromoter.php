<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\BookStatusUpdated;
use App\Jobs\RecomputeRecommendationsJob;
use App\Models\Book;
use App\Models\BookPosition;
use App\Models\BookProgress;
use App\Models\ListeningEvent;
use App\Models\User;
use App\Models\UserBookStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A book the user marked "read" (marked_read_at) whose last listening progress is over 90% was
 * almost certainly listened to the end, so it is turned into a real finish instead: status
 * `completed` with finished_at, marked_read_at cleared, and progress set to 100% through a
 * BOOK_FINISH listening event (so book_positions and every other device see the finish) plus
 * the legacy book_progress rows.
 *
 * Runs when a book is marked read, when progress for a marked-read book arrives, and once over
 * existing data through `books:promote-read-to-finished`.
 */
class ReadToFinishedPromoter
{
    /** Progress strictly above this percentage promotes a marked-read book. */
    public const PROGRESS_THRESHOLD = 90.0;

    /** device_id on the BOOK_FINISH event this class records, so other devices pull it. */
    public const EVENT_DEVICE_ID = 'server';

    public function __construct(
        private PositionMaterializer $positionMaterializer,
    ) {
    }

    /**
     * Marked-read books among $bookIds (all of the user's when null) whose last progress is
     * over the threshold. Read-only.
     *
     * @param list<int>|null $bookIds
     * @return list<array{status: UserBookStatus, book: Book, progress: float}>
     */
    public function findCandidates(int $userId, ?array $bookIds = null): array
    {
        $query = UserBookStatus::query()
            ->where('user_id', $userId)
            ->whereNotNull('book_id')
            ->whereNotNull('marked_read_at');

        if ($bookIds !== null) {
            if ($bookIds === []) {
                return [];
            }
            $query->whereIn('book_id', $bookIds);
        }

        $candidates = [];
        foreach ($query->get() as $status) {
            /** @var Book|null $book */
            $book = Book::find($status->book_id);
            if ($book === null) {
                continue;
            }
            $progress = $this->lastProgressPercentage($userId, $book);
            if ($progress !== null && $progress > self::PROGRESS_THRESHOLD) {
                $candidates[] = ['status' => $status, 'book' => $book, 'progress' => $progress];
            }
        }

        return $candidates;
    }

    /**
     * Promote every qualifying marked-read book among $bookIds (all when null).
     *
     * @param list<int>|null $bookIds
     * @return list<int> ids of the books that were promoted
     */
    public function promoteForUser(User $user, ?array $bookIds = null): array
    {
        $promoted = [];
        foreach ($this->findCandidates((int) $user->id, $bookIds) as $candidate) {
            $this->promote($user, $candidate['book'], $candidate['status']);
            $promoted[] = (int) $candidate['book']->id;
        }

        if ($promoted !== []) {
            RecomputeRecommendationsJob::dispatch((int) $user->id);
        }

        return $promoted;
    }

    /**
     * The user's most recent progress for the book across book_positions and book_progress, as a
     * percentage, or null when there is none.
     */
    public function lastProgressPercentage(int $userId, Book $book): ?float
    {
        $durationMs = $book->duration !== null && (int) $book->duration > 0 ? (int) $book->duration * 1000 : 0;

        $latestMs = null;
        $latestPercentage = null;

        $positions = BookPosition::query()
            ->where('user_id', $userId)
            ->where('book_id', $book->id)
            ->where('device_id', '!=', self::EVENT_DEVICE_ID)
            ->get();
        foreach ($positions as $position) {
            $timestampMs = (int) $position->last_event_timestamp_ms;
            if ($latestMs !== null && $timestampMs <= $latestMs) {
                continue;
            }
            $latestMs = $timestampMs;
            $latestPercentage = $durationMs > 0
                ? ((int) $position->position_ms / $durationMs) * 100
                : (float) $position->progress_percentage;
        }

        $progressRows = BookProgress::query()
            ->where('user_id', $userId)
            ->where('book_id', $book->id)
            ->get();
        foreach ($progressRows as $progress) {
            $at = $progress->last_listened_at ?? $progress->updated_at;
            if ($at === null) {
                continue;
            }
            $timestampMs = (int) $at->getTimestampMs();
            if ($latestMs !== null && $timestampMs <= $latestMs) {
                continue;
            }
            $latestMs = $timestampMs;
            $percentage = (float) $progress->progress_percentage;
            $totalSeconds = (int) ($progress->total_duration_seconds ?? 0);
            if ($percentage <= 0 && $totalSeconds > 0) {
                $percentage = ((int) $progress->current_position_seconds / $totalSeconds) * 100;
            }
            $latestPercentage = $percentage;
        }

        return $latestPercentage;
    }

    private function promote(User $user, Book $book, UserBookStatus $status): void
    {
        $previousStatus = $status->status;
        $finishedAt = $this->lastListenedAt((int) $user->id, $book) ?? $status->marked_read_at ?? now();

        DB::transaction(function () use ($user, $book, $status, $previousStatus, $finishedAt): void {
            $update = [
                'status' => 'completed',
                'marked_read_at' => null,
                'finished_at' => $status->finished_at ?? $finishedAt,
            ];
            if ($previousStatus !== 'completed') {
                $update['read_count'] = (int) ($status->read_count ?? 0) + 1;
            }
            UserBookStatus::query()
                ->where('user_id', $user->id)
                ->where('book_id', $book->id)
                ->update($update);

            $endMs = $this->endPositionMs((int) $user->id, $book);

            foreach (BookProgress::query()->where('user_id', $user->id)->where('book_id', $book->id)->get() as $progress) {
                $progress->completed = true;
                $progress->completed_at = $progress->completed_at ?? $finishedAt;
                $progress->progress_percentage = 100;
                $progress->current_position_seconds = (int) ($progress->total_duration_seconds ?: intdiv($endMs, 1000));
                $progress->save();
            }

            $nowMs = (int) now()->getTimestampMs();
            $eventId = (string) Str::uuid();
            ListeningEvent::create([
                'id' => $eventId,
                'user_id' => $user->id,
                'book_id' => $book->id,
                'event_type' => 'BOOK_FINISH',
                'timestamp_ms' => $nowMs,
                'position_ms' => $endMs,
                'metadata' => ['source' => 'read_to_finished', 'progressPercentage' => 100],
                'device_id' => self::EVENT_DEVICE_ID,
                'timezone' => 'UTC',
                'sync_status' => 'SYNCED',
                'created_at' => $nowMs,
                'synced_at' => $nowMs,
            ]);
            $this->positionMaterializer->materialize([
                'id' => $eventId,
                'user_id' => $user->id,
                'book_id' => $book->id,
                'event_type' => 'BOOK_FINISH',
                'timestamp_ms' => $nowMs,
                'position_ms' => $endMs,
                'metadata' => [],
                'device_id' => self::EVENT_DEVICE_ID,
            ]);
            BookPosition::query()
                ->where('user_id', $user->id)
                ->where('book_id', $book->id)
                ->where('device_id', self::EVENT_DEVICE_ID)
                ->update(['progress_percentage' => 100]);
        });

        if ($previousStatus !== 'completed') {
            BookStatusUpdated::dispatch($user, $book, 'completed', $previousStatus);
        }
    }

    /** The book's length in ms, or the furthest known position when its duration is unknown. */
    private function endPositionMs(int $userId, Book $book): int
    {
        if ($book->duration !== null && (int) $book->duration > 0) {
            return (int) $book->duration * 1000;
        }

        $positionMax = (int) BookPosition::query()
            ->where('user_id', $userId)
            ->where('book_id', $book->id)
            ->max('position_ms');
        $progressMax = (int) BookProgress::query()
            ->where('user_id', $userId)
            ->where('book_id', $book->id)
            ->max(DB::raw('COALESCE(total_duration_seconds, current_position_seconds)')) * 1000;

        return max($positionMax, $progressMax);
    }

    private function lastListenedAt(int $userId, Book $book): ?Carbon
    {
        $positionMs = BookPosition::query()
            ->where('user_id', $userId)
            ->where('book_id', $book->id)
            ->where('device_id', '!=', self::EVENT_DEVICE_ID)
            ->max('last_event_timestamp_ms');
        $progressAt = BookProgress::query()
            ->where('user_id', $userId)
            ->where('book_id', $book->id)
            ->max('last_listened_at');

        $candidates = array_filter([
            $positionMs !== null ? Carbon::createFromTimestampMs((int) $positionMs) : null,
            $progressAt !== null ? Carbon::parse((string) $progressAt) : null,
        ]);

        return $candidates === [] ? null : max($candidates);
    }
}
