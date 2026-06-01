<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $user_id
 * @property int $book_id
 * @property string $event_type
 * @property int $timestamp_ms
 * @property int $position_ms
 * @property array<string, mixed>|null $metadata
 * @property string $device_id
 * @property string $timezone
 * @property string $sync_status
 * @property int $created_at
 * @property int|null $synced_at
 * @property string|null $migrated_from
 * @property string|null $migration_source_id
 */
class ListeningEvent extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'listening_events';

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The data type of the auto-incrementing ID.
     */
    protected $keyType = 'string';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'id',
        'user_id',
        'book_id',
        'event_type',
        'timestamp_ms',
        'position_ms',
        'metadata',
        'device_id',
        'timezone',
        'sync_status',
        'created_at',
        'synced_at',
        'migrated_from',
        'migration_source_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'metadata' => 'array',
        'timestamp_ms' => 'integer',
        'position_ms' => 'integer',
        'created_at' => 'integer',
        'synced_at' => 'integer',
    ];

    /**
     * Get the user that owns the event.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the book that this event relates to.
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
