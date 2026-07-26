<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $book_id
 * @property string $file_path
 * @property string $file_path_hash
 * @property int $file_size
 * @property int $file_mtime
 * @property int $chunk_size
 * @property string $sha256
 * @property array<int, array{offset: int, size: int, sha256: string}> $chunks
 * @property \Illuminate\Support\Carbon $generated_at
 */
class BookFileChunkHash extends Model
{
    protected $fillable = [
        'book_id',
        'file_path',
        'file_path_hash',
        'file_size',
        'file_mtime',
        'chunk_size',
        'sha256',
        'chunks',
        'generated_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'file_mtime' => 'integer',
        'chunk_size' => 'integer',
        'chunks' => 'array',
        'generated_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Book, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
