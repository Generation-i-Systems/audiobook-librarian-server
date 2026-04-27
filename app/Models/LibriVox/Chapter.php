<?php

declare(strict_types=1);

namespace App\Models\LibriVox;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $book_id
 * @property int $chapter_number
 * @property string|null $title
 * @property string|null $reader
 * @property string $file_name
 * @property string|null $format
 * @property int|null $duration
 * @property int|null $size_bytes
 * @property string|null $listen_url
 */
class Chapter extends Model
{
    protected $table = 'librivox_chapters';

    protected $fillable = [
        'book_id',
        'chapter_number',
        'title',
        'reader',
        'file_name',
        'format',
        'duration',
        'size_bytes',
        'listen_url',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'book_id');
    }
}
