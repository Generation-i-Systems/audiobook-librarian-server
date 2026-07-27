<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\CamelCaseAttributeAccess;

/**
 * @property int $id
 * @property int $book_id
 * @property int $chapter_number
 * @property string|null $title
 * @property string|null $reader
 * @property float|null $start_seconds
 * @property string $file_name
 * @property string $format
 * @property int|null $duration
 * @property int|null $size_bytes
 * @property string|null $listen_url
 * @property string|null $source
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @property-read \App\Models\Book $book
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Chapter newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Chapter newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Chapter query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Chapter whereBookId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Chapter whereChapterNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Chapter whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Chapter whereDuration($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Chapter whereFileName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Chapter whereFormat($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Chapter whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Chapter whereSizeBytes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Chapter whereSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Chapter whereStartSeconds($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Chapter whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Chapter extends Model
{
    use HasFactory;
    use CamelCaseAttributeAccess;

    protected $fillable = [
        'book_id',
        'chapter_number',
        'title',
        'reader',
        'start_seconds',
        'file_name',
        'format',
        'duration',
        'size_bytes',
        'listen_url',
        'source',
    ];

    protected $casts = [
        'chapter_number' => 'integer',
        'start_seconds' => 'float',
        'duration' => 'integer',
        'size_bytes' => 'integer',
    ];

    public function book()
    {
        return $this->belongsTo(Book::class);
    }
}
