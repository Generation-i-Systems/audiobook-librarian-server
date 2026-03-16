<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $period_type  day|week|month|year
 * @property string $metric       total_hours|genre_hours|playlist_hours|fiction_hours|nonfiction_hours
 * @property int $target_minutes
 * @property int|null $genre_id
 * @property int|null $playlist_id
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $user
 * @property-read \App\Models\Genre|null $genre
 * @property-read \App\Models\Playlist|null $playlist
 */
class ListeningGoal extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'period_type', 'metric', 'target_minutes',
        'genre_id', 'playlist_id', 'is_active',
    ];

    protected $casts = [
        'target_minutes' => 'integer',
        'genre_id'       => 'integer',
        'playlist_id'    => 'integer',
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
}
