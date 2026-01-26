<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property int $book_id
 * @property string $device_id
 * @property \Illuminate\Support\Carbon $start_time
 * @property \Illuminate\Support\Carbon $end_time
 * @property int $duration_seconds
 * @property int $position_start
 * @property int $position_end
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @property-read \App\Models\Book|null $book
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AudioSession newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AudioSession newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AudioSession query()
 * @mixin \Eloquent
 */
class AudioSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'book_id',
        'device_id',
        'start_time',
        'end_time',
        'duration_seconds',
        'position_start',
        'position_end',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'duration_seconds' => 'integer',
        'position_start' => 'integer',
        'position_end' => 'integer',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function book(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
