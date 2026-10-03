<?php

declare(strict_types=1);

namespace App\Models\Imports;

use App\Enums\ImportDraftState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $owner_user_id
 * @property ImportDraftState $state
 * @property int $revision
 * @property int|null $plan_revision
 * @property int|null $transfer_verified_plan_revision
 * @property \Illuminate\Support\Carbon|null $transfer_verified_at
 * @property string $source_mode
 * @property string $source_display_name
 * @property string|null $source_root_fingerprint
 * @property array<int, string>|null $source_warnings
 * @property int $observation_schema_version
 * @property array<string, mixed> $client_metadata
 * @property array<string, mixed>|null $analysis_request
 * @property array<string, mixed>|null $recommendation
 * @property array<string, mixed> $transfer_summary
 * @property array<string, mixed>|null $interpretation_error
 * @property array<int, array<string, mixed>>|null $evidence_requests
 * @property string|null $cancel_reason
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $queued_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class ImportDraft extends Model
{
    protected $table = 'import_drafts';

    protected $fillable = [
        'public_id',
        'owner_user_id',
        'state',
        'revision',
        'plan_revision',
        'transfer_verified_plan_revision',
        'source_mode',
        'source_display_name',
        'source_root_fingerprint',
        'source_warnings',
        'observation_schema_version',
        'client_metadata',
        'analysis_request',
        'recommendation',
        'transfer_summary',
        'interpretation_error',
        'evidence_requests',
        'cancel_reason',
        'expires_at',
        'queued_at',
        'completed_at',
        'cancelled_at',
        'transfer_verified_at',
    ];

    protected $casts = [
        'state' => ImportDraftState::class,
        'owner_user_id' => 'integer',
        'revision' => 'integer',
        'plan_revision' => 'integer',
        'transfer_verified_plan_revision' => 'integer',
        'observation_schema_version' => 'integer',
        'source_warnings' => 'array',
        'client_metadata' => 'array',
        'analysis_request' => 'array',
        'recommendation' => 'array',
        'transfer_summary' => 'array',
        'interpretation_error' => 'array',
        'evidence_requests' => 'array',
        'expires_at' => 'datetime',
        'queued_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'transfer_verified_at' => 'datetime',
    ];

    /**
     * @return HasMany<ImportDraftFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(ImportDraftFile::class, 'draft_id')->orderBy('id');
    }

    /**
     * @return HasMany<ImportArtifact, $this>
     */
    public function artifacts(): HasMany
    {
        return $this->hasMany(ImportArtifact::class, 'draft_id')->orderBy('id');
    }

    /**
     * @return HasMany<ImportPlan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(ImportPlan::class, 'draft_id')->orderBy('revision');
    }

    /**
     * @return HasMany<ImportEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ImportEvent::class, 'draft_id')->orderBy('id');
    }
}
