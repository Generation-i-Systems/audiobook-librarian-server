<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $abb_id
 * @property string $title
 * @property string $author
 * @property string|null $narrator
 * @property string|null $category
 * @property string|null $url
 * @property string|null $description
 * @property string|null $cover_url
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon $discovered_at
 * @property bool $notified
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscoveredBook byAuthor(string $authorName)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscoveredBook byCategory(string $category)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscoveredBook newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscoveredBook newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscoveredBook notNotified()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscoveredBook query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscoveredBook whereAbbId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscoveredBook whereAuthor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscoveredBook whereCategory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscoveredBook whereCoverUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscoveredBook whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscoveredBook whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscoveredBook whereDiscoveredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscoveredBook whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscoveredBook whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscoveredBook whereNarrator($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscoveredBook whereNotified($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscoveredBook whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscoveredBook whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscoveredBook whereUrl($value)
 * @mixin \Eloquent
 */
class DiscoveredBook extends Model
{
    protected $fillable = [
        'abb_id',
        'title',
        'author',
        'narrator',
        'category',
        'url',
        'description',
        'cover_url',
        'metadata',
        'discovered_at',
        'notified',
    ];

    protected $casts = [
        'metadata' => 'array',
        'discovered_at' => 'datetime',
        'notified' => 'boolean',
    ];

    public function scopeNotNotified($query)
    {
        return $query->where('notified', false);
    }

    public function scopeByAuthor($query, string $authorName)
    {
        return $query->where('author', 'LIKE', '%' . $authorName . '%');
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}
