<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\CamelCaseAttributeAccess;

/**
 * @property int $id
 * @property int $book_id
 * @property int $chapter_number
 * @property string $file_name
 * @property string $format
 * @property int $duration
 * @property int $size_bytes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class Chapter extends Model
{
    use HasFactory;
    use CamelCaseAttributeAccess;

    protected $fillable = [
        'book_id',
        'chapter_number',
        'file_name',
        'format',
        'duration',
        'size_bytes',
    ];

    public function book()
    {
        return $this->belongsTo(Book::class);
    }
}
