<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ReadingSession;
use Illuminate\Support\Facades\Log;

class UserReadingStatsService
{
    public function recordReadingSession(string $userId, string $bookId, array $data): array
    {
        try {
            $session = ReadingSession::create([
                'user_id' => $userId,
                'book_id' => $bookId,
                'started_at' => $data['started_at'] ?? now(),
                'ended_at' => $data['ended_at'] ?? now(),
                'duration_seconds' => $data['duration_seconds'] ?? 0,
                'pages' => $data['pages'] ?? null,
                'position_start' => $data['position_start'] ?? null,
                'position_end' => $data['position_end'] ?? null,
                'device' => $data['device'] ?? null,
            ]);

            return $session->toArray();
        } catch (\Exception $e) {
            Log::error('MySqlService recordReadingSession failed: ' . $e->getMessage(), [
                'userId' => $userId,
                'bookId' => $bookId,
            ]);

            throw $e;
        }
    }

    public function getDailyStats(string $userId, ?string $from = null, ?string $to = null): array
    {
        try {
            $query = ReadingSession::where('user_id', $userId);

            if ($from) {
                $query->whereDate('started_at', '>=', $from);
            }

            if ($to) {
                $query->whereDate('started_at', '<=', $to);
            }

            return $query->selectRaw('DATE(started_at) as date, SUM(duration_seconds) as duration_seconds, COUNT(*) as sessions, COUNT(DISTINCT book_id) as books')
                ->whereNotNull('started_at')
                ->groupBy('date')
                ->orderBy('date')
                ->get()
                ->map(function (ReadingSession $row): array {
                    return [
                        'date' => (string) $row->getAttribute('date'),
                        'duration_seconds' => (int) $row->getAttribute('duration_seconds'),
                        'sessions' => (int) $row->getAttribute('sessions'),
                        'books' => (int) $row->getAttribute('books'),
                    ];
                })
                ->toArray();
        } catch (\Exception $e) {
            Log::error('MySqlService getDailyStats failed: ' . $e->getMessage(), [
                'userId' => $userId,
                'from' => $from,
                'to' => $to,
            ]);

            return [];
        }
    }

    public function getBookStats(string $userId, string $bookId): array
    {
        try {
            $stats = ReadingSession::where('user_id', $userId)
                ->where('book_id', $bookId)
                ->selectRaw('SUM(duration_seconds) as total_duration_seconds, COUNT(*) as sessions, MIN(started_at) as first_started_at, MAX(ended_at) as last_ended_at')
                ->first();

            if (!$stats) {
                return $this->emptyBookStats();
            }

            return [
                'total_duration_seconds' => (int) ($stats->getAttribute('total_duration_seconds') ?? 0),
                'sessions' => (int) ($stats->getAttribute('sessions') ?? 0),
                'first_started_at' => $this->formatIso8601DateTime($stats->getAttribute('first_started_at')),
                'last_ended_at' => $this->formatIso8601DateTime($stats->getAttribute('last_ended_at')),
            ];
        } catch (\Exception $e) {
            Log::error('MySqlService getBookStats failed: ' . $e->getMessage(), [
                'userId' => $userId,
                'bookId' => $bookId,
            ]);

            return $this->emptyBookStats();
        }
    }

    public function getUserStats(string $userId): array
    {
        try {
            $stats = ReadingSession::where('user_id', $userId)
                ->selectRaw('SUM(duration_seconds) as total_duration_seconds, COUNT(*) as sessions, COUNT(DISTINCT DATE(started_at)) as active_days')
                ->whereNotNull('started_at')
                ->first();

            $streaks = $this->getStreaks($userId);

            return [
                'total_duration_seconds' => (int) ($stats?->getAttribute('total_duration_seconds') ?? 0),
                'sessions' => (int) ($stats?->getAttribute('sessions') ?? 0),
                'active_days' => (int) ($stats?->getAttribute('active_days') ?? 0),
                'streak_current' => $streaks['current'] ?? 0,
                'streak_longest' => $streaks['longest'] ?? 0,
            ];
        } catch (\Exception $e) {
            Log::error('MySqlService getUserStats failed: ' . $e->getMessage(), [
                'userId' => $userId,
            ]);

            return [
                'total_duration_seconds' => 0,
                'sessions' => 0,
                'active_days' => 0,
                'streak_current' => 0,
                'streak_longest' => 0,
            ];
        }
    }

    public function getStreaks(string $userId): array
    {
        try {
            $dates = ReadingSession::where('user_id', $userId)
                ->selectRaw('DISTINCT DATE(started_at) as reading_date')
                ->whereNotNull('started_at')
                ->orderBy('reading_date', 'desc')
                ->pluck('reading_date')
                ->map(fn ($date) => is_string($date) ? $date : (string) $date)
                ->values()
                ->toArray();

            if (empty($dates)) {
                return [
                    'current' => 0,
                    'longest' => 0,
                    'last_active_date' => null,
                ];
            }

            $lastActiveDate = $dates[0];
            $today = now()->format('Y-m-d');
            $yesterday = now()->subDay()->format('Y-m-d');
            $currentStreak = 0;
            $longestStreak = 0;
            $tempStreak = 0;

            foreach ($dates as $i => $date) {
                if ($i === 0) {
                    $tempStreak = 1;
                } else {
                    $prevDate = $dates[$i - 1];
                    $currentDate = strtotime($date);
                    $prevDateTime = strtotime($prevDate);

                    if ($currentDate === $prevDateTime - 86400) {
                        $tempStreak++;
                    } else {
                        $tempStreak = 1;
                    }
                }

                $longestStreak = max($longestStreak, $tempStreak);
            }

            if ($lastActiveDate === $today || $lastActiveDate === $yesterday) {
                $currentStreak = $tempStreak;
            }

            return [
                'current' => $currentStreak,
                'longest' => $longestStreak,
                'last_active_date' => $dates[0] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('MySqlService getStreaks failed: ' . $e->getMessage(), [
                'userId' => $userId,
            ]);

            return [
                'current' => 0,
                'longest' => 0,
                'last_active_date' => null,
            ];
        }
    }

    private function emptyBookStats(): array
    {
        return [
            'total_duration_seconds' => 0,
            'sessions' => 0,
            'first_started_at' => null,
            'last_ended_at' => null,
        ];
    }

    private function formatIso8601DateTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (is_string($value) && $value !== '') {
            try {
                return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }
}
