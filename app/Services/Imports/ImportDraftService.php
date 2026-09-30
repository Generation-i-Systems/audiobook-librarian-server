<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Enums\ImportDraftState;
use App\Enums\PermissionKey;
use App\Jobs\InterpretImportDraftJob;
use App\Models\Imports\ImportArtifact;
use App\Models\Imports\ImportDraft;
use App\Models\Imports\ImportDraftFile;
use App\Models\Imports\ImportEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Lifecycle, ownership, and revisions for imports.v1 drafts.
 */
class ImportDraftService
{
    public function __construct(
        private readonly SourceObservationValidator $observationValidator,
        private readonly ImportDraftPresenter $presenter,
        private readonly ImportStagingStore $staging,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) config('import_drafts.enabled');
    }

    /**
     * @return array<string, mixed>
     */
    public function capabilities(User $user): array
    {
        $enabled = $this->isEnabled() && $user->hasPermission(PermissionKey::IMPORT_BOOKS);

        return [
            'contract_version' => config('import_drafts.contract_version'),
            'imports' => [
                'drafts' => $enabled,
                'observation_schema_versions' => config('import_drafts.observation_schema_versions'),
                'transfer_modes' => $enabled ? config('import_drafts.transfer_modes') : [],
                'max_artifact_bytes' => (int) config('import_drafts.max_artifact_bytes'),
                'max_upload_chunk_bytes' => (int) config('import_drafts.max_upload_chunk_bytes'),
                'accepted_audio_extensions' => config('import_drafts.accepted_audio_extensions'),
                'local_ai_artifacts' => false,
                'sse' => false,
                'shared_stage_targets' => [],
            ],
        ];
    }

    public function assertCanImport(User $user): void
    {
        if (!$this->isEnabled()) {
            throw ImportApiException::disabled();
        }
        if (!$user->hasPermission(PermissionKey::IMPORT_BOOKS)) {
            throw ImportApiException::forbidden();
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(User $user, array $payload): ImportDraft
    {
        $this->assertCanImport($user);
        $observation = $this->observationValidator->validate($payload);

        $draft = DB::transaction(function () use ($user, $observation): ImportDraft {
            $source = $observation['source'];
            $bytesTotal = array_sum(array_map(fn (array $file): int => (int) $file['bytes'], $source['files']));

            $draft = ImportDraft::query()->create([
                'public_id' => 'imp_' . Str::ulid()->toBase32(),
                'owner_user_id' => (int) $user->id,
                'state' => ImportDraftState::CREATED,
                'revision' => 1,
                'source_mode' => $source['mode'],
                'source_display_name' => $source['display_name'],
                'source_root_fingerprint' => $source['root_fingerprint'] ?? null,
                'source_warnings' => $source['warnings'] ?? [],
                'observation_schema_version' => (int) $observation['client']['observation_schema_version'],
                'client_metadata' => [
                    'client_id' => $observation['client']['client_id'],
                    'client_kind' => $observation['client']['client_kind'],
                    'client_version' => $observation['client']['client_version'],
                    'capabilities' => $observation['client']['capabilities'] ?? [],
                ],
                'analysis_request' => ['requested' => $observation['analysis']['requested']],
                'transfer_summary' => [
                    'mode' => $source['mode'],
                    'state' => 'not_started',
                    'bytes_total' => $bytesTotal,
                    'bytes_verified' => 0,
                ],
                'expires_at' => now()->addDays((int) config('import_drafts.draft_ttl_days')),
            ]);

            foreach ($source['files'] as $file) {
                $this->createFile($draft, $file);
            }
            foreach ($source['artifacts'] ?? [] as $artifact) {
                $this->createArtifact($draft, $artifact);
            }
            $this->recordEvent($draft, 'state_changed', ['state' => $draft->state->value]);

            return $draft;
        });

        Log::info('Import draft created', [
            'draft_id' => $draft->public_id,
            'owner_user_id' => $draft->owner_user_id,
            'file_count' => count($observation['source']['files']),
            'client_kind' => $observation['client']['client_kind'],
        ]);

        InterpretImportDraftJob::dispatch($draft->id);

        // A synchronous queue has already interpreted the draft; return its current state.
        return $draft->refresh()->load('files');
    }

    public function findForUser(User $user, string $publicId): ImportDraft
    {
        $this->assertCanImport($user);
        $query = ImportDraft::query()->where('public_id', $publicId);
        if (!$user->isAdmin()) {
            $query->where('owner_user_id', (int) $user->id);
        }
        $draft = $query->with('files')->first();
        if ($draft === null) {
            throw ImportApiException::notFound();
        }

        return $draft;
    }

    /**
     * Caller-owned drafts, newest first, cursor = last seen internal id.
     *
     * @return array{drafts: \Illuminate\Support\Collection<int, ImportDraft>, next_cursor: ?string}
     */
    public function listForUser(User $user, ?string $state, ?string $cursor): array
    {
        $this->assertCanImport($user);
        if ($state !== null && ImportDraftState::tryFrom($state) === null) {
            throw ImportApiException::validation('Unknown import state filter.', ['state' => $state]);
        }

        $pageSize = (int) config('import_drafts.page_size');
        $query = ImportDraft::query()
            ->where('owner_user_id', (int) $user->id)
            ->with('files')
            ->orderByDesc('id')
            ->limit($pageSize + 1);
        if ($state !== null) {
            $query->where('state', $state);
        }
        $cursorId = $this->decodeCursor($cursor);
        if ($cursorId !== null) {
            $query->where('id', '<', $cursorId);
        }

        $drafts = $query->get();
        $nextCursor = null;
        if ($drafts->count() > $pageSize) {
            $drafts = $drafts->take($pageSize);
            $nextCursor = base64_encode((string) $drafts->last()?->id);
        }

        return ['drafts' => $drafts->values(), 'next_cursor' => $nextCursor];
    }

    /**
     * Events after a cursor (an event id), oldest first. next_cursor is always set so
     * a polling client can keep passing it back, even when nothing new happened.
     *
     * @return array{
     *     draft: ImportDraft,
     *     events: \Illuminate\Support\Collection<int, ImportEvent>,
     *     next_cursor: string,
     *     has_more: bool
     * }
     */
    public function eventsForUser(User $user, string $publicId, ?string $after): array
    {
        $draft = $this->findForUser($user, $publicId);
        $afterId = 0;
        if ($after !== null && $after !== '') {
            if (!ctype_digit($after)) {
                throw ImportApiException::validation('The event cursor is not valid.');
            }
            $afterId = (int) $after;
        }

        $pageSize = (int) config('import_drafts.event_page_size');
        $events = ImportEvent::query()
            ->where('draft_id', $draft->id)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($pageSize + 1)
            ->get();
        $hasMore = $events->count() > $pageSize;
        $events = $events->take($pageSize)->values();
        $last = $events->last();

        return [
            'draft' => $draft,
            'events' => $events,
            'next_cursor' => (string) ($last !== null ? $last->id : $afterId),
            'has_more' => $hasMore,
        ];
    }

    public function cancel(User $user, string $publicId, ?int $expectedRevision, ?string $reason): ImportDraft
    {
        $draft = $this->findForUser($user, $publicId);

        $cancelled = DB::transaction(function () use ($draft, $expectedRevision, $reason): ImportDraft {
            /** @var ImportDraft $locked */
            $locked = ImportDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if ($locked->state === ImportDraftState::CANCELLED) {
                return $locked->load('files');
            }
            $this->assertRevision($locked, $expectedRevision);
            if (!$locked->state->isCancellable()) {
                throw ImportApiException::invalidState($locked->state->value, 'cancelled');
            }

            $locked->fill([
                'state' => ImportDraftState::CANCELLED,
                'revision' => $locked->revision + 1,
                'cancel_reason' => $reason,
                'cancelled_at' => now(),
            ])->save();
            $this->recordEvent($locked, 'state_changed', ['state' => $locked->state->value]);

            return $locked->load('files');
        });

        // Uploaded bytes are never kept for a cancelled draft; deleted only once the cancel is committed.
        if ($this->staging->deleteDraft($cancelled->public_id)) {
            Log::info('Import draft staged bytes deleted', [
                'draft_id' => $cancelled->public_id,
                'reason' => 'cancelled',
            ]);
        }

        return $cancelled;
    }

    public function assertRevision(ImportDraft $draft, ?int $expectedRevision): void
    {
        if ($expectedRevision !== null && $expectedRevision !== $draft->revision) {
            throw ImportApiException::revisionConflict($draft->revision, $this->presenter->draft($draft));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function recordEvent(ImportDraft $draft, string $type, array $payload): void
    {
        ImportEvent::query()->create([
            'draft_id' => $draft->id,
            'event_type' => $type,
            'observed_revision' => $draft->revision,
            'payload' => $payload,
        ]);
    }

    /**
     * @param array<string, mixed> $file
     */
    private function createFile(ImportDraft $draft, array $file): void
    {
        ImportDraftFile::query()->create([
            'draft_id' => $draft->id,
            'file_id' => $file['file_id'],
            'relative_path' => $file['relative_path'],
            'normalized_path_key' => $file['normalized_path_key'],
            'role' => $file['role'],
            'bytes' => (int) $file['bytes'],
            'sha256' => $file['sha256'] ?? null,
            'fingerprint_algorithm' => $file['fingerprint']['algorithm'] ?? null,
            'fingerprint_value' => $file['fingerprint']['value'] ?? null,
            'modified_at' => $file['modified_at'] ?? null,
            'media_observation' => $file['media_observation'] ?? null,
            'text_artifact_id' => $file['text_artifact_id'] ?? null,
            'image_artifact_id' => $file['image_artifact_id'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $artifact
     */
    private function createArtifact(ImportDraft $draft, array $artifact): void
    {
        ImportArtifact::query()->create([
            'draft_id' => $draft->id,
            'artifact_id' => $artifact['artifact_id'],
            'kind' => $artifact['kind'],
            'media_type' => $artifact['media_type'],
            'bytes' => $artifact['bytes'] ?? null,
            'sha256' => $artifact['sha256'],
            'inline_text' => $artifact['inline_utf8'] ?? null,
        ]);
    }

    private function decodeCursor(?string $cursor): ?int
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $decoded = base64_decode($cursor, true);
        if ($decoded === false || !ctype_digit($decoded)) {
            throw ImportApiException::validation('The page cursor is not valid.');
        }

        return (int) $decoded;
    }
}
