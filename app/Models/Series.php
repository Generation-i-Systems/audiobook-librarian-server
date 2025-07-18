<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Series extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_series')
            ->withPivot('series_number')
            ->withTimestamps();
    }
}
