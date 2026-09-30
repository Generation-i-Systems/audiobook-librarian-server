<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A user's chosen delivery (show, badge, off) for one notification type.
 *
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string $delivery
 */
class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'delivery',
    ];
}
