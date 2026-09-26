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
 * @property string $scope 'user' | 'system'
 * @property string $owner_key "user:{userId}" | "account:{accountRootId}"
 * @property-read \App\Models\User $user
 */
class UserTagFilter extends Model
{
    public const MODE_REQUIRE = 'require';
    public const MODE_BAN = 'ban';

    public const SCOPE_USER = 'user';
    public const SCOPE_SYSTEM = 'system';

    protected $fillable = [
        'user_id',
        'tag',
        'mode',
        'scope',
        'owner_key',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSystemScope(): bool
    {
        return $this->scope === self::SCOPE_SYSTEM;
    }

    protected static function booted(): void
    {
        static::saving(function (UserTagFilter $filter): void {
            if (!$filter->scope) {
                $filter->scope = self::SCOPE_USER;
            }
            if (!$filter->owner_key) {
                $filter->owner_key = $filter->scope === self::SCOPE_SYSTEM
                    ? 'account:' . $filter->user_id
                    : 'user:' . $filter->user_id;
            }
        });
    }
}
