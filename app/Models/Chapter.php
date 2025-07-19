<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\CamelCaseAttributeAccess;

class Chapter extends Model
{
    use HasFactory, CamelCaseAttributeAccess;

    protected $fillable = [
        'book_id',
        'chapter_number',
        'file_name',
        'format',
        'duration',
        'size_bytes',
    ];

    public function book()
    {
        return $this->belongsTo(Book::class);
    }
}