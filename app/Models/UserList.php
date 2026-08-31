<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $string_id
 * @property string $name
 * @property bool $is_default
 * @property int $created_at
 * @property int $updated_at
 * @property-read \App\Models\User $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\UserListItem> $items
 */
class UserList extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'string_id', 'name', 'is_default', 'created_at', 'updated_at'];

    protected $casts = [
        'is_default' => 'boolean',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];

    /** Route-model bind on the client-generated uuid, not the internal auto-increment id. */
    public function getRouteKeyName(): string
    {
        return 'string_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<UserListItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(UserListItem::class);
    }
}
