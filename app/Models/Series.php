<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\CamelCaseAttributeAccess;
use App\Traits\Auditable;

/**
 * @property int $id
 * @property string $name
 * @property bool $is_collection
 * @property bool $isFavorite
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Book> $books
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Book> $books_in_series
 * @property \Illuminate\Database\Eloquent\Collection<int, \App\Models\Author>|array $authors
 * @property-read \Illuminate\Database\Eloquent\Relations\Pivot|null $pivot
 * @property-read int|null $book_count
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read int|null $books_count
 * @method static \Database\Factories\SeriesFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Series newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Series newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Series onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Series query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Series whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Series whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Series whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Series whereIsCollection($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Series whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Series whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Series withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Series withoutTrashed()
 * @mixin \Illuminate\Database\Eloquent\Model
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $favoritedByUsers
 */
class Series extends Model
{
    use HasFactory;
    use CamelCaseAttributeAccess;
    use Auditable;
    use SoftDeletes;

    protected $fillable = ['name', 'is_collection'];

    protected $casts = [
        'is_collection' => 'boolean',
    ];

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \Database\Factories\SeriesFactory::new();
    }

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_series')
            ->withPivot('series_number')
            ->withTimestamps();
    }

    public function favoritedByUsers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_series_favorites');
    }
}
