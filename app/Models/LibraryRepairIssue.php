<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibraryRepairIssue extends Model
{
    use HasFactory;

    protected $fillable = [
        'book_id',
        'issue_type',
        'status',
        'directory_path',
        'metadata',
        'auto_resolved',
        'resolved_at',
        'resolution_notes',
    ];

    protected $casts = [
        'metadata' => 'array',
        'auto_resolved' => 'boolean',
        'resolved_at' => 'datetime',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
