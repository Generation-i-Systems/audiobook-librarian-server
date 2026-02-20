<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookPosition extends Model
{
    protected $table = 'book_positions';

    public $incrementing = false;
    protected $primaryKey = null;

    protected function setKeysForSaveQuery($query)
    {
        return $query
            ->where('user_id', $this->getAttribute('user_id'))
            ->where('book_id', $this->getAttribute('book_id'))
            ->where('device_id', $this->getAttribute('device_id'));
    }

    protected $fillable = [
        'user_id',
        'book_id',
        'device_id',
        'position_ms',
        'progress_percentage',
        'current_chapter',
        'current_chapter_name',
        'completed',
        'last_event_timestamp_ms',
        'last_event_id',
    ];

    protected $casts = [
        'position_ms' => 'integer',
        'progress_percentage' => 'decimal:2',
        'current_chapter' => 'integer',
        'completed' => 'boolean',
        'last_event_timestamp_ms' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }

    public function lastEvent(): BelongsTo
    {
        return $this->belongsTo(ListeningEvent::class, 'last_event_id', 'id');
    }
}
