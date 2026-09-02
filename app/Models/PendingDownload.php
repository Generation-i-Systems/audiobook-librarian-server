<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PendingDownload extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'magnet_infohash',
        'release_name',
        'torrent_name_hint',
        'abb_url',
        'abb_category',
        'magnet_uri',
        'book_count',
        'status',
        'matched_book_directory',
        'matched_at',
        'consumed_at',
        'created_by_user_id',
        'expires_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'matched_at' => 'datetime',
        'consumed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * @return HasMany<PendingDownloadBook, $this>
     */
    public function books(): HasMany
    {
        return $this->hasMany(PendingDownloadBook::class)->orderBy('sort_order');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeExpired($query)
    {
        return $query->where('status', '!=', 'consumed')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());
    }

    public function markMatched(string $directoryPath): void
    {
        $this->update([
            'status' => 'matched',
            'matched_book_directory' => $directoryPath,
            'matched_at' => now(),
        ]);
    }

    public function markConsumed(): void
    {
        $this->update([
            'status' => 'consumed',
            'consumed_at' => now(),
        ]);
    }
}
