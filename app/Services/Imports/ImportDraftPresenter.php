<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\Imports\ImportDraft;

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
            'transfer' => $draft->transfer_summary,
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
