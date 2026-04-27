<?php

declare(strict_types=1);

namespace App\Models\LibriVox;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $librivox_id
 * @property string $title
 * @property string|null $description
 * @property string|null $language
 * @property string|null $cover_image
 * @property int|null $year
 * @property int|null $duration
 * @property int|null $audio_file_count
 * @property array|null $librivox_info
 */
class Book extends Model
{
    use SoftDeletes;

    protected $table = 'librivox_books';

    protected $fillable = [
        'librivox_id',
        'title',
        'description',
        'language',
        'cover_image',
        'year',
        'duration',
        'audio_file_count',
        'librivox_info',
    ];

    protected $casts = [
        'librivox_info' => 'array',
        'year'          => 'integer',
        'duration'      => 'integer',
    ];

    /** @return BelongsToMany<Author, $this> */
    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(Author::class, 'librivox_book_author', 'book_id', 'author_id');
    }

    /** @return BelongsToMany<Genre, $this> */
    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class, 'librivox_book_genre', 'book_id', 'genre_id');
    }

    /** @return HasMany<Chapter, $this> */
    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class, 'book_id');
    }
}
