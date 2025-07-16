<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Book extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'publication_year',
        'cover_image',
        'language',
        'book_number',
        'path',
        'source',
        'audio_sample_path',
        'series_id',
    ];

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

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

    public function genres()
    {
        return $this->belongsToMany(Genre::class);
    }
}
