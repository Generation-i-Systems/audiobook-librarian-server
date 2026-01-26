<?php

namespace App\Models;

use App\Traits\CamelCaseAttributeAccess;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $user_id
 * @property int $book_id
 * @property int|null $order
 * @property string $status
 * @property array|null $status_detail
 * @property int $read_count
 * @property \Illuminate\Support\Carbon|null $target_date
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
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
        'target_date',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'order' => 'integer',
        'status_detail' => 'array',
        'read_count' => 'integer',
        'target_date' => 'date',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
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
