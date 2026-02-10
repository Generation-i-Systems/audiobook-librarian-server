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
 * @property string $file_path
 * @property string|null $preview_path
 * @property int $file_size
 * @property array $manifest
 * @property bool $is_public
 * @property int $download_count
 * @property float $average_rating
 * @property int $rating_count
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Skin|null $forkedFrom
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Skin> $forks
 * @property-read int|null $forks_count
 * @property-read string $download_url
 * @property-read string|null $preview_url
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SkinCustomization> $customizations
 * @property-read int|null $customizations_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SkinRating> $ratings
 * @property-read int|null $ratings_count
 * @property-read \App\Models\User $user
 * @method static Builder<static>|Skin newModelQuery()
 * @method static Builder<static>|Skin newQuery()
 * @method static Builder<static>|Skin onlyTrashed()
 * @method static Builder<static>|Skin popular()
 * @method static Builder<static>|Skin public()
 * @method static Builder<static>|Skin query()
 * @method static Builder<static>|Skin recent()
 * @method static Builder<static>|Skin topRated()
 * @method static Builder<static>|Skin whereAuthor($value)
 * @method static Builder<static>|Skin whereAverageRating($value)
 * @method static Builder<static>|Skin whereCreatedAt($value)
 * @method static Builder<static>|Skin whereDeletedAt($value)
 * @method static Builder<static>|Skin whereDescription($value)
 * @method static Builder<static>|Skin whereDownloadCount($value)
 * @method static Builder<static>|Skin whereFilePath($value)
 * @method static Builder<static>|Skin whereFileSize($value)
 * @method static Builder<static>|Skin whereForkedFromId($value)
 * @method static Builder<static>|Skin whereId($value)
 * @method static Builder<static>|Skin whereIsPublic($value)
 * @method static Builder<static>|Skin whereManifest($value)
 * @method static Builder<static>|Skin whereName($value)
 * @method static Builder<static>|Skin wherePreviewPath($value)
 * @method static Builder<static>|Skin whereRatingCount($value)
 * @method static Builder<static>|Skin whereUpdatedAt($value)
 * @method static Builder<static>|Skin whereUserId($value)
 * @method static Builder<static>|Skin whereVersion($value)
 * @method static Builder<static>|Skin withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Skin withoutTrashed()
 * @mixin \Eloquent
 */
class Skin extends Model
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
        'file_path',
        'preview_path',
        'file_size',
        'manifest',
        'is_public',
        'download_count',
        'average_rating',
        'rating_count',
    ];

    protected $casts = [
        'manifest' => 'array',
        'is_public' => 'boolean',
        'file_size' => 'integer',
        'download_count' => 'integer',
        'rating_count' => 'integer',
        'average_rating' => 'decimal:2',
    ];

    protected $appends = [
        'download_url',
        'preview_url',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function forkedFrom(): BelongsTo
    {
        return $this->belongsTo(Skin::class, 'forked_from_id');
    }

    public function forks(): HasMany
    {
        return $this->hasMany(Skin::class, 'forked_from_id');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(SkinRating::class);
    }

    public function customizations(): HasMany
    {
        return $this->hasMany(SkinCustomization::class);
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
        return route('api.v1.skins.download', $this->id);
    }

    public function getPreviewUrlAttribute(): ?string
    {
        if ($this->preview_path) {
            return asset('storage/' . $this->preview_path);
        }

        return null;
    }
}
