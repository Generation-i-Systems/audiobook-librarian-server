<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ListeningStatistic;
use App\Models\Book;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class StatisticsController extends Controller
{
    /**
     * Get listening statistics overview (OpenAPI spec)
     */
    public function getOverview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => 'nullable|string|in:week,month,quarter,year,all_time',
        ]);

        $period = $validated['period'] ?? 'month';
        $userId = auth('api')->id() ?? $request->header('X-Device-ID', 'unknown');

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

        $query = ListeningStatistic::where('device_id', $userId);

        if ($startDate) {
            $query->where('listening_date', '>=', $startDate->toDateString());
        }

        $stats = $query->selectRaw('
            SUM(seconds_listened) as total_listening_time_ms,
            COUNT(DISTINCT book_id) as books_started,
            COUNT(DISTINCT CASE WHEN session_type = "completed" THEN book_id END) as books_finished,
            AVG(seconds_listened) as average_session_duration_ms,
            COUNT(DISTINCT listening_date) as days_with_activity
        ')->first();

        // Calculate streaks
        $currentStreak = $this->calculateCurrentStreak($userId);
        $longestStreak = $this->calculateLongestStreak($userId);

        // Get favorite genres (top 5 most listened)
        $favoriteGenres = $this->getFavoriteGenres($userId, $startDate);

        // Get daily stats for the period
        $dailyStats = $this->getDailyStatsForPeriod($userId, $startDate);

        return response()->json([
            'daily_stats' => $dailyStats,
            'total_listening_time_ms' => ($stats->total_listening_time_ms ?? 0) * 1000,
            'books_started' => $stats->books_started ?? 0,
            'books_finished' => $stats->books_finished ?? 0,
            'average_session_duration_ms' => ($stats->average_session_duration_ms ?? 0) * 1000,
            'favorite_genres' => $favoriteGenres,
            'current_streak' => $currentStreak,
            'longest_streak' => $longestStreak,
        ]);
    }

    /**
     * Get daily listening statistics (OpenAPI spec)
     */
    public function getDailyStatsOpenApi(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
            'limit' => 'nullable|integer|min:1|max:365',
        ]);

        $userId = auth('api')->id() ?? $request->header('X-Device-ID', 'unknown');

        $startDate = ($validated['start_date'] ?? null) ? Carbon::parse($validated['start_date']) : now()->subDays(29);
        $endDate = ($validated['end_date'] ?? null) ? Carbon::parse($validated['end_date']) : now();
        $limit = $validated['limit'] ?? 30;

        $stats = ListeningStatistic::where('device_id', $userId)
            ->whereBetween('listening_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->selectRaw('
                listening_date as date,
                SUM(seconds_listened) * 1000 as listening_time_ms,
                COUNT(*) as sessions_count,
                JSON_ARRAYAGG(DISTINCT book_id) as books_listened
            ')
            ->groupBy('listening_date')
            ->orderByDesc('listening_date')
            ->limit($limit)
            ->get();

        $dailyStats = $stats->map(function ($stat) {
            return [
                'date' => $stat->date,
                'listening_time_ms' => (int) $stat->listening_time_ms,
                'sessions_count' => $stat->sessions_count,
                'books_listened' => json_decode($stat->books_listened, true) ?? [],
            ];
        });

        return response()->json([
            'daily_stats' => $dailyStats
        ]);
    }

    /**
     * Report listening session (OpenAPI spec)
     */
    public function reportSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'book_id' => 'required|integer|exists:books,id',
            'session_start' => 'required|date_format:Y-m-d\TH:i:s\Z',
            'session_end' => 'required|date_format:Y-m-d\TH:i:s\Z|after:session_start',
            'start_position_ms' => 'required|integer|min:0',
            'end_position_ms' => 'required|integer|min:0',
            'playback_speed' => 'nullable|numeric|min:0.1|max:5.0',
            'pauses_count' => 'nullable|integer|min:0',
        ]);

        $userId = auth('api')->id() ?? $request->header('X-Device-ID', 'unknown');

        $sessionStart = Carbon::parse($validated['session_start']);
        $sessionEnd = Carbon::parse($validated['session_end']);
        $sessionDuration = $sessionEnd->diffInSeconds($sessionStart);

        // Calculate actual listening time based on position change and playback speed
        $positionChange = ($validated['end_position_ms'] - $validated['start_position_ms']) / 1000;
        $playbackSpeed = $validated['playback_speed'] ?? 1.0;
        $actualListeningTime = min($sessionDuration, $positionChange / $playbackSpeed);

        $statistic = ListeningStatistic::createSession(
            $validated['book_id'],
            $userId,
            (int) $actualListeningTime,
            (int) ($validated['start_position_ms'] / 1000),
            (int) ($validated['end_position_ms'] / 1000),
            'listening',
            [
                'session_start' => $validated['session_start'],
                'session_end' => $validated['session_end'],
                'playback_speed' => $playbackSpeed,
                'pauses_count' => $validated['pauses_count'] ?? 0,
            ],
            auth('api')->id()
        );

        return response()->json([
            'success' => true,
            'message' => 'Session reported successfully'
        ], 201);
    }

    /**
     * Record a listening session (existing method for backward compatibility)
     */
    public function recordSession(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'book_id' => 'required|integer|exists:books,id',
                'device_id' => 'required|string|max:255',
                'seconds_listened' => 'required|integer|min:1',
                'start_position_seconds' => 'nullable|integer|min:0',
                'end_position_seconds' => 'nullable|integer|min:0',
                'session_type' => 'nullable|string|in:listening,completed,resumed,paused',
                'user_id' => 'nullable|string|max:255',
                'metadata' => 'nullable|array',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'message' => 'Invalid input data',
                'errors' => $e->errors()
            ], 422);
        }

        $statistic = ListeningStatistic::createSession(
            $validated['book_id'],
            $validated['device_id'],
            $validated['seconds_listened'],
            $validated['start_position_seconds'] ?? null,
            $validated['end_position_seconds'] ?? null,
            $validated['session_type'] ?? 'listening',
            $validated['metadata'] ?? [],
            $validated['user_id'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Listening session recorded successfully',
            'data' => [
                'id' => $statistic->id,
                'book_id' => $statistic->book_id,
                'device_id' => $statistic->device_id,
                'listening_date' => $statistic->listening_date->toDateString(),
                'seconds_listened' => $statistic->seconds_listened,
                'session_type' => $statistic->session_type,
                'formatted_duration' => $statistic->formatted_duration,
            ]
        ], 201);
    }

    /**
     * Get daily statistics for a device
     */
    public function getDailyStats(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'required|string|max:255',
            'date' => 'nullable|date_format:Y-m-d',
        ]);

        $date = $validated['date'] ?? now()->toDateString();
        $stats = ListeningStatistic::getDailyStats($validated['device_id'], $date);

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Get weekly statistics for a device
     */
    public function getWeeklyStats(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'required|string|max:255',
            'start_date' => 'nullable|date_format:Y-m-d',
        ]);

        $startDate = $validated['start_date'] ? Carbon::parse($validated['start_date']) : now()->startOfWeek();
        $endDate = $startDate->copy()->endOfWeek();

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
            ->get();

        $weeklyTotal = $stats->sum('total_seconds');
        $totalBooks = ListeningStatistic::where('device_id', $validated['device_id'])
            ->whereBetween('listening_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->distinct('book_id')
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'week_start' => $startDate->toDateString(),
                'week_end' => $endDate->toDateString(),
                'total_seconds' => $weeklyTotal,
                'total_books' => $totalBooks,
                'formatted_total_duration' => $this->formatSeconds($weeklyTotal),
                'daily_breakdown' => $stats->map(function ($stat) {
                    return [
                        'date' => $stat->listening_date,
                        'total_seconds' => $stat->total_seconds,
                        'books_listened' => $stat->books_listened,
                        'session_count' => $stat->session_count,
                        'formatted_duration' => $this->formatSeconds($stat->total_seconds),
                    ];
                })
            ]
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

        $book = Book::find($bookId);
        if (!$book) {
            return response()->json([
                'error' => 'Book not found',
                'message' => 'The specified book could not be found'
            ], 404);
        }

        $stats = ListeningStatistic::getBookStats($bookId, $validated['device_id'] ?? null);

        return response()->json([
            'success' => true,
            'data' => array_merge($stats, [
                'book' => [
                    'id' => $book->id,
                    'title' => $book->title,
                    'cover_image' => $book->cover_image,
                ]
            ])
        ]);
    }

    /**
     * Get listening trends over time
     */
    public function getListeningTrends(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'required|string|max:255',
            'period' => 'nullable|string|in:week,month,year',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
        ]);

        $period = $validated['period'] ?? 'month';

        if (isset($validated['start_date']) && isset($validated['end_date'])) {
            $startDate = Carbon::parse($validated['start_date']);
            $endDate = Carbon::parse($validated['end_date']);
        } else {
            switch ($period) {
                case 'week':
                    $startDate = now()->subWeeks(4)->startOfWeek();
                    $endDate = now()->endOfWeek();
                    break;
                case 'year':
                    $startDate = now()->subYear()->startOfMonth();
                    $endDate = now()->endOfMonth();
                    break;
                case 'month':
                default:
                    $startDate = now()->subMonths(3)->startOfMonth();
                    $endDate = now()->endOfMonth();
                    break;
            }
        }

        $groupBy = match ($period) {
            'week' => 'listening_date',
            'year' => 'YEAR(listening_date), MONTH(listening_date)',
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
            ->get();

        $totalSeconds = $stats->sum('total_seconds');
        $totalBooks = ListeningStatistic::where('device_id', $validated['device_id'])
            ->whereBetween('listening_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->distinct('book_id')
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'total_seconds' => $totalSeconds,
                'total_books' => $totalBooks,
                'formatted_total_duration' => $this->formatSeconds($totalSeconds),
                'average_daily_seconds' => $stats->isNotEmpty() ? round($totalSeconds / $stats->count()) : 0,
                'trends' => $stats->map(function ($stat) {
                    return [
                        'date' => $stat->listening_date,
                        'total_seconds' => $stat->total_seconds,
                        'books_listened' => $stat->books_listened,
                        'session_count' => $stat->session_count,
                        'avg_session_duration' => round($stat->avg_session_duration),
                        'formatted_duration' => $this->formatSeconds($stat->total_seconds),
                        'formatted_avg_session' => $this->formatSeconds(round($stat->avg_session_duration)),
                    ];
                })
            ]
        ]);
    }

    /**
     * Get top books by listening time
     */
    public function getTopBooks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'nullable|string|max:255',
            'limit' => 'nullable|integer|min:1|max:50',
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

        $limit = $validated['limit'] ?? 10;
        $topBooks = $query->limit($limit)->get();

        return response()->json([
            'success' => true,
            'data' => $topBooks->map(function ($stat) {
                return [
                    'book_id' => $stat->book_id,
                    'book' => [
                        'id' => $stat->book->id,
                        'title' => $stat->book->title,
                        'cover_image' => $stat->book->cover_image,
                    ],
                    'total_seconds' => $stat->total_seconds,
                    'session_count' => $stat->session_count,
                    'days_listened' => $stat->days_listened,
                    'first_listened' => $stat->first_listened,
                    'last_listened' => $stat->last_listened,
                    'formatted_duration' => $this->formatSeconds($stat->total_seconds),
                ];
            })
        ]);
    }

    /**
     * Get comprehensive dashboard statistics
     */
    public function getDashboardStats(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'required|string|max:255',
        ]);

        $deviceId = $validated['device_id'];
        $today = now()->toDateString();
        $thisWeek = now()->startOfWeek();
        $thisMonth = now()->startOfMonth();

        // Today's stats
        $todayStats = ListeningStatistic::getDailyStats($deviceId, $today);

        // This week's stats
        $weekStats = ListeningStatistic::where('device_id', $deviceId)
            ->where('listening_date', '>=', $thisWeek->toDateString())
            ->selectRaw('
                SUM(seconds_listened) as total_seconds,
                COUNT(DISTINCT book_id) as books_listened,
                COUNT(*) as session_count
            ')
            ->first();

        // This month's stats
        $monthStats = ListeningStatistic::where('device_id', $deviceId)
            ->where('listening_date', '>=', $thisMonth->toDateString())
            ->selectRaw('
                SUM(seconds_listened) as total_seconds,
                COUNT(DISTINCT book_id) as books_listened,
                COUNT(*) as session_count
            ')
            ->first();

        // All-time stats
        $allTimeStats = ListeningStatistic::where('device_id', $deviceId)
            ->selectRaw('
                SUM(seconds_listened) as total_seconds,
                COUNT(DISTINCT book_id) as books_listened,
                COUNT(*) as session_count,
                COUNT(DISTINCT listening_date) as days_listened,
                MIN(listening_date) as first_day,
                MAX(listening_date) as last_day
            ')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'today' => [
                    'total_seconds' => $todayStats['total_seconds'],
                    'books_listened' => $todayStats['books_listened'],
                    'session_count' => $todayStats['session_count'],
                    'formatted_duration' => $todayStats['formatted_duration'],
                ],
                'this_week' => [
                    'total_seconds' => $weekStats->total_seconds ?? 0,
                    'books_listened' => $weekStats->books_listened ?? 0,
                    'session_count' => $weekStats->session_count ?? 0,
                    'formatted_duration' => $this->formatSeconds($weekStats->total_seconds ?? 0),
                ],
                'this_month' => [
                    'total_seconds' => $monthStats->total_seconds ?? 0,
                    'books_listened' => $monthStats->books_listened ?? 0,
                    'session_count' => $monthStats->session_count ?? 0,
                    'formatted_duration' => $this->formatSeconds($monthStats->total_seconds ?? 0),
                ],
                'all_time' => [
                    'total_seconds' => $allTimeStats->total_seconds ?? 0,
                    'books_listened' => $allTimeStats->books_listened ?? 0,
                    'session_count' => $allTimeStats->session_count ?? 0,
                    'days_listened' => $allTimeStats->days_listened ?? 0,
                    'first_day' => $allTimeStats->first_day,
                    'last_day' => $allTimeStats->last_day,
                    'formatted_duration' => $this->formatSeconds($allTimeStats->total_seconds ?? 0),
                ],
            ]
        ]);
    }

    /**
     * Calculate current listening streak
     */
    private function calculateCurrentStreak(string $userId): int
    {
        $streak = 0;
        $currentDate = now();

        while (true) {
            $hasActivity = ListeningStatistic::where('device_id', $userId)
                ->where('listening_date', $currentDate->toDateString())
                ->exists();

            if (!$hasActivity) {
                break;
            }

            $streak++;
            $currentDate->subDay();
        }

        return $streak;
    }

    /**
     * Calculate longest listening streak
     */
    private function calculateLongestStreak(string $userId): int
    {
        $dates = ListeningStatistic::where('device_id', $userId)
            ->select('listening_date')
            ->distinct()
            ->orderBy('listening_date')
            ->pluck('listening_date')
            ->map(fn ($date) => Carbon::parse($date))
            ->toArray();

        if (empty($dates)) {
            return 0;
        }

        $longestStreak = 1;
        $currentStreak = 1;

        for ($i = 1; $i < count($dates); $i++) {
            if ($dates[$i]->diffInDays($dates[$i - 1]) === 1) {
                $currentStreak++;
                $longestStreak = max($longestStreak, $currentStreak);
            } else {
                $currentStreak = 1;
            }
        }

        return $longestStreak;
    }

    /**
     * Get favorite genres for a user
     */
    private function getFavoriteGenres(string $userId, ?Carbon $startDate): array
    {
        $query = ListeningStatistic::where('listening_statistics.device_id', $userId)
            ->join('books', 'listening_statistics.book_id', '=', 'books.id')
            ->join('book_genre', 'books.id', '=', 'book_genre.book_id')
            ->join('genres', 'book_genre.genre_id', '=', 'genres.id');

        if ($startDate) {
            $query->where('listening_date', '>=', $startDate->toDateString());
        }

        return $query->selectRaw('genres.name, SUM(seconds_listened) as total_time')
            ->groupBy('genres.name')
            ->orderByDesc('total_time')
            ->limit(5)
            ->pluck('genres.name')
            ->toArray();
    }

    /**
     * Get daily stats for a period
     */
    private function getDailyStatsForPeriod(string $userId, ?Carbon $startDate): array
    {
        $query = ListeningStatistic::where('device_id', $userId);

        if ($startDate) {
            $query->where('listening_date', '>=', $startDate->toDateString());
        }

        $stats = $query->selectRaw('
            listening_date as date,
            SUM(seconds_listened) * 1000 as listening_time_ms,
            COUNT(*) as sessions_count,
            JSON_ARRAYAGG(DISTINCT book_id) as books_listened
        ')
        ->groupBy('listening_date')
        ->orderByDesc('listening_date')
        ->limit(30)
        ->get();

        return $stats->map(function ($stat) {
            return [
                'date' => $stat->date,
                'listening_time_ms' => (int) $stat->listening_time_ms,
                'sessions_count' => $stat->sessions_count,
                'books_listened' => json_decode($stat->books_listened, true) ?? [],
            ];
        })->toArray();
    }

    /**
     * Format seconds into human readable format
     */
    protected function formatSeconds(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%d:%02d', $minutes, $secs);
    }
}
