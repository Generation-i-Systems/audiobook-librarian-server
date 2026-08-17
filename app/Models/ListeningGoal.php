<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $period_type  day|week|month|year|custom
 * @property string $metric       total_hours|genre_hours|playlist_hours|fiction_hours|nonfiction_hours|books_finished|series_hours|author_hours|book_hours
 * @property int $target_minutes
 * @property int|null $genre_id
 * @property int|null $playlist_id
 * @property int|null $series_id
 * @property int|null $author_id
 * @property int|null $book_id
 * @property string|null $book_title
 * @property string|null $book_author
 * @property \Illuminate\Support\Carbon|null $start_date
 * @property \Illuminate\Support\Carbon|null $end_date
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 * @property-read \App\Models\Genre|null $genre
 * @property-read \App\Models\Playlist|null $playlist
 * @property-read \App\Models\Series|null $series
 * @property-read \App\Models\Author|null $author
 * @property-read \App\Models\Book|null $book
 */
class ListeningGoal extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'period_type', 'metric', 'target_minutes',
        'genre_id', 'playlist_id', 'series_id', 'author_id', 'book_id',
        'book_title', 'book_author', 'start_date', 'end_date', 'is_active',
    ];

    protected $casts = [
        'target_minutes' => 'integer',
        'genre_id'       => 'integer',
        'playlist_id'    => 'integer',
        'series_id'      => 'integer',
        'author_id'      => 'integer',
        'book_id'        => 'integer',
        'start_date'     => 'date',
        'end_date'       => 'date',
        'is_active'      => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function genre(): BelongsTo
    {
        return $this->belongsTo(Genre::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
