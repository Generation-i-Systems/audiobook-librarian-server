<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $language
 * @property string $sync_type
 * @property \Carbon\Carbon $started_at
 * @property \Carbon\Carbon|null $completed_at
 * @property int|null $since_timestamp
 * @property int $books_imported
 * @property int $books_failed
 * @property string $status
 * @property string|null $error_message
 */
class LibriVoxSyncLog extends Model
{
    protected $table = 'librivox_sync_logs';

    protected $fillable = [
        'language',
        'sync_type',
        'started_at',
        'completed_at',
        'since_timestamp',
        'books_imported',
        'books_failed',
        'status',
        'pid',
        'error_message',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public static function lastCompleted(string $language): ?self
    {
        return self::where('language', $language)
            ->where('status', 'completed')
            ->latest('completed_at')
            ->first();
    }

    public static function hasSynced(string $language): bool
    {
        return self::where('language', $language)
            ->where('status', 'completed')
            ->exists();
    }
}
