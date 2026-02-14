<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
