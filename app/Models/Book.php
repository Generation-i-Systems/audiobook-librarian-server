<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;
use App\Relations\TouchesParentBelongsToMany;
use App\Traits\CamelCaseAttributeAccess;
use App\Traits\Auditable;

/**
 * @property int $id
 * @property string|null $title
 * @property string|null $description
 * @property string|null $directoryPath
 * @property string|null $coverImage
 * @property string|null $language
 * @property string|null $source
 * @property string|null $asin
 * @property bool $needsReview
 * @property array|null $needsReviewReasons
 * @property int|null $duration
 * @property int|null $audioFileCount
 * @property array|null $audibleInfo
 * @property array|null $googleBooksInfo
 * @property array|null $hardcoverInfo
 * @property array|null $audiobookBayInfo
 * @property \App\Models\Publisher|null $publisher
 * @property \Carbon\CarbonInterface|null $releaseDate
 * @property \Carbon\CarbonInterface|null $createdAt
 * @property \Carbon\CarbonInterface|null $updatedAt
 * @property-read \Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Collection<int, \App\Models\Author> $authors
 * @property-read \Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Collection<int, \App\Models\Narrator> $narrators
 * @property-read \Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Collection<int, \App\Models\Genre> $genres
 * @property-read \Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Collection<int, \App\Models\Series> $series
 * @method static Book|null find(int|string $id)
 * @method static \Illuminate\Database\Eloquent\Builder|Book where(string $column, $operator = null, $value = null, $boolean = 'and')
 */
class Book extends Model
{
    use HasFactory;
    use CamelCaseAttributeAccess;
    use Auditable;

    protected $observables = [
        'pivotAttached',
        'pivotDetached',
        'pivotUpdated',
    ];

    protected $touches = [
        'authors',
        'narrators',
        'genres',
        'series',
    ];

    protected $fillable = [
        'title',
        'description',
        'directory_path',
        'release_date',
        'cover_image',
        'language',
        'asin',
        'source',
        'batch_id',
        'series_id',
        'duration',
        'publisher_id',
        'needs_review',
        'needs_review_reasons',
        'audio_file_count',
        'mongo_id',
        'mongo_record',
        'file_tags',
        'audible_info',
        'google_books_info',
        'hardcover_info',
        'audiobook_bay_info',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'release_date' => 'date:Y-m-d',
        'duration' => 'integer',
        'needs_review' => 'boolean',
        'needs_review_reasons' => 'array',
        'audio_file_count' => 'integer',
        'mongo_record' => 'array',
        'file_tags' => 'array',
        'audible_info' => 'array',
        'google_books_info' => 'array',
        'hardcover_info' => 'array',
        'audiobook_bay_info' => 'array',
        'directory_exists' => 'boolean',
        'directory_last_checked' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Scope to filter books with existing directories only
     */
    public function scopeWithExistingDirectories($query)
    {
        return $query->where('directory_exists', true);
    }

    /**
     * Scope to filter books with missing directories
     */
    public function scopeWithMissingDirectories($query)
    {
        return $query->where('directory_exists', false);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('progress', 'last_listened')->withTimestamps();
    }

    protected function newBelongsToMany(
        Builder $query,
        Model $parent,
        $table,
        $foreignPivotKey,
        $relatedPivotKey,
        $parentKey,
        $relatedKey,
        $relationName = null
    ): BelongsToMany {
        return new TouchesParentBelongsToMany(
            $query,
            $parent,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relationName
        );
    }

    public function chapters()
    {
        return $this->hasMany(Chapter::class);
    }

    public function authors()
    {
        return $this->belongsToMany(Author::class);
    }

    public function narrators()
    {
        return $this->belongsToMany(Narrator::class);
    }

    public function publisher()
    {
        return $this->belongsTo(Publisher::class);
    }

    public function series()
    {
        return $this->belongsToMany(Series::class, 'book_series')
            ->withPivot('series_number')
            ->withTimestamps();
    }

    public function genres()
    {
        return $this->belongsToMany(Genre::class);
    }
}
