<?php

declare(strict_types=1);

namespace App\Models\LibriVox;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 */
class Author extends Model
{
    protected $table = 'librivox_authors';

    protected $fillable = ['name'];

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'librivox_book_author', 'author_id', 'book_id');
    }
}
