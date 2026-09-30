<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Enums\ImportDraftState;
use App\Models\Imports\ImportDraft;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drives created -> interpreting -> awaiting_review (or needs_attention/failed).
 * Each transition locks the draft, bumps the revision once, and appends one event.
 * Slow interpretation work runs outside the lock.
 */
class ImportInterpretationService
{
    public function __construct(
        private readonly ImportObservationInterpreter $interpreter,
        private readonly ImportDraftService $draftService,
    ) {
    }

    public function run(int $draftId): void
    {
        $draft = $this->begin($draftId);
        if ($draft === null) {
            return;
        }

        try {
            $recommendation = $this->interpreter->interpret($draft);
        } catch (ImportInterpretationException $e) {
            $this->finishWithError($draftId, $e->targetState, $e->errorCode, $e->getMessage());

            return;
        } catch (Throwable $e) {
            Log::error('Import draft interpretation failed', [
                'draft_id' => $draft->public_id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
            $this->markFailed($draftId);

            return;
        }

        $this->complete($draftId, $recommendation);
    }

    /**
     * Used when interpretation crashed or the worker gave up (timeout, lost process).
     */
    public function markFailed(int $draftId): void
    {
        $this->finishWithError(
            $draftId,
            ImportDraftState::FAILED,
            'interpretation_failed',
            'The server could not prepare a recommendation for this import. Try creating it again.'
        );
    }

    private function begin(int $draftId): ?ImportDraft
    {
        return DB::transaction(function () use ($draftId): ?ImportDraft {
            $draft = ImportDraft::query()->whereKey($draftId)->lockForUpdate()->first();
            if ($draft === null || $draft->state !== ImportDraftState::CREATED) {
                return null;
            }
            $this->transition($draft, ImportDraftState::INTERPRETING, []);

            return $draft;
        });
    }

    /**
     * @param array<string, mixed> $recommendation
     */
    private function complete(int $draftId, array $recommendation): void
    {
        DB::transaction(function () use ($draftId, $recommendation): void {
            $draft = $this->lockedInterpreting($draftId);
            if ($draft === null) {
                return;
            }
            $draft->recommendation = $recommendation;
            $draft->interpretation_error = null;
            $this->transition($draft, ImportDraftState::AWAITING_REVIEW, [
                'duplicate_candidates' => count($recommendation['duplicate_candidates']),
                'warnings' => count($recommendation['warnings']),
            ]);
        });
    }

    private function finishWithError(int $draftId, ImportDraftState $state, string $code, string $message): void
    {
        DB::transaction(function () use ($draftId, $state, $code, $message): void {
            $draft = $this->lockedInterpreting($draftId);
            if ($draft === null) {
                return;
            }
            $draft->interpretation_error = ['code' => $code, 'message' => $message];
            $this->transition($draft, $state, ['error_code' => $code]);
        });
    }

    /**
     * Returns null when the draft was cancelled (or otherwise moved on) meanwhile.
     */
    private function lockedInterpreting(int $draftId): ?ImportDraft
    {
        $draft = ImportDraft::query()->whereKey($draftId)->lockForUpdate()->first();

        return $draft !== null && $draft->state === ImportDraftState::INTERPRETING ? $draft : null;
    }

    /**
     * @param array<string, mixed> $details
     */
    private function transition(ImportDraft $draft, ImportDraftState $state, array $details): void
    {
        $draft->state = $state;
        $draft->revision = $draft->revision + 1;
        $draft->save();
        $this->draftService->recordEvent($draft, 'state_changed', ['state' => $state->value] + $details);
    }
}
