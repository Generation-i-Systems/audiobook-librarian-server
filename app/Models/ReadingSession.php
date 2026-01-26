<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property int $book_id
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 * @property int $duration_seconds
 * @property int|null $pages
 * @property int|null $position_start
 * @property int|null $position_end
 * @property string|null $device
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class ReadingSession extends Model
{
    protected $fillable = [
        'user_id',
        'book_id',
        'started_at',
        'ended_at',
        'duration_seconds',
        'pages',
        'position_start',
        'position_end',
        'device',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'duration_seconds' => 'integer',
        'pages' => 'integer',
        'position_start' => 'integer',
        'position_end' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function book()
    {
        return $this->belongsTo(Book::class);
    }
}
