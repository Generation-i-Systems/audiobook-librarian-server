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
 * @property string|null $normalized_name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Book> $books
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read int|null $books_count
 * @method static \Database\Factories\NarratorFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Narrator newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Narrator newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Narrator onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Narrator query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Narrator whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Narrator whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Narrator whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Narrator whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Narrator whereNormalizedName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Narrator whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Narrator withName($name)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Narrator withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Narrator withoutTrashed()
 * @mixin \Eloquent
 */
class Narrator extends Model
{
    use HasFactory;
    use CamelCaseAttributeAccess;
    use Auditable;
    use SoftDeletes;

    protected $fillable = ['name', 'normalized_name'];

    /**
     * The "booting" method of the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($narrator) {
            $narrator->normalized_name = static::normalizeName($narrator->name);
        });
    }

    /**
     * Normalize a name for comparison
     *
     * @param string $name
     * @return string
     */
    public static function normalizeName($name)
    {
        if (!is_string($name)) {
            return '';
        }

        // Convert to UTF-8 if not already
        $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');

        // Remove BOM if present
        $bom = pack('H*', 'EFBBBF');
        $name = preg_replace("/^$bom/", '', $name);

        // Normalize line endings and whitespace
        $name = preg_replace('/\s+/', ' ', trim($name));

        // Normalize Unicode characters (e.g., convert é to e + ´ to é)
        if (class_exists('Normalizer') && method_exists('Normalizer', 'normalize')) {
            $name = \Normalizer::normalize($name, \Normalizer::FORM_C);
        }

        // Convert to lowercase for case-insensitive comparison
        return mb_strtolower($name, 'UTF-8');
    }

    /**
     * Scope a query to find a narrator by name (case-insensitive and accent-insensitive)
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $name
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithName($query, $name)
    {
        $normalized = static::normalizeName($name);
        return $query->where('normalized_name', $normalized);
    }

    public function books(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Book::class);
    }
}
