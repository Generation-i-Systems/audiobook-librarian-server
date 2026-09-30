<?php

declare(strict_types=1);

namespace App\Models\Imports;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only draft event log backing polling, SSE, and support exports.
 *
 * @property int $id
 * @property int $draft_id
 * @property string $event_type
 * @property int $observed_revision
 * @property array<string, mixed> $payload
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class ImportEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'import_events';

    protected $fillable = [
        'draft_id',
        'event_type',
        'observed_revision',
        'payload',
    ];

    protected $casts = [
        'observed_revision' => 'integer',
        'payload' => 'array',
    ];
}
