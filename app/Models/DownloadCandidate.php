<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DownloadCandidate extends Model
{
    protected $fillable = [
        'author_id',
        'series_id',
        'title',
        'abb_url',
        'magnet_uri',
        'cover_url',
        'description',
        'genre',
        'abb_id',
        'status',
        'discovered_at',
        'decided_at',
        'decided_by_user_id',
        'pending_download_id',
    ];

    protected $casts = [
        'discovered_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Author, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    /**
     * @return BelongsTo<Series, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /**
     * @return BelongsTo<PendingDownload, $this>
     */
    public function pendingDownload(): BelongsTo
    {
        return $this->belongsTo(PendingDownload::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForAuthor($query, int $authorId)
    {
        return $query->where('author_id', $authorId);
    }
}
