<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Enums\ImportDraftState;
use App\Models\Imports\ImportDraft;
use App\Models\Imports\ImportDraftFile;
use App\Models\Imports\ImportPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Resumable, tus-compatible uploads of an approved plan's files (imports.v1 phase 5).
 *
 * Every mutation locks the draft row first and then the file row, so the order is the
 * same everywhere and two appends to one draft cannot interleave. Nothing here queues
 * or imports anything.
 */
class ImportTransferService
{
    public const FILE_NOT_STARTED = 'not_started';
    public const FILE_UPLOADING = 'uploading';
    public const FILE_UPLOADED = 'uploaded';
    public const FILE_REJECTED = 'rejected';
    public const FILE_VERIFIED = 'verified';

    public const SUMMARY_NOT_STARTED = 'not_started';
    public const SUMMARY_TRANSFERRING = 'transferring';
    public const SUMMARY_VERIFYING = 'verifying';
    public const SUMMARY_VERIFIED = 'verified';

    private const UPLOAD_CONTENT_TYPE = 'application/offset+octet-stream';

    private const TRANSFER_STATES = [
        ImportDraftState::APPROVED,
        ImportDraftState::TRANSFERRING,
        ImportDraftState::VERIFYING,
    ];

    public function __construct(
        private readonly ImportDraftService $draftService,
        private readonly ImportStagingStore $staging,
        private readonly ImportRelativePathNormalizer $pathNormalizer,
    ) {
    }

    /**
     * POST /uploads: opens (or reopens) sessions for approved manifest files and reports
     * each file's confirmed offset, so a repeated call after a restart is a safe resume.
     *
     * @param array<string, mixed> $body
     * @return array{draft: ImportDraft, uploads: array<int, array{file_id: string, upload_url: string, offset: int}>}
     */
    public function createSessions(User $user, string $publicId, ?int $ifMatch, array $body): array
    {
        $draft = $this->draftService->findForUser($user, $publicId);
        if ($ifMatch === null) {
            throw ImportApiException::revisionRequired();
        }
        [$planRevision, $requested] = $this->validateSessionRequest($body);

        return DB::transaction(function () use ($draft, $ifMatch, $planRevision, $requested): array {
            $locked = $this->lockDraft($draft);
            $this->draftService->assertRevision($locked, $ifMatch);
            $this->assertTransferState($locked, 'uploaded');
            $plan = $this->livePlan($locked, $planRevision);
            $this->assertManifestMatches($plan, $requested);

            $files = $locked->files->keyBy('file_id');
            $changed = $locked->state === ImportDraftState::APPROVED;
            foreach ($requested as $fileId => $request) {
                /** @var ImportDraftFile $file */
                $file = $files[$fileId];
                $changed = $this->openSession($locked, $file, $request['sha256']) || $changed;
            }
            $changed = $this->materializeEmptyFiles($locked) || $changed;
            if ($changed) {
                $this->applyProgress($locked, true);
            }

            $uploads = [];
            foreach (array_keys($requested) as $fileId) {
                $uploads[] = [
                    'file_id' => (string) $fileId,
                    'upload_url' => $this->uploadUrl($locked, (string) $fileId),
                    'offset' => (int) $files[$fileId]->received_bytes,
                ];
            }

            return ['draft' => $locked->load('files'), 'uploads' => $uploads];
        });
    }

    /**
     * HEAD /uploads/{fileId}: the confirmed offset and expected length.
     *
     * @return array{offset: int, length: int}
     */
    public function offset(User $user, string $publicId, string $fileId): array
    {
        $draft = $this->draftService->findForUser($user, $publicId);

        return DB::transaction(function () use ($draft, $fileId): array {
            $locked = $this->lockDraft($draft);
            $this->assertUploadState($locked);
            $file = $this->lockSessionFile($locked, $fileId);
            $this->reconcileStagedLength($locked, $file);

            return ['offset' => (int) $file->received_bytes, 'length' => (int) $file->bytes];
        });
    }

    /**
     * PATCH /uploads/{fileId}: appends one sequential chunk and returns the new offset.
     *
     * @param resource $body
     */
    public function append(
        User $user,
        string $publicId,
        string $fileId,
        ?string $contentType,
        ?string $offsetHeader,
        ?string $contentLength,
        $body
    ): int {
        $draft = $this->draftService->findForUser($user, $publicId);
        $mediaType = strtolower(trim(explode(';', (string) $contentType)[0]));
        if ($mediaType !== self::UPLOAD_CONTENT_TYPE) {
            throw new ImportApiException(
                415,
                'unsupported_media_type',
                'Upload chunks must be sent as ' . self::UPLOAD_CONTENT_TYPE . '.'
            );
        }
        if ($offsetHeader === null || !ctype_digit(trim($offsetHeader))) {
            throw ImportApiException::validation('The Upload-Offset header must be a non-negative integer.');
        }
        $offset = (int) trim($offsetHeader);
        $maxChunk = (int) config('import_drafts.max_upload_chunk_bytes');
        if ($contentLength !== null && ctype_digit(trim($contentLength)) && (int) $contentLength > $maxChunk) {
            throw $this->chunkTooLarge($maxChunk);
        }
        $chunk = $this->staging->bufferBody($body, $maxChunk);

        try {
            $result = DB::transaction(function () use ($draft, $fileId, $offset, $chunk): int|ImportApiException {
                $locked = $this->lockDraft($draft);
                $this->assertUploadState($locked);
                $file = $this->lockSessionFile($locked, $fileId);
                if ($this->reconcileStagedLength($locked, $file) && $offset !== 0) {
                    return $this->offsetConflict(0);
                }

                return $this->appendLocked($locked, $file, $offset, $chunk['stream'], $chunk['bytes']);
            });
        } finally {
            fclose($chunk['stream']);
        }
        if ($result instanceof ImportApiException) {
            throw $result;
        }

        return $result;
    }

    /**
     * POST /verify: re-hashes every staged file against its expected SHA-256 and length,
     * checks the staged layout and binds the result to draft_id + plan_revision.
     */
    public function verify(User $user, string $publicId, ?int $ifMatch): ImportDraft
    {
        $draft = $this->draftService->findForUser($user, $publicId);
        if ($ifMatch === null) {
            throw ImportApiException::revisionRequired();
        }
        $this->draftService->assertRevision($draft, $ifMatch);
        $this->assertTransferState($draft, 'verified');
        $plan = $this->livePlan($draft, (int) $draft->plan_revision);
        if (
            $draft->transfer_verified_plan_revision === $plan->revision
            && ($draft->transfer_summary['state'] ?? null) === self::SUMMARY_VERIFIED
        ) {
            return $draft;
        }

        $incomplete = $draft->files
            ->filter(fn (ImportDraftFile $file): bool => $file->bytes > 0 && !$this->isComplete($file))
            ->pluck('file_id')->values()->all();
        if ($incomplete !== []) {
            throw ImportApiException::policy(
                'upload_incomplete',
                'Some files have not finished uploading.',
                ['file_ids' => $incomplete]
            );
        }
        $this->assertStagedLayout($draft, $plan);

        // Hash outside the lock (large books take a while); the revision check below
        // proves nothing changed in between.
        $failed = [];
        foreach ($draft->files as $file) {
            if ($file->bytes > 0 && !$this->stagedBytesMatch($file)) {
                $failed[] = $file->file_id;
            }
        }

        $result = DB::transaction(function () use ($draft, $ifMatch, $plan, $failed): ImportDraft|ImportApiException {
            $locked = $this->lockDraft($draft);
            $this->draftService->assertRevision($locked, $ifMatch);
            $this->assertTransferState($locked, 'verified');
            $this->livePlan($locked, $plan->revision);
            if ($failed !== []) {
                return $this->rejectVerification($locked, $failed);
            }
            $this->materializeEmptyFiles($locked);

            return $this->recordVerified($locked, $plan);
        });
        if ($result instanceof ImportApiException) {
            throw $result;
        }

        return $result;
    }

    /**
     * Resets every file of a locked draft to not_started, e.g. when its plan is
     * invalidated. The caller deletes the staged bytes after its transaction commits.
     */
    public function resetTransfer(ImportDraft $locked, string $reason): bool
    {
        $summary = (array) $locked->transfer_summary;
        $touched = $locked->files()->where(function ($query): void {
            $query->where('transfer_state', '!=', self::FILE_NOT_STARTED)->orWhereNotNull('expected_sha256');
        })->exists();
        if (!$touched && ($summary['state'] ?? self::SUMMARY_NOT_STARTED) === self::SUMMARY_NOT_STARTED) {
            return false;
        }

        $locked->files()->update([
            'transfer_state' => self::FILE_NOT_STARTED,
            'received_bytes' => 0,
            'received_sha256' => null,
            'expected_sha256' => null,
            'staged_relative_path' => null,
            'uploaded_at' => null,
            'verified_at' => null,
        ]);
        $locked->transfer_summary = array_merge($summary, [
            'state' => self::SUMMARY_NOT_STARTED,
            'bytes_verified' => 0,
        ]);
        $locked->transfer_verified_plan_revision = null;
        $locked->transfer_verified_at = null;
        $this->draftService->recordEvent($locked, 'transfer_reset', ['reason' => $reason]);
        $locked->unsetRelation('files');

        return true;
    }

    public function deleteStagedBytes(ImportDraft $draft): void
    {
        if ($this->staging->deleteDraft($draft->public_id)) {
            Log::info('Import draft staged bytes deleted', ['draft_id' => $draft->public_id]);
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: int, 1: array<string, array{sha256: string, bytes: int}>}
     */
    private function validateSessionRequest(array $body): array
    {
        $errors = [];
        $planRevision = $body['plan_revision'] ?? null;
        if (!is_int($planRevision) || $planRevision < 1) {
            $errors['plan_revision'] = 'Must be the approved plan revision.';
        }
        $files = $body['files'] ?? null;
        $requested = [];
        if (!is_array($files) || !array_is_list($files) || $files === []) {
            $errors['files'] = 'List at least one file to upload.';
            $files = [];
        }
        foreach ($files as $index => $file) {
            $fileId = is_array($file) ? ($file['file_id'] ?? null) : null;
            $sha256 = is_array($file) ? ($file['sha256'] ?? null) : null;
            $bytes = is_array($file) ? ($file['bytes'] ?? null) : null;
            if (!is_string($fileId) || $fileId === '' || strlen($fileId) > 128) {
                $errors['files.' . $index . '.file_id'] = 'Required.';
            } elseif (isset($requested[$fileId])) {
                $errors['files.' . $index . '.file_id'] = 'Listed more than once.';
            }
            if (!is_string($sha256) || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
                $errors['files.' . $index . '.sha256'] = 'Must be a lowercase hex SHA-256.';
            }
            if (!is_int($bytes) || $bytes < 1) {
                $errors['files.' . $index . '.bytes'] = 'Must be at least 1.';
            }
            if (is_string($fileId) && is_string($sha256) && is_int($bytes)) {
                $requested[$fileId] = ['sha256' => $sha256, 'bytes' => $bytes];
            }
        }
        if ($errors !== []) {
            throw ImportApiException::validation('The upload request was not valid.', ['fields' => $errors]);
        }

        return [(int) $planRevision, $requested];
    }

    /**
     * @param array<string, array{sha256: string, bytes: int}> $requested
     */
    private function assertManifestMatches(ImportPlan $plan, array $requested): void
    {
        $manifest = collect($plan->manifest_snapshot)->keyBy('file_id');
        $unknown = array_values(array_filter(
            array_keys($requested),
            static fn (int|string $fileId): bool => !$manifest->has((string) $fileId)
        ));
        if ($unknown !== []) {
            throw ImportApiException::policy(
                'unknown_file',
                'Some files are not part of the approved import.',
                ['file_ids' => array_map('strval', $unknown)]
            );
        }
        foreach ($requested as $fileId => $request) {
            $entry = (array) $manifest[(string) $fileId];
            if ((int) $entry['bytes'] !== $request['bytes']) {
                throw ImportApiException::policy('upload_manifest_mismatch', 'A file changed size since approval.', [
                    'file_id' => (string) $fileId,
                    'field' => 'bytes',
                    'expected_bytes' => (int) $entry['bytes'],
                ]);
            }
            if (($entry['sha256'] ?? null) !== null && $entry['sha256'] !== $request['sha256']) {
                throw ImportApiException::policy('upload_manifest_mismatch', 'A file changed since approval.', [
                    'file_id' => (string) $fileId,
                    'field' => 'sha256',
                ]);
            }
        }
    }

    private function openSession(ImportDraft $draft, ImportDraftFile $file, string $sha256): bool
    {
        if ($file->expected_sha256 === $sha256 && $file->staged_relative_path !== null) {
            return false;
        }
        $wasStarted = $file->expected_sha256 !== null;
        $file->fill([
            'expected_sha256' => $sha256,
            'staged_relative_path' => $this->staging->relativePath($draft, $file),
            'transfer_state' => self::FILE_UPLOADING,
            'received_bytes' => 0,
            'received_sha256' => null,
            'uploaded_at' => null,
            'verified_at' => null,
        ])->save();
        if ($wasStarted) {
            // A new hash for the same file means the source changed: its old bytes are void.
            $this->staging->deleteFile((string) $file->staged_relative_path);
            $this->draftService->recordEvent($draft, 'transfer_file_rejected', [
                'file_id' => $file->file_id,
                'reason' => 'source_changed',
            ]);
        }

        return true;
    }

    /**
     * Zero-byte manifest files are never uploaded; the server stages them itself.
     */
    private function materializeEmptyFiles(ImportDraft $draft): bool
    {
        $changed = false;
        foreach ($draft->files as $file) {
            if ($file->bytes !== 0 || $this->isComplete($file)) {
                continue;
            }
            $relativePath = $this->staging->relativePath($draft, $file);
            $this->staging->createEmpty($relativePath);
            $file->fill([
                'expected_sha256' => hash('sha256', ''),
                'received_sha256' => hash('sha256', ''),
                'staged_relative_path' => $relativePath,
                'transfer_state' => self::FILE_UPLOADED,
                'received_bytes' => 0,
                'uploaded_at' => now(),
            ])->save();
            $changed = true;
        }

        return $changed;
    }

    /**
     * @param resource $chunk
     */
    private function appendLocked(
        ImportDraft $draft,
        ImportDraftFile $file,
        int $offset,
        $chunk,
        int $bytes
    ): int|ImportApiException {
        $confirmed = (int) $file->received_bytes;
        if ($offset !== $confirmed) {
            return $this->offsetConflict($confirmed);
        }
        if ($bytes === 0) {
            return $confirmed;
        }
        if ($offset + $bytes > $file->bytes) {
            return ImportApiException::policy(
                'upload_length_exceeded',
                'This chunk goes past the end of the file.',
                ['upload_length' => (int) $file->bytes]
            );
        }

        $this->staging->append((string) $file->staged_relative_path, $offset, $chunk, $bytes);
        $file->received_bytes = $offset + $bytes;
        $file->transfer_state = self::FILE_UPLOADING;
        $file->save();

        if ($file->received_bytes < $file->bytes) {
            if ($this->crossedProgressStep($confirmed, $file->received_bytes, (int) $file->bytes)) {
                $this->recordProgress($draft, $file);
            }

            return $file->received_bytes;
        }

        return $this->completeFile($draft, $file);
    }

    private function completeFile(ImportDraft $draft, ImportDraftFile $file): int|ImportApiException
    {
        $hash = $this->staging->sha256((string) $file->staged_relative_path);
        if ($hash !== null && hash_equals((string) $file->expected_sha256, $hash)) {
            $file->fill([
                'transfer_state' => self::FILE_UPLOADED,
                'received_sha256' => $hash,
                'uploaded_at' => now(),
            ])->save();
            $this->recordProgress($draft, $file);
            $this->applyProgress($draft, true);

            return (int) $file->received_bytes;
        }

        $this->rejectFile($draft, $file, 'hash_mismatch');
        $this->applyProgress($draft, true);
        Log::warning('Import upload rejected: hash mismatch', [
            'draft_id' => $draft->public_id,
            'file_id' => $file->file_id,
        ]);

        return new ImportApiException(
            422,
            'upload_hash_mismatch',
            'A file did not match its checksum after upload and has to be sent again.',
            ['file_id' => $file->file_id, 'reason' => 'hash_mismatch'],
            true
        );
    }

    private function rejectFile(ImportDraft $draft, ImportDraftFile $file, string $reason): void
    {
        if ($file->staged_relative_path !== null) {
            $this->staging->deleteFile($file->staged_relative_path);
        }
        $file->fill([
            'transfer_state' => self::FILE_REJECTED,
            'received_bytes' => 0,
            'received_sha256' => null,
            'uploaded_at' => null,
            'verified_at' => null,
        ])->save();
        $this->draftService->recordEvent($draft, 'transfer_file_rejected', [
            'file_id' => $file->file_id,
            'reason' => $reason,
        ]);
    }

    /**
     * @param array<int, string> $fileIds
     */
    private function rejectVerification(ImportDraft $locked, array $fileIds): ImportApiException
    {
        foreach ($locked->files as $file) {
            if (in_array($file->file_id, $fileIds, true)) {
                $this->rejectFile($locked, $file, 'verification_failed');
            }
        }
        $this->applyProgress($locked, true);
        Log::warning('Import transfer verification failed', [
            'draft_id' => $locked->public_id,
            'file_ids' => $fileIds,
        ]);

        return new ImportApiException(
            422,
            'transfer_verification_failed',
            'Some uploaded files did not match their checksums and have to be sent again.',
            ['file_ids' => $fileIds],
            true
        );
    }

    private function recordVerified(ImportDraft $locked, ImportPlan $plan): ImportDraft
    {
        $verifiedAt = now();
        $audit = [];
        foreach ($locked->files as $file) {
            $file->fill(['transfer_state' => self::FILE_VERIFIED, 'verified_at' => $verifiedAt])->save();
            $audit[] = [
                'file_id' => $file->file_id,
                'relative_path' => $file->relative_path,
                'bytes' => (int) $file->bytes,
                'sha256' => $file->received_sha256,
            ];
        }
        $locked->transfer_verified_plan_revision = $plan->revision;
        $locked->transfer_verified_at = $verifiedAt;
        $this->applyProgress($locked, true);
        $this->draftService->recordEvent($locked, 'transfer_verified', [
            'plan_revision' => $plan->revision,
            'files' => $audit,
        ]);
        Log::info('Import transfer verified', [
            'draft_id' => $locked->public_id,
            'plan_revision' => $plan->revision,
            'file_count' => count($audit),
        ]);

        return $locked->load('files');
    }

    /**
     * Recomputes the transfer summary and draft state from the files, bumps the revision
     * when anything visible changed (or when $bump is set) and records state changes.
     */
    private function applyProgress(ImportDraft $draft, bool $bump): void
    {
        $files = $draft->files()->get();
        $bytesVerified = (int) $files->filter(fn (ImportDraftFile $file): bool => $this->isComplete($file))
            ->sum('bytes');
        $allComplete = $files->every(fn (ImportDraftFile $file): bool => $this->isComplete($file));
        $allVerified = $files->every(
            static fn (ImportDraftFile $file): bool => $file->transfer_state === self::FILE_VERIFIED
        );
        if (!$allVerified) {
            $draft->transfer_verified_plan_revision = null;
            $draft->transfer_verified_at = null;
        }
        $summaryState = match (true) {
            $allVerified && $draft->transfer_verified_plan_revision !== null => self::SUMMARY_VERIFIED,
            $allComplete => self::SUMMARY_VERIFYING,
            default => self::SUMMARY_TRANSFERRING,
        };
        $state = $allComplete ? ImportDraftState::VERIFYING : ImportDraftState::TRANSFERRING;

        $summary = (array) $draft->transfer_summary;
        $newSummary = array_merge($summary, ['state' => $summaryState, 'bytes_verified' => $bytesVerified]);
        $stateChanged = $draft->state !== $state;
        if (!$bump && !$stateChanged && $newSummary == $summary) {
            return;
        }
        $draft->transfer_summary = $newSummary;
        $draft->state = $state;
        $draft->revision = $draft->revision + 1;
        $draft->save();
        if ($stateChanged) {
            $this->draftService->recordEvent($draft, 'state_changed', [
                'state' => $state->value,
                'plan_revision' => $draft->plan_revision,
            ]);
        }
        $draft->setRelation('files', $files);
    }

    private function recordProgress(ImportDraft $draft, ImportDraftFile $file): void
    {
        $this->draftService->recordEvent($draft, 'transfer_progress', [
            'file_id' => $file->file_id,
            'bytes_received' => (int) $file->received_bytes,
            'bytes_total' => (int) $file->bytes,
        ]);
    }

    private function crossedProgressStep(int $before, int $after, int $total): bool
    {
        $step = max(1, (int) config('import_drafts.progress_event_percent_step'));

        return intdiv(intdiv($before * 100, $total), $step) !== intdiv(intdiv($after * 100, $total), $step);
    }

    /**
     * A staged file shorter than its confirmed offset lost bytes on disk; start it over.
     *
     * @return bool whether the file was reset
     */
    private function reconcileStagedLength(ImportDraft $draft, ImportDraftFile $file): bool
    {
        if ($file->received_bytes === 0 || $file->staged_relative_path === null) {
            return false;
        }
        $size = $this->staging->size($file->staged_relative_path);
        if ($size !== null && $size >= $file->received_bytes) {
            return false;
        }
        $wasComplete = $this->isComplete($file);
        $this->rejectFile($draft, $file, 'staged_bytes_missing');
        $file->transfer_state = self::FILE_UPLOADING;
        $file->save();
        if ($wasComplete) {
            $this->applyProgress($draft, true);
        }
        Log::warning('Import upload staged bytes missing; restarting file', [
            'draft_id' => $draft->public_id,
            'file_id' => $file->file_id,
        ]);

        return true;
    }

    private function stagedBytesMatch(ImportDraftFile $file): bool
    {
        if ($file->staged_relative_path === null || $file->expected_sha256 === null) {
            return false;
        }
        if ($this->staging->size($file->staged_relative_path) !== (int) $file->bytes) {
            return false;
        }
        $hash = $this->staging->sha256($file->staged_relative_path);

        return $hash !== null && hash_equals($file->expected_sha256, $hash);
    }

    private function assertStagedLayout(ImportDraft $draft, ImportPlan $plan): void
    {
        $problems = [];
        $keys = [];
        $files = $draft->files->keyBy('file_id');
        foreach ($plan->manifest_snapshot as $entry) {
            $fileId = (string) ($entry['file_id'] ?? '');
            $file = $files[$fileId] ?? null;
            try {
                $key = $this->pathNormalizer->key($this->pathNormalizer->normalize((string) $entry['relative_path']));
            } catch (ImportApiException) {
                $problems[] = $fileId;
                continue;
            }
            $expectedStage = $file === null ? null : $this->staging->relativePath($draft, $file);
            $wrongStage = $file !== null && $file->bytes > 0 && $file->staged_relative_path !== $expectedStage;
            if (isset($keys[$key]) || $file === null || $wrongStage) {
                $problems[] = $fileId;
            }
            $keys[$key] = true;
        }
        if ($problems !== []) {
            throw ImportApiException::policy(
                'staged_layout_invalid',
                'The uploaded files do not form a valid book folder.',
                ['file_ids' => array_values(array_unique($problems))]
            );
        }
    }

    private function isComplete(ImportDraftFile $file): bool
    {
        return in_array($file->transfer_state, [self::FILE_UPLOADED, self::FILE_VERIFIED], true);
    }

    private function lockDraft(ImportDraft $draft): ImportDraft
    {
        /** @var ImportDraft $locked */
        $locked = ImportDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();

        return $locked->load('files');
    }

    private function lockSessionFile(ImportDraft $draft, string $fileId): ImportDraftFile
    {
        /** @var ImportDraftFile|null $file */
        $file = ImportDraftFile::query()
            ->where('draft_id', $draft->id)
            ->where('file_id', $fileId)
            ->lockForUpdate()
            ->first();
        if ($file === null || $file->expected_sha256 === null || $file->staged_relative_path === null) {
            throw new ImportApiException(404, 'upload_not_found', 'No upload was started for this file.');
        }

        return $file;
    }

    private function livePlan(ImportDraft $draft, int $requestedRevision): ImportPlan
    {
        $plan = $draft->plan_revision === null ? null : ImportPlan::query()
            ->where('draft_id', $draft->id)
            ->where('revision', $draft->plan_revision)
            ->whereNull('invalidated_at')
            ->first();
        if ($plan === null || $requestedRevision !== $plan->revision) {
            throw ImportApiException::approvedPlanStale($requestedRevision, $draft->revision);
        }
        if ($plan->transfer_mode !== 'upload') {
            throw ImportApiException::policy(
                'transfer_mode_mismatch',
                'This import was approved for a different transfer mode.',
                ['transfer_mode' => $plan->transfer_mode]
            );
        }

        return $plan;
    }

    private function assertTransferState(ImportDraft $draft, string $action): void
    {
        if (!in_array($draft->state, self::TRANSFER_STATES, true)) {
            throw ImportApiException::invalidState($draft->state->value, $action);
        }
    }

    private function assertUploadState(ImportDraft $draft): void
    {
        if (!in_array($draft->state, [ImportDraftState::TRANSFERRING, ImportDraftState::VERIFYING], true)) {
            throw ImportApiException::invalidState($draft->state->value, 'uploaded to');
        }
    }

    private function uploadUrl(ImportDraft $draft, string $fileId): string
    {
        return 'imports/drafts/' . $draft->public_id . '/uploads/' . rawurlencode($fileId);
    }

    private function offsetConflict(int $expected): ImportApiException
    {
        return new ImportApiException(
            409,
            'upload_offset_conflict',
            'The upload offset does not match what the server has received.',
            ['expected_offset' => $expected],
            true
        );
    }

    private function chunkTooLarge(int $maxChunk): ImportApiException
    {
        return new ImportApiException(
            413,
            'chunk_too_large',
            'This upload chunk is larger than the server accepts.',
            ['max_chunk_bytes' => $maxChunk],
            true
        );
    }
}
