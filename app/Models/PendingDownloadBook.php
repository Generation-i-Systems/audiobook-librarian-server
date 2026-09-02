<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingDownloadBook extends Model
{
    protected $fillable = [
        'pending_download_id',
        'sort_order',
        'title',
        'authors',
        'genre',
        'tags',
        'description',
        'cover_url',
        'series_name',
        'series_number',
        'abb_url',
        'narrator',
        'matched_book_id',
    ];

    protected $casts = [
        'authors' => 'array',
        'tags' => 'array',
    ];

    public function pendingDownload(): BelongsTo
    {
        return $this->belongsTo(PendingDownload::class);
    }

    public function matchedBook(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'matched_book_id');
    }
}
