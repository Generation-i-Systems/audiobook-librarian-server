<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $author_name
 * @property bool $notify_email
 * @property \Illuminate\Support\Carbon|null $last_notification_sent_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FavoriteAuthor newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FavoriteAuthor newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FavoriteAuthor query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FavoriteAuthor whereAuthorName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FavoriteAuthor whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FavoriteAuthor whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FavoriteAuthor whereLastNotificationSentAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FavoriteAuthor whereNotifyEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FavoriteAuthor whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FavoriteAuthor whereUserId($value)
 * @mixin \Eloquent
 */
class FavoriteAuthor extends Model
{
    protected $fillable = [
        'user_id',
        'author_name',
        'notify_email',
        'last_notification_sent_at',
    ];

    protected $casts = [
        'notify_email' => 'boolean',
        'last_notification_sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
