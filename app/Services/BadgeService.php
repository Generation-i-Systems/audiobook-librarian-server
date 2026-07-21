<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Badge;
use App\Models\BookPosition;
use App\Models\BookProgress;
use App\Models\ClientEvent;
use App\Models\ListeningGoal;
use App\Models\Message;
use App\Models\Playlist;
use App\Models\Review;
use App\Models\UserBadge;
use App\Models\UserRecommendation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BadgeService
{
    public function __construct(private readonly ListeningActivityService $listeningActivityService)
    {
    }

    /**
     * Evaluate and award badges for a given User model.
     */
    public function evaluateBadgesForUser(\App\Models\User $user): array
    {
        return $this->evaluateUserBadges((string) $user->id);
    }

    /**
     * Evaluate and award badges for a user after a listening session
     */
    public function evaluateUserBadges(string $userId, ?string $deviceId = null): array
    {
        $newBadges = [];

        try {
            $this->clearUserStatsCache($userId, $deviceId);

            // Get user's current listening statistics
            $userStats = $this->getUserListeningStatistics($userId, $deviceId);

            // Get all active badges
            $badges = Badge::active()->ordered()->get();

            foreach ($badges as $badge) {
                if ($this->shouldEvaluateBadge($badge, $userId, $deviceId, $userStats)) {
                    if ($badge->evaluateCriteria($userStats)) {
                        $newBadge = $this->awardBadge($badge, $userId, $deviceId, $userStats);
                        if ($newBadge) {
                            $newBadges[] = $newBadge;
                        }
                    }
                }
            }

            return $newBadges;
        } catch (\Exception $e) {
            Log::error('Error evaluating user badges', [
                'user_id'   => $userId,
                'device_id' => $deviceId,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return [];
        }
    }

    /**
     * Session-shaped listening activity rows for a user, matching by user_id, device_id, or
     * a device-passed-as-userId (for unauthenticated/device-only evaluation). Backed by
     * ListeningActivityService, which derives these from the event-sourced listening_events
     * table - the legacy listening_statistics table this used to query is no longer written
     * to by any current client.
     *
     * @return Collection<int, (object{book_id: int, user_id: int, device_id: string,
     *   listening_date: string, seconds_listened: int, session_start: \Carbon\Carbon,
     *   metadata: array{playback_speed: mixed}}&\stdClass)>
     */
    protected function userSessions(string $userId, ?string $deviceId = null): Collection
    {
        return $this->listeningActivityService->getSessions($userId, $deviceId);
    }

    /**
     * Get comprehensive listening statistics for a user
     */
    protected function getUserListeningStatistics(string $userId, ?string $deviceId = null): array
    {
        $cacheKey = "user_stats_{$userId}" . ($deviceId ? "_{$deviceId}" : '');

        return Cache::remember($cacheKey, 300, function () use ($userId, $deviceId) {
            $sessions = $this->userSessions($userId, $deviceId);

            $sortedDates = $sessions->pluck('listening_date')->sort()->values();

            $allTimeStats = (object) [
                'total_listening_time' => $sessions->sum('seconds_listened'),
                'session_count' => $sessions->count(),
                'books_started' => $sessions->pluck('book_id')->filter()->unique()->count(),
                'total_listening_days' => $sessions->pluck('listening_date')->unique()->count(),
                'first_listening_date' => $sortedDates->first(),
                'last_listening_date' => $sortedDates->last(),
                'longest_session' => (int) $sessions->max('seconds_listened'),
            ];

            $completedBooks = $this->getCompletedBookProgressRecords($userId, $deviceId);

            // Calculate listening streak
            $currentStreak = $this->calculateCurrentStreak($userId, $deviceId);
            $longestStreak = $this->calculateLongestStreak($userId, $deviceId);

            // Get genre and author variety
            $genresExplored    = $this->getGenresExplored($userId, $deviceId);
            $authorsExplored   = $this->getAuthorsExplored($userId, $deviceId);
            $narratorsExplored = $this->getNarratorsExplored($userId, $deviceId);

            // Get weekend listening sessions/time
            $weekendSessions = $this->getWeekendSessions($userId, $deviceId);
            $weekendListeningTime = $this->getWeekendListeningTime($userId, $deviceId);

            // Get recent completion stats
            $booksCompletedThisMonth = $this->getBooksCompletedInTimeframe($userId, $deviceId, 30);
            $booksCompletedThisWeek  = $this->getBooksCompletedInTimeframe($userId, $deviceId, 7);
            $booksCompletedOnWeekend = $this->getBooksCompletedOnWeekend($userId, $deviceId);
            $quickFinishes = $this->getQuickFinishCount($userId, $deviceId);

            // Get series exploration stats
            $seriesExplored = $this->getSeriesExploredCount($userId, $deviceId);
            $seriesCompleted = $this->getSeriesCompleted($userId, $deviceId);

            // Get additional statistics for expanded badge categories
            $bookmarksCreated  = $this->getBookmarksCreated($userId, $deviceId);
            $booksReviewed     = $this->getBooksReviewed($userId, $deviceId);
            $librarySize       = $this->getLibrarySize($userId, $deviceId);
            $completionRate    = $this->getCompletionRate($userId, $deviceId);
            $chapterCompletion = $this->getChapterCompletion($userId, $deviceId);
            $deviceVariety     = $this->getDeviceVariety($userId, $deviceId);
            $repeatListening   = $this->getRepeatListening($userId, $deviceId);
            $languageVariety   = $this->getLanguageVariety($userId, $deviceId);
            $classicBooks      = $this->getClassicBooksExplored($userId, $deviceId);
            $indieBooks        = $this->getIndieBooksExplored($userId, $deviceId);
            $recommendationsSent = $this->getRecommendationsSent($userId);
            $recommendationsRead = $this->getRecommendationsRead($userId);
            $playlistCount       = $this->getPlaylistCount($userId);
            $timeOfDayStats      = $this->getTimeOfDayStatistics($userId, $deviceId);
            $seasonalStats       = $this->getSeasonalStatistics(
                $userId,
                $deviceId,
                $allTimeStats->first_listening_date
            );
            $speedStats          = $this->getPlaybackSpeedStatistics($userId, $deviceId);
            $goalStats           = $this->getGoalStatistics($userId);

            // Get action-based statistics from client events
            $actionCounts = $this->getActionCounts($userId, $deviceId);

            return [
                'total_listening_time'       => (int) ($allTimeStats->total_listening_time ?? 0),
                'session_count'              => (int) ($allTimeStats->session_count ?? 0),
                'books_started'              => (int) ($allTimeStats->books_started ?? 0),
                'books_completed'            => $completedBooks->count(),
                'total_listening_days'       => (int) ($allTimeStats->total_listening_days ?? 0),
                'longest_session'            => (int) ($allTimeStats->longest_session ?? 0),
                'current_streak'             => $currentStreak,
                'longest_streak'             => $longestStreak,
                'genres_explored'            => $genresExplored,
                'authors_explored'           => $authorsExplored,
                'narrator_variety'           => $narratorsExplored,
                'weekend_listening'          => $weekendSessions,
                'weekend_listening_time'     => $weekendListeningTime,
                'books_completed_this_month' => $booksCompletedThisMonth,
                'books_completed_this_week'  => $booksCompletedThisWeek,
                'books_completed_on_weekend' => $booksCompletedOnWeekend,
                'quick_finishes'             => $quickFinishes,
                'series_explored'           => $seriesExplored,
                'series_completion'          => $seriesCompleted,
                'bookmarks_created'          => $bookmarksCreated,
                'books_reviewed'             => $booksReviewed,
                'library_size'               => $librarySize,
                'completion_rate'            => $completionRate,
                'chapter_completion'         => $chapterCompletion,
                'device_variety'             => $deviceVariety,
                'repeat_listening'           => $repeatListening,
                'language_variety'           => $languageVariety,
                'classic_books_explored'     => $classicBooks,
                'indie_books_explored'       => $indieBooks,
                'recommendations_sent'       => $recommendationsSent,
                'discovery_rate'             => $recommendationsRead,
                'playlist_count'             => $playlistCount,
                'first_listening_date'       => $allTimeStats->first_listening_date,
                'last_listening_date'        => $allTimeStats->last_listening_date,
            ] + $timeOfDayStats + $seasonalStats + $speedStats + $goalStats + $actionCounts;
        });
    }

    /**
     * Calculate current listening streak
     */
    protected function calculateCurrentStreak(string $userId, ?string $deviceId = null): int
    {
        return $this->listeningActivityService->getCurrentStreak($userId, $deviceId);
    }

    /**
     * Calculate longest listening streak
     */
    protected function calculateLongestStreak(string $userId, ?string $deviceId = null): int
    {
        return $this->listeningActivityService->getLongestStreak($userId, $deviceId);
    }

    /**
     * Get number of unique genres explored
     */
    protected function getGenresExplored(string $userId, ?string $deviceId = null): int
    {
        $engagedBookIds = $this->getMeaningfullyEngagedBookIds($userId, $deviceId);

        if ($engagedBookIds->isEmpty()) {
            return 0;
        }

        return DB::table('book_genre')
            ->whereIn('book_id', $engagedBookIds)
            ->distinct('genre_id')
            ->count('genre_id');
    }

    /**
     * Get number of unique authors explored
     */
    protected function getAuthorsExplored(string $userId, ?string $deviceId = null): int
    {
        $engagedBookIds = $this->getMeaningfullyEngagedBookIds($userId, $deviceId);

        if ($engagedBookIds->isEmpty()) {
            return 0;
        }

        return DB::table('author_book')
            ->whereIn('book_id', $engagedBookIds)
            ->distinct('author_id')
            ->count('author_id');
    }

    /**
     * Get number of unique narrators explored
     */
    protected function getNarratorsExplored(string $userId, ?string $deviceId = null): int
    {
        $engagedBookIds = $this->getMeaningfullyEngagedBookIds($userId, $deviceId);

        if ($engagedBookIds->isEmpty()) {
            return 0;
        }

        return DB::table('book_narrator')
            ->whereIn('book_id', $engagedBookIds)
            ->distinct('narrator_id')
            ->count('narrator_id');
    }

    protected function getMeaningfullyEngagedBookIds(string $userId, ?string $deviceId = null, int $minimumSeconds = 600): Collection
    {
        $listenedBookIds = $this->userSessions($userId, $deviceId)
            ->whereNotNull('book_id')
            ->groupBy('book_id')
            ->filter(static fn (Collection $sessions): bool => $sessions->sum('seconds_listened') >= $minimumSeconds)
            ->keys();

        return $listenedBookIds
            ->merge($this->getCompletedBookIds($userId, $deviceId))
            ->filter()
            ->map(static fn ($bookId): int => (int) $bookId)
            ->unique()
            ->values();
    }

    /**
     * Get weekend listening sessions count
     */
    protected function getWeekendSessions(string $userId, ?string $deviceId = null): int
    {
        return $this->weekendSessions($userId, $deviceId)->count();
    }

    protected function getWeekendListeningTime(string $userId, ?string $deviceId = null): int
    {
        return (int) $this->weekendSessions($userId, $deviceId)->sum('seconds_listened');
    }

    private function weekendSessions(string $userId, ?string $deviceId = null): Collection
    {
        return $this->userSessions($userId, $deviceId)
            ->filter(static fn (object $session): bool => Carbon::parse($session->listening_date)->isWeekend());
    }

    /**
     * Get books completed in timeframe
     */
    protected function getBooksCompletedInTimeframe(string $userId, ?string $deviceId, int $days): int
    {
        $startDate = Carbon::now()->subDays($days);

        return $this->getCompletedBookProgressRecords($userId, $deviceId)
            ->filter(fn (object $record): bool => $record->completed_at->gte($startDate))
            ->count();
    }

    /**
     * Get series completion count - counts series where all books have been completed
     */
    protected function getSeriesCompleted(string $userId, ?string $deviceId = null): int
    {
        try {
            $completedBookIds = $this->getCompletedBookIds($userId, $deviceId);

            if ($completedBookIds->isEmpty()) {
                return 0;
            }

            $seriesWithCompleted = $this->getBookSeriesIds($completedBookIds);

            $completedSeries = 0;
            foreach ($seriesWithCompleted as $seriesId) {
                $seriesBookIds = $this->getSeriesBookIds((int) $seriesId);
                $completedInSeries = $seriesBookIds
                    ->intersect($completedBookIds->map(fn ($bookId) => (int) $bookId))
                    ->count();

                if ($seriesBookIds->isNotEmpty() && $completedInSeries >= $seriesBookIds->count()) {
                    $completedSeries++;
                }
            }

            return $completedSeries;
        } catch (\Exception $e) {
            Log::warning('Failed to calculate series completion', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    protected function getSeriesExploredCount(string $userId, ?string $deviceId = null): int
    {
        return $this->getBookSeriesIds($this->getCompletedBookIds($userId, $deviceId))->count();
    }

    /**
     * Get number of bookmarks created by user
     */
    protected function getBookmarksCreated(string $userId, ?string $deviceId = null): int
    {
        return DB::table('bookmarks')->where('user_id', $userId)->count();
    }

    /**
     * Get number of books reviewed by user
     */
    protected function getBooksReviewed(string $userId, ?string $deviceId = null): int
    {
        if (! is_numeric($userId)) {
            return 0;
        }

        return Review::query()
            ->where('user_id', (int) $userId)
            ->distinct('book_id')
            ->count();
    }

    /**
     * Get user's library size (books they own/have access to)
     */
    /**
     * Get user's library size (distinct books they own/have engaged with).
     *
     * Note: this counts books the server has a record of - explicit status tags
     * (queue/wishlist/completed/etc. in user_book_status) or any playback interaction
     * (book_positions, written for virtually every listening event). It cannot see
     * books that exist only in a user's local folder scan and have never been played or
     * explicitly statused - the server has no sync signal for "this book exists in my
     * local library" independent of interaction, so a large purely-local, unplayed
     * collection will still undercount here.
     */
    protected function getLibrarySize(string $userId, ?string $deviceId = null): int
    {
        if (! is_numeric($userId)) {
            return 0;
        }

        $legacyLibraryBookIds = DB::table('book_user')
            ->where('user_id', $userId)
            ->pluck('book_id');

        $statusBookIds = DB::table('user_book_status')
            ->where('user_id', $userId)
            ->whereNotNull('book_id')
            ->pluck('book_id');

        $positionBookIds = BookPosition::query()
            ->where('user_id', (int) $userId)
            ->whereNotNull('book_id')
            ->distinct()
            ->pluck('book_id');

        return $legacyLibraryBookIds
            ->merge($statusBookIds)
            ->merge($positionBookIds)
            ->filter()
            ->unique()
            ->count();
    }

    /**
     * Get completion rate percentage
     */
    protected function getCompletionRate(string $userId, ?string $deviceId = null): int
    {
        $totalBooks     = $this->userSessions($userId, $deviceId)->pluck('book_id')->filter()->unique()->count();
        $completedBooks = $this->getCompletedBookIds($userId, $deviceId)->count();

        if ($totalBooks === 0) {
            return 0;
        }

        return (int) (($completedBooks / $totalBooks) * 100);
    }

    /**
     * Get total chapter completions
     */
    protected function getChapterCompletion(string $userId, ?string $deviceId = null): int
    {
        // This would require chapter tracking in your system
        // For now, estimate based on sessions (simplified)
        // Rough estimate: assume average session covers 1-2 chapters
        return (int) ($this->userSessions($userId, $deviceId)->count() * 1.5);
    }

    protected function getLanguageVariety(string $userId, ?string $deviceId = null): int
    {
        $engagedBookIds = $this->getMeaningfullyEngagedBookIds($userId, $deviceId);

        if ($engagedBookIds->isEmpty()) {
            return 0;
        }

        return DB::table('books')
            ->whereIn('id', $engagedBookIds)
            ->whereNotNull('language')
            ->distinct('language')
            ->count('language');
    }

    /**
     * Get device variety count
     */
    protected function getDeviceVariety(string $userId, ?string $deviceId = null): int
    {
        try {
            if (is_numeric($userId)) {
                return DB::table('devices')
                    ->where('user_id', (int) $userId)
                    ->count();
            }
            return 1;
        } catch (\Exception $e) {
            return 1;
        }
    }

    /**
     * Get repeat listening count (books listened to multiple times)
     */
    protected function getRepeatListening(string $userId, ?string $deviceId = null): int
    {
        // Count books that have been "completed" more than once
        return 0;
    }

    protected function getBooksCompletedOnWeekend(string $userId, ?string $deviceId = null): int
    {
        $weekendDays = [Carbon::SATURDAY, Carbon::SUNDAY];

        return $this->getCompletedBookProgressRecords($userId, $deviceId)
            ->filter(fn (object $record): bool => in_array($record->completed_at->dayOfWeek, $weekendDays, true))
            ->count();
    }

    protected function getQuickFinishCount(string $userId, ?string $deviceId = null): int
    {
        $completedDates = $this->getCompletedBookProgressRecords($userId, $deviceId)
            ->pluck('completed_at', 'book_id');

        $bookFirstDates = $this->userSessions($userId, $deviceId)
            ->whereNotNull('book_id')
            ->groupBy('book_id')
            ->map(static fn (Collection $sessions): string => $sessions->pluck('listening_date')->sort()->first());

        return $bookFirstDates->filter(function (string $firstDate, int $bookId) use ($completedDates): bool {
            $completedDate = $completedDates->get($bookId);

            if ($completedDate === null) {
                return false;
            }

            return Carbon::parse($firstDate)->diffInDays($completedDate, true) <= 7;
        })->count();
    }

    protected function getCompletedBookIds(string $userId, ?string $deviceId = null): Collection
    {
        return $this->getCompletedBookProgressRecords($userId, $deviceId)
            ->pluck('book_id')
            ->filter()
            ->map(static fn ($bookId): int => (int) $bookId)
            ->unique()
            ->values();
    }

    /**
     * Completed-book records, keyed by book_id to the latest known completion date.
     *
     * Unions two data sources: the legacy BookProgress table (still written by
     * ProgressController) and BookPosition (written by PositionMaterializer for the modern
     * event-sourced BOOK_FINISH path used by current clients). Neither source alone reflects
     * all real completions.
     *
     * @return Collection<int, (object{book_id: int, completed_at: Carbon}&\stdClass)>
     */
    protected function getCompletedBookProgressRecords(string $userId, ?string $deviceId = null): Collection
    {
        /** @var array<int, Carbon> $completions */
        $completions = [];

        foreach ($this->completedBookProgressQuery($userId, $deviceId)->get(['book_id', 'completed_at']) as $progress) {
            /** @var BookProgress $progress */
            if ($progress->completed_at !== null) {
                $completions[$progress->book_id] = Carbon::parse($progress->completed_at);
            }
        }

        $positions = $this->completedBookPositionQuery($userId, $deviceId)->get(['book_id', 'last_event_timestamp_ms']);
        foreach ($positions as $position) {
            /** @var BookPosition $position */
            $date = Carbon::createFromTimestampMs((int) $position->last_event_timestamp_ms);
            $existing = $completions[$position->book_id] ?? null;
            if ($existing === null || $date->gt($existing)) {
                $completions[$position->book_id] = $date;
            }
        }

        return collect($completions)->map(static fn (Carbon $date, int $bookId): object => (object) [
            'book_id'      => $bookId,
            'completed_at' => $date,
        ])->values();
    }

    private function completedBookProgressQuery(string $userId, ?string $deviceId = null): Builder
    {
        if (is_numeric($userId)) {
            return BookProgress::query()
                ->where('user_id', (int) $userId)
                ->where('completed', true)
                ->whereNotNull('book_id');
        }

        $query = BookProgress::query()
            ->where('completed', true)
            ->whereNotNull('book_id');

        if ($deviceId !== null) {
            $query->where('device_id', $deviceId);
        } else {
            $query->where('device_id', $userId);
        }

        return $query;
    }

    private function completedBookPositionQuery(string $userId, ?string $deviceId = null): Builder
    {
        if (is_numeric($userId)) {
            return BookPosition::query()
                ->where('user_id', (int) $userId)
                ->where('completed', true)
                ->whereNotNull('book_id');
        }

        $query = BookPosition::query()
            ->where('completed', true)
            ->whereNotNull('book_id');

        if ($deviceId !== null) {
            $query->where('device_id', $deviceId);
        } else {
            $query->where('device_id', $userId);
        }

        return $query;
    }

    protected function getClassicBooksExplored(string $userId, ?string $deviceId = null): int
    {
        $engagedBookIds = $this->getMeaningfullyEngagedBookIds($userId, $deviceId);

        if ($engagedBookIds->isEmpty()) {
            return 0;
        }

        return DB::table('books')
            ->whereIn('id', $engagedBookIds)
            ->where(function ($query): void {
                $query->where('year', '<=', 1970)
                    ->orWhereYear('release_date', '<=', 1970);
            })
            ->distinct('id')
            ->count('id');
    }

    protected function getIndieBooksExplored(string $userId, ?string $deviceId = null): int
    {
        $engagedBookIds = $this->getMeaningfullyEngagedBookIds($userId, $deviceId);

        if ($engagedBookIds->isEmpty()) {
            return 0;
        }

        return DB::table('books')
            ->whereIn('id', $engagedBookIds)
            ->where(function ($query): void {
                $query->whereNull('publisher_id')
                    ->orWhere('source', '!=', 'audible');
            })
            ->distinct('id')
            ->count('id');
    }

    protected function getRecommendationsSent(string $userId): int
    {
        if (! is_numeric($userId)) {
            return 0;
        }

        return UserRecommendation::query()
            ->where('sender_id', (int) $userId)
            ->count();
    }

    protected function getRecommendationsRead(string $userId): int
    {
        if (! is_numeric($userId)) {
            return 0;
        }

        $recommendedBookIds = UserRecommendation::query()
            ->where('recipient_id', (int) $userId)
            ->distinct('book_id')
            ->pluck('book_id');

        if ($recommendedBookIds->isEmpty()) {
            return 0;
        }

        return $this->getMeaningfullyEngagedBookIds($userId)
            ->intersect($recommendedBookIds->map(static fn ($bookId): int => (int) $bookId))
            ->count();
    }

    protected function getPlaylistCount(string $userId): int
    {
        if (! is_numeric($userId)) {
            return 0;
        }

        return Playlist::query()->where('user_id', (int) $userId)->count();
    }

    protected function getTimeOfDayStatistics(string $userId, ?string $deviceId = null): array
    {
        $sessions = $this->userSessions($userId, $deviceId);

        $morningSessions = 0;
        $eveningSessions = 0;
        $commuteSessions = 0;

        foreach ($sessions as $session) {
            $hour = $session->session_start->hour;

            if ($hour >= 5 && $hour < 12) {
                $morningSessions++;
            }

            if ($hour >= 18 || $hour < 1) {
                $eveningSessions++;
            }

            if (($hour >= 6 && $hour < 10) || ($hour >= 16 && $hour < 20)) {
                $commuteSessions++;
            }
        }

        return [
            'morning_sessions' => $morningSessions,
            'evening_sessions' => $eveningSessions,
            'commute_sessions' => $commuteSessions,
        ];
    }

    protected function getSeasonalStatistics(string $userId, ?string $deviceId = null, mixed $firstListeningDate = null): array
    {
        $dates = $this->userSessions($userId, $deviceId)
            ->pluck('listening_date')
            ->unique()
            ->map(fn ($date) => Carbon::parse((string) $date));

        $stats = [
            'new_year_sessions' => 0,
            'spring_sessions' => 0,
            'summer_sessions' => 0,
            'autumn_sessions' => 0,
            'winter_sessions' => 0,
            'anniversary_sessions' => 0,
        ];

        $anniversaryMonthDay = $firstListeningDate !== null ? Carbon::parse((string) $firstListeningDate)->format('m-d') : null;
        $firstListeningYear = $firstListeningDate !== null ? Carbon::parse((string) $firstListeningDate)->year : null;

        foreach ($dates as $date) {
            $month = $date->month;

            if ($month === 1 && $date->day === 1) {
                $stats['new_year_sessions']++;
            }

            if (in_array($month, [3, 4, 5], true)) {
                $stats['spring_sessions']++;
            }

            if (in_array($month, [6, 7, 8], true)) {
                $stats['summer_sessions']++;
            }

            if (in_array($month, [9, 10, 11], true)) {
                $stats['autumn_sessions']++;
            }

            if (in_array($month, [12, 1, 2], true)) {
                $stats['winter_sessions']++;
            }

            if (
                $anniversaryMonthDay !== null
                && $firstListeningYear !== null
                && $date->year > $firstListeningYear
                && $date->format('m-d') === $anniversaryMonthDay
            ) {
                $stats['anniversary_sessions'] = 1;
            }
        }

        return $stats;
    }

    protected function getPlaybackSpeedStatistics(string $userId, ?string $deviceId = null): array
    {
        $sessions = $this->userSessions($userId, $deviceId);

        $speedThresholds = [
            'speed_time_110' => 1.10,
            'speed_time_125' => 1.25,
            'speed_time_150' => 1.50,
            'speed_time_175' => 1.75,
            'speed_time_200' => 2.00,
        ];

        $speedStats = array_fill_keys(array_keys($speedThresholds), 0);
        $speedBucketThresholds = [1.10, 1.25, 1.50, 1.75, 2.00];
        $speedBuckets = array_fill_keys(array_map(static fn (float $speed): string => number_format($speed, 2, '.', ''), $speedBucketThresholds), 0);

        foreach ($sessions as $session) {
            $speed = (float) data_get($session->metadata, 'playback_speed', 1.0);
            $secondsListened = (int) $session->seconds_listened;

            foreach ($speedThresholds as $key => $threshold) {
                if ($speed >= $threshold) {
                    $speedStats[$key] += $secondsListened;
                }
            }

            foreach ($speedBucketThresholds as $bucket) {
                if (abs($speed - $bucket) < 0.051) {
                    $speedBuckets[number_format($bucket, 2, '.', '')] += $secondsListened;
                    break;
                }
            }
        }

        $speedStats['speed_variety'] = collect($speedBuckets)
            ->filter(static fn (int $seconds): bool => $seconds >= 1800)
            ->count();

        return $speedStats;
    }

    protected function getGoalStatistics(string $userId): array
    {
        if (! is_numeric($userId)) {
            return [
                'weekly_goal_streak' => 0,
                'monthly_goal_streak' => 0,
                'yearly_goal_achieved' => 0,
            ];
        }

        $goals = ListeningGoal::query()
            ->where('user_id', (int) $userId)
            ->where('is_active', true)
            ->get();

        $weeklyGoalStreak = 0;
        $monthlyGoalStreak = 0;
        $yearlyGoalAchieved = 0;

        foreach ($goals as $goal) {
            if ($goal->period_type === 'week') {
                $weeklyGoalStreak = max($weeklyGoalStreak, $this->calculateGoalAchievementStreak($goal, 4));
            }

            if ($goal->period_type === 'month') {
                $monthlyGoalStreak = max($monthlyGoalStreak, $this->calculateGoalAchievementStreak($goal, 3));
            }

            if ($goal->period_type === 'year') {
                $yearlyGoalAchieved = max($yearlyGoalAchieved, $this->goalIsMetForOffset($goal, 0) ? 1 : 0);
            }
        }

        return [
            'weekly_goal_streak' => $weeklyGoalStreak,
            'monthly_goal_streak' => $monthlyGoalStreak,
            'yearly_goal_achieved' => $yearlyGoalAchieved,
        ];
    }

    protected function calculateGoalAchievementStreak(ListeningGoal $goal, int $periods): int
    {
        $streak = 0;

        for ($offset = 0; $offset < $periods; $offset++) {
            if (! $this->goalIsMetForOffset($goal, $offset)) {
                break;
            }

            $streak++;
        }

        return $streak;
    }

    protected function goalIsMetForOffset(ListeningGoal $goal, int $offset): bool
    {
        $period = $this->goalPeriodBounds($goal->period_type, $offset);
        $minutes = $this->goalProgressMinutes($goal, $period['start'], $period['end']);

        return $minutes >= $goal->target_minutes;
    }

    protected function goalPeriodBounds(string $periodType, int $offset): array
    {
        $now = Carbon::now();

        return match ($periodType) {
            'day' => [
                'start' => $now->copy()->subDays($offset)->startOfDay(),
                'end' => $now->copy()->subDays($offset)->endOfDay(),
            ],
            'week' => [
                'start' => $now->copy()->subWeeks($offset)->startOfWeek(),
                'end' => $now->copy()->subWeeks($offset)->endOfWeek(),
            ],
            'month' => [
                'start' => $now->copy()->subMonths($offset)->startOfMonth(),
                'end' => $now->copy()->subMonths($offset)->endOfMonth(),
            ],
            'year' => [
                'start' => $now->copy()->subYears($offset)->startOfYear(),
                'end' => $now->copy()->subYears($offset)->endOfYear(),
            ],
            default => [
                'start' => $now->copy()->startOfDay(),
                'end' => $now->copy()->endOfDay(),
            ],
        };
    }

    protected function goalProgressMinutes(ListeningGoal $goal, Carbon $start, Carbon $end): int
    {
        $userId = (string) $goal->user_id;
        $sessions = $this->userSessions($userId)
            ->filter(static fn (object $session): bool => $session->listening_date >= $start->toDateString()
                && $session->listening_date <= $end->toDateString());

        switch ($goal->metric) {
            case 'genre_hours':
                if ($goal->genre_id === null) {
                    return 0;
                }

                $bookIds = DB::table('book_genre')->where('genre_id', $goal->genre_id)->pluck('book_id');
                $sessions = $sessions->whereIn('book_id', $bookIds);
                break;
            case 'playlist_hours':
                if ($goal->playlist_id === null) {
                    return 0;
                }

                $bookIds = DB::table('user_book_status')
                    ->where('user_id', $goal->user_id)
                    ->where('playlist_id', $goal->playlist_id)
                    ->pluck('book_id');
                $sessions = $sessions->whereIn('book_id', $bookIds);
                break;
            case 'fiction_hours':
                $bookIds = DB::table('book_genre')
                    ->join('genres', 'genres.id', '=', 'book_genre.genre_id')
                    ->where('genres.is_fiction', true)
                    ->pluck('book_genre.book_id');
                $sessions = $sessions->whereIn('book_id', $bookIds);
                break;
            case 'nonfiction_hours':
                $bookIds = DB::table('book_genre')
                    ->join('genres', 'genres.id', '=', 'book_genre.genre_id')
                    ->where('genres.is_fiction', false)
                    ->pluck('book_genre.book_id');
                $sessions = $sessions->whereIn('book_id', $bookIds);
                break;
            default:
                break;
        }

        return (int) floor($sessions->sum('seconds_listened') / 60);
    }

    protected function getBookSeriesIds(Collection $bookIds): Collection
    {
        if ($bookIds->isEmpty()) {
            return collect();
        }

        return DB::table('books')
            ->whereIn('id', $bookIds)
            ->whereNotNull('series_id')
            ->pluck('series_id')
            ->merge(
                DB::table('book_series')
                    ->whereIn('book_id', $bookIds)
                    ->pluck('series_id')
            )
            ->filter()
            ->unique()
            ->values();
    }

    protected function getSeriesBookIds(int $seriesId): Collection
    {
        return DB::table('books')
            ->where('series_id', $seriesId)
            ->pluck('id')
            ->merge(
                DB::table('book_series')
                    ->where('series_id', $seriesId)
                    ->pluck('book_id')
            )
            ->filter()
            ->map(static fn ($bookId): int => (int) $bookId)
            ->unique()
            ->values();
    }

    /**
     * Get action counts from client_events for action-based badge criteria
     *
     * @return array<string, int>
     */
    protected function getActionCounts(string $userId, ?string $deviceId = null): array
    {
        $eventTypes = [
            'app_installed',
            'app_installed_android',
            'app_installed_ios',
            'app_installed_desktop',
            'book_downloaded',
            'book_started',
            'skin_changed',
            'gallery_skin_downloaded',
            'theme_changed',
            'drive_mode_activated',
            'bookmark_created',
        ];

        $query = ClientEvent::where(function ($q) use ($userId, $deviceId) {
            $q->where('user_id', $userId);
            if ($deviceId) {
                $q->orWhere('device_id', $deviceId);
            }
        })->whereIn('event_type', $eventTypes);

        $counts = (clone $query)->select('event_type', DB::raw('COUNT(*) as cnt'))
            ->groupBy('event_type')
            ->pluck('cnt', 'event_type')
            ->toArray();

        $result = [];
        foreach ($eventTypes as $type) {
            $result['action_' . $type] = (int) ($counts[$type] ?? 0);
        }

        return $result;
    }

    /**
     * Check if we should evaluate a badge for a user
     */
    protected function shouldEvaluateBadge(Badge $badge, string $userId, ?string $deviceId, array $userStats): bool
    {
        // Skip if badge is not active
        if (! $badge->is_active) {
            return false;
        }

        // Skip if user already has this badge and it's not repeatable
        if (! $badge->is_repeatable && $badge->hasBeenEarnedByUser($userId, $deviceId)) {
            return false;
        }

        return true;
    }

    /**
     * Award a badge to a user
     */
    protected function awardBadge(Badge $badge, string $userId, ?string $deviceId, array $userStats): ?UserBadge
    {
        try {
            $userBadge = UserBadge::awardBadge(
                $badge,
                $userId,
                $deviceId,
                $userStats, // Store the stats that led to earning the badge
                null,
                Carbon::now()
            );

            Log::info('Badge awarded', [
                'badge_id'   => $badge->id,
                'badge_name' => $badge->name,
                'user_id'    => $userId,
                'device_id'  => $deviceId,
                'tier_level' => $userBadge->tier_level,
            ]);

            // Create a message for the user about the new badge
            $this->createBadgeMessage($badge, $userId);

            // Clear user stats cache since they've earned a new badge
            $cacheKey = "user_stats_{$userId}" . ($deviceId ? "_{$deviceId}" : '');
            Cache::forget($cacheKey);

            return $userBadge;
        } catch (\Exception $e) {
            Log::error('Error awarding badge', [
                'badge_id'   => $badge->id,
                'badge_name' => $badge->name,
                'user_id'    => $userId,
                'device_id'  => $deviceId,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get badges available to a user with progress information
     */
    public function getUserBadgeProgress(string $userId, ?string $deviceId = null): array
    {
        $userStats      = $this->getUserListeningStatistics($userId, $deviceId);
        $earnedBadges   = UserBadge::getUserBadgesWithDetails($userId, $deviceId);
        $earnedBadgeIds = $earnedBadges->pluck('badge_id')->toArray();

        $availableBadges = Badge::active()->ordered()->get();
        $badgeProgress   = [];

        foreach ($availableBadges as $badge) {
            $hasEarned = in_array($badge->id, $earnedBadgeIds);
            $progress  = $badge->getProgressPercentage($userStats);

            $badgeProgress[] = [
                'badge'          => $badge,
                'earned'         => $hasEarned,
                'progress'       => $progress,
                'times_earned'   => $badge->getTimesEarnedByUser($userId, $deviceId),
                'can_earn_again' => $badge->is_repeatable || ! $hasEarned,
            ];
        }

        return $badgeProgress;
    }

    /**
     * Get user's badge summary statistics
     */
    public function getUserBadgeSummary(string $userId, ?string $deviceId = null): array
    {
        return UserBadge::getUserBadgeStats($userId, $deviceId);
    }

    /**
     * Get recent badge notifications for a user
     */
    public function getUnnotifiedBadges(string $userId, ?string $deviceId = null): array
    {
        return UserBadge::with(['badge'])
            ->forUserOrDevice($userId, $deviceId)
            ->unnotified()
            ->newest()
            ->get()
            ->toArray();
    }

    /**
     * Mark badges as notified
     */
    public function markBadgesAsNotified(array $badgeIds, string $userId, ?string $deviceId = null): void
    {
        UserBadge::forUserOrDevice($userId, $deviceId)
            ->whereIn('badge_id', $badgeIds)
            ->unnotified()
            ->update(['is_notified' => true]);
    }

    /**
     * Create a message for the user when a badge is awarded
     */
    protected function createBadgeMessage(Badge $badge, string $userId): void
    {
        try {
            // Only create messages for authenticated users (numeric user IDs)
            if (! is_numeric($userId)) {
                return;
            }

            Message::create([
                'sender_id'    => null,
                'recipient_id' => (int) $userId,
                'type'         => 'badge_earned',
                'content'      => "You earned the \"{$badge->name}\" badge! {$badge->description}",
                'payload' => [
                    'badge_id'       => $badge->id,
                    'badge_key'      => $badge->key,
                    'badge_name'     => $badge->name,
                    'badge_icon'     => $badge->icon,
                    'badge_tier'     => $badge->tier,
                    'badge_points'   => $badge->points,
                    'badge_category' => $badge->category,
                ],
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to create badge message', [
                'badge_id' => $badge->id,
                'user_id'  => $userId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    protected function clearUserStatsCache(string $userId, ?string $deviceId = null): void
    {
        Cache::forget("user_stats_{$userId}");
        $this->listeningActivityService->clearCache($userId);

        if ($deviceId !== null) {
            Cache::forget("user_stats_{$userId}_{$deviceId}");
        }
    }
}
