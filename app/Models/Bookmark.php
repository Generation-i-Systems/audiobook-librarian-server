<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\CamelCaseAttributeAccess;

/**
 * @property int $id
 * @property string|null $string_id
 * @property int $user_id
 * @property int $book_id
 * @property string|null $device_id
 * @property string|null $device_name
 * @property string|null $title
 * @property string|null $chapter
 * @property int|null $chapter_number
 * @property string|null $chapter_title
 * @property int $position
 * @property int|null $position_ms
 * @property string|null $notes
 * @property bool $is_auto
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @property-read \App\Models\Book $book
 * @property-read \App\Models\User $user
 * @property-read \App\Models\Device|null $device
 */
class Bookmark extends Model
{
    use HasFactory;
    use CamelCaseAttributeAccess;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'book_id',
        'string_id',
        'device_id',
        'device_name',
        'title',
        'chapter',
        'chapter_number',
        'chapter_title',
        'position',
        'position_ms',
        'notes',
        'is_auto',
    ];

    protected $casts = [
        'chapter_number' => 'integer',
        'position' => 'integer',
        'position_ms' => 'integer',
        'is_auto' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }
}
