<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookPosition;
use App\Models\BookProgress;
use App\Models\ListeningEvent;
use App\Models\ListeningStatistic;
use App\Models\UserBookStatus;
use App\Models\User;
use Carbon\Carbon;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\ControllerDatabaseService as ControllerDatabase;
use App\Services\ListeningActivityService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class StatisticsController extends Controller
{
    public function __construct(private readonly ListeningActivityService $listeningActivityService)
    {
    }

    private function listeningStatsQuery(?int $userId, string $deviceId): \Illuminate\Database\Eloquent\Builder
    {
        $query = ListeningStatistic::query();

        if ($userId !== null) {
            $userDeviceIds = ControllerDatabase::table('devices')
                ->where('user_id', $userId)
                ->pluck('device_id')
                ->push($deviceId)
                ->filter()
                ->unique()
                ->values();

            $query->where(function ($statsQuery) use ($userId, $userDeviceIds) {
                $statsQuery->where('user_id', $userId);

                if ($userDeviceIds->isNotEmpty()) {
                    $statsQuery->orWhereIn('device_id', $userDeviceIds);
                }
            });
        } else {
            $query->where('device_id', $deviceId);
        }

        return $query;
    }

    /**
     * Get listening statistics overview (OpenAPI spec)
     */
    public function getOverview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => 'nullable|string|in:week,month,quarter,year,all_time',
        ]);

        $period = $validated['period'] ?? 'month';
        $userId = Auth::id();
        $deviceId = (string) ($request->header('X-Device-ID', 'unknown'));

        // Calculate date ranges based on period
        switch ($period) {
            case 'week':
                $startDate = now()->startOfWeek();
                break;
            case 'quarter':
                $startDate = now()->startOfQuarter();
                break;
            case 'year':
                $startDate = now()->startOfYear();
                break;
            case 'all_time':
                $startDate = null;
                break;
            case 'month':
            default:
                $startDate = now()->startOfMonth();
                break;
        }

        $allSessions = $this->listeningActivityService->getSessions($userId, $deviceId);
        $sessions = $startDate ? $allSessions->filter(
            static fn (object $session): bool => $session->listening_date >= $startDate->toDateString()
        ) : $allSessions;

        if ($userId !== null) {
            $booksFinished = $this->getCompletedBookDatesForUser($userId, $startDate)->count();
        } else {
            $booksFinished = $this->completedProgressQuery($userId, $deviceId, $startDate)
                ->distinct('book_id')
                ->count('book_id');
        }

        // Calculate streaks
        $currentStreak = $this->calculateCurrentStreak($userId, $deviceId);
        $longestStreak = $this->calculateLongestStreak($userId, $deviceId);

        // Get favorite genres (top 5 most listened)
        $favoriteGenres = $this->getFavoriteGenres($sessions);

        // Get daily stats for the period
        $dailyStats = $this->getDailyStatsForPeriod($sessions);
        $listeningMinutes = $this->getListeningMinutesBreakdown($allSessions);

        return response()->json([
            'daily_stats'                 => $dailyStats,
            'total_listening_time_ms'     => $sessions->sum('seconds_listened') * 1000,
            'books_started'               => $sessions->pluck('book_id')->unique()->count(),
            'books_finished'              => $booksFinished,
            'average_session_duration_ms' => $sessions->isEmpty() ? 0 : (int) round($sessions->avg('seconds_listened') * 1000),
            'favorite_genres'             => $favoriteGenres,
            'current_streak'              => $currentStreak,
            'longest_streak'              => $longestStreak,
            'listening_minutes'           => $listeningMinutes,
        ]);
    }

    /**
     * Get daily listening statistics (OpenAPI spec)
     */
    public function getDailyStatsOpenApi(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d',
            'limit'      => 'nullable|integer|min:1|max:365',
        ]);

        $userId = Auth::id();
        $deviceId = (string) ($request->header('X-Device-ID', 'unknown'));

        $startDate = ($validated['start_date'] ?? null) ? Carbon::parse($validated['start_date']) : now()->subDays(29);
        $endDate   = ($validated['end_date'] ?? null) ? Carbon::parse($validated['end_date']) : now();
        $limit     = $validated['limit'] ?? 30;

        $startDateString = $startDate->toDateString();
        $endDateString   = $endDate->toDateString();

        $sessions = $this->listeningActivityService->getSessions($userId, $deviceId)
            ->filter(static fn (object $session): bool => $session->listening_date >= $startDateString
                && $session->listening_date <= $endDateString);

        $dailyStats = $sessions->groupBy('listening_date')
            ->map(static fn (Collection $daySessions, string $date): array => [
                'date'              => $date,
                'listening_time_ms' => $daySessions->sum('seconds_listened') * 1000,
                'sessions_count'    => $daySessions->count(),
                'books_listened'    => $daySessions->pluck('book_id')->unique()->values()->all(),
            ])
            ->sortKeysDesc()
            ->take($limit)
            ->values();

        return response()->json([
            'daily_stats' => $dailyStats,
        ]);
    }

    /**
     * Get timeline listening statistics, grouped by day or month
     */
    public function getTimelineStats(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from'               => 'nullable|date_format:Y-m-d',
            'to'                 => 'nullable|date_format:Y-m-d',
            'group_by'           => 'nullable|string|in:day,week,month,year',
            'detail_period_type' => 'nullable|string|in:day,week,month,year',
            'detail_period'      => 'nullable|string|max:20',
            'day_filter'         => 'nullable|string|in:any,weekday,weekend',
            'weekdays'           => 'nullable|array',
            'weekdays.*'         => 'integer|between:0,6',
        ]);

        $userId   = Auth::id();
        $deviceId = (string) ($request->header('X-Device-ID', 'unknown'));
        $from     = isset($validated['from']) ? Carbon::parse($validated['from']) : now()->subDays(29);
        $to       = isset($validated['to']) ? Carbon::parse($validated['to']) : now();
        $groupBy  = $validated['group_by'] ?? 'day';
        $dayFilter = $validated['day_filter'] ?? 'any';
        $weekdays = collect($validated['weekdays'] ?? [])->map(static fn ($day): int => (int) $day)->unique()->values()->all();

        $sessions = $this->filterSessionsForTimeline(
            $this->listeningActivityService->getSessions($userId, $deviceId),
            $from,
            $to,
            $dayFilter,
            $weekdays
        );

        $totalMs = $sessions->sum('seconds_listened') * 1000;
        $totalSessions = $sessions->count();

        $bars = $sessions
            ->groupBy(fn (object $session): string => $this->timelinePeriodKey($session->listening_date, $groupBy))
            ->map(static fn (Collection $periodSessions, string $period): array => [
                'period'            => $period,
                'listening_time_ms' => $periodSessions->sum('seconds_listened') * 1000,
                'sessions_count'    => $periodSessions->count(),
            ])
            ->sortKeys()
            ->values();

        $detail = null;
        if (isset($validated['detail_period_type'], $validated['detail_period'])) {
            $detail = $this->getTimelinePeriodDetails(
                $userId,
                $deviceId,
                $validated['detail_period_type'],
                $validated['detail_period'],
                $dayFilter,
                $weekdays
            );
        }

        return response()->json([
            'bars'    => $bars,
            'summary' => [
                'total_listening_time_ms' => (int) $totalMs,
                'total_sessions'          => $totalSessions,
                'from'                    => $from->toDateString(),
                'to'                      => $to->toDateString(),
                'group_by'                => $groupBy,
                'day_filter'              => $dayFilter,
                'weekdays'                => $weekdays,
            ],
            'detail' => $detail,
        ]);
    }

    /**
     * Get a day-level timeline: listening segments for a specific date.
     *
     * Each segment represents a contiguous listening interval derived from raw events.
     * Book titles are resolved server-side. Events from all devices for the authenticated
     * user are included and grouped per device to avoid cross-device interference.
     */
    public function getDayTimeline(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date'     => 'required|date_format:Y-m-d',
            'timezone' => 'nullable|string|max:60',
        ]);

        $userId   = Auth::id();
        $timezone = $this->resolveTimezone($validated['timezone'] ?? null);
        $date     = Carbon::createFromFormat('Y-m-d', $validated['date'], $timezone)->startOfDay();
        $dayStartMs = $date->copy()->setTimezone('UTC')->getTimestampMs();
        $dayEndMs   = $date->copy()->addDay()->setTimezone('UTC')->getTimestampMs();

        $events = ListeningEvent::query()
            ->where('user_id', $userId)
            ->whereBetween('timestamp_ms', [$dayStartMs, $dayEndMs])
            ->whereIn('event_type', [
                'PLAY_START', 'PLAY_RESUME', 'SESSION_START',
                'PLAY_PAUSE', 'PLAY_STOP', 'SESSION_END', 'CHAPTER_CHANGE',
            ])
            ->orderBy('timestamp_ms')
            ->get();

        // Resolve book titles in a single query
        $bookIds = $events->pluck('book_id')->unique()->filter()->values();
        $books   = Book::whereIn('id', $bookIds)->pluck('title', 'id');
        $bookTitles = fn (int $bookId): string => $books->get($bookId) ?? "Book $bookId";

        $nowMs = (int) (microtime(true) * 1000);

        $segments = $this->buildDaySegments($events->all(), $bookTitles, $dayStartMs, $dayEndMs, $nowMs);

        return response()->json([
            'date'               => $validated['date'],
            'timezone'           => $timezone,
            'total_listening_ms' => array_sum(array_column($segments, 'duration_ms')),
            'segments'           => $segments,
        ]);
    }

    private function resolveTimezone(?string $tz): string
    {
        if ($tz === null) {
            return 'UTC';
        }
        try {
            new DateTimeZone($tz);
            return $tz;
        } catch (\Exception) {
            return 'UTC';
        }
    }

    /**
     * Reconstruct listening segments from raw events, grouped by device.
     *
     * @param  \App\Models\ListeningEvent[]  $events     Sorted by timestamp_ms ascending
     * @param  callable(int): string         $bookTitle  Resolver for book IDs → titles
     */
    private function buildDaySegments(
        array $events,
        callable $bookTitle,
        int $dayStartMs,
        int $dayEndMs,
        int $nowMs
    ): array {
        $openEventTypes  = ['PLAY_START', 'PLAY_RESUME', 'SESSION_START'];
        $closeEventTypes = ['PLAY_PAUSE', 'PLAY_STOP', 'SESSION_END'];
        $minSegmentMs    = 5_000;

        $byDevice = [];
        foreach ($events as $event) {
            $byDevice[$event->device_id][] = $event;
        }

        $allSegments = [];
        foreach ($byDevice as $deviceSegments) {
            $open = null;
            foreach ($deviceSegments as $event) {
                $type     = $event->event_type;
                $metadata = is_array($event->metadata) ? $event->metadata : [];

                if (in_array($type, $openEventTypes, true)) {
                    if ($open !== null) {
                        $seg = $this->makeSegment($open, $event, $dayStartMs, $bookTitle, true);
                        if ($seg !== null && $seg['duration_ms'] >= $minSegmentMs) {
                            $allSegments[] = $seg;
                        }
                    }
                    $open = $event;
                } elseif ($type === 'CHAPTER_CHANGE' && $open !== null) {
                    $seg = $this->makeSegment($open, $event, $dayStartMs, $bookTitle, false);
                    if ($seg !== null && $seg['duration_ms'] >= $minSegmentMs) {
                        $allSegments[] = $seg;
                    }
                    // Re-open with new chapter info
                    $open = clone $event;
                    $open->event_type = 'PLAY_RESUME';
                } elseif (in_array($type, $closeEventTypes, true) && $open !== null) {
                    $seg = $this->makeSegment($open, $event, $dayStartMs, $bookTitle, false);
                    if ($seg !== null && $seg['duration_ms'] >= $minSegmentMs) {
                        $allSegments[] = $seg;
                    }
                    $open = null;
                }
            }

            if ($open !== null) {
                $effectiveEnd    = (object) ['timestamp_ms' => min($nowMs, $dayEndMs), 'position_ms' => $open->position_ms];
                $seg = $this->makeSegment($open, $effectiveEnd, $dayStartMs, $bookTitle, true);
                if ($seg !== null && $seg['duration_ms'] >= $minSegmentMs) {
                    $allSegments[] = $seg;
                }
            }
        }

        usort($allSegments, fn ($a, $b) => $a['start_ms'] <=> $b['start_ms']);

        return $allSegments;
    }

    private function makeSegment(
        object $open,
        object $close,
        int $dayStartMs,
        callable $bookTitle,
        bool $isOrphaned
    ): ?array {
        $startMs = max((int) $open->timestamp_ms, $dayStartMs);
        $endMs   = (int) $close->timestamp_ms;
        if ($endMs <= $startMs) {
            return null;
        }

        $metadata = is_array($open->metadata ?? null) ? $open->metadata : [];

        return [
            'book_id'           => (int) $open->book_id,
            'book_title'        => $bookTitle((int) $open->book_id),
            'start_ms'          => $startMs,
            'end_ms'            => $endMs,
            'duration_ms'       => $endMs - $startMs,
            'start_position_ms' => (int) $open->position_ms,
            'end_position_ms'   => (int) $close->position_ms,
            'chapter_name'      => $metadata['chapterName'] ?? null,
            'chapter_index'     => isset($metadata['chapterIndex']) ? (int) $metadata['chapterIndex'] : null,
            'playback_speed'    => (float) ($metadata['playbackSpeed'] ?? 1.0),
            'device_id'         => $open->device_id,
            'is_orphaned'       => $isOrphaned,
        ];
    }

    private function getTimelinePeriodDetails(
        ?int $userId,
        string $deviceId,
        string $periodType,
        string $period,
        string $dayFilter = 'any',
        array $weekdays = []
    ): array {
        [$startDate, $endDate] = $this->resolveTimelineDetailRange($periodType, $period);

        $sessions = $this->filterSessionsForTimeline(
            $this->listeningActivityService->getSessions($userId, $deviceId),
            $startDate,
            $endDate,
            $dayFilter,
            $weekdays
        );

        $bookIds = $sessions->pluck('book_id')->filter()->unique()->values();
        $titles = Book::whereIn('id', $bookIds)->pluck('title', 'id');

        $books = $sessions->groupBy('book_id')
            ->map(static function (Collection $bookSessions, int $bookId) use ($titles): array {
                $seconds = $bookSessions->sum('seconds_listened');

                return [
                    'book_id' => $bookId,
                    'title' => $titles->get($bookId),
                    'total_seconds' => $seconds,
                    'total_minutes' => (int) floor($seconds / 60),
                    'session_count' => $bookSessions->count(),
                ];
            })
            ->sortByDesc('total_seconds')
            ->values();

        $totalSeconds = $sessions->sum('seconds_listened');

        return [
            'period_type' => $periodType,
            'period' => $period,
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'total_seconds' => $totalSeconds,
            'total_minutes' => (int) floor($totalSeconds / 60),
            'session_count' => $sessions->count(),
            'books_count' => $bookIds->count(),
            'books' => $books,
        ];
    }

    private function resolveTimelineDetailRange(string $periodType, string $period): array
    {
        return match ($periodType) {
            'day' => $this->resolveDayRange($period),
            'week' => $this->resolveWeekRange($period),
            'month' => $this->resolveMonthRange($period),
            'year' => $this->resolveYearRange($period),
            default => throw new \InvalidArgumentException('Unsupported detail period type.'),
        };
    }

    private function resolveDayRange(string $period): array
    {
        if (! Carbon::hasFormat($period, 'Y-m-d')) {
            abort(422, 'Invalid day period format. Use YYYY-MM-DD.');
        }

        $date = Carbon::createFromFormat('Y-m-d', $period);

        return [$date->copy()->startOfDay(), $date->copy()->endOfDay()];
    }

    private function resolveWeekRange(string $period): array
    {
        if (! preg_match('/^(\d{4})-W(\d{2})$/', $period, $matches)) {
            abort(422, 'Invalid week period format. Use YYYY-Www.');
        }

        $date = Carbon::now()->setISODate((int) $matches[1], (int) $matches[2]);

        return [$date->copy()->startOfWeek(), $date->copy()->endOfWeek()];
    }

    private function resolveMonthRange(string $period): array
    {
        if (! Carbon::hasFormat($period, 'Y-m')) {
            abort(422, 'Invalid month period format. Use YYYY-MM.');
        }

        $date = Carbon::createFromFormat('Y-m', $period);

        return [$date->copy()->startOfMonth(), $date->copy()->endOfMonth()];
    }

    private function resolveYearRange(string $period): array
    {
        if (! preg_match('/^\d{4}$/', $period)) {
            abort(422, 'Invalid year period format. Use YYYY.');
        }

        $date = Carbon::createFromFormat('Y', $period);

        return [$date->copy()->startOfYear(), $date->copy()->endOfYear()];
    }

    /**
     * @param Collection<int, (object{book_id: int, user_id: int, device_id: string,
     *   listening_date: string, seconds_listened: int, session_start: Carbon,
     *   metadata: array{playback_speed: mixed}}&\stdClass)> $sessions
     * @return Collection<int, (object{book_id: int, user_id: int, device_id: string,
     *   listening_date: string, seconds_listened: int, session_start: Carbon,
     *   metadata: array{playback_speed: mixed}}&\stdClass)>
     */
    private function filterSessionsForTimeline(
        Collection $sessions,
        Carbon $from,
        Carbon $to,
        string $dayFilter = 'any',
        array $weekdays = []
    ): Collection {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $inRange = static function (object $session) use ($fromDate, $toDate): bool {
            return $session->listening_date >= $fromDate && $session->listening_date <= $toDate;
        };
        $filtered = $sessions->filter($inRange);

        if (! empty($weekdays)) {
            // The `weekdays` request parameter uses MySQL's WEEKDAY() convention (0=Monday..
            // 6=Sunday), matching this endpoint's existing documented contract - not Carbon's
            // dayOfWeek convention (0=Sunday..6=Saturday), so translate before comparing.
            $normalizedDays = collect($weekdays)
                ->map(static fn ($day): int => (max(0, min(6, (int) $day)) + 1) % 7)
                ->unique()
                ->values();

            $matchesWeekday = static function (object $session) use ($normalizedDays): bool {
                return $normalizedDays->contains(Carbon::parse($session->listening_date)->dayOfWeek);
            };

            return $filtered->filter($matchesWeekday);
        }

        if ($dayFilter === 'weekday') {
            $isWeekday = static fn (object $session): bool => ! Carbon::parse($session->listening_date)->isWeekend();

            return $filtered->filter($isWeekday);
        }

        if ($dayFilter === 'weekend') {
            $isWeekend = static fn (object $session): bool => Carbon::parse($session->listening_date)->isWeekend();

            return $filtered->filter($isWeekend);
        }

        return $filtered;
    }

    /**
     * Group key for a listening_date under a given timeline grouping mode. Uses ISO
     * week-numbering (year 'o' + week 'W') for the 'week' grouping.
     */
    private function timelinePeriodKey(string $listeningDate, string $groupBy): string
    {
        $date = Carbon::parse($listeningDate);

        return match ($groupBy) {
            'week' => $date->format('o-\WW'),
            'month' => $date->format('Y-m'),
            'year' => $date->format('Y'),
            default => $date->format('Y-m-d'),
        };
    }

    /**
     * Report listening session (OpenAPI spec)
     */
    public function reportSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'book_id'              => 'nullable|integer|exists:books,id',
            'title'                => 'required_without:book_id|string|max:255',
            'author'               => 'required_without:book_id|string|max:255',
            'session_start'        => 'required|date',
            'session_end'          => 'required|date|after:session_start',
            'start_position_ms'    => 'required|integer|min:0',
            'end_position_ms'      => 'required|integer|min:0',
            'playback_speed'       => 'nullable|numeric|min:0.1|max:5.0',
            'pauses_count'         => 'nullable|integer|min:0',
            'actual_duration_ms'   => 'nullable|integer|min:0', // Now nullable
            'events'               => 'nullable|array',         // Now nullable
            'events.*.timestamp'   => 'required|integer',
            'events.*.type'        => 'required|string',
            'events.*.position_ms' => 'required|integer|min:0',
            'events.*.metadata'    => 'nullable|array',
        ]);

        $userId = Auth::id();
        $deviceId = (string) ($request->header('X-Device-ID', 'unknown'));

        $sessionStart    = Carbon::parse($validated['session_start']);
        $sessionEnd      = Carbon::parse($validated['session_end']);
        $sessionDuration = abs($sessionEnd->diffInSeconds($sessionStart));

        // Use actual_duration_ms when available (real listening time from client),
        // fall back to wall-clock session duration, never use end_position_ms which is a book position
        $actualDurationMs = $validated['actual_duration_ms'] ?? 0;
        $secondsListened = (int) $sessionDuration;
        if ($actualDurationMs > 0) {
            $secondsListened = (int) floor($actualDurationMs / 1000);
        }
        $playbackSpeed = $validated['playback_speed'] ?? 1.0;

        $statistic = ListeningStatistic::createSession(
            $validated['book_id'] ?? null,
            $deviceId,
            $secondsListened,
            (int) ($validated['start_position_ms'] / 1000),
            (int) ($validated['end_position_ms'] / 1000),
            'listening',
            [
                'session_start'  => $validated['session_start'],
                'session_end'    => $validated['session_end'],
                'playback_speed' => $playbackSpeed,
                'pauses_count'   => $validated['pauses_count'] ?? 0,
            ],
            $userId !== null ? (string) $userId : null,
            $validated['actual_duration_ms'] ?? 0,
            $validated['events'] ?? [],
            $validated['title'] ?? null,
            $validated['author'] ?? null
        );
        // Check for badge achievements after recording the session
        try {
            $badgeService = app(\App\Services\BadgeService::class);
            $badgeUserId = $userId !== null ? (string) $userId : $deviceId;
            $newBadges    = $badgeService->evaluateUserBadges($badgeUserId, $deviceId);

            $response = [
                'success' => true,
                'message' => 'Session reported successfully',
            ];

            // Include new badges in response if any were earned
            if (! empty($newBadges)) {
                $response['badges_earned'] = array_map(function ($userBadge) {
                    return [
                        'id'          => $userBadge->badge->id,
                        'key'         => $userBadge->badge->key,
                        'name'        => $userBadge->badge->name,
                        'description' => $userBadge->badge->description,
                        'icon'        => $userBadge->badge->icon,
                        'tier'        => $userBadge->badge->tier,
                        'points'      => $userBadge->badge->points,
                        'earned_at'   => $userBadge->earned_at->toISOString(),
                        'tier_level'  => $userBadge->tier_level,
                    ];
                }, $newBadges);
            }

            return response()->json($response, 201);
        } catch (\Exception $e) {
            // Log badge evaluation error but don't fail the session recording
            Log::warning('Badge evaluation failed after session recording', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Session reported successfully',
            ], 201);
        }
    }

    /**
     * Record a listening session (existing method for backward compatibility)
     */
    public function recordSession(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'book_id'                => 'nullable|integer|exists:books,id',
                'title'                  => 'required_without:book_id|string|max:255',
                'author'                 => 'required_without:book_id|string|max:255',
                'device_id'              => 'required|string|max:255',
                'seconds_listened'       => 'required|integer|min:1',
                'start_position_seconds' => 'nullable|integer|min:0',
                'end_position_seconds'   => 'nullable|integer|min:0',
                'session_type'           => 'nullable|string|in:listening,completed,resumed,paused',
                'user_id'                => 'nullable|string|max:255',
                'metadata'               => 'nullable|array',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'error'   => 'Validation failed',
                'message' => 'Invalid input data',
                'errors'  => $e->errors(),
            ], 422);
        }

        $statistic = ListeningStatistic::createSession(
            $validated['book_id'] ?? null,
            $validated['device_id'],
            $validated['seconds_listened'],
            $validated['start_position_seconds'] ?? null,
            $validated['end_position_seconds'] ?? null,
            $validated['session_type'] ?? 'listening',
            $validated['metadata'] ?? [],
            $this->stringUserId(Auth::id() ?? $validated['user_id'] ?? null),
            0,
            [],
            $validated['title'] ?? null,
            $validated['author'] ?? null
        );

        // Check for badge achievements after recording the session
        try {
            $badgeService = app(\App\Services\BadgeService::class);
            $authUserId   = Auth::id();
            $badgeUserId  = $authUserId ? (string) $authUserId : $validated['device_id'];
            $newBadges    = $badgeService->evaluateUserBadges(
                $badgeUserId,
                $validated['device_id']
            );

            $response = [
                'success' => true,
                'message' => 'Listening session recorded successfully',
                'data'    => [
                    'id'                 => $statistic->id,
                    'book_id'            => $statistic->book_id,
                    'device_id'          => $statistic->device_id,
                    'listening_date'     => $statistic->listening_date->toDateString(),
                    'seconds_listened'   => $statistic->seconds_listened,
                    'session_type'       => $statistic->session_type,
                    'formatted_duration' => $statistic->formatted_duration,
                ],
            ];

            // Include new badges in response if any were earned
            if (! empty($newBadges)) {
                $response['badges_earned'] = array_map(function ($userBadge) {
                    return [
                        'id'          => $userBadge->badge->id,
                        'key'         => $userBadge->badge->key,
                        'name'        => $userBadge->badge->name,
                        'description' => $userBadge->badge->description,
                        'icon'        => $userBadge->badge->icon,
                        'tier'        => $userBadge->badge->tier,
                        'points'      => $userBadge->badge->points,
                        'earned_at'   => $userBadge->earned_at->toISOString(),
                        'tier_level'  => $userBadge->tier_level,
                    ];
                }, $newBadges);
            }

            return response()->json($response, 201);
        } catch (\Exception $e) {
            // Log badge evaluation error but don't fail the session recording
            Log::warning('Badge evaluation failed after session recording', [
                'device_id' => $validated['device_id'],
                'error'     => $e->getMessage(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Listening session recorded successfully',
                'data'    => [
                    'id'                 => $statistic->id,
                    'book_id'            => $statistic->book_id,
                    'device_id'          => $statistic->device_id,
                    'listening_date'     => $statistic->listening_date->toDateString(),
                    'seconds_listened'   => $statistic->seconds_listened,
                    'session_type'       => $statistic->session_type,
                    'formatted_duration' => $statistic->formatted_duration,
                ],
            ], 201);
        }
    }

    /**
     * Get daily statistics for a device
     */
    public function getDailyStats(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'required|string|max:255',
            'date'      => 'nullable|date_format:Y-m-d',
        ]);

        $date  = $validated['date'] ?? now()->toDateString();
        $stats = ListeningStatistic::getDailyStats($validated['device_id'], $date);

        return response()->json([
            'success' => true,
            'data'    => $stats,
        ]);
    }

    /**
     * Get weekly statistics for a device
     */
    public function getWeeklyStats(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id'  => 'required|string|max:255',
            'start_date' => 'nullable|date_format:Y-m-d',
        ]);

        $startDate = $validated['start_date'] ? Carbon::parse($validated['start_date']) : now()->startOfWeek();
        $endDate   = $startDate->copy()->endOfWeek();

        $stats = ListeningStatistic::where('device_id', $validated['device_id'])
            ->whereBetween('listening_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->selectRaw('
                listening_date,
                SUM(seconds_listened) as total_seconds,
                COUNT(DISTINCT book_id) as books_listened,
                COUNT(*) as session_count
            ')
            ->groupBy('listening_date')
            ->orderBy('listening_date')
            ->toBase()
            ->get();

        $weeklyTotal = $stats->sum('total_seconds');
        $totalBooks  = ListeningStatistic::where('device_id', $validated['device_id'])
            ->whereBetween('listening_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->distinct('book_id')
            ->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'week_start'               => $startDate->toDateString(),
                'week_end'                 => $endDate->toDateString(),
                'total_seconds'            => $weeklyTotal,
                'total_books'              => $totalBooks,
                'formatted_total_duration' => $this->formatSeconds((int) $weeklyTotal),
                'daily_breakdown'          => $stats->map(function (object $stat) {
                    /** @var \stdClass&object{listening_date: string, total_seconds: int|float, books_listened: int, session_count: int} $stat */
                    return [
                        'date'               => $stat->listening_date,
                        'total_seconds'      => $stat->total_seconds,
                        'books_listened'     => $stat->books_listened,
                        'session_count'      => $stat->session_count,
                        'formatted_duration' => $this->formatSeconds((int) $stat->total_seconds),
                    ];
                }),
            ],
        ]);
    }

    /**
     * Get book-specific statistics
     */
    public function getBookStats(Request $request, int $bookId): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'nullable|string|max:255',
        ]);

        /** @var Book|null $book */
        $book = Book::find($bookId);
        if (! $book) {
            return response()->json([
                'error'   => 'Book not found',
                'message' => 'The specified book could not be found',
            ], 404);
        }

        $stats = ListeningStatistic::getBookStats($bookId, $validated['device_id'] ?? null);

        return response()->json([
            'success' => true,
            'data'    => array_merge($stats, [
                'book' => [
                    'id'          => $book->id,
                    'title'       => $book->title,
                    'cover_image' => $book->cover_image,
                ],
            ]),
        ]);
    }

    /**
     * Get listening trends over time
     */
    public function getListeningTrends(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id'  => 'required|string|max:255',
            'period'     => 'nullable|string|in:week,month,year',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d',
        ]);

        $period = $validated['period'] ?? 'month';

        if (isset($validated['start_date']) && isset($validated['end_date'])) {
            $startDate = Carbon::parse($validated['start_date']);
            $endDate   = Carbon::parse($validated['end_date']);
        } else {
            switch ($period) {
                case 'week':
                    $startDate = now()->subWeeks(4)->startOfWeek();
                    $endDate   = now()->endOfWeek();
                    break;
                case 'year':
                    $startDate = now()->subYear()->startOfMonth();
                    $endDate   = now()->endOfMonth();
                    break;
                case 'month':
                default:
                    $startDate = now()->subMonths(3)->startOfMonth();
                    $endDate   = now()->endOfMonth();
                    break;
            }
        }

        $groupBy = match ($period) {
            'week'  => 'listening_date',
            'year'  => 'YEAR(listening_date), MONTH(listening_date)',
            default => 'listening_date', // month
        };

        $stats = ListeningStatistic::where('device_id', $validated['device_id'])
            ->whereBetween('listening_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->selectRaw("
                listening_date,
                SUM(seconds_listened) as total_seconds,
                COUNT(DISTINCT book_id) as books_listened,
                COUNT(*) as session_count,
                AVG(seconds_listened) as avg_session_duration
            ")
            ->groupByRaw($groupBy)
            ->orderBy('listening_date')
            ->toBase()
            ->get();

        $totalSeconds = $stats->sum('total_seconds');
        $totalBooks   = ListeningStatistic::where('device_id', $validated['device_id'])
            ->whereBetween('listening_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->distinct('book_id')
            ->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'period'                   => $period,
                'start_date'               => $startDate->toDateString(),
                'end_date'                 => $endDate->toDateString(),
                'total_seconds'            => $totalSeconds,
                'total_books'              => $totalBooks,
                'formatted_total_duration' => $this->formatSeconds((int) $totalSeconds),
                'average_daily_seconds'    => $stats->isNotEmpty() ? (int) round($totalSeconds / $stats->count()) : 0,
                'trends'                   => $stats->map(function ($stat) {
                    /** @var \stdClass $stat */
                    return [
                        'date'                  => $stat->listening_date,
                        'total_seconds'         => $stat->total_seconds,
                        'books_listened'        => $stat->books_listened,
                        'session_count'         => $stat->session_count,
                        'avg_session_duration'  => round($stat->avg_session_duration),
                        'formatted_duration'    => $this->formatSeconds((int) $stat->total_seconds),
                        'formatted_avg_session' => $this->formatSeconds((int) round($stat->avg_session_duration)),
                    ];
                }),
            ],
        ]);
    }

    /**
     * Get top books by listening time
     */
    public function getTopBooks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id'   => 'nullable|string|max:255',
            'limit'       => 'nullable|integer|min:1|max:50',
            'period_days' => 'nullable|integer|min:1|max:365',
        ]);

        $query = ListeningStatistic::with('book')
            ->selectRaw('
                book_id,
                SUM(seconds_listened) as total_seconds,
                COUNT(*) as session_count,
                COUNT(DISTINCT listening_date) as days_listened,
                MIN(listening_date) as first_listened,
                MAX(listening_date) as last_listened
            ')
            ->groupBy('book_id')
            ->orderByDesc('total_seconds');

        if (isset($validated['device_id'])) {
            $query->where('device_id', $validated['device_id']);
        }

        if (isset($validated['period_days'])) {
            $startDate = now()->subDays($validated['period_days'])->toDateString();
            $query->where('listening_date', '>=', $startDate);
        }

        $limit    = $validated['limit'] ?? 10;
        $topBooks = $query->limit($limit)->get();

        return response()->json([
            'success' => true,
            // @phpstan-ignore-next-line
            'data'    => $topBooks->map(function ($stat) {
                /** @var \App\Models\ListeningStatistic $stat */
                return [
                    'book_id'            => $stat->book_id,
                    'book'               => [
                        'id'          => $stat->book->id,
                        'title'       => $stat->book->title,
                        'cover_image' => $stat->book->cover_image,
                    ],
                    // @phpstan-ignore-next-line
                    'total_seconds'      => (int) $stat->total_seconds,
                    // @phpstan-ignore-next-line
                    'session_count'      => $stat->session_count,
                    // @phpstan-ignore-next-line
                    'days_listened'      => $stat->days_listened,
                    // @phpstan-ignore-next-line
                    'first_listened'     => $stat->first_listened,
                    // @phpstan-ignore-next-line
                    'last_listened'      => $stat->last_listened,
                    // @phpstan-ignore-next-line
                    'formatted_duration' => $this->formatSeconds((int) $stat->total_seconds),
                ];
            }),
        ]);
    }

    /**
     * Get comprehensive dashboard statistics
     */
    public function getDashboardStats(Request $request): JsonResponse
    {
        $deviceId = $request->input('device_id') ?? $request->header('X-Device-ID');
        $userId   = Auth::id();

        if (! $deviceId && ! $userId) {
            return response()->json(['message' => 'device_id or authentication required.'], 400);
        }

        $today     = now()->toDateString();
        $thisWeek  = now()->startOfWeek();
        $thisMonth = now()->startOfMonth();

        // Today's listening stats
        $todayStats = [
            'total_seconds' => 0,
            'books_listened' => 0,
            'session_count' => 0,
            'formatted_duration' => '0:00',
        ];

        $todayQuery = $this->listeningStatsQuery($userId, (string) ($deviceId ?? 'unknown'))
            ->whereDate('listening_date', $today);

        $todaySummary = $todayQuery->selectRaw('SUM(seconds_listened) as total_seconds, COUNT(DISTINCT book_id) as books_listened, COUNT(*) as session_count')
            ->first();

        $todayStats = [
            'total_seconds' => (int) ($todaySummary->total_seconds ?? 0),
            'books_listened' => (int) ($todaySummary->books_listened ?? 0),
            'session_count' => (int) ($todaySummary->session_count ?? 0),
            'formatted_duration' => $this->formatSeconds((int) ($todaySummary->total_seconds ?? 0)),
        ];

        // High-level user stats (from user_book_status)
        $userStats = [
            'total_completed'      => 0,
            'completed_this_month' => 0,
            'upcoming_goals'       => 0,
            'overdue_goals'        => 0,
        ];

        if ($userId) {
            $completedBookDates = $this->getCompletedBookDatesForUser($userId);

            $userStats['total_completed'] = $completedBookDates->count();
            $userStats['completed_this_month'] = $completedBookDates
                ->filter(fn (Carbon $date): bool => $date->gte($thisMonth))
                ->count();

            $userStats['upcoming_goals'] = UserBookStatus::where('user_id', $userId)
                ->whereIn('status', ['queue', 'in_progress'])
                ->where('target_date', '>=', now()->toDateString())
                ->count();

            $userStats['overdue_goals'] = UserBookStatus::where('user_id', $userId)
                ->whereIn('status', ['queue', 'in_progress'])
                ->where('target_date', '<', now()->toDateString())
                ->count();
        }

        // All-time listening stats
        $query = $this->listeningStatsQuery($userId, (string) ($deviceId ?? 'unknown'));

        $allTimeStats = $query->selectRaw('
                SUM(seconds_listened) as total_seconds,
                COUNT(DISTINCT book_id) as books_listened,
                COUNT(*) as session_count,
                COUNT(DISTINCT listening_date) as days_listened
            ')
            ->first();

        $listeningMinutes = $this->getListeningMinutesBreakdown(
            $this->listeningActivityService->getSessions($userId, (string) ($deviceId ?? 'unknown'))
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'today'              => [
                    'total_seconds'      => $todayStats['total_seconds'],
                    'books_listened'     => $todayStats['books_listened'],
                    'session_count'      => $todayStats['session_count'],
                    'formatted_duration' => $todayStats['formatted_duration'],
                ],
                'user_tracking'      => $userStats,
                'listening_overview' => [
                    'total_seconds'            => $allTimeStats->total_seconds ?? 0,
                    'total_books'              => $allTimeStats->books_listened ?? 0,
                    'days_active'              => $allTimeStats->days_listened ?? 0,
                    'formatted_total_duration' => $this->formatSeconds($allTimeStats->total_seconds ?? 0),
                ],
                'listening_minutes' => $listeningMinutes,
            ],
        ]);
    }

    /**
     * @param Collection<int, (object{book_id: int, user_id: int, device_id: string,
     *   listening_date: string, seconds_listened: int, session_start: Carbon,
     *   metadata: array{playback_speed: mixed}}&\stdClass)> $sessions
     */
    private function getListeningMinutesBreakdown(Collection $sessions): array
    {
        $today = now()->toDateString();
        $weekStart = now()->startOfWeek()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        $daySeconds = $sessions->where('listening_date', $today)->sum('seconds_listened');
        $weekSeconds = $sessions->where('listening_date', '>=', $weekStart)->sum('seconds_listened');
        $monthSeconds = $sessions->where('listening_date', '>=', $monthStart)->sum('seconds_listened');

        return [
            'day' => (int) floor($daySeconds / 60),
            'week' => (int) floor($weekSeconds / 60),
            'month' => (int) floor($monthSeconds / 60),
        ];
    }

    private function completedProgressQuery(?int $userId, string $deviceId, ?Carbon $startDate = null)
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

    private function getCompletedBookDatesForUser(int $userId, ?Carbon $startDate = null): \Illuminate\Support\Collection
    {
        $statusDates = UserBookStatus::query()
            ->where('user_id', $userId)
            ->where('status', 'completed')
            ->whereNotNull('book_id')
            ->whereNotNull('finished_at')
            ->get(['book_id', 'finished_at'])
            ->mapWithKeys(function (UserBookStatus $status): array {
                return [$status->book_id => Carbon::parse((string) $status->finished_at)];
            });

        $progressDates = BookProgress::query()
            ->where('user_id', $userId)
            ->where('completed', true)
            ->whereNotNull('book_id')
            ->whereNotNull('completed_at')
            ->get(['book_id', 'completed_at'])
            ->mapWithKeys(function (BookProgress $progress): array {
                return [$progress->book_id => Carbon::parse((string) $progress->completed_at)];
            });

        // The modern event-sourced completion path (BOOK_FINISH -> PositionMaterializer) writes
        // to book_positions, not book_progress/user_book_status. last_event_timestamp_ms is the
        // client-reported time of the finishing event.
        $positionDates = BookPosition::query()
            ->where('user_id', $userId)
            ->where('completed', true)
            ->get(['book_id', 'last_event_timestamp_ms'])
            ->mapWithKeys(function (BookPosition $position): array {
                return [$position->book_id => Carbon::createFromTimestampMs((int) $position->last_event_timestamp_ms)];
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

        if ($startDate !== null) {
            return $merged->filter(fn (Carbon $date): bool => $date->gte($startDate));
        }

        return $merged;
    }

    /**
     * Get reading progress stats by date (finished books per month/year)
     */
    public function getReadingHistoryStats(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'Authentication required.'], 401);
        }

        $groupBy = $request->input('group_by', 'month'); // month or year

        $completedBookDates = $this->getCompletedBookDatesForUser($user->id);

        $stats = $completedBookDates->groupBy(function (Carbon $date) use ($groupBy) {
            return $groupBy === 'year' ? $date->format('Y') : $date->format('Y-m');
        })->map(function ($items, $period) {
            return [
                'period' => (string) $period,
                'count'  => $items->count(),
            ];
        })->values();

        return response()->json($stats);
    }

    /**
     * Calculate current listening streak
     */
    private function calculateCurrentStreak(?int $userId, string $deviceId): int
    {
        return $this->listeningActivityService->getCurrentStreak($userId, $deviceId);
    }

    /**
     * Calculate longest listening streak
     */
    private function calculateLongestStreak(?int $userId, string $deviceId): int
    {
        return $this->listeningActivityService->getLongestStreak($userId, $deviceId);
    }

    /**
     * Get favorite genres for the given sessions (top 5 by total time listened)
     *
     * @param Collection<int, (object{book_id: int, user_id: int, device_id: string,
     *   listening_date: string, seconds_listened: int, session_start: Carbon,
     *   metadata: array{playback_speed: mixed}}&\stdClass)> $sessions
     */
    private function getFavoriteGenres(Collection $sessions): array
    {
        $secondsByBookId = $sessions->groupBy('book_id')
            ->map(static fn (Collection $bookSessions): int => (int) $bookSessions->sum('seconds_listened'));

        if ($secondsByBookId->isEmpty()) {
            return [];
        }

        $genreSeconds = [];
        Book::query()
            ->whereIn('id', $secondsByBookId->keys())
            ->with(['genres' => fn ($query) => $query->whereNull('genres.deleted_at')])
            ->get(['id'])
            ->each(function (Book $book) use ($secondsByBookId, &$genreSeconds): void {
                $seconds = $secondsByBookId->get($book->id, 0);
                foreach ($book->genres as $genre) {
                    $genreSeconds[$genre->name] = ($genreSeconds[$genre->name] ?? 0) + $seconds;
                }
            });

        arsort($genreSeconds);

        return array_slice(array_keys($genreSeconds), 0, 5);
    }

    /**
     * Get daily stats for the given sessions, most recent 30 days first
     *
     * @param Collection<int, (object{book_id: int, user_id: int, device_id: string,
     *   listening_date: string, seconds_listened: int, session_start: Carbon,
     *   metadata: array{playback_speed: mixed}}&\stdClass)> $sessions
     */
    private function getDailyStatsForPeriod(Collection $sessions): array
    {
        return $sessions->groupBy('listening_date')
            ->map(static fn (Collection $daySessions, string $date): array => [
                'date'              => $date,
                'listening_time_ms' => (int) $daySessions->sum('seconds_listened') * 1000,
                'sessions_count'    => $daySessions->count(),
                'books_listened'    => $daySessions->pluck('book_id')->unique()->values()->all(),
            ])
            ->sortKeysDesc()
            ->take(30)
            ->values()
            ->toArray();
    }

    /**
     * Format seconds into human readable format
     */
    protected function formatSeconds(int $seconds): string
    {
        $hours   = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs    = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%d:%02d', $minutes, $secs);
    }

    private function stringUserId(int|string|null $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        return (string) $userId;
    }

    public function getDiagnostics(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = $user->id;

        $totalEvents = ListeningEvent::where('user_id', $userId)->count();
        $syncedCount = ListeningEvent::where('user_id', $userId)->where('sync_status', 'SYNCED')->count();
        $pendingCount = ListeningEvent::where('user_id', $userId)->where('sync_status', 'PENDING_SYNC')->count();
        $failedCount = ListeningEvent::where('user_id', $userId)->where('sync_status', 'SYNC_FAILED')->count();

        $sessionStarts = ListeningEvent::where('user_id', $userId)
            ->where('event_type', 'SESSION_START')
            ->get(['id', 'book_id', 'device_id', 'timestamp_ms']);

        $maxWindowMs = 4 * 3_600_000;
        $orphanedCount = 0;

        foreach ($sessionStarts as $start) {
            $windowEnd = $start->timestamp_ms + $maxWindowMs;
            $hasEnd = ListeningEvent::where('user_id', $userId)
                ->where('book_id', $start->book_id)
                ->where('device_id', $start->device_id)
                ->where('event_type', 'SESSION_END')
                ->where('timestamp_ms', '>', $start->timestamp_ms)
                ->where('timestamp_ms', '<=', $windowEnd)
                ->exists();
            if (!$hasEnd) {
                $orphanedCount++;
            }
        }

        return response()->json([
            'orphaned_sessions' => $orphanedCount,
            'total_events' => $totalEvents,
            'pending_sync' => $pendingCount,
            'sync_failed' => $failedCount,
            'synced' => $syncedCount,
        ]);
    }
}
