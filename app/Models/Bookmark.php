<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\CamelCaseAttributeAccess;

/**
 * @property int $id
 * @property int $user_id
 * @property int $book_id
 * @property string|null $title
 * @property string|null $chapter
 * @property int $position
 * @property string|null $notes
 * @property bool $is_auto
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @property-read \App\Models\Book $book
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bookmark newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bookmark newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bookmark query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bookmark whereBookId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bookmark whereChapter($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bookmark whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bookmark whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bookmark whereIsAuto($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bookmark whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bookmark wherePosition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bookmark whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bookmark whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bookmark whereUserId($value)
 * @mixin \Eloquent
 */
class Bookmark extends Model
{
    use HasFactory;
    use CamelCaseAttributeAccess;

    protected $fillable = [
        'user_id',
        'book_id',
        'title',
        'chapter',
        'position',
        'notes',
        'is_auto',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
