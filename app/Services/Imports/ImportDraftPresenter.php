<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\Imports\ImportDraft;
use App\Models\Imports\ImportEvent;

/**
 * Renders drafts in the imports.v1 shape. Never exposes a filesystem path.
 */
class ImportDraftPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function draft(ImportDraft $draft): array
    {
        $files = $draft->relationLoaded('files') ? $draft->files : $draft->files()->get();

        return [
            'id' => $draft->public_id,
            'revision' => $draft->revision,
            'plan_revision' => $draft->plan_revision,
            'state' => $draft->state->value,
            'created_at' => $draft->created_at?->toIso8601ZuluString(),
            'expires_at' => $draft->expires_at?->toIso8601ZuluString(),
            'source_summary' => [
                'display_name' => $draft->source_display_name,
                'file_count' => $files->count(),
                'bytes' => (int) $files->sum('bytes'),
            ],
            'recommendation' => $draft->recommendation,
            'interpretation_error' => $draft->interpretation_error,
            'plan' => $this->plan($draft),
            'transfer' => $draft->transfer_summary,
        ];
    }

    /**
     * The current approved plan, exactly as it was locked.
     *
     * @return array<string, mixed>|null
     */
    public function plan(ImportDraft $draft): ?array
    {
        if ($draft->plan_revision === null) {
            return null;
        }
        $plan = $draft->plans()->where('revision', $draft->plan_revision)->first();
        if ($plan === null) {
            return null;
        }

        return [
            'revision' => $plan->revision,
            'approved_at' => $plan->approved_at->toIso8601ZuluString(),
            'metadata' => $plan->metadata,
            'cover_artifact_id' => $plan->cover_artifact_id,
            'target' => $plan->target,
            'duplicate_action' => $plan->duplicate_action,
            'file_operation' => $plan->file_operation,
            'transfer_mode' => $plan->transfer_mode,
            'acknowledged_warning_ids' => $plan->recommendation_snapshot['acknowledged_warning_ids'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function event(ImportDraft $draft, ImportEvent $event): array
    {
        return [
            'id' => (string) $event->id,
            'event' => $event->event_type,
            'draft_id' => $draft->public_id,
            'revision' => $event->observed_revision,
            'created_at' => $event->created_at?->toIso8601ZuluString(),
            'data' => $event->payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function envelope(ImportDraft $draft): array
    {
        return [
            'contract_version' => config('import_drafts.contract_version'),
            'draft' => $this->draft($draft),
        ];
    }

    public function etag(ImportDraft $draft): string
    {
        return '"' . $draft->revision . '"';
    }
}
