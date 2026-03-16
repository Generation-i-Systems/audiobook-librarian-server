<?php

namespace App\Models;

use App\Traits\CamelCaseAttributeAccess;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $user_id
 * @property int|null $playlist_id
 * @property int|null $book_id
 * @property string|null $title
 * @property string|null $author
 * @property int|null $order
 * @property string $status
 * @property array|null $status_detail
 * @property int $read_count
 * @property \Illuminate\Support\Carbon|null $target_date
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @property-read \App\Models\Book|null $book
 * @property-read \App\Models\User $user
 * @method static \Database\Factories\UserBookStatusFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBookStatus newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBookStatus newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBookStatus query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBookStatus whereBookId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBookStatus whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBookStatus whereFinishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBookStatus whereOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBookStatus whereReadCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBookStatus whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBookStatus whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBookStatus whereStatusDetail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBookStatus whereTargetDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBookStatus whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBookStatus whereUserId($value)
 * @mixin \Eloquent
 */
class UserBookStatus extends Model
{
    use CamelCaseAttributeAccess;
    use HasFactory;

    protected $table = 'user_book_status';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'playlist_id',
        'book_id',
        'title',
        'author',
        'order',
        'status',
        'status_detail',
        'read_count',
        'target_date',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'playlist_id' => 'integer',
        'order' => 'integer',
        'status_detail' => 'array',
        'read_count' => 'integer',
        'target_date' => 'date',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }
}
