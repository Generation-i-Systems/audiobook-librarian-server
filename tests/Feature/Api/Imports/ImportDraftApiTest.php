<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Imports;

use App\Enums\PermissionKey;
use App\Jobs\InterpretImportDraftJob;
use App\Models\Imports\ImportDraft;
use App\Models\Imports\ImportEvent;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\ApiTestCase;

class ImportDraftApiTest extends ApiTestCase
{
    private const DRAFTS_URL = '/api/v1/imports/drafts';

    protected function setUp(): void
    {
        parent::setUp();
        config(['import_drafts.enabled' => true]);
        $this->grantImportPermission($this->user);
        // Draft lifecycle only; interpretation is covered by ImportDraftInterpretationTest.
        Queue::fake();
    }

    public function testCreateDraftQueuesInterpretation(): void
    {
        $draftId = (string) $this->postDraft($this->observation())->assertCreated()->json('draft.id');

        $internalId = ImportDraft::query()->where('public_id', $draftId)->value('id');
        Queue::assertPushed(
            InterpretImportDraftJob::class,
            static fn (InterpretImportDraftJob $job): bool => $job->draftId === $internalId
        );
    }

    public function testCapabilitiesReportDraftsEnabledForPermittedUser(): void
    {
        $response = $this->getJson('/api/v1/imports/capabilities');

        $response->assertOk()
            ->assertJsonPath('contract_version', 'imports.v1')
            ->assertJsonPath('imports.drafts', true)
            ->assertJsonPath('imports.transfer_modes', ['upload'])
            ->assertJsonPath('imports.local_ai_artifacts', false)
            ->assertJsonPath('imports.shared_stage_targets', []);
    }

    public function testCapabilitiesReportDraftsDisabledWhenFeatureIsOff(): void
    {
        config(['import_drafts.enabled' => false]);

        $this->getJson('/api/v1/imports/capabilities')
            ->assertOk()
            ->assertJsonPath('imports.drafts', false)
            ->assertJsonPath('imports.transfer_modes', []);
    }

    public function testCreateDraftPersistsObservationWithoutPaths(): void
    {
        $observation = $this->observation();

        $response = $this->postDraft($observation);

        $response->assertCreated()
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('contract_version', 'imports.v1')
            ->assertJsonPath('draft.revision', 1)
            ->assertJsonPath('draft.state', 'created')
            ->assertJsonPath('draft.source_summary', [
                'display_name' => $observation['source']['display_name'],
                'file_count' => 2,
                'bytes' => 1814,
            ])
            ->assertJsonPath('draft.transfer', [
                'mode' => 'upload',
                'state' => 'not_started',
                'bytes_total' => 1814,
                'bytes_verified' => 0,
            ]);
        $this->assertMatchesRegularExpression('/^imp_[A-Za-z0-9]+$/', (string) $response->json('draft.id'));

        $draft = ImportDraft::query()->where('public_id', $response->json('draft.id'))->firstOrFail();
        $this->assertSame((int) $this->user->id, $draft->owner_user_id);
        $this->assertSame(
            ['Disc 1/01 Chapter.mp3', 'metadata.nfo'],
            $draft->files()->pluck('relative_path')->all()
        );
        $this->assertSame(1, $draft->artifacts()->count());
        $this->assertSame(['state_changed'], ImportEvent::query()->where('draft_id', $draft->id)->pluck('event_type')->all());
    }

    public function testCreateDraftRequiresIdempotencyKey(): void
    {
        $this->postJson(self::DRAFTS_URL, $this->observation())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $this->assertSame(0, ImportDraft::query()->count());
    }

    public function testRetriedCreateWithSameKeyReplaysWithoutSecondDraft(): void
    {
        $key = (string) Str::uuid();
        $observation = $this->observation();

        $first = $this->postDraft($observation, $key)->assertCreated();
        $second = $this->postDraft($observation, $key)->assertCreated();

        $this->assertSame($first->json('draft.id'), $second->json('draft.id'));
        $second->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, ImportDraft::query()->count());
    }

    public function testReusedKeyWithDifferentBodyIsRejected(): void
    {
        $key = (string) Str::uuid();
        $this->postDraft($this->observation(), $key)->assertCreated();

        $changed = $this->observation();
        $changed['source']['display_name'] = 'Something else';

        $this->postDraft($changed, $key)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'idempotency_key_reused');
        $this->assertSame(1, ImportDraft::query()->count());
    }

    #[DataProvider('unsafePathProvider')]
    public function testCreateDraftRejectsUnsafeRelativePaths(string $unsafePath): void
    {
        $observation = $this->observation();
        $observation['source']['files'][0]['relative_path'] = $unsafePath;

        $this->postDraft($observation)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
        $this->assertSame(0, ImportDraft::query()->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafePathProvider(): array
    {
        return [
            'absolute' => ['/etc/passwd'],
            'windows drive' => ['C:/books/a.mp3'],
            'parent traversal' => ['Disc 1/../../secret.mp3'],
            'backslash' => ['Disc 1\\a.mp3'],
            'empty segment' => ['Disc 1//a.mp3'],
            'current dir segment' => ['./a.mp3'],
            'control character' => ["a\u{0007}.mp3"],
        ];
    }

    public function testCreateDraftAcceptsEmptySidecarFile(): void
    {
        $observation = $this->observation();
        $observation['source']['files'][] = [
            'file_id' => 'f_03',
            'relative_path' => '.nomedia',
            'role' => 'other',
            'bytes' => 0,
        ];

        $this->postDraft($observation)
            ->assertCreated()
            ->assertJsonPath('draft.source_summary.file_count', 3);
    }

    public function testCreateDraftRejectsCaseInsensitiveDuplicatePaths(): void
    {
        $observation = $this->observation();
        $observation['source']['files'][1]['relative_path'] = 'disc 1/01 CHAPTER.mp3';

        $this->postDraft($observation)->assertStatus(422);
    }

    public function testCreateDraftRejectsUnofferedTransferMode(): void
    {
        $observation = $this->observation();
        $observation['source']['mode'] = 'shared_stage';

        $this->postDraft($observation)->assertStatus(422);
    }

    public function testCreateDraftRejectsInlineArtifactWithWrongChecksum(): void
    {
        $observation = $this->observation();
        $observation['source']['artifacts'][0]['sha256'] = str_repeat('0', 64);

        $this->postDraft($observation)->assertStatus(422);
    }

    #[DataProvider('untrimmedInlineTextProvider')]
    public function testCreateDraftKeepsInlineTextByteForByte(string $text): void
    {
        $observation = $this->observation();
        $observation['source']['artifacts'][0]['inline_utf8'] = $text;
        $observation['source']['artifacts'][0]['sha256'] = hash('sha256', $text);

        $draftId = (string) $this->postDraft($observation)->assertCreated()->json('draft.id');

        $draft = ImportDraft::query()->where('public_id', $draftId)->firstOrFail();
        $this->assertSame($text, $draft->artifacts()->value('inline_text'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function untrimmedInlineTextProvider(): array
    {
        return [
            'trailing newline' => ["Title: Test\n"],
            'leading spaces' => ["  Title: Test"],
        ];
    }

    public function testUserWithoutImportPermissionIsForbidden(): void
    {
        $this->actingAsUser(User::factory()->create(['role' => 'library-user']));

        $this->postDraft($this->observation())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'import_not_permitted');
    }

    public function testDisabledFeatureHidesDraftRoutes(): void
    {
        config(['import_drafts.enabled' => false]);

        $this->getJson(self::DRAFTS_URL)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'imports_disabled');
    }

    public function testShowReturnsDraftAndHonorsIfNoneMatch(): void
    {
        $draftId = (string) $this->postDraft($this->observation())->json('draft.id');

        $this->getJson(self::DRAFTS_URL . '/' . $draftId)
            ->assertOk()
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('draft.id', $draftId);

        $this->withHeader('If-None-Match', '"1"')
            ->get(self::DRAFTS_URL . '/' . $draftId)
            ->assertStatus(304);
    }

    public function testOtherUsersCannotSeeDraft(): void
    {
        $draftId = (string) $this->postDraft($this->observation())->json('draft.id');
        $other = User::factory()->create(['role' => 'library-user']);
        $this->grantImportPermission($other);
        $this->actingAsUser($other);

        $this->getJson(self::DRAFTS_URL . '/' . $draftId)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'draft_not_found');
    }

    public function testListReturnsOnlyOwnDraftsNewestFirstWithCursor(): void
    {
        config(['import_drafts.page_size' => 2]);
        $ids = [];
        foreach (range(1, 3) as $ignored) {
            $ids[] = (string) $this->postDraft($this->observation())->json('draft.id');
        }

        $firstPage = $this->getJson(self::DRAFTS_URL)->assertOk();
        $this->assertSame([$ids[2], $ids[1]], array_column($firstPage->json('data'), 'id'));
        $cursor = (string) $firstPage->json('next_cursor');

        $secondPage = $this->getJson(self::DRAFTS_URL . '?cursor=' . urlencode($cursor))->assertOk();
        $this->assertSame([$ids[0]], array_column($secondPage->json('data'), 'id'));
        $this->assertNull($secondPage->json('next_cursor'));
    }

    public function testCancelAdvancesRevisionAndRecordsEvent(): void
    {
        $draftId = (string) $this->postDraft($this->observation())->json('draft.id');

        $this->withHeaders(['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"1"'])
            ->postJson(self::DRAFTS_URL . '/' . $draftId . '/cancel', ['reason' => 'Wrong folder'])
            ->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('draft.state', 'cancelled')
            ->assertJsonPath('draft.revision', 2);

        $draft = ImportDraft::query()->where('public_id', $draftId)->firstOrFail();
        $this->assertSame('Wrong folder', $draft->cancel_reason);
        $this->assertSame(2, $draft->events()->count());
    }

    public function testCancelWithStaleRevisionConflicts(): void
    {
        $draftId = (string) $this->postDraft($this->observation())->json('draft.id');

        $this->withHeaders(['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '"5"'])
            ->postJson(self::DRAFTS_URL . '/' . $draftId . '/cancel')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'draft_revision_conflict')
            ->assertJsonPath('error.details.current_revision', 1)
            ->assertJsonPath('error.retryable', true);
    }

    private function grantImportPermission(User $user): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['key' => PermissionKey::IMPORT_BOOKS->value],
            ['label' => PermissionKey::IMPORT_BOOKS->label()]
        );
        $user->permissions()->syncWithoutDetaching([$permission->id]);
    }

    /**
     * @param array<string, mixed> $observation
     */
    private function postDraft(array $observation, ?string $key = null): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson(self::DRAFTS_URL, $observation);
    }

    /**
     * @return array<string, mixed>
     */
    private function observation(): array
    {
        $nfo = '<audiobook><title>Test</title></audiobook>';

        return [
            'contract_version' => 'imports.v1',
            'client' => [
                'client_id' => (string) Str::uuid(),
                'client_kind' => 'kotlin_desktop',
                'client_version' => '0.1.0',
                'observation_schema_version' => 1,
                'capabilities' => ['resumable_upload'],
            ],
            'source' => [
                'display_name' => 'Test Book ' . Str::random(6),
                'mode' => 'upload',
                'root_fingerprint' => 'sha256:' . str_repeat('a', 64),
                'warnings' => ['Access denied: Disc 2/private'],
                'files' => [
                    [
                        'file_id' => 'f_01',
                        'relative_path' => 'Disc 1/01 Chapter.mp3',
                        'role' => 'audio',
                        'bytes' => 1000,
                        'modified_at' => '2026-09-16T19:12:08Z',
                        'sha256' => null,
                        'fingerprint' => ['algorithm' => 'size-mtime-head-tail-v1', 'value' => 'abc'],
                        'media_observation' => [
                            'container' => 'mp3',
                            'duration_ms' => 60000,
                            'raw_tags' => ['title' => ['Chapter 1'], 'artist' => ['Jane Author']],
                        ],
                    ],
                    [
                        'file_id' => 'f_02',
                        'relative_path' => 'metadata.nfo',
                        'role' => 'nfo',
                        'bytes' => 814,
                        'sha256' => hash('sha256', $nfo),
                        'text_artifact_id' => 'nfo_01',
                    ],
                ],
                'artifacts' => [
                    [
                        'artifact_id' => 'nfo_01',
                        'kind' => 'text',
                        'media_type' => 'text/plain',
                        'sha256' => hash('sha256', $nfo),
                        'inline_utf8' => $nfo,
                    ],
                ],
            ],
            'analysis' => ['requested' => ['tag_normalization', 'duplicate_check']],
        ];
    }
}
