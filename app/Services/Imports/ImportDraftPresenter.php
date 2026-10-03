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
            'source_evidence' => $this->sourceEvidence($draft, $files),
            'recommendation' => $draft->recommendation,
            'interpretation_error' => $draft->interpretation_error,
            'evidence_requests' => $this->evidenceRequests($draft),
            'plan' => $this->plan($draft),
            'transfer' => $draft->transfer_summary,
        ];
    }

    /**
     * The observations used for this recommendation, with relative names only. Bound the response for large books.
     *
     * @param \Illuminate\Support\Collection<int, \App\Models\Imports\ImportDraftFile> $files
     * @return array<string, mixed>
     */
    private function sourceEvidence(ImportDraft $draft, $files): array
    {
        $shown = $files->take(20);
        return [
            'relative_context' => $draft->client_metadata['relative_context'] ?? null,
            'files' => $shown->values()->map(static function ($file): array {
                $tags = (array) ($file->media_observation['raw_tags'] ?? []);
                $tags = array_slice($tags, 0, 40, true);
                return [
                    'relative_path' => $file->relative_path,
                    'role' => $file->role,
                    'bytes' => (int) $file->bytes,
                    'raw_tags' => array_map(
                        static fn ($values): array => array_map(
                            static fn ($value): string => mb_substr((string) $value, 0, 500),
                            array_slice((array) $values, 0, 4)
                        ),
                        $tags
                    ),
                ];
            })->all(),
            'omitted_file_count' => max(0, $files->count() - $shown->count()),
        ];
    }

    /**
     * What the server asked the client for (an audio sample) and how each request ended.
     *
     * @return array<int, array<string, mixed>>
     */
    public function evidenceRequests(ImportDraft $draft): array
    {
        $public = ['id', 'type', 'status', 'file_id', 'start_ms', 'duration_ms', 'max_bytes', 'media_types', 'expires_at'];

        return array_map(
            static fn (array $request): array => array_intersect_key($request, array_flip($public)),
            array_values((array) ($draft->evidence_requests ?? []))
        );
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
