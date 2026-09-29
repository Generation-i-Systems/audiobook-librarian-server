<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a user's community notification list. Clients pick these up during
 * sync (there is no remote push) and raise local notifications for `delivery = show`.
 *
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string $delivery
 * @property int|null $actor_id
 * @property array<string, mixed>|null $payload
 * @property \Illuminate\Support\Carbon|null $read_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read User|null $actor
 */
class UserNotification extends Model
{
    public const DELIVERY_SHOW = 'show';
    public const DELIVERY_BADGE = 'badge';
    public const DELIVERY_OFF = 'off';
    public const DELIVERIES = [self::DELIVERY_SHOW, self::DELIVERY_BADGE, self::DELIVERY_OFF];

    public const TYPE_RECOMMENDATION = 'recommendation';
    public const TYPE_RECOMMENDATION_REACTION = 'recommendation_reaction';
    public const TYPE_RECOMMENDATION_REPLY = 'recommendation_reply';

    protected $fillable = [
        'user_id',
        'type',
        'delivery',
        'actor_id',
        'payload',
        'read_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
