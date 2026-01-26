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
