<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $shelf_key
 * @property string $title
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $computed_at
 * @property-read \App\Models\User $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RecommendationShelfBook> $shelfBooks
 */
class RecommendationShelf extends Model
{
    protected $fillable = [
        'user_id',
        'shelf_key',
        'title',
        'sort_order',
        'computed_at',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'computed_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<RecommendationShelfBook, $this> */
    public function shelfBooks(): HasMany
    {
        return $this->hasMany(RecommendationShelfBook::class, 'shelf_id')->orderBy('rank');
    }
}
