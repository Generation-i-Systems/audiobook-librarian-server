<?php

namespace App\Services;

use App\Models\Badge;
use App\Models\ListeningStatistic;
use App\Models\Message;
use App\Models\UserBadge;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BadgeService
{
    /**
     * Evaluate and award badges for a given User model.
     */
    public function evaluateBadgesForUser(\App\Models\User $user): array
    {
        return $this->evaluateUserBadges($user->id);
    }

    /**
     * Evaluate and award badges for a user after a listening session
     */
    public function evaluateUserBadges(string $userId, ?string $deviceId = null): array
    {
        $newBadges = [];

        try {
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
     * Build a base query for ListeningStatistic matching a user by user_id, device_id, or deviceId.
     */
    protected function userStatsQuery(string $userId, ?string $deviceId = null): \Illuminate\Database\Eloquent\Builder
    {
        return ListeningStatistic::where(function ($q) use ($userId, $deviceId) {
            $q->where('device_id', $userId)
                ->orWhere('user_id', $userId);
            if ($deviceId) {
                $q->orWhere('device_id', $deviceId);
            }
        });
    }

    /**
     * Get comprehensive listening statistics for a user
     */
    protected function getUserListeningStatistics(string $userId, ?string $deviceId = null): array
    {
        $cacheKey = "user_stats_{$userId}" . ($deviceId ? "_{$deviceId}" : '');

        return Cache::remember($cacheKey, 300, function () use ($userId, $deviceId) {
            $query = $this->userStatsQuery($userId, $deviceId);

            $allTimeStats = $query->selectRaw('
                SUM(seconds_listened) as total_listening_time,
                COUNT(*) as session_count,
                COUNT(DISTINCT book_id) as books_started,
                COUNT(DISTINCT CASE WHEN session_type = "completed" THEN book_id END) as books_completed,
                COUNT(DISTINCT listening_date) as total_listening_days,
                MIN(listening_date) as first_listening_date,
                MAX(listening_date) as last_listening_date,
                MAX(seconds_listened) as longest_session
            ')->first();

            // Calculate listening streak
            $currentStreak = $this->calculateCurrentStreak($userId, $deviceId);
            $longestStreak = $this->calculateLongestStreak($userId, $deviceId);

            // Get genre and author variety
            $genresExplored    = $this->getGenresExplored($userId, $deviceId);
            $authorsExplored   = $this->getAuthorsExplored($userId, $deviceId);
            $narratorsExplored = $this->getNarratorsExplored($userId, $deviceId);

            // Get weekend listening sessions
            $weekendSessions = $this->getWeekendSessions($userId, $deviceId);

            // Get recent completion stats
            $booksCompletedThisMonth = $this->getBooksCompletedInTimeframe($userId, $deviceId, 30);
            $booksCompletedThisWeek  = $this->getBooksCompletedInTimeframe($userId, $deviceId, 7);

            // Get series completion count
            $seriesCompleted = $this->getSeriesCompleted($userId, $deviceId);

            // Get additional statistics for expanded badge categories
            $bookmarksCreated  = $this->getBookmarksCreated($userId, $deviceId);
            $booksReviewed     = $this->getBooksReviewed($userId, $deviceId);
            $librarySize       = $this->getLibrarySize($userId, $deviceId);
            $completionRate    = $this->getCompletionRate($userId, $deviceId);
            $chapterCompletion = $this->getChapterCompletion($userId, $deviceId);
            $deviceVariety     = $this->getDeviceVariety($userId, $deviceId);
            $repeatListening   = $this->getRepeatListening($userId, $deviceId);

            return [
                'total_listening_time'       => (int) ($allTimeStats->total_listening_time ?? 0),
                'session_count'              => (int) ($allTimeStats->session_count ?? 0),
                'books_started'              => (int) ($allTimeStats->books_started ?? 0),
                'books_completed'            => (int) ($allTimeStats->books_completed ?? 0),
                'total_listening_days'       => (int) ($allTimeStats->total_listening_days ?? 0),
                'longest_session'            => (int) ($allTimeStats->longest_session ?? 0),
                'current_streak'             => $currentStreak,
                'longest_streak'             => $longestStreak,
                'genres_explored'            => $genresExplored,
                'authors_explored'           => $authorsExplored,
                'narrator_variety'           => $narratorsExplored,
                'weekend_listening'          => $weekendSessions,
                'books_completed_this_month' => $booksCompletedThisMonth,
                'books_completed_this_week'  => $booksCompletedThisWeek,
                'series_completion'          => $seriesCompleted,
                'bookmarks_created'          => $bookmarksCreated,
                'books_reviewed'             => $booksReviewed,
                'library_size'               => $librarySize,
                'completion_rate'            => $completionRate,
                'chapter_completion'         => $chapterCompletion,
                'device_variety'             => $deviceVariety,
                'repeat_listening'           => $repeatListening,
                'first_listening_date'       => $allTimeStats->getAttribute('first_listening_date'),
                'last_listening_date'        => $allTimeStats->getAttribute('last_listening_date'),
            ];
        });
    }

    /**
     * Calculate current listening streak
     */
    protected function calculateCurrentStreak(string $userId, ?string $deviceId = null): int
    {
        $streak      = 0;
        $currentDate = Carbon::now();

        while (true) {
            $hasActivity = $this->userStatsQuery($userId, $deviceId)
                ->where('listening_date', $currentDate->toDateString())->exists();

            if (! $hasActivity) {
                break;
            }

            $streak++;
            $currentDate->subDay();

            // Prevent infinite loops
            if ($streak > 365) {
                break;
            }
        }

        return $streak;
    }

    /**
     * Calculate longest listening streak
     */
    protected function calculateLongestStreak(string $userId, ?string $deviceId = null): int
    {
        $dates = $this->userStatsQuery($userId, $deviceId)->select('listening_date')
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
     * Get number of unique genres explored
     */
    protected function getGenresExplored(string $userId, ?string $deviceId = null): int
    {
        return $this->userStatsQuery($userId, $deviceId)
            ->join('books', 'listening_statistics.book_id', '=', 'books.id')
            ->join('book_genre', 'books.id', '=', 'book_genre.book_id')
            ->distinct('book_genre.genre_id')
            ->count();
    }

    /**
     * Get number of unique authors explored
     */
    protected function getAuthorsExplored(string $userId, ?string $deviceId = null): int
    {
        return $this->userStatsQuery($userId, $deviceId)
            ->join('books', 'listening_statistics.book_id', '=', 'books.id')
            ->join('author_book', 'books.id', '=', 'author_book.book_id')
            ->distinct('author_book.author_id')
            ->count();
    }

    /**
     * Get number of unique narrators explored
     */
    protected function getNarratorsExplored(string $userId, ?string $deviceId = null): int
    {
        $query = $this->userStatsQuery($userId, $deviceId);

        return $query->join('books', 'listening_statistics.book_id', '=', 'books.id')
            ->join('book_narrator', 'books.id', '=', 'book_narrator.book_id')
            ->distinct('book_narrator.narrator_id')
            ->count();
    }

    /**
     * Get weekend listening sessions count
     */
    protected function getWeekendSessions(string $userId, ?string $deviceId = null): int
    {
        $query = $this->userStatsQuery($userId, $deviceId);

        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            return $query->whereRaw("CAST(strftime('%w', listening_date) AS INTEGER) IN (0, 6)")
                ->count();
        }

        return $query->whereRaw('DAYOFWEEK(listening_date) IN (1, 7)')
            ->count();
    }

    /**
     * Get books completed in timeframe
     */
    protected function getBooksCompletedInTimeframe(string $userId, ?string $deviceId, int $days): int
    {
        $query = $this->userStatsQuery($userId, $deviceId);

        $startDate = Carbon::now()->subDays($days);

        return $query->where('session_type', 'completed')
            ->where('listening_date', '>=', $startDate)
            ->distinct('book_id')
            ->count();
    }

    /**
     * Get series completion count - counts series where all books have been completed
     */
    protected function getSeriesCompleted(string $userId, ?string $deviceId = null): int
    {
        try {
            // Find series where every book has a "completed" session for this user
            $completedBookIds = ListeningStatistic::where(function ($q) use ($userId, $deviceId) {
                $q->where('device_id', $userId);
                if ($deviceId) {
                    $q->orWhere('device_id', $deviceId);
                }
            })
                ->where('session_type', 'completed')
                ->distinct('book_id')
                ->pluck('book_id');

            if ($completedBookIds->isEmpty()) {
                return 0;
            }

            // Get all series that have at least one completed book
            $seriesWithCompleted = DB::table('books')
                ->whereIn('id', $completedBookIds)
                ->whereNotNull('series_id')
                ->select('series_id')
                ->distinct()
                ->pluck('series_id');

            $completedSeries = 0;
            foreach ($seriesWithCompleted as $seriesId) {
                $totalBooksInSeries = DB::table('books')->where('series_id', $seriesId)->count();
                $completedInSeries  = DB::table('books')
                    ->where('series_id', $seriesId)
                    ->whereIn('id', $completedBookIds)
                    ->count();

                if ($totalBooksInSeries > 0 && $completedInSeries >= $totalBooksInSeries) {
                    $completedSeries++;
                }
            }

            return $completedSeries;
        } catch (\Exception $e) {
            Log::warning('Failed to calculate series completion', ['error' => $e->getMessage()]);
            return 0;
        }
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
        // This would depend on your review system implementation
        // For now, return 0 as placeholder
        return 0;
    }

    /**
     * Get user's library size (books they own/have access to)
     */
    protected function getLibrarySize(string $userId, ?string $deviceId = null): int
    {
        return DB::table('book_user')->where('user_id', $userId)->count();
    }

    /**
     * Get completion rate percentage
     */
    protected function getCompletionRate(string $userId, ?string $deviceId = null): int
    {
        $query = $this->userStatsQuery($userId, $deviceId);

        $totalBooks     = $query->distinct('book_id')->count();
        $completedBooks = $query->where('session_type', 'completed')->distinct('book_id')->count();

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
        $query = $this->userStatsQuery($userId, $deviceId);

        // Rough estimate: assume average session covers 1-2 chapters
        return (int) ($query->count() * 1.5);
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
        $query = $this->userStatsQuery($userId, $deviceId);

        // Count books that have been "completed" more than once
        return $query->where('session_type', 'completed')
            ->select('book_id')
            ->groupBy('book_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
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
}
