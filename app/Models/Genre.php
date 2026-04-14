<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\CamelCaseAttributeAccess;
use App\Traits\Auditable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $emoji
 * @property string|null $icon_path
 * @property bool|null $is_fiction
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Book> $books
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read int|null $books_count
 * @method static \Database\Factories\GenreFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Genre newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Genre newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Genre onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Genre query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Genre whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Genre whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Genre whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Genre whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Genre whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Genre withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Genre withoutTrashed()
 * @mixin \Eloquent
 */
class Genre extends Model
{
    use HasFactory;
    use CamelCaseAttributeAccess;
    use Auditable;
    use SoftDeletes;

    protected $fillable = ['name', 'emoji', 'icon_path', 'is_fiction'];

    protected $casts = ['is_fiction' => 'boolean'];

    public function books(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Book::class);
    }
}
