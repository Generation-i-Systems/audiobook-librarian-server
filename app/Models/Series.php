<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Series extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'parent_name'];

    public function books()
    {
        return $this->hasMany(Book::class);
    }

    public function follows()
    {
        return $this->morphMany(Follow::class, 'followable');
    }
}
