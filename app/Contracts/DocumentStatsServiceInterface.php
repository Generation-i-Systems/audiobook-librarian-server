<?php

namespace App\Contracts;

interface DocumentStatsServiceInterface
{
    /**
     * Record a reading session for a user and book.
     *
     * @param string $userId
     * @param string $bookId
     * @param array $data { started_at?: datetime|string, ended_at?: datetime|string, duration_seconds?: int, pages?: int|null, position_start?: int|null, position_end?: int|null, device?: string|null }
     * @return array Created session as array
     */
    public function recordReadingSession(string $userId, string $bookId, array $data): array;

    /**
     * Get daily aggregated stats for a user within an optional date range.
     *
     * @param string $userId
     * @param string|null $from ISO8601 date (inclusive)
     * @param string|null $to ISO8601 date (inclusive)
     * @return array [ { date: Y-m-d, duration_seconds: int, sessions: int, books: int } ]
     */
    public function getDailyStats(string $userId, ?string $from = null, ?string $to = null): array;

    /**
     * Get aggregated stats for a specific book for a user.
     *
     * @param string $userId
     * @param string $bookId
     * @return array { total_duration_seconds: int, sessions: int, first_started_at?: string|null, last_ended_at?: string|null }
     */
    public function getBookStats(string $userId, string $bookId): array;

    /**
     * Get overall user stats.
     *
     * @param string $userId
     * @return array { total_duration_seconds: int, sessions: int, active_days: int, streak_current: int, streak_longest: int }
     */
    public function getUserStats(string $userId): array;

    /**
     * Get reading streak details.
     *
     * @param string $userId
     * @return array { current: int, longest: int, last_active_date?: string|null }
     */
    public function getStreaks(string $userId): array;
}
