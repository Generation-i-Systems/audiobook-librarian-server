<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Relations\TouchesParentBelongsToMany;
use App\Traits\CamelCaseAttributeAccess;
use App\Traits\Auditable;
use Illuminate\Support\Facades\Schema;

/**
 * @property int $id
 * @property string|null $title
 * @property string|null $description
 * @property string|null $directoryPath
 * @property string|null $directory_path
 * @property string|null $coverImage
 * @property string|null $cover_image
 * @property string|null $language
 * @property string|null $source
 * @property string|null $asin
 * @property string|null $isbn
 * @property bool $needsReview
 * @property bool $needs_review
 * @property array|null $needsReviewReasons
 * @property array|null $needs_review_reasons
 * @property int|null $duration
 * @property int|null $audioFileCount
 * @property int|null $audio_file_count
 * @property bool $ai_processed
 * @property float|null $ai_confidence
 * @property array|null $audibleInfo
 * @property array|null $googleBooksInfo
 * @property array|null $hardcoverInfo
 * @property array|null $audiobookBayInfo
 * @property \App\Models\Publisher|null $publisher
 * @property \Carbon\CarbonInterface|string|null $releaseDate
 * @property \Carbon\CarbonInterface|string|null $release_date
 * @property \Carbon\CarbonInterface|null $createdAt
 * @property \Carbon\CarbonInterface|null $updatedAt
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Author> $authors
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Narrator> $narrators
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Genre> $genres
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Series> $series
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Chapter> $chapters
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\BookProgress> $progress
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\UserRecommendation> $recommendations
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\UserBookStatus> $statuses
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\BookTag> $userTags
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @property string|null $mongo_id
 * @property bool $directory_exists
 * @property \Illuminate\Support\Carbon|null $directory_last_checked
 * @property int|null $year
 * @property string|null $batch_id
 * @property int|null $series_id
 * @property int|null $publisher_id
 * @property \Illuminate\Support\Carbon|null $ai_processed_at
 * @property string|null $ai_suggestions
 * @property array<array-key, mixed>|null $file_tags
 * @property array<array-key, mixed>|null $audible_info
 * @property array<array-key, mixed>|null $google_books_info
 * @property array<array-key, mixed>|null $audiobook_bay_info
 * @property array<array-key, mixed>|null $hardcover_info
 * @property string|null $librivox_id
 * @property array<array-key, mixed>|null $librivox_info
 * @property array<array-key, mixed>|null $mongo_record
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $last_library_json_update
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read int|null $authors_count
 * @property-read int|null $chapters_count
 * @property-read int|null $genres_count
 * @property-read int|null $narrators_count
 * @property-read int|null $progress_count
 * @property-read int|null $recommendations_count
 * @property-read int|null $series_count
 * @property-read int|null $statuses_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 * @method static \Database\Factories\BookFactory factory($count = null, $state = [])
 * @method static Builder<static>|Book newModelQuery()
 * @method static Builder<static>|Book newQuery()
 * @method static Builder<static>|Book onlyTrashed()
 * @method static Builder<static>|Book query()
 * @method static Builder<static>|Book whereAiConfidence($value)
 * @method static Builder<static>|Book whereAiProcessed($value)
 * @method static Builder<static>|Book whereAiProcessedAt($value)
 * @method static Builder<static>|Book whereAiSuggestions($value)
 * @method static Builder<static>|Book whereAsin($value)
 * @method static Builder<static>|Book whereAudibleInfo($value)
 * @method static Builder<static>|Book whereAudioFileCount($value)
 * @method static Builder<static>|Book whereAudiobookBayInfo($value)
 * @method static Builder<static>|Book whereBatchId($value)
 * @method static Builder<static>|Book whereCoverImage($value)
 * @method static Builder<static>|Book whereCreatedAt($value)
 * @method static Builder<static>|Book whereDeletedAt($value)
 * @method static Builder<static>|Book whereDescription($value)
 * @method static Builder<static>|Book whereDirectoryExists($value)
 * @method static Builder<static>|Book whereDirectoryLastChecked($value)
 * @method static Builder<static>|Book whereDirectoryPath($value)
 * @method static Builder<static>|Book whereDuration($value)
 * @method static Builder<static>|Book whereFileTags($value)
 * @method static Builder<static>|Book whereGoogleBooksInfo($value)
 * @method static Builder<static>|Book whereHardcoverInfo($value)
 * @method static Builder<static>|Book whereId($value)
 * @method static Builder<static>|Book whereIsbn($value)
 * @method static Builder<static>|Book whereLanguage($value)
 * @method static Builder<static>|Book whereLastLibraryJsonUpdate($value)
 * @method static Builder<static>|Book whereMongoId($value)
 * @method static Builder<static>|Book whereMongoRecord($value)
 * @method static Builder<static>|Book whereNeedsReview($value)
 * @method static Builder<static>|Book whereNeedsReviewReasons($value)
 * @method static Builder<static>|Book wherePublisherId($value)
 * @method static Builder<static>|Book whereReleaseDate($value)
 * @method static Builder<static>|Book whereSeriesId($value)
 * @method static Builder<static>|Book whereSource($value)
 * @method static Builder<static>|Book whereTitle($value)
 * @method static Builder<static>|Book whereUpdatedAt($value)
 * @method static Builder<static>|Book whereYear($value)
 * @method static Builder<static>|Book withExistingDirectories()
 * @method static Builder<static>|Book withMissingDirectories()
 * @method static Builder<static>|Book withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Book withUserData(string|int $userId)
 * @method static Builder<static>|Book withoutTrashed()
 * @mixin \Illuminate\Database\Eloquent\Model
 */
class Book extends Model
{
    use HasFactory;
    use CamelCaseAttributeAccess;
    use Auditable;
    use SoftDeletes;

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
        'librivox_id',
        'librivox_info',
        'year',
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
        'librivox_info' => 'array',
        'ai_processed_at' => 'datetime',
        'directory_exists' => 'boolean',
        'directory_last_checked' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Scope to filter books with existing directories only
     */
    public function scopeWithExistingDirectories($query)
    {
        return $query->where('directory_exists', true);
    }

    /**
     * Set cover_image attribute - normalize to just filename.
     *
     * cover_image should store only the filename (e.g., "cover.jpg"),
     * resolved relative to the book's directory_path at runtime.
     * This setter auto-converts full paths and URLs to filenames.
     */
    public function setCoverImageAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['cover_image'] = null;
            return;
        }

        // If it's a remote URL, keep as-is (handled by proxy at runtime)
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            $this->attributes['cover_image'] = $value;
            return;
        }

        // If it contains a path separator, extract just the filename
        if (str_contains($value, '/')) {
            $this->attributes['cover_image'] = basename($value);
            return;
        }

        // Already just a filename
        $this->attributes['cover_image'] = $value;
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

    public function chapters(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Chapter::class);
    }

    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(Author::class);
    }

    public function narrators(): BelongsToMany
    {
        return $this->belongsToMany(Narrator::class);
    }

    public function publisher(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    public function series(): BelongsToMany
    {
        return $this->belongsToMany(Series::class, 'book_series')
            ->withPivot('series_number')
            ->withTimestamps();
    }

    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class);
    }

    public function progress(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BookProgress::class);
    }

    public function recommendations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserRecommendation::class);
    }

    public function statuses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserBookStatus::class);
    }

    public function userTags(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BookTag::class);
    }

    /**
     * Scope to include user-specific data (progress, recommendation, status)
     */
    public function scopeWithUserData(Builder $query, int|string $userId): Builder
    {
        $relations = [
            'progress' => fn ($q) => $q->where('user_id', $userId),
            'recommendations' => fn ($q) => $q->where('recipient_id', $userId)->whereNull('acknowledged_at'),
            'statuses' => fn ($q) => $q->where('user_id', $userId),
        ];

        if (Schema::hasTable('book_tags')) {
            $relations['userTags'] = fn ($q) => $q->where('user_id', $userId);
        }

        return $query->with($relations);
    }
}
