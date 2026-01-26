<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $book_id
 * @property string $issue_type
 * @property string $status
 * @property string|null $directory_path
 * @property array|null $metadata
 * @property bool $auto_resolved
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property string|null $resolution_notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @property-read \App\Models\Book|null $book
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LibraryRepairIssue newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LibraryRepairIssue newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LibraryRepairIssue pending()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LibraryRepairIssue query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LibraryRepairIssue whereAutoResolved($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LibraryRepairIssue whereBookId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LibraryRepairIssue whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LibraryRepairIssue whereDirectoryPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LibraryRepairIssue whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LibraryRepairIssue whereIssueType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LibraryRepairIssue whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LibraryRepairIssue whereResolutionNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LibraryRepairIssue whereResolvedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LibraryRepairIssue whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LibraryRepairIssue whereUpdatedAt($value)
 * @mixin \Eloquent
 */
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
