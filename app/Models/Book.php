<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Traits\CamelCaseAttributeAccess;
use App\Traits\Auditable;

class Book extends Model
{
    use HasFactory;
    use CamelCaseAttributeAccess;
    use Auditable;

    protected $fillable = [
        'title',
        'description',
        'directory_path',
        'release_date',
        'cover_image',
        'language',
        'source',
        'series_id',
        'duration',
        'publisher',
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
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('progress', 'last_listened')->withTimestamps();
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
