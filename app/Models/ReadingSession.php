<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $book_id
 * @property string|null $title
 * @property string|null $author
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
 * @property-read \App\Models\Book|null $book
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReadingSession newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReadingSession newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReadingSession query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReadingSession whereBookId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReadingSession whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReadingSession whereDevice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReadingSession whereDurationSeconds($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReadingSession whereEndedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReadingSession whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReadingSession wherePages($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReadingSession wherePositionEnd($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReadingSession wherePositionStart($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReadingSession whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReadingSession whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReadingSession whereUserId($value)
 * @mixin \Eloquent
 */
class ReadingSession extends Model
{
    protected $fillable = [
        'user_id',
        'book_id',
        'title',
        'author',
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

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function book(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
