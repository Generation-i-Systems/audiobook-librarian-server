<?php

declare(strict_types=1);

namespace App\Models\Imports;

use Illuminate\Database\Eloquent\Model;

/**
 * Bounded pre-transfer evidence (NFO text, cover, AI result). Never a bulk-file channel.
 *
 * @property int $id
 * @property int $draft_id
 * @property string $artifact_id
 * @property string $kind
 * @property string $media_type
 * @property int|null $bytes
 * @property string $sha256
 * @property string|null $storage_key
 * @property string|null $inline_text
 * @property array<string, mixed>|null $metadata
 */
class ImportArtifact extends Model
{
    protected $table = 'import_artifacts';

    protected $fillable = [
        'draft_id',
        'artifact_id',
        'kind',
        'media_type',
        'bytes',
        'sha256',
        'storage_key',
        'inline_text',
        'metadata',
    ];

    protected $casts = [
        'bytes' => 'integer',
        'metadata' => 'array',
    ];
}
