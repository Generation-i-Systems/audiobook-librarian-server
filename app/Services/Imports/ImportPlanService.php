<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Enums\ImportDraftState;
use App\Models\Imports\ImportDraft;
use App\Models\Imports\ImportDraftFile;
use App\Models\Imports\ImportPlan;
use App\Models\User;
use App\Services\BookImportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Validates an approval against the draft's recommendation and locks an
 * immutable ImportPlan holding exactly what the user confirmed.
 */
class ImportPlanService
{
    private const PLAN_KEYS = [
        'contract_version', 'expected_revision', 'metadata', 'cover_artifact_id', 'target', 'duplicate_action',
        'file_operation', 'transfer_mode', 'acknowledged_warning_ids',
    ];

    /**
     * Metadata that shaped the offered destinations and duplicate checks. The
     * approval may not change these without a PATCH that re-derives them.
     */
    private const REVIEWED_FIELDS = ['title', 'authors', 'narrators', 'series', 'genres'];

    public function __construct(
        private readonly ImportDraftService $draftService,
        private readonly ImportMetadataValidator $metadataValidator,
        private readonly ImportRecommendationPolicy $policy,
        private readonly BookImportService $importService,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public function approve(User $user, string $publicId, ?int $ifMatchRevision, array $body): ImportDraft
    {
        $draft = $this->draftService->findForUser($user, $publicId);
        $this->assertShape($body);

        return DB::transaction(function () use ($user, $draft, $ifMatchRevision, $body): ImportDraft {
            /** @var ImportDraft $locked */
            $locked = ImportDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            $locked->load('files');
            $this->draftService->assertRevision($locked, $ifMatchRevision);
            $this->draftService->assertRevision($locked, (int) $body['expected_revision']);
            if ($locked->state !== ImportDraftState::AWAITING_REVIEW) {
                throw ImportApiException::invalidState($locked->state->value, 'approved');
            }

            $metadata = $this->metadataValidator->validateApproved($body['metadata']);
            $recommendation = (array) $locked->recommendation;
            $this->assertReviewedMetadata($metadata, (array) ($recommendation['metadata'] ?? []));
            $this->assertGenres($metadata['genres'] ?? []);
            $candidate = $this->assertDecisions($locked, $recommendation, $body);
            $this->assertCoverArtifact($locked, $body['cover_artifact_id'] ?? null);
            $this->assertCoverArtifact($locked, $metadata['cover_artifact_id'] ?? null);
            $acknowledged = $this->assertAcknowledgements($recommendation, $body['acknowledged_warning_ids'] ?? []);

            $planRevision = $locked->revision + 1;
            $duplicateBookId = $this->decision($recommendation, 'duplicate_action')['related_book_id'] ?? null;
            $plan = ImportPlan::query()->create([
                'draft_id' => $locked->id,
                'revision' => $planRevision,
                'approved_by_user_id' => (int) $user->id,
                'approved_at' => now(),
                'metadata' => $metadata,
                'cover_artifact_id' => $body['cover_artifact_id'] ?? null,
                'target' => [
                    'candidate_id' => $candidate['id'],
                    'relative_directory' => $candidate['relative_directory'],
                    'duplicate_book_id' => $duplicateBookId,
                ],
                'duplicate_action' => $body['duplicate_action'],
                'file_operation' => $body['file_operation'],
                'transfer_mode' => $body['transfer_mode'],
                'manifest_snapshot' => $this->manifest($locked),
                'recommendation_snapshot' => $recommendation + ['acknowledged_warning_ids' => $acknowledged],
            ]);
            $this->assertPlanMatchesApproval($plan, $metadata, $body);

            $locked->plan_revision = $planRevision;
            $locked->revision = $planRevision;
            $locked->state = ImportDraftState::APPROVED;
            $locked->save();
            $this->draftService->recordEvent($locked, 'state_changed', [
                'state' => $locked->state->value,
                'plan_revision' => $planRevision,
            ]);

            Log::info('Import draft approved', [
                'draft_id' => $locked->public_id,
                'plan_revision' => $planRevision,
                'duplicate_action' => $body['duplicate_action'],
                'target' => $candidate['id'],
            ]);

            return $locked;
        });
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertShape(array $body): void
    {
        $errors = [];
        foreach (array_diff(array_keys($body), self::PLAN_KEYS) as $unknown) {
            $errors[$unknown] = 'This field is not part of an import plan.';
        }
        if (($body['contract_version'] ?? null) !== config('import_drafts.contract_version')) {
            $errors['contract_version'] = 'Must be ' . config('import_drafts.contract_version') . '.';
        }
        if (!is_int($body['expected_revision'] ?? null) || $body['expected_revision'] < 1) {
            $errors['expected_revision'] = 'Must be the draft revision you reviewed.';
        }
        if (!is_array($body['target'] ?? null) || !is_string($body['target']['candidate_id'] ?? null)) {
            $errors['target.candidate_id'] = 'Choose one of the offered destinations.';
        }
        foreach (['duplicate_action', 'file_operation', 'transfer_mode'] as $field) {
            if (!is_string($body[$field] ?? null)) {
                $errors[$field] = 'Required.';
            }
        }
        if (
            array_key_exists('cover_artifact_id', $body) && $body['cover_artifact_id'] !== null
            && !is_string($body['cover_artifact_id'])
        ) {
            $errors['cover_artifact_id'] = 'Must be an artifact id or null.';
        }
        $acknowledged = $body['acknowledged_warning_ids'] ?? [];
        if (
            !is_array($acknowledged) || !array_is_list($acknowledged)
            || array_filter($acknowledged, static fn (mixed $id): bool => !is_string($id)) !== []
        ) {
            $errors['acknowledged_warning_ids'] = 'Must be a list of warning ids.';
        }
        if (!array_key_exists('metadata', $body)) {
            $errors['metadata'] = 'Required.';
        }
        if ($errors !== []) {
            throw ImportApiException::validation('The import plan was not valid.', ['fields' => $errors]);
        }
    }

    /**
     * @param array<string, mixed> $approved
     * @param array<string, mixed> $reviewed
     */
    private function assertReviewedMetadata(array $approved, array $reviewed): void
    {
        $changed = [];
        foreach (self::REVIEWED_FIELDS as $field) {
            $approvedValue = $this->comparable($field, $approved[$field] ?? null);
            if ($approvedValue !== $this->comparable($field, $reviewed[$field] ?? null)) {
                $changed[] = $field;
            }
        }
        if ($changed !== []) {
            throw ImportApiException::policy(
                'metadata_not_reviewed',
                'Save these changes to the draft first so the destination and duplicate checks can be refreshed.',
                ['fields' => $changed]
            );
        }
    }

    private function comparable(string $field, mixed $value): mixed
    {
        if ($field === 'series') {
            if (!is_array($value) || ($value['name'] ?? null) === null) {
                return null;
            }
            $number = $value['number'] ?? null;

            return [$value['name'], is_int($number) || is_float($number) ? (float) $number : null];
        }
        if (in_array($field, ['authors', 'narrators', 'genres'], true)) {
            return is_array($value) ? array_values($value) : [];
        }

        return $value;
    }

    /**
     * @param array<int, string> $genres
     */
    private function assertGenres(array $genres): void
    {
        $valid = $this->importService->getValidGenres();
        foreach ($genres as $genre) {
            if (!in_array($genre, $valid, true)) {
                throw ImportApiException::policy('invalid_genre', 'Choose a genre from the library\'s genre list.', [
                    'genre' => $genre,
                    'suggestion' => $this->importService->mapToValidGenre($genre),
                    'valid_genres' => $valid,
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $recommendation
     * @param array<string, mixed> $body
     * @return array<string, mixed> the chosen target candidate
     */
    private function assertDecisions(ImportDraft $draft, array $recommendation, array $body): array
    {
        $duplicateAction = (string) $body['duplicate_action'];
        $duplicateOptions = (array) ($this->decision($recommendation, 'duplicate_action')['options'] ?? []);
        if (!in_array($duplicateAction, $duplicateOptions, true)) {
            throw ImportApiException::policy('invalid_duplicate_action', 'Choose an offered duplicate action.', [
                'duplicate_action' => $duplicateAction,
                'options' => $duplicateOptions,
            ]);
        }

        $candidateId = (string) $body['target']['candidate_id'];
        $candidate = null;
        foreach ((array) ($recommendation['target_candidates'] ?? []) as $offered) {
            if (($offered['id'] ?? null) === $candidateId) {
                $candidate = $offered;
            }
        }
        if ($candidate === null) {
            throw ImportApiException::policy('invalid_target', 'Choose one of the offered destinations.', [
                'candidate_id' => $candidateId,
            ]);
        }
        if ($duplicateAction !== 'skip') {
            $this->assertTargetUsable($candidate, $duplicateAction);
        }

        $transferMode = (string) $body['transfer_mode'];
        if ($transferMode !== $draft->source_mode) {
            throw ImportApiException::policy('invalid_transfer_mode', 'The transfer mode must match the draft.', [
                'transfer_mode' => $draft->source_mode,
            ]);
        }
        $operations = $this->policy->fileOperationsFor($transferMode);
        if (!in_array($body['file_operation'], $operations, true)) {
            throw ImportApiException::policy('invalid_file_operation', 'That file operation is not allowed here.', [
                'file_operation' => $body['file_operation'],
                'options' => $operations,
            ]);
        }

        return $candidate;
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function assertTargetUsable(array $candidate, string $duplicateAction): void
    {
        if (!in_array($duplicateAction, (array) ($candidate['duplicate_actions'] ?? []), true)) {
            throw ImportApiException::policy(
                'target_incompatible',
                'That destination cannot be used with the chosen duplicate action.',
                ['candidate_id' => $candidate['id'], 'duplicate_actions' => $candidate['duplicate_actions'] ?? []]
            );
        }
        $isNewDirectory = $candidate['id'] !== ImportRecommendationPolicy::TARGET_EXISTING_BOOK;
        if (
            ($candidate['available'] ?? false) !== true
            || ($isNewDirectory && $this->policy->isTargetOccupied((string) $candidate['relative_directory']))
        ) {
            throw ImportApiException::policy(
                'target_unavailable',
                'That destination folder is in use. Edit the draft to refresh the destinations.',
                ['candidate_id' => $candidate['id']]
            );
        }
    }

    private function assertCoverArtifact(ImportDraft $draft, mixed $coverArtifactId): void
    {
        if ($coverArtifactId === null) {
            return;
        }
        $known = $draft->artifacts()->pluck('artifact_id')->all();
        foreach ($draft->files as $file) {
            $known[] = $file->image_artifact_id;
            $known[] = $file->media_observation['embedded_cover']['artifact_id'] ?? null;
        }
        if (!in_array($coverArtifactId, $known, true)) {
            throw ImportApiException::policy('invalid_cover_artifact', 'Choose one of this import\'s cover images.', [
                'cover_artifact_id' => $coverArtifactId,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $recommendation
     * @param array<int, string> $acknowledged
     * @return array<int, string>
     */
    private function assertAcknowledgements(array $recommendation, array $acknowledged): array
    {
        $warnings = (array) ($recommendation['warnings'] ?? []);
        $unknown = array_values(array_diff($acknowledged, array_column($warnings, 'id')));
        if ($unknown !== []) {
            throw ImportApiException::policy('unknown_warning', 'Some acknowledged warnings do not exist.', [
                'warning_ids' => $unknown,
            ]);
        }
        $required = array_column(array_filter(
            $warnings,
            static fn (array $warning): bool => ($warning['requires_acknowledgment'] ?? false) === true
        ), 'id');
        $missing = array_values(array_diff($required, $acknowledged));
        if ($missing !== []) {
            throw ImportApiException::policy('warnings_not_acknowledged', 'Acknowledge the warnings first.', [
                'warning_ids' => $missing,
            ]);
        }

        return $acknowledged;
    }

    /**
     * @param array<string, mixed> $recommendation
     * @return array<string, mixed>|null
     */
    private function decision(array $recommendation, string $id): ?array
    {
        foreach ((array) ($recommendation['required_decisions'] ?? []) as $decision) {
            if (($decision['id'] ?? null) === $id) {
                return $decision;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function manifest(ImportDraft $draft): array
    {
        return $draft->files->map(static fn (ImportDraftFile $file): array => [
            'file_id' => $file->file_id,
            'relative_path' => $file->relative_path,
            'role' => $file->role,
            'bytes' => $file->bytes,
            'sha256' => $file->sha256,
        ])->values()->all();
    }

    /**
     * Hard check before the approval becomes visible: the stored plan must hold
     * exactly the confirmed values (see BookImportService::assertDirectoryPathConfirmed()).
     *
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $body
     */
    private function assertPlanMatchesApproval(ImportPlan $plan, array $metadata, array $body): void
    {
        $stored = ImportPlan::query()->whereKey($plan->id)->firstOrFail();
        $matches = $stored->metadata == $metadata
            && $stored->duplicate_action === $body['duplicate_action']
            && $stored->file_operation === $body['file_operation']
            && $stored->transfer_mode === $body['transfer_mode']
            && ($stored->target['candidate_id'] ?? null) === $body['target']['candidate_id']
            && $stored->cover_artifact_id === ($body['cover_artifact_id'] ?? null);
        if (!$matches) {
            throw new RuntimeException('Import plan ' . $plan->id . ' does not match the confirmed approval.');
        }
    }
}
