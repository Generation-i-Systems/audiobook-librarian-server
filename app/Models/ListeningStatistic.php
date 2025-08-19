<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\CamelCaseAttributeAccess;

class ListeningStatistic extends Model
{
    use CamelCaseAttributeAccess;

    protected $fillable = [
        'book_id',
        'user_id',
        'device_id',
        'listening_date',
        'seconds_listened',
        'session_start',
        'session_end',
        'start_position_seconds',
        'end_position_seconds',
        'session_type',
        'metadata',
    ];

    protected $casts = [
        'listening_date' => 'date',
        'seconds_listened' => 'integer',
        'session_start' => 'datetime',
        'session_end' => 'datetime',
        'start_position_seconds' => 'integer',
        'end_position_seconds' => 'integer',
        'metadata' => 'array',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * Get formatted duration for this session
     */
    public function getFormattedDurationAttribute(): string
    {
        $seconds = $this->seconds_listened;
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%d:%02d', $minutes, $secs);
    }

    /**
     * Create a new listening session
     */
    public static function createSession(
        int $bookId,
        string $deviceId,
        int $secondsListened,
        ?int $startPosition = null,
        ?int $endPosition = null,
        string $sessionType = 'listening',
        array $metadata = [],
        ?string $userId = null
    ): self {
        return self::create([
            'book_id' => $bookId,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'listening_date' => now()->toDateString(),
            'seconds_listened' => $secondsListened,
            'session_start' => now()->subSeconds($secondsListened),
            'session_end' => now(),
            'start_position_seconds' => $startPosition,
            'end_position_seconds' => $endPosition,
            'session_type' => $sessionType,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Get daily listening statistics for a device
     */
    public static function getDailyStats(string $deviceId, ?string $date = null): array
    {
        $date = $date ?: now()->toDateString();

        $stats = self::where('device_id', $deviceId)
            ->whereDate('listening_date', $date)
            ->selectRaw('
                SUM(seconds_listened) as total_seconds,
                COUNT(DISTINCT book_id) as books_listened,
                COUNT(*) as session_count,
                MIN(session_start) as first_session,
                MAX(session_end) as last_session
            ')
            ->first();

        return [
            'date' => $date,
            'total_seconds' => $stats->total_seconds ?? 0,
            'books_listened' => $stats->books_listened ?? 0,
            'session_count' => $stats->session_count ?? 0,
            'first_session' => $stats->first_session,
            'last_session' => $stats->last_session,
            'formatted_duration' => self::formatSeconds($stats->total_seconds ?? 0),
        ];
    }

    /**
     * Get book listening statistics
     */
    public static function getBookStats(int $bookId, ?string $deviceId = null): array
    {
        $query = self::where('book_id', $bookId);

        if ($deviceId) {
            $query->where('device_id', $deviceId);
        }

        $stats = $query->selectRaw('
                SUM(seconds_listened) as total_seconds,
                COUNT(*) as session_count,
                MIN(listening_date) as first_listened,
                MAX(listening_date) as last_listened,
                MIN(session_start) as first_session,
                MAX(session_end) as last_session
            ')
            ->first();

        return [
            'book_id' => $bookId,
            'total_seconds' => $stats->total_seconds ?? 0,
            'session_count' => $stats->session_count ?? 0,
            'first_listened' => $stats->first_listened,
            'last_listened' => $stats->last_listened,
            'first_session' => $stats->first_session,
            'last_session' => $stats->last_session,
            'formatted_duration' => self::formatSeconds($stats->total_seconds ?? 0),
        ];
    }

    /**
     * Format seconds into human readable format
     */
    protected static function formatSeconds(int $seconds): string
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
