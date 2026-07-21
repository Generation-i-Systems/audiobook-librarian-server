<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ListeningEvent;
use App\Services\ControllerDatabaseService as ControllerDatabase;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Derives per-session listening activity from the event-sourced `listening_events` table.
 *
 * The legacy `listening_statistics` table is no longer populated by any current client
 * (clients now sync via EventSyncManager -> EventController::sync() -> listening_events),
 * so streak/listening-time/badge calculations that read `listening_statistics` are reading
 * a table real usage never touches. This service reconstructs session-shaped rows (matching
 * the field names the legacy table used: book_id, listening_date, seconds_listened,
 * session_start, metadata.playback_speed) from SESSION_END events, using each event's own
 * client-reported timestamp_ms and timezone so listening_date reflects when the user actually
 * listened, not when the sync request happened to reach the server.
 */
class ListeningActivityService
{
    /**
     * @var array<string, Collection<int, (object{book_id: int, user_id: int, device_id: string,
     *   listening_date: string, seconds_listened: int, session_start: Carbon,
     *   metadata: array{playback_speed: mixed}}&\stdClass)>>
     */
    private array $sessionsCache = [];

    /**
     * Get session-shaped listening activity rows for a user, matching another device, or both.
     *
     * @return Collection<int, (object{book_id: int, user_id: int, device_id: string,
     *   listening_date: string, seconds_listened: int, session_start: Carbon,
     *   metadata: array{playback_speed: mixed}}&\stdClass)> listening_date is a Y-m-d string
     *   local to the event's own timezone; session_start is the same instant as a Carbon.
     */
    public function getSessions(int|string|null $userId, ?string $deviceId = null): Collection
    {
        $cacheKey = ($userId === null ? 'null' : (string) $userId) . '|' . ($deviceId ?? '');

        if (isset($this->sessionsCache[$cacheKey])) {
            return $this->sessionsCache[$cacheKey];
        }

        $query = ListeningEvent::query()->where('event_type', 'SESSION_END');

        if ($userId !== null && is_numeric($userId)) {
            $userDeviceIds = ControllerDatabase::table('devices')
                ->where('user_id', (int) $userId)
                ->pluck('device_id')
                ->push($deviceId)
                ->filter()
                ->unique()
                ->values();

            $query->where(function ($q) use ($userId, $userDeviceIds): void {
                $q->where('user_id', (int) $userId);
                if ($userDeviceIds->isNotEmpty()) {
                    $q->orWhereIn('device_id', $userDeviceIds);
                }
            });
        } elseif ($deviceId !== null) {
            $query->where('device_id', $deviceId);
        } elseif ($userId !== null) {
            // Non-numeric $userId with no separate device id: legacy callers pass a device id
            // as the "user id" for unauthenticated/local-only devices.
            $query->where('device_id', (string) $userId);
        }

        // The client can sync the same logical session more than once (retry after a timed-out
        // sync response, etc.), each time with a new client-generated UUID `id` - the server has
        // no way to detect that at write time since `id` is the dedup key. Collapse events that
        // share (user_id, book_id, device_id, timestamp_ms) down to one before summing, or
        // repeated syncs of one real session inflate totals arbitrarily (seen in production as
        // >24h of "listening" on a single day).
        $events = $query->get(['book_id', 'user_id', 'device_id', 'timestamp_ms', 'metadata', 'timezone'])
            ->unique(static fn (ListeningEvent $event): string => "{$event->user_id}|{$event->book_id}|{$event->device_id}|{$event->timestamp_ms}");

        $sessions = $events->map(function (ListeningEvent $event): object {
            $localStart = Carbon::createFromTimestampMs((int) $event->timestamp_ms)
                ->setTimezone($this->safeTimezone($event->timezone));

            $metadata = $event->metadata ?? [];
            $secondsListened = (int) round(
                (int) (data_get($metadata, 'adjustedDurationMs') ?? data_get($metadata, 'sessionDurationMs', 0)) / 1000
            );

            return (object) [
                'book_id'          => $event->book_id,
                'user_id'          => $event->user_id,
                'device_id'        => $event->device_id,
                'listening_date'   => $localStart->toDateString(),
                'seconds_listened' => $secondsListened,
                'session_start'    => $localStart,
                'metadata'         => [
                    'playback_speed' => data_get($metadata, 'playbackSpeed', 1.0),
                ],
            ];
        });

        $this->sessionsCache[$cacheKey] = $sessions;

        return $sessions;
    }

    /**
     * Forget all in-memory cached sessions for a user, across every device-id variant callers
     * may have queried with. Since this service is typically resolved once per
     * request/service-container scope, callers that mutate listening_events and then
     * re-evaluate stats within the same scope (e.g. BadgeService evaluating badges multiple
     * times as new events arrive) must call this or they'll see stale data - different
     * consumers call getSessions() with different $deviceId values (some pass it through, some
     * pass null), each keyed separately, so a single userId|deviceId key isn't enough to
     * invalidate all of them.
     */
    public function clearCache(int|string|null $userId): void
    {
        $prefix = ($userId === null ? 'null' : (string) $userId) . '|';

        foreach (array_keys($this->sessionsCache) as $cacheKey) {
            if (str_starts_with($cacheKey, $prefix)) {
                unset($this->sessionsCache[$cacheKey]);
            }
        }
    }

    /**
     * Distinct listening_date values (sorted ascending) with at least one session.
     *
     * @return Collection<int, string>
     */
    public function getActivityDates(int|string|null $userId, ?string $deviceId = null): Collection
    {
        return $this->getSessions($userId, $deviceId)
            ->pluck('listening_date')
            ->unique()
            ->sort()
            ->values();
    }

    /**
     * Current listening streak in days, ending on the most recent day with activity.
     *
     * Unlike a naive "must have activity today" check, this allows a one-day grace period:
     * a streak ending yesterday still counts as "current" since a user may simply not have
     * listened yet today.
     */
    public function getCurrentStreak(int|string|null $userId, ?string $deviceId = null): int
    {
        $dates = $this->getActivityDates($userId, $deviceId);

        if ($dates->isEmpty()) {
            return 0;
        }

        $mostRecent = Carbon::parse($dates->last());
        $today      = Carbon::now()->startOfDay();

        if ($mostRecent->lt($today->copy()->subDay())) {
            return 0;
        }

        $streak      = 1;
        $currentDate = $mostRecent->copy();
        $dateSet     = $dates->flip();

        while ($dateSet->has($currentDate->copy()->subDay()->toDateString())) {
            $currentDate->subDay();
            $streak++;
        }

        return $streak;
    }

    /**
     * Longest consecutive-day listening streak on record.
     */
    public function getLongestStreak(int|string|null $userId, ?string $deviceId = null): int
    {
        $dates = $this->getActivityDates($userId, $deviceId)
            ->map(static fn (string $date): Carbon => Carbon::parse($date))
            ->values();

        if ($dates->isEmpty()) {
            return 0;
        }

        $longest = 1;
        $current = 1;

        for ($i = 1; $i < $dates->count(); $i++) {
            // diffInDays() returns a signed diff (negative here, since $dates is ascending and
            // the earlier date is passed as the argument) - compare the absolute day count, not
            // the raw signed value, or consecutive days are never recognized as consecutive.
            if ((int) $dates[$i]->diffInDays($dates[$i - 1], true) === 1) {
                $current++;
                $longest = max($longest, $current);
            } else {
                $current = 1;
            }
        }

        return $longest;
    }

    private function safeTimezone(?string $timezone): string
    {
        if ($timezone === null || $timezone === '') {
            return 'UTC';
        }

        try {
            new \DateTimeZone($timezone);

            return $timezone;
        } catch (\Exception) {
            return 'UTC';
        }
    }
}
