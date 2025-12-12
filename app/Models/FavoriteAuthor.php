<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
