<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\CamelCaseAttributeAccess;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRecommendation extends Model
{
    use CamelCaseAttributeAccess;
    use HasFactory;

    protected $fillable = [
        'sender_id',
        'recipient_id',
        'book_id',
        'message',
        'acknowledged_at',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
    ];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
