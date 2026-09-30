<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ImportDraftState;
use App\Models\Imports\ImportDraft;
use App\Services\Imports\ImportStagingStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Deletes staged upload bytes of cancelled, failed or expired import drafts once the
 * retention window has passed. Only staging directories whose draft exists in the
 * active database are touched: another library profile's drafts may share the root.
 */
class PurgeImportStaging extends Command
{
    protected $signature = 'imports:purge-staging {--dry-run : List what would be deleted}';

    protected $description = 'Delete staged import uploads of cancelled, failed or expired drafts';

    public function handle(ImportStagingStore $staging): int
    {
        $retentionHours = max(0, (int) config('import_drafts.staging_retention_hours'));
        $cutoff = now()->subHours($retentionHours);
        $stagedIds = $staging->stagedDraftIds();
        $purged = 0;

        foreach (array_chunk($stagedIds, 500) as $chunk) {
            $drafts = ImportDraft::query()
                ->whereIn('public_id', $chunk)
                ->whereIn('state', [
                    ImportDraftState::CANCELLED->value,
                    ImportDraftState::FAILED->value,
                    ImportDraftState::EXPIRED->value,
                ])
                ->where('updated_at', '<=', $cutoff)
                ->get(['id', 'public_id', 'state']);
            foreach ($drafts as $draft) {
                if ($this->option('dry-run')) {
                    $this->line('Would delete staged uploads for ' . $draft->public_id);
                    $purged++;
                    continue;
                }
                if ($staging->deleteDraft($draft->public_id)) {
                    $purged++;
                    Log::info('Import draft staged bytes purged', [
                        'draft_id' => $draft->public_id,
                        'state' => $draft->state->value,
                    ]);
                }
            }
        }

        $this->info("Purged staged uploads for {$purged} import draft(s).");

        return Command::SUCCESS;
    }
}
