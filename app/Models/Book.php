<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Book extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'author_id',
        'series_id',
        'genre_id',
        'cover_image',
        'description',
        'directory_path',
        'type',
        'date_added',
        'published_year',
        'series_number'
    ];

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function queues()
    {
        return $this->hasMany(BookQueue::class);
    }

    public function genre()
    {
        return $this->belongsTo(Genre::class);
    }

    public function author()
    {
        return $this->belongsTo(Author::class);
    }

    public function series()
    {
        return $this->belongsTo(Series::class);
    }
}
