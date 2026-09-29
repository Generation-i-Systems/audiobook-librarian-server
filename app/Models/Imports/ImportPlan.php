<?php

declare(strict_types=1);

namespace App\Models\Imports;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable approved plan. Invalidation is recorded, never overwritten.
 *
 * @property int $id
 * @property int $draft_id
 * @property int $revision
 * @property int $approved_by_user_id
 * @property \Illuminate\Support\Carbon $approved_at
 * @property array<string, mixed> $metadata
 * @property string|null $cover_artifact_id
 * @property array<string, mixed> $target
 * @property string $duplicate_action
 * @property string $file_operation
 * @property string $transfer_mode
 * @property array<int, array<string, mixed>> $manifest_snapshot
 * @property array<string, mixed>|null $recommendation_snapshot
 * @property \Illuminate\Support\Carbon|null $invalidated_at
 * @property string|null $invalidated_reason
 */
class ImportPlan extends Model
{
    protected $table = 'import_plans';

    protected $fillable = [
        'draft_id',
        'revision',
        'approved_by_user_id',
        'approved_at',
        'metadata',
        'cover_artifact_id',
        'target',
        'duplicate_action',
        'file_operation',
        'transfer_mode',
        'manifest_snapshot',
        'recommendation_snapshot',
        'invalidated_at',
        'invalidated_reason',
    ];

    protected $casts = [
        'revision' => 'integer',
        'approved_by_user_id' => 'integer',
        'approved_at' => 'datetime',
        'metadata' => 'array',
        'target' => 'array',
        'manifest_snapshot' => 'array',
        'recommendation_snapshot' => 'array',
        'invalidated_at' => 'datetime',
    ];
}
