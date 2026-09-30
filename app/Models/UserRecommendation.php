<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\CamelCaseAttributeAccess;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $sender_id
 * @property int $recipient_id
 * @property int $book_id
 * @property string|null $message
 * @property \Illuminate\Support\Carbon|null $acknowledged_at
 * @property string|null $batch_id
 * @property string $audience_type
 * @property int|null $group_id
 * @property int|null $start_position_ms
 * @property \Illuminate\Support\Carbon|null $seen_at
 * @property \Illuminate\Support\Carbon|null $dismissed_at
 * @property string|null $reaction
 * @property string|null $reply
 * @property \Illuminate\Support\Carbon|null $responded_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @property-read \App\Models\Book|null $book
 * @property-read \App\Models\Group|null $group
 * @property-read \App\Models\User|null $recipient
 * @property-read \App\Models\User|null $sender
 * @method static \Database\Factories\UserRecommendationFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRecommendation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRecommendation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRecommendation query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRecommendation whereAcknowledgedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRecommendation whereBookId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRecommendation whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRecommendation whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRecommendation whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRecommendation whereRecipientId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRecommendation whereSenderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRecommendation whereUpdatedAt($value)
 * @mixin \Illuminate\Database\Eloquent\Model
 * @mixin \Eloquent
 */
class UserRecommendation extends Model
{
    use CamelCaseAttributeAccess;
    use HasFactory;

    protected $fillable = [
        'sender_id',
        'recipient_id',
        'book_id',
        'message',
        'acknowledged_at',
        'batch_id',
        'audience_type',
        'group_id',
        'start_position_ms',
        'seen_at',
        'dismissed_at',
        'reaction',
        'reply',
        'responded_at',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
        'seen_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'responded_at' => 'datetime',
        'group_id' => 'integer',
        'start_position_ms' => 'integer',
    ];

    public const AUDIENCE_USER = 'user';
    public const AUDIENCE_GROUP = 'group';
    public const AUDIENCE_ALL = 'all';

    public const REACTIONS = ['thanks', 'queued', 'loved', 'not_for_me'];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
