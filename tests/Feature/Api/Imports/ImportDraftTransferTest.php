<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Imports;

use App\Models\Imports\ImportDraft;
use App\Models\Imports\ImportDraftFile;
use App\Models\Imports\ImportEvent;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\ApiTestCase;

class ImportDraftTransferTest extends ApiTestCase
{
    use BuildsImportObservations;

    private const CHUNK = 64;

    private string $stagingRoot = '';

    /** @var array<string, string> file_id => content */
    private array $contents = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpImportDrafts();
        $this->stagingRoot = sys_get_temp_dir() . '/import-staging-test-' . Str::random(8);
        config([
            'import_drafts.staging_root' => $this->stagingRoot,
            'import_drafts.max_upload_chunk_bytes' => self::CHUNK,
        ]);
        $this->contents = [
            'f_01' => random_bytes(200),
            'f_02' => str_repeat('liner notes ', 3),
        ];
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->stagingRoot)) {
            File::deleteDirectory($this->stagingRoot);
        }
        $this->tearDownImportDrafts();
        parent::tearDown();
    }

    public function testMultiChunkUploadAndVerifySucceeds(): void
    {
        [$draftId, $revision, $planRevision] = $this->approvedDraft();

        $response = $this->createUploads($draftId, $revision, $this->sessionFiles($planRevision))
            ->assertCreated()
            ->assertHeader('ETag', '"' . ($revision + 1) . '"');
        $this->assertSame([
            'contract_version' => 'imports.v1',
            'chunk_size' => self::CHUNK,
            'uploads' => [
                ['file_id' => 'f_01', 'upload_url' => 'imports/drafts/' . $draftId . '/uploads/f_01', 'offset' => 0],
                ['file_id' => 'f_02', 'upload_url' => 'imports/drafts/' . $draftId . '/uploads/f_02', 'offset' => 0],
            ],
        ], $response->json());
        $this->assertSame('transferring', $this->draftJson($draftId)['state']);

        foreach ($this->contents as $fileId => $content) {
            $this->uploadAll($draftId, $fileId, $content);
        }

        $draft = $this->draftJson($draftId);
        $this->assertSame('verifying', $draft['state']);
        $totalBytes = strlen($this->contents['f_01']) + strlen($this->contents['f_02']);
        $this->assertSame(
            ['mode' => 'upload', 'state' => 'verifying', 'bytes_total' => $totalBytes, 'bytes_verified' => $totalBytes],
            $draft['transfer']
        );

        $this->verify($draftId, (int) $draft['revision'])
            ->assertStatus(202)
            ->assertJsonPath('draft.state', 'verifying')
            ->assertJsonPath('draft.transfer.state', 'verified')
            ->assertJsonPath('draft.revision', (int) $draft['revision'] + 1);

        $stored = ImportDraft::query()->where('public_id', $draftId)->sole();
        $this->assertSame($planRevision, $stored->transfer_verified_plan_revision);
        $audit = ImportEvent::query()->where('draft_id', $stored->id)->where('event_type', 'transfer_verified')->sole();
        $this->assertSame($planRevision, $audit->payload['plan_revision']);
        $this->assertSame(['f_01', 'f_02', 'f_03'], array_column($audit->payload['files'], 'file_id'));
        foreach ($stored->files as $file) {
            $this->assertSame('verified', $file->transfer_state);
            $staged = file_get_contents($this->stagingRoot . '/' . $file->staged_relative_path);
            $this->assertSame($this->contents[$file->file_id] ?? '', $staged);
        }
    }

    public function testVerifyRepeatedAfterSuccessReplaysWithoutChange(): void
    {
        [$draftId] = $this->uploadedDraft();
        $revision = (int) $this->draftJson($draftId)['revision'];
        $this->verify($draftId, $revision)->assertStatus(202);

        $this->verify($draftId, $revision + 1)
            ->assertStatus(202)
            ->assertJsonPath('draft.revision', $revision + 1)
            ->assertJsonPath('draft.transfer.state', 'verified');
    }

    public function testHeadReportsOffsetForResumeAndSessionsResume(): void
    {
        [$draftId, $revision, $planRevision] = $this->approvedDraft();
        $this->createUploads($draftId, $revision, $this->sessionFiles($planRevision))->assertCreated();
        $content = $this->contents['f_01'];
        $this->patchChunk($draftId, 'f_01', 0, substr($content, 0, self::CHUNK))->assertNoContent();
        $this->patchChunk($draftId, 'f_01', self::CHUNK, substr($content, self::CHUNK, self::CHUNK))
            ->assertNoContent()
            ->assertHeader('Upload-Offset', (string) (2 * self::CHUNK));

        $this->call('HEAD', $this->uploadUrl($draftId, 'f_01'))
            ->assertNoContent()
            ->assertHeader('Upload-Offset', (string) (2 * self::CHUNK))
            ->assertHeader('Upload-Length', (string) strlen($content))
            ->assertHeader('Cache-Control', 'no-store, private');

        $current = (int) $this->draftJson($draftId)['revision'];
        $this->createUploads($draftId, $current, $this->sessionFiles($planRevision))
            ->assertCreated()
            ->assertJsonPath('uploads.0.offset', 2 * self::CHUNK)
            ->assertJsonPath('uploads.1.offset', 0);
    }

    public function testOffsetConflictsAreRejectedWithoutWriting(): void
    {
        [$draftId] = $this->draftWithSessions();
        $chunk = substr($this->contents['f_01'], 0, self::CHUNK);

        $this->patchChunk($draftId, 'f_01', 10, $chunk)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'upload_offset_conflict')
            ->assertJsonPath('error.details.expected_offset', 0);
        $this->patchChunk($draftId, 'f_01', 0, $chunk)->assertNoContent();

        $this->patchChunk($draftId, 'f_01', 0, $chunk)
            ->assertStatus(409)
            ->assertJsonPath('error.details.expected_offset', self::CHUNK);
        $this->assertSame(self::CHUNK, $this->file('f_01')->received_bytes);
        $this->assertSame($chunk, file_get_contents($this->stagedPath('f_01')));
    }

    public function testChunkLargerThanLimitIsRejected(): void
    {
        [$draftId] = $this->draftWithSessions();

        $this->patchChunk($draftId, 'f_01', 0, substr($this->contents['f_01'], 0, self::CHUNK + 1))
            ->assertStatus(413)
            ->assertJsonPath('error.code', 'chunk_too_large')
            ->assertJsonPath('error.details.max_chunk_bytes', self::CHUNK);
        $this->assertSame(0, $this->file('f_01')->received_bytes);
    }

    public function testChunkPastExpectedLengthIsRejected(): void
    {
        [$draftId] = $this->draftWithSessions();
        $length = strlen($this->contents['f_02']);

        $this->patchChunk($draftId, 'f_02', 0, str_repeat('x', $length + 1))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'upload_length_exceeded')
            ->assertJsonPath('error.details.upload_length', $length);
        $this->assertSame(0, $this->file('f_02')->received_bytes);
    }

    public function testWrongContentTypeIsRejected(): void
    {
        [$draftId] = $this->draftWithSessions();

        $this->patchChunk($draftId, 'f_01', 0, 'abc', 'application/octet-stream')
            ->assertStatus(415)
            ->assertJsonPath('error.code', 'unsupported_media_type');
    }

    public function testHashMismatchDiscardsBytesAndResetsOffset(): void
    {
        [$draftId, $revision, $planRevision] = $this->approvedDraft();
        $files = $this->sessionFiles($planRevision);
        $files['files'][1]['sha256'] = hash('sha256', 'something else');
        $this->createUploads($draftId, $revision, $files)->assertCreated();
        $revisionBefore = (int) $this->draftJson($draftId)['revision'];

        $this->patchChunk($draftId, 'f_02', 0, $this->contents['f_02'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'upload_hash_mismatch')
            ->assertJsonPath('error.details', ['file_id' => 'f_02', 'reason' => 'hash_mismatch']);

        $this->call('HEAD', $this->uploadUrl($draftId, 'f_02'))->assertHeader('Upload-Offset', '0');
        $file = $this->file('f_02');
        $this->assertSame('rejected', $file->transfer_state);
        $this->assertFileDoesNotExist($this->stagedPath('f_02'));
        $this->assertSame($revisionBefore + 1, (int) $this->draftJson($draftId)['revision']);
        $rejected = ImportEvent::query()->where('event_type', 'transfer_file_rejected')->sole();
        $this->assertSame(['file_id' => 'f_02', 'reason' => 'hash_mismatch'], $rejected->payload);
    }

    public function testProgressEventsAreThrottled(): void
    {
        config(['import_drafts.progress_event_percent_step' => 50]);
        [$draftId] = $this->draftWithSessions();

        $this->uploadAll($draftId, 'f_01', $this->contents['f_01']);

        $progress = ImportEvent::query()->where('event_type', 'transfer_progress')->orderBy('id')->get()
            ->map(fn (ImportEvent $event): int => $event->payload['bytes_received'])->all();
        $this->assertSame([2 * self::CHUNK, strlen($this->contents['f_01'])], $progress);
    }

    public function testOtherUsersCannotReachUploads(): void
    {
        [$draftId, $revision, $planRevision] = $this->draftWithSessions();
        $other = User::factory()->create(['role' => 'library-user']);
        $this->grantImportPermission($other);
        $this->actingAsUser($other);

        $this->createUploads($draftId, $revision, $this->sessionFiles($planRevision))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'draft_not_found');
        $this->call('HEAD', $this->uploadUrl($draftId, 'f_01'))->assertNotFound();
        $this->patchChunk($draftId, 'f_01', 0, 'abc')->assertNotFound();
        $this->verify($draftId, $revision)->assertNotFound();
    }

    public function testUploadsRequirePermission(): void
    {
        [$draftId, $revision, $planRevision] = $this->approvedDraft();
        $this->user->permissions()->detach();

        $this->createUploads($draftId, $revision, $this->sessionFiles($planRevision))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'import_not_permitted');
    }

    public function testStalePlanRevisionConflicts(): void
    {
        [$draftId, $revision, $planRevision] = $this->approvedDraft();

        $this->createUploads($draftId, $revision, $this->sessionFiles($planRevision - 1))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'approved_plan_stale')
            ->assertJsonPath('error.details', ['plan_revision' => $planRevision - 1, 'current_revision' => $revision]);
        $this->assertSame('approved', $this->draftJson($draftId)['state']);
    }

    public function testUploadsRequireIfMatch(): void
    {
        [$draftId, , $planRevision] = $this->approvedDraft();

        $this->withDraftHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson($this->draftUrl($draftId) . '/uploads', $this->sessionFiles($planRevision))
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'revision_required');
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $mutate
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSessionProvider')]
    public function testInvalidSessionRequestIsRejected(\Closure $mutate, string $code): void
    {
        [$draftId, $revision, $planRevision] = $this->approvedDraft();

        $this->createUploads($draftId, $revision, $mutate($this->sessionFiles($planRevision)))
            ->assertStatus(422)
            ->assertJsonPath('error.code', $code);
        $this->assertSame(0, ImportDraftFile::query()->whereNotNull('expected_sha256')->count());
    }

    /**
     * @return array<string, array{\Closure, string}>
     */
    public static function invalidSessionProvider(): array
    {
        $set = static fn (string $key, mixed $value): \Closure => static function (array $body) use ($key, $value): array {
            data_set($body, $key, $value);

            return $body;
        };

        return [
            'unknown file' => [$set('files.0.file_id', 'f_99'), 'unknown_file'],
            'wrong size' => [$set('files.0.bytes', 5), 'upload_manifest_mismatch'],
            'zero bytes' => [$set('files.0.bytes', 0), 'validation_failed'],
            'uppercase hash' => [$set('files.0.sha256', str_repeat('A', 64)), 'validation_failed'],
            'no files' => [$set('files', []), 'validation_failed'],
        ];
    }

    public function testVerifyListsIncompleteFiles(): void
    {
        [$draftId] = $this->draftWithSessions();
        $this->uploadAll($draftId, 'f_02', $this->contents['f_02']);

        $this->verify($draftId, (int) $this->draftJson($draftId)['revision'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'upload_incomplete')
            ->assertJsonPath('error.details.file_ids', ['f_01']);
    }

    public function testVerifyRejectsStagedBytesThatChanged(): void
    {
        [$draftId] = $this->uploadedDraft();
        $path = $this->stagedPath('f_01');
        file_put_contents($path, strrev((string) file_get_contents($path)));

        $this->verify($draftId, (int) $this->draftJson($draftId)['revision'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'transfer_verification_failed')
            ->assertJsonPath('error.details.file_ids', ['f_01']);

        $draft = $this->draftJson($draftId);
        $this->assertSame('transferring', $draft['state']);
        $this->assertSame('transferring', $draft['transfer']['state']);
        $this->assertSame(0, $this->file('f_01')->received_bytes);
    }

    public function testCancelDeletesStagedBytes(): void
    {
        [$draftId] = $this->draftWithSessions();
        $this->patchChunk($draftId, 'f_01', 0, substr($this->contents['f_01'], 0, self::CHUNK))->assertNoContent();
        $this->assertDirectoryExists($this->stagingRoot . '/' . $draftId);

        $this->withDraftHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson($this->draftUrl($draftId) . '/cancel')
            ->assertOk()
            ->assertJsonPath('draft.state', 'cancelled');

        $this->assertDirectoryDoesNotExist($this->stagingRoot . '/' . $draftId);
        $this->call('HEAD', $this->uploadUrl($draftId, 'f_01'))->assertStatus(409);
    }

    public function testMetadataEditAfterUploadInvalidatesPlanAndTransfer(): void
    {
        [$draftId] = $this->uploadedDraft();
        $revision = (int) $this->draftJson($draftId)['revision'];

        $this->patchDraft($draftId, ['metadata' => ['description' => 'Edited after upload.']], '"' . $revision . '"')
            ->assertOk()
            ->assertJsonPath('draft.state', 'awaiting_review')
            ->assertJsonPath('draft.plan_revision', null)
            ->assertJsonPath('draft.transfer.state', 'not_started')
            ->assertJsonPath('draft.transfer.bytes_verified', 0);

        $this->assertDirectoryDoesNotExist($this->stagingRoot . '/' . $draftId);
        foreach (ImportDraftFile::query()->get() as $file) {
            $this->assertSame(['not_started', 0, null], [
                $file->transfer_state, $file->received_bytes, $file->expected_sha256,
            ]);
        }
        $this->assertSame(1, ImportEvent::query()->where('event_type', 'transfer_reset')->count());
        $this->patchChunk($draftId, 'f_01', 0, 'abc')->assertStatus(409);
    }

    public function testPurgeStagingRemovesOnlyExpiredTerminalDrafts(): void
    {
        [$cancelledId] = $this->draftWithSessions();
        $this->withDraftHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson($this->draftUrl($cancelledId) . '/cancel')->assertOk();
        [$activeId] = $this->draftWithSessions();
        $this->patchChunk($activeId, 'f_01', 0, substr($this->contents['f_01'], 0, self::CHUNK))->assertNoContent();
        foreach ([$cancelledId, 'imp_UNKNOWN'] as $id) {
            File::ensureDirectoryExists($this->stagingRoot . '/' . $id);
            File::put($this->stagingRoot . '/' . $id . '/1.part', 'left behind');
        }

        $this->artisan('imports:purge-staging')->assertSuccessful();
        $this->assertDirectoryExists($this->stagingRoot . '/' . $cancelledId);

        $this->travel((int) config('import_drafts.staging_retention_hours') + 1)->hours();
        $this->artisan('imports:purge-staging')->assertSuccessful();

        $this->assertDirectoryDoesNotExist($this->stagingRoot . '/' . $cancelledId);
        $this->assertDirectoryExists($this->stagingRoot . '/' . $activeId);
        $this->assertDirectoryExists($this->stagingRoot . '/imp_UNKNOWN');
    }

    /**
     * @return array{0: string, 1: int, 2: int} draft id, current revision, plan revision
     */
    private function approvedDraft(): array
    {
        $observation = $this->dustRoadObservation();
        $observation['source']['files'][0]['bytes'] = strlen($this->contents['f_01']);
        $observation['source']['files'][] = [
            'file_id' => 'f_02', 'relative_path' => 'notes.txt', 'role' => 'other', 'bytes' => strlen($this->contents['f_02']),
        ];
        $observation['source']['files'][] = [
            'file_id' => 'f_03', 'relative_path' => '.nomedia', 'role' => 'other', 'bytes' => 0,
        ];
        $draft = $this->createInterpretedDraft($observation);
        $approved = (array) $this->approve((string) $draft['id'], $this->approvalFromDefaults($draft))
            ->assertOk()->json('draft');

        return [(string) $approved['id'], (int) $approved['revision'], (int) $approved['plan_revision']];
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    private function draftWithSessions(): array
    {
        [$draftId, $revision, $planRevision] = $this->approvedDraft();
        $this->createUploads($draftId, $revision, $this->sessionFiles($planRevision))->assertCreated();

        return [$draftId, $revision + 1, $planRevision];
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    private function uploadedDraft(): array
    {
        $draft = $this->draftWithSessions();
        foreach ($this->contents as $fileId => $content) {
            $this->uploadAll($draft[0], $fileId, $content);
        }

        return $draft;
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionFiles(int $planRevision): array
    {
        $files = [];
        foreach ($this->contents as $fileId => $content) {
            $files[] = ['file_id' => $fileId, 'sha256' => hash('sha256', $content), 'bytes' => strlen($content)];
        }

        return ['plan_revision' => $planRevision, 'files' => $files];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createUploads(string $draftId, int $revision, array $body): TestResponse
    {
        return $this->withDraftHeaders(['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"' . $revision . '"'])
            ->postJson($this->draftUrl($draftId) . '/uploads', $body);
    }

    private function verify(string $draftId, int $revision): TestResponse
    {
        return $this->withDraftHeaders(['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"' . $revision . '"'])
            ->postJson($this->draftUrl($draftId) . '/verify', []);
    }

    private function uploadAll(string $draftId, string $fileId, string $content): void
    {
        for ($offset = 0; $offset < strlen($content); $offset += self::CHUNK) {
            $this->patchChunk($draftId, $fileId, $offset, substr($content, $offset, self::CHUNK))
                ->assertNoContent()
                ->assertHeader('Upload-Offset', (string) min(strlen($content), $offset + self::CHUNK));
        }
    }

    private function patchChunk(
        string $draftId,
        string $fileId,
        int $offset,
        string $bytes,
        string $contentType = 'application/offset+octet-stream'
    ): TestResponse {
        return $this->call('PATCH', $this->uploadUrl($draftId, $fileId), [], [], [], [
            'CONTENT_TYPE' => $contentType,
            'CONTENT_LENGTH' => (string) strlen($bytes),
            'HTTP_UPLOAD_OFFSET' => (string) $offset,
            'HTTP_ACCEPT' => 'application/json',
        ], $bytes);
    }

    /**
     * @return array<string, mixed>
     */
    private function draftJson(string $draftId): array
    {
        return (array) $this->withDraftHeaders([])->getJson($this->draftUrl($draftId))->assertOk()->json('draft');
    }

    private function draftUrl(string $draftId): string
    {
        return self::DRAFTS_URL . '/' . $draftId;
    }

    private function uploadUrl(string $draftId, string $fileId): string
    {
        return '/api/v1/imports/drafts/' . $draftId . '/uploads/' . $fileId;
    }

    private function file(string $fileId): ImportDraftFile
    {
        return ImportDraftFile::query()->where('file_id', $fileId)->latest('id')->firstOrFail();
    }

    private function stagedPath(string $fileId): string
    {
        $file = $this->file($fileId);

        return $this->stagingRoot . '/' . $file->draft->public_id . '/' . $file->id . '.part';
    }
}
