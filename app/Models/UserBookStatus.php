<?php

namespace App\Models;

use App\Traits\CamelCaseAttributeAccess;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserBookStatus extends Model
{
    use CamelCaseAttributeAccess;
    use HasFactory;

    protected $table = 'user_book_status';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'book_id',
        'order',
        'status',
        'status_detail',
        'read_count',
    ];

    protected $casts = [
        'order' => 'integer',
        'status_detail' => 'array',
        'read_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
