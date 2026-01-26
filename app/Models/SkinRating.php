<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\CamelCaseAttributeAccess;

/**
 * @property int $id
 * @property int $skin_id
 * @property int $user_id
 * @property int $rating
 * @property string|null $comment
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @property-read \App\Models\Skin $skin
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SkinRating newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SkinRating newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SkinRating query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SkinRating whereComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SkinRating whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SkinRating whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SkinRating whereRating($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SkinRating whereSkinId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SkinRating whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SkinRating whereUserId($value)
 * @mixin \Eloquent
 */
class SkinRating extends Model
{
    use HasFactory;
    use CamelCaseAttributeAccess;

    protected $fillable = [
        'skin_id',
        'user_id',
        'rating',
        'comment',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function skin(): BelongsTo
    {
        return $this->belongsTo(Skin::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
