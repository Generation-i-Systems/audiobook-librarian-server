<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Enums\ImportDraftState;
use App\Jobs\ResumeImportDraftInterpretationJob;
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
        private readonly ImportEvidenceService $evidence,
        private readonly ImportStagingStore $staging,
    ) {
    }

    public function run(int $draftId): void
    {
        $draft = $this->begin($draftId);
        if ($draft === null) {
            return;
        }

        $this->interpretAndFinish($draft, null, true);
    }

    /**
     * Interprets and either asks the client for evidence or completes. $mayRequestEvidence is false on the
     * pass that already has the client's answer.
     */
    private function interpretAndFinish(ImportDraft $draft, ?array $audioMetadata, bool $mayRequestEvidence): void
    {
        $draftId = $draft->id;
        try {
            $recommendation = $this->interpreter->interpret($draft, $audioMetadata);
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

        if ($mayRequestEvidence && $this->evidence->shouldRequest($draft, $recommendation)) {
            $this->requestEvidence($draftId, $recommendation);

            return;
        }

        $this->complete($draftId, $recommendation);
    }

    /**
     * Continues a draft that was waiting for an audio sample: the client answered, or the request ran out.
     * Safe to call at any time; it does nothing while the request is still pending and not yet due, and a
     * second concurrent call does nothing once the first has claimed the outcome.
     */
    public function resume(int $draftId): void
    {
        $claimed = DB::transaction(function () use ($draftId): ?array {
            $draft = $this->lockedInterpreting($draftId);
            if ($draft === null) {
                return null;
            }
            $requests = array_values((array) ($draft->evidence_requests ?? []));
            foreach ($requests as $index => $request) {
                if (isset($request['claimed_at'])) {
                    continue;
                }
                if ($request['status'] === 'pending') {
                    if (!$this->evidence->due($request)) {
                        continue;
                    }
                    $requests[$index]['status'] = 'expired';
                    $request['status'] = 'expired';
                }
                $requests[$index]['claimed_at'] = now()->toIso8601ZuluString();
                $draft->evidence_requests = $requests;
                $draft->save();

                return ['draft' => $draft, 'request' => $request];
            }

            return null;
        });
        if ($claimed === null) {
            return;
        }

        /** @var ImportDraft $draft */
        $draft = $claimed['draft'];
        $request = $claimed['request'];
        if ($request['status'] === 'answered') {
            $audio = $this->analyzeSample($draft, (string) $request['id']);
            $this->interpretAndFinish($draft, $audio, false);

            return;
        }

        // Unavailable or expired: keep the recommendation already made (no second AI call) and say why.
        $recommendation = (array) $draft->recommendation;
        $recommendation['warnings'] = array_merge((array) ($recommendation['warnings'] ?? []), [$this->evidenceWarning((string) $request['status'])]);
        $this->complete($draftId, $recommendation);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function analyzeSample(ImportDraft $draft, string $requestId): ?array
    {
        $path = $this->staging->evidencePath($draft->public_id, $requestId);
        try {
            return is_file($path) ? $this->interpreter->analyzeAudioSample($path, $draft->source_display_name) : null;
        } catch (Throwable $e) {
            Log::warning('Import draft audio analysis failed', ['draft_id' => $draft->public_id, 'error' => $e->getMessage()]);

            return null;
        } finally {
            $this->staging->deleteEvidence($draft->public_id, $requestId);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function evidenceWarning(string $status): array
    {
        $expired = $status === 'expired';

        return [
            'id' => $expired ? 'audio_evidence_expired' : 'audio_evidence_unavailable',
            'code' => $expired ? 'audio_evidence_expired' : 'audio_evidence_unavailable',
            'message' => $expired ? 'An audio sample was not received in time. The recommendation uses the files only.' : 'An audio sample could not be sent from your computer. The recommendation uses the files only.',
            'requires_acknowledgment' => false,
        ];
    }

    /**
     * @param array<string, mixed> $recommendation
     */
    private function requestEvidence(int $draftId, array $recommendation): void
    {
        $requestId = null;
        DB::transaction(function () use ($draftId, $recommendation, &$requestId): void {
            $draft = $this->lockedInterpreting($draftId);
            if ($draft === null) {
                return;
            }
            $request = $this->evidence->newRequest($draft);
            $requestId = $request['id'];
            $draft->recommendation = $recommendation;
            $draft->evidence_requests = [$request];
            $draft->revision = $draft->revision + 1;
            $draft->save();
            $this->draftService->recordEvent($draft, 'evidence_requested', [
                'request_id' => $request['id'],
                'type' => $request['type'],
                'file_id' => $request['file_id'],
                'expires_at' => $request['expires_at'],
            ]);
        });
        if ($requestId !== null) {
            // Closes the request (and finishes the draft) if the client never answers.
            ResumeImportDraftInterpretationJob::dispatch($draftId)
                ->delay(now()->addSeconds((int) config('import_drafts.audio_evidence.request_ttl_seconds') + 1));
        }
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
