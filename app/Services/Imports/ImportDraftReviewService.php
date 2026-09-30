<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Enums\ImportDraftState;
use App\Models\Imports\ImportDraft;
use App\Models\Imports\ImportPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Revision-safe metadata edits during review (PATCH /imports/drafts/{id}).
 *
 * Edits are allowed in awaiting_review. Editing an approved draft explicitly
 * invalidates the locked plan (recorded, never overwritten) and returns the
 * draft to awaiting_review, as the rebuild plan requires. Every edit re-derives
 * duplicates, destinations and decisions from the edited metadata.
 */
class ImportDraftReviewService
{
    public const EVENT_TARGETS_REFRESHED = 'targets_refreshed';
    public const TRIGGER_REVIEW_PATCH = 'review_patch';
    public const TRIGGER_APPROVAL = 'approval';

    public function __construct(
        private readonly ImportDraftService $draftService,
        private readonly ImportMetadataValidator $metadataValidator,
        private readonly ImportRecommendationPolicy $policy,
        private readonly ImportObservationInterpreter $interpreter,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public function update(User $user, string $publicId, ?int $expectedRevision, array $body): ImportDraft
    {
        $draft = $this->draftService->findForUser($user, $publicId);
        if ($expectedRevision === null) {
            throw ImportApiException::revisionRequired();
        }
        $patch = $this->validateBody($body);

        return DB::transaction(function () use ($draft, $expectedRevision, $patch): ImportDraft {
            /** @var ImportDraft $locked */
            $locked = ImportDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            $this->draftService->assertRevision($locked->load('files'), $expectedRevision);
            if (!in_array($locked->state, [ImportDraftState::AWAITING_REVIEW, ImportDraftState::APPROVED], true)) {
                throw ImportApiException::invalidState($locked->state->value, 'edited');
            }

            $recommendation = (array) $locked->recommendation;
            $current = (array) ($recommendation['metadata'] ?? []);
            $edited = $this->metadataValidator->merge($current, $patch);
            $this->metadataValidator->assertMergedValid($edited);
            $changed = array_keys(array_filter(
                $patch,
                static fn (mixed $value, string $field): bool
                    => ($current[$field] ?? null) !== ($edited[$field] ?? null),
                ARRAY_FILTER_USE_BOTH
            ));
            if ($changed === []) {
                $this->refreshTargets($locked, self::TRIGGER_REVIEW_PATCH);

                return $locked->load('files');
            }

            $locked->revision = $locked->revision + 1;
            if ($locked->state === ImportDraftState::APPROVED) {
                $this->invalidatePlan($locked);
            }
            $locked->recommendation = $this->reviewedRecommendation($locked, $recommendation, $edited, $changed);
            $locked->save();
            $this->draftService->recordEvent($locked, 'metadata_updated', ['fields' => $changed]);

            return $locked->load('files');
        });
    }

    /**
     * Re-derives the server-computed parts of a locked draft (duplicates, destinations,
     * policy warnings, required decisions) from its stored metadata, so destination
     * availability is current. The metadata and its provenance are never touched.
     *
     * When anything changed the revision is bumped once and a targets_refreshed event is
     * recorded. An approved draft is never rewritten: its locked plan was built from the
     * old recommendation, so a change fails with approved_plan_stale instead.
     *
     * @return bool whether the recommendation changed
     */
    public function refreshTargets(ImportDraft $locked, string $trigger): bool
    {
        $recommendation = (array) $locked->recommendation;
        $refreshed = $this->withDerivedPolicy($locked, $recommendation, (array) ($recommendation['metadata'] ?? []));
        if ($refreshed == $recommendation) {
            return false;
        }
        if ($locked->state !== ImportDraftState::AWAITING_REVIEW) {
            throw ImportApiException::approvedPlanStale((int) $locked->plan_revision, $locked->revision);
        }

        $locked->revision = $locked->revision + 1;
        $locked->recommendation = $refreshed;
        $locked->save();
        $this->draftService->recordEvent($locked, self::EVENT_TARGETS_REFRESHED, [
            'trigger' => $trigger,
            'unavailable_candidate_ids' => array_column(array_filter(
                $refreshed['target_candidates'],
                static fn (array $candidate): bool => $candidate['available'] !== true
            ), 'id'),
        ]);

        return true;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function validateBody(array $body): array
    {
        if (array_key_exists('local_ai_artifacts', $body)) {
            throw ImportApiException::policy(
                'local_ai_artifacts_not_supported',
                'This server does not accept local AI results yet.'
            );
        }
        $unknown = array_diff(array_keys($body), ['contract_version', 'metadata']);
        if ($unknown !== []) {
            throw ImportApiException::validation(
                'Only book details can be edited.',
                ['fields' => array_values($unknown)]
            );
        }
        $contractVersion = config('import_drafts.contract_version');
        if (isset($body['contract_version']) && $body['contract_version'] !== $contractVersion) {
            throw ImportApiException::validation('Unsupported contract version.');
        }

        return $this->metadataValidator->validatePatch($body['metadata'] ?? []);
    }

    private function invalidatePlan(ImportDraft $draft): void
    {
        ImportPlan::query()
            ->where('draft_id', $draft->id)
            ->where('revision', $draft->plan_revision)
            ->whereNull('invalidated_at')
            ->update(['invalidated_at' => now(), 'invalidated_reason' => 'metadata_edited_after_approval']);
        $this->draftService->recordEvent($draft, 'plan_invalidated', [
            'plan_revision' => $draft->plan_revision,
            'reason' => 'metadata_edited_after_approval',
        ]);
        $draft->plan_revision = null;
        $draft->state = ImportDraftState::AWAITING_REVIEW;
        $this->draftService->recordEvent($draft, 'state_changed', ['state' => $draft->state->value]);
    }

    /**
     * @param array<string, mixed> $recommendation
     * @param array<string, mixed> $edited
     * @param array<int, string> $changed
     * @return array<string, mixed>
     */
    private function reviewedRecommendation(
        ImportDraft $draft,
        array $recommendation,
        array $edited,
        array $changed
    ): array {
        $provenance = (array) ($recommendation['field_provenance'] ?? []);
        foreach ($changed as $field) {
            $entries = array_values(array_filter(
                (array) ($provenance[$field] ?? []),
                static fn (array $entry): bool
                    => ($entry['source_id'] ?? null) !== ImportObservationInterpreter::SOURCE_USER_EDIT
            ));
            array_unshift($entries, $this->interpreter->provenanceEntry(
                ImportObservationInterpreter::SOURCE_USER_EDIT,
                $edited[$field],
                ImportObservationInterpreter::CONFIDENCE[ImportObservationInterpreter::SOURCE_USER_EDIT]
            ));
            $provenance[$field] = $entries;
        }

        return array_merge($this->withDerivedPolicy($draft, $recommendation, $edited), [
            'metadata' => $edited,
            'field_provenance' => $provenance,
        ]);
    }

    /**
     * The recommendation with its policy parts re-derived from $metadata; other
     * warnings and every non-policy key are kept as they were.
     *
     * @param array<string, mixed> $recommendation
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function withDerivedPolicy(ImportDraft $draft, array $recommendation, array $metadata): array
    {
        $keptWarnings = array_values(array_filter(
            (array) ($recommendation['warnings'] ?? []),
            static fn (array $warning): bool => !in_array(
                $warning['code'] ?? null,
                ImportRecommendationPolicy::POLICY_WARNING_CODES,
                true
            )
        ));
        $derived = $this->policy->derive(
            $draft,
            $metadata,
            (array) ($recommendation['identifiers'] ?? []),
            $keptWarnings
        );

        return array_merge($recommendation, $derived);
    }
}
