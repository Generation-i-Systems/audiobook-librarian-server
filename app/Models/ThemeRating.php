<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\CamelCaseAttributeAccess;

/**
 * @property int $id
 * @property int $theme_id
 * @property int $user_id
 * @property int $rating
 * @property string|null $comment
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @property-read \App\Models\Theme $theme
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ThemeRating newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ThemeRating newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ThemeRating query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ThemeRating whereComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ThemeRating whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ThemeRating whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ThemeRating whereRating($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ThemeRating whereThemeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ThemeRating whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ThemeRating whereUserId($value)
 * @mixin \Eloquent
 */
class ThemeRating extends Model
{
    use HasFactory;
    use CamelCaseAttributeAccess;

    protected $fillable = [
        'theme_id',
        'user_id',
        'rating',
        'comment',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
