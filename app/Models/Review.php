<?php

namespace App\Models;

use App\Traits\CamelCaseAttributeAccess;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $book_id
 * @property int $user_id
 * @property string $comment
 * @property int $age_rating
 * @property string $content_rating
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\Book $book
 * @property-read \App\Models\User $user
 */
class Review extends Model
{
    use HasFactory;
    use CamelCaseAttributeAccess;
    use SoftDeletes;

    protected $fillable = [
        'book_id',
        'user_id',
        'comment',
        'age_rating',
        'content_rating',
    ];

    protected $casts = [
        'book_id' => 'integer',
        'user_id' => 'integer',
        'age_rating' => 'integer',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
