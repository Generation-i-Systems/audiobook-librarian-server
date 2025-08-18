<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AudioSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'book_id',
        'device_id',
        'start_time',
        'end_time',
        'duration_seconds',
        'position_start',
        'position_end',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'duration_seconds' => 'integer',
        'position_start' => 'integer',
        'position_end' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function book()
    {
        return $this->belongsTo(Book::class);
    }
}
