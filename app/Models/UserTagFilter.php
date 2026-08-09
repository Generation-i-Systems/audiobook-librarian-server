<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $tag
 * @property string $mode 'require' | 'ban'
 * @property bool $locked_by_admin
 * @property-read \App\Models\User $user
 */
class UserTagFilter extends Model
{
    public const MODE_REQUIRE = 'require';
    public const MODE_BAN = 'ban';

    protected $fillable = [
        'user_id',
        'tag',
        'mode',
        'locked_by_admin',
    ];

    protected $casts = [
        'locked_by_admin' => 'boolean',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
