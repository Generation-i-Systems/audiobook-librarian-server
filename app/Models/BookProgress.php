<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\CamelCaseAttributeAccess;

/**
 * @property int $id
 * @property int|null $book_id
 * @property int|null $client_book_id
 * @property int $user_id
 * @property string|null $device_id
 * @property int $current_position_seconds
 * @property int $total_duration_seconds
 * @property float $progress_percentage
 * @property int|null $current_chapter
 * @property string|null $current_chapter_name
 * @property \Illuminate\Support\Carbon|null $last_listened_at
 * @property bool $completed
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Book|null $book
 * @property-read \App\Models\ClientBook|null $clientBook
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @property-read string $formatted_duration
 * @property-read string $formatted_progress
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookProgress newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookProgress newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookProgress query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookProgress whereBookId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookProgress whereCompleted($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookProgress whereCompletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookProgress whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookProgress whereCurrentChapter($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookProgress whereCurrentChapterName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookProgress whereCurrentPositionSeconds($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookProgress whereDeviceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookProgress whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookProgress whereLastListenedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookProgress whereProgressPercentage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookProgress whereTotalDurationSeconds($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookProgress whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BookProgress whereUserId($value)
 * @mixin \Eloquent
 */
class BookProgress extends Model
{
    use CamelCaseAttributeAccess;

    protected $table = 'book_progress';

    protected $fillable = [
        'book_id',
        'client_book_id',
        'user_id',
        'device_id',
        'current_position_seconds',
        'total_duration_seconds',
        'progress_percentage',
        'current_chapter',
        'current_chapter_name',
        'last_listened_at',
        'completed',
        'completed_at',
    ];

    protected $casts = [
        'current_position_seconds' => 'integer',
        'total_duration_seconds' => 'integer',
        'progress_percentage' => 'decimal:2',
        'current_chapter' => 'integer',
        'last_listened_at' => 'datetime',
        'completed' => 'boolean',
        'completed_at' => 'datetime',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function clientBook(): BelongsTo
    {
        return $this->belongsTo(ClientBook::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }

    /**
     * Update progress and calculate percentage
     */
    public function updateProgress(int $positionSeconds, ?int $totalDuration = null): void
    {
        $this->current_position_seconds = $positionSeconds;
        $this->last_listened_at = now();

        if ($totalDuration) {
            $this->total_duration_seconds = $totalDuration;
        }

        if ($this->total_duration_seconds > 0) {
            $percentage = ($positionSeconds / $this->total_duration_seconds) * 100;
            $this->progress_percentage = min(100, max(0, $percentage));

            // Mark as completed if near the end (95% or more)
            if ($percentage >= 95 && !$this->completed) {
                $this->completed = true;
                $this->completed_at = now();
            }
        }
    }

    /**
     * Get progress as formatted time
     */
    public function getFormattedProgressAttribute(): string
    {
        $hours = floor($this->current_position_seconds / 3600);
        $minutes = floor(($this->current_position_seconds % 3600) / 60);
        $seconds = $this->current_position_seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    /**
     * Get total duration as formatted time
     */
    public function getFormattedDurationAttribute(): string
    {
        if (!$this->total_duration_seconds) {
            return '0:00';
        }

        $hours = floor($this->total_duration_seconds / 3600);
        $minutes = floor(($this->total_duration_seconds % 3600) / 60);
        $seconds = $this->total_duration_seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%d:%02d', $minutes, $seconds);
    }
}
