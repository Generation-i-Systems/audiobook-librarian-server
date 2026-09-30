<?php

declare(strict_types=1);

namespace App\Models\Imports;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $draft_id
 * @property string $file_id
 * @property string $relative_path
 * @property string $normalized_path_key
 * @property string $role
 * @property int $bytes
 * @property string|null $sha256
 * @property string|null $fingerprint_algorithm
 * @property string|null $fingerprint_value
 * @property \Illuminate\Support\Carbon|null $modified_at
 * @property array<string, mixed>|null $media_observation
 * @property string|null $text_artifact_id
 * @property string|null $image_artifact_id
 * @property string $transfer_state
 * @property int $received_bytes
 * @property string|null $received_sha256
 * @property string|null $staged_relative_path
 * @property string|null $expected_sha256
 * @property \Illuminate\Support\Carbon|null $uploaded_at
 * @property \Illuminate\Support\Carbon|null $verified_at
 */
class ImportDraftFile extends Model
{
    protected $table = 'import_draft_files';

    protected $fillable = [
        'draft_id',
        'file_id',
        'relative_path',
        'normalized_path_key',
        'role',
        'bytes',
        'sha256',
        'fingerprint_algorithm',
        'fingerprint_value',
        'modified_at',
        'media_observation',
        'text_artifact_id',
        'image_artifact_id',
        'transfer_state',
        'received_bytes',
        'received_sha256',
        'staged_relative_path',
        'expected_sha256',
        'uploaded_at',
        'verified_at',
    ];

    protected $casts = [
        'bytes' => 'integer',
        'received_bytes' => 'integer',
        'modified_at' => 'datetime',
        'uploaded_at' => 'datetime',
        'verified_at' => 'datetime',
        'media_observation' => 'array',
    ];

    /**
     * @return BelongsTo<ImportDraft, $this>
     */
    public function draft(): BelongsTo
    {
        return $this->belongsTo(ImportDraft::class, 'draft_id');
    }
}
