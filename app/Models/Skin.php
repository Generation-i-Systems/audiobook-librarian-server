<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\CamelCaseAttributeAccess;

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
        return route('api.skins.download', $this->id);
    }

    public function getPreviewUrlAttribute(): ?string
    {
        if ($this->preview_path) {
            return asset('storage/' . $this->preview_path);
        }

        return null;
    }
}
