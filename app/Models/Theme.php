<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\CamelCaseAttributeAccess;

/**
 * @property int $id
 * @property string $name
 * @property string $author
 * @property string $version
 * @property string|null $description
 * @property int $user_id
 * @property int|null $forked_from_id
 * @property array $theme_data
 * @property bool $is_public
 * @property int $download_count
 * @property float $average_rating
 * @property int $rating_count
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Theme|null $forkedFrom
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Theme> $forks
 * @property-read int|null $forks_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ThemeRating> $ratings
 * @property-read int|null $ratings_count
 * @property-read \App\Models\User $user
 * @method static Builder<static>|Theme newModelQuery()
 * @method static Builder<static>|Theme newQuery()
 * @method static Builder<static>|Theme onlyTrashed()
 * @method static Builder<static>|Theme popular()
 * @method static Builder<static>|Theme public()
 * @method static Builder<static>|Theme query()
 * @method static Builder<static>|Theme recent()
 * @method static Builder<static>|Theme topRated()
 * @method static Builder<static>|Theme whereAuthor($value)
 * @method static Builder<static>|Theme whereAverageRating($value)
 * @method static Builder<static>|Theme whereCreatedAt($value)
 * @method static Builder<static>|Theme whereDeletedAt($value)
 * @method static Builder<static>|Theme whereDescription($value)
 * @method static Builder<static>|Theme whereDownloadCount($value)
 * @method static Builder<static>|Theme whereForkedFromId($value)
 * @method static Builder<static>|Theme whereId($value)
 * @method static Builder<static>|Theme whereIsPublic($value)
 * @method static Builder<static>|Theme whereName($value)
 * @method static Builder<static>|Theme whereRatingCount($value)
 * @method static Builder<static>|Theme whereThemeData($value)
 * @method static Builder<static>|Theme whereUpdatedAt($value)
 * @method static Builder<static>|Theme whereUserId($value)
 * @method static Builder<static>|Theme whereVersion($value)
 * @method static Builder<static>|Theme withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Theme withoutTrashed()
 * @mixin \Eloquent
 */
class Theme extends Model
{
    use HasFactory;
    use CamelCaseAttributeAccess;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'author',
        'version',
        'description',
        'user_id',
        'forked_from_id',
        'theme_data',
        'is_public',
        'download_count',
        'average_rating',
        'rating_count',
    ];

    protected $casts = [
        'theme_data' => 'array',
        'is_public' => 'boolean',
        'download_count' => 'integer',
        'rating_count' => 'integer',
        'average_rating' => 'decimal:2',
    ];

    protected $appends = [
        'download_url',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function forkedFrom(): BelongsTo
    {
        return $this->belongsTo(Theme::class, 'forked_from_id');
    }

    public function forks(): HasMany
    {
        return $this->hasMany(Theme::class, 'forked_from_id');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(ThemeRating::class);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function scopePopular(Builder $query): Builder
    {
        return $query->orderBy('download_count', 'desc');
    }

    public function scopeTopRated(Builder $query): Builder
    {
        return $query->where('rating_count', '>', 0)
            ->orderBy('average_rating', 'desc');
    }

    public function incrementDownloadCount(): void
    {
        $this->increment('download_count');
    }

    public function updateRating(): void
    {
        $averageRating = $this->ratings()->avg('rating');
        $ratingCount = $this->ratings()->count();

        $this->update([
            'average_rating' => $averageRating ?? 0,
            'rating_count' => $ratingCount,
        ]);
    }

    public function getDownloadUrlAttribute(): string
    {
        return route('api.v1.themes.download', $this->id);
    }
}
