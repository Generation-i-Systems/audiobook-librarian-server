<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $book_id
 * @property string $content_hash
 * @property string|null $cover_hash
 * @property string|null $cover_caption
 * @property \Illuminate\Support\Carbon|null $embedded_at
 * @property-read \App\Models\Book $book
 */
class BookEmbedding extends Model
{
    protected $fillable = [
        'book_id',
        'content_hash',
        'cover_hash',
        'cover_caption',
        'embedded_at',
    ];

    protected $casts = [
        'embedded_at' => 'datetime',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
