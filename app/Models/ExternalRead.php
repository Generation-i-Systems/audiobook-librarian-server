<?php

namespace App\Models;

use App\Traits\CamelCaseAttributeAccess;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $book_id
 * @property string|null $title
 * @property string|null $author
 * @property string $origin
 * @property string|null $source
 * @property string|null $note
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @property-read \App\Models\Book|null $book
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExternalRead newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExternalRead newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExternalRead query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExternalRead whereBookId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExternalRead whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExternalRead whereFinishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExternalRead whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExternalRead whereNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExternalRead whereOrigin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExternalRead whereSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExternalRead whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExternalRead whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExternalRead whereUserId($value)
 * @mixin \Eloquent
 */
class ExternalRead extends Model
{
    use CamelCaseAttributeAccess;
    use HasFactory;

    protected $fillable = [
        'user_id',
        'book_id',
        'title',
        'author',
        'origin', // 'previous' | 'external'
        'source', // optional free-form source e.g. 'Audible', 'Libby'
        'note',   // optional note/label
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
