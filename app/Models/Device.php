<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\CamelCaseAttributeAccess;

/**
 * @property string $device_id
 * @property int $user_id
 * @property string $name
 * @property \Illuminate\Support\Carbon|null $last_seen
 * @property bool $sync_enabled
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\BookProgress> $bookProgress
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Bookmark> $bookmarks
 */
class Device extends Model
{
    use CamelCaseAttributeAccess;

    protected $primaryKey = 'device_id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'device_id',
        'user_id',
        'name',
        'last_seen',
        'sync_enabled',
    ];

    protected $casts = [
        'last_seen' => 'datetime',
        'sync_enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bookProgress(): HasMany
    {
        return $this->hasMany(BookProgress::class, 'device_id', 'device_id');
    }

    public function bookmarks(): HasMany
    {
        return $this->hasMany(Bookmark::class, 'device_id', 'device_id');
    }

    public function updateLastSeen(): void
    {
        $this->last_seen = now();
        $this->save();
    }
}
