<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ListeningEvent;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CloseOrphanedSessions extends Command
{
    protected $signature = 'sessions:close-orphaned
                            {--user-id= : Only process a specific user ID}
                            {--dry-run : Report orphans without creating synthetic events}';

    protected $description = 'Synthesize SESSION_END events for orphaned SESSION_START events (no matching end within 4 hours)';

    private const MAX_SESSION_WINDOW_MS = 4 * 3_600_000; // 4 hours in ms

    private const POSITION_EVENT_TYPES = [
        'PLAY_START',
        'PLAY_RESUME',
        'PLAY_PAUSE',
        'PLAY_STOP',
        'CHAPTER_CHANGE',
        'SEEK',
    ];

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $specificUserId = $this->option('user-id');

        if ($isDryRun) {
            $this->info('[DRY RUN] No events will be created.');
        }

        $query = User::query();
        if ($specificUserId !== null) {
            $query->where('id', $specificUserId);
        }

        $users = $query->get(['id', 'name', 'email']);

        if ($users->isEmpty()) {
            $this->warn('No users found.');
            return Command::SUCCESS;
        }

        $totalOrphaned = 0;
        $totalClosed = 0;

        foreach ($users as $user) {
            [$orphaned, $closed] = $this->processUser($user->id, $isDryRun);
            $totalOrphaned += $orphaned;
            $totalClosed += $closed;

            if ($orphaned > 0) {
                $status = $isDryRun ? "(would close $orphaned)" : "closed $closed";
                $this->line("  User {$user->id} ({$user->email}): $status orphaned session(s)");
            }
        }

        $this->info("Done. Orphaned: $totalOrphaned" . ($isDryRun ? '' : ", closed: $totalClosed"));

        return Command::SUCCESS;
    }

    /**
     * @return array{int, int} [orphanedCount, closedCount]
     */
    private function processUser(int $userId, bool $isDryRun): array
    {
        $sessionStarts = ListeningEvent::where('user_id', $userId)
            ->where('event_type', 'SESSION_START')
            ->get();

        if ($sessionStarts->isEmpty()) {
            return [0, 0];
        }

        $orphanedCount = 0;
        $closedCount = 0;
        $nowMs = (int) (now()->getPreciseTimestamp(3));

        foreach ($sessionStarts as $start) {
            $windowEnd = $start->timestamp_ms + self::MAX_SESSION_WINDOW_MS;

            $bookEvents = ListeningEvent::where('user_id', $userId)
                ->where('book_id', $start->book_id)
                ->where('timestamp_ms', '>', $start->timestamp_ms)
                ->where('timestamp_ms', '<=', $windowEnd)
                ->orderBy('timestamp_ms')
                ->get();

            $hasMatchingEnd = $bookEvents->contains(function ($event) use ($start) {
                // A real client-side end shares the original device_id; a previously
                // synthesized end (from an earlier run of this command) always has
                // device_id = 'server-synthesized', which never matches a real device -
                // without this branch, every rerun would find the orphan "still open" and
                // synthesize another duplicate end event for it, forever.
                return $event->event_type === 'SESSION_END'
                    && ($event->device_id === $start->device_id || $event->device_id === 'server-synthesized');
            });

            if ($hasMatchingEnd) {
                continue;
            }

            $orphanedCount++;

            $lastPositionEvent = $bookEvents
                ->filter(fn ($e) => $e->device_id === $start->device_id
                    && in_array($e->event_type, self::POSITION_EVENT_TYPES, true))
                ->last();

            $endMs = min(
                $lastPositionEvent !== null ? $lastPositionEvent->timestamp_ms : $windowEnd,
                $windowEnd,
                $nowMs,
            );
            $endPositionMs = $lastPositionEvent !== null ? $lastPositionEvent->position_ms : $start->position_ms;

            if (!$isDryRun) {
                // Use the same camelCase metadata keys real clients send for SESSION_END
                // (see EventMetadata.forSessionEnd on the client) so this synthesized event is
                // read identically to a real one by ListeningActivityService and anything else
                // that aggregates seconds_listened from metadata.
                $durationMs = max(0, $endMs - $start->timestamp_ms);
                ListeningEvent::create([
                    'id' => Str::uuid()->toString(),
                    'user_id' => $userId,
                    'book_id' => $start->book_id,
                    'event_type' => 'SESSION_END',
                    'timestamp_ms' => $endMs,
                    'position_ms' => $endPositionMs,
                    'metadata' => [
                        'sessionDurationMs' => $durationMs,
                        'adjustedDurationMs' => $durationMs,
                        'playbackSpeed' => 1.0,
                        'pauseCount' => 0,
                    ],
                    'device_id' => 'server-synthesized',
                    'timezone' => $start->timezone,
                    'sync_status' => 'SYNCED',
                    'created_at' => $nowMs,
                    'synced_at' => $nowMs,
                ]);
                $closedCount++;
            }
        }

        return [$orphanedCount, $closedCount];
    }
}
