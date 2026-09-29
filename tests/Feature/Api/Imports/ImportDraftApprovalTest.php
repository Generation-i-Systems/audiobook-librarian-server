<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Imports;

use App\Models\Imports\ImportPlan;
use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\ApiTestCase;

class ImportDraftApprovalTest extends ApiTestCase
{
    use BuildsImportObservations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpImportDrafts();
    }

    protected function tearDown(): void
    {
        $this->tearDownImportDrafts();
        parent::tearDown();
    }

    public function testApprovalLocksPlanExactlyAsConfirmed(): void
    {
        $draft = $this->createInterpretedDraft($this->dustRoadObservation());
        $payload = $this->approvalFromDefaults($draft);
        $payload['metadata']['description'] = "  Kept exactly, spaces and all.\n";
        $payload['metadata']['tags'] = ['road trip', 'epic-fantasy'];
        $payload['metadata']['language'] = 'en';

        $response = $this->approve((string) $draft['id'], $payload, '"3"')
            ->assertOk()
            ->assertHeader('ETag', '"4"')
            ->assertJsonPath('draft.state', 'approved')
            ->assertJsonPath('draft.revision', 4)
            ->assertJsonPath('draft.plan_revision', 4);

        $expectedPlan = [
            'revision' => 4,
            'metadata' => $payload['metadata'],
            'cover_artifact_id' => null,
            'target' => [
                'candidate_id' => 'recommended',
                'relative_directory' => 'Fantasy/Jane Author/Dust Road',
                'duplicate_book_id' => null,
            ],
            'duplicate_action' => 'create_new',
            'file_operation' => 'copy',
            'transfer_mode' => 'upload',
            'acknowledged_warning_ids' => [],
        ];
        $plan = $response->json('draft.plan');
        unset($plan['approved_at']);
        $this->assertSame($expectedPlan, $plan);

        $stored = ImportPlan::query()->sole();
        $this->assertSame($payload['metadata'], $stored->metadata);
        $this->assertSame((int) $this->user->id, $stored->approved_by_user_id);
        $this->assertSame([[
            'file_id' => 'f_01', 'relative_path' => 'Dust Road.m4b', 'role' => 'audio', 'bytes' => 1000, 'sha256' => null,
        ]], $stored->manifest_snapshot);
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $mutate
     */
    #[DataProvider('invalidApprovalProvider')]
    public function testInvalidApprovalIsRejected(\Closure $mutate, int $status, string $code): void
    {
        $draft = $this->createInterpretedDraft($this->dustRoadObservation());

        $this->approve((string) $draft['id'], $mutate($this->approvalFromDefaults($draft)))
            ->assertStatus($status)
            ->assertJsonPath('error.code', $code);
        $this->assertSame(0, ImportPlan::query()->count());
    }

    /**
     * @return array<string, array{\Closure, int, string}>
     */
    public static function invalidApprovalProvider(): array
    {
        $set = static fn (string $key, mixed $value): \Closure => static function (array $p) use ($key, $value): array {
            data_set($p, $key, $value);

            return $p;
        };

        return [
            'stale expected revision' => [$set('expected_revision', 2), 409, 'draft_revision_conflict'],
            'unknown target' => [$set('target.candidate_id', 'elsewhere'), 422, 'invalid_target'],
            'unoffered duplicate action' => [$set('duplicate_action', 'merge'), 422, 'invalid_duplicate_action'],
            'in place for upload' => [$set('file_operation', 'in_place'), 422, 'invalid_file_operation'],
            'other transfer mode' => [$set('transfer_mode', 'shared_stage'), 422, 'invalid_transfer_mode'],
            'title changed without patch' => [$set('metadata.title', 'Another'), 422, 'metadata_not_reviewed'],
            'no authors' => [$set('metadata.authors', []), 422, 'validation_failed'],
            'unknown warning acknowledged' => [$set('acknowledged_warning_ids', ['nope']), 422, 'unknown_warning'],
            'unknown cover' => [$set('cover_artifact_id', 'cover_x'), 422, 'invalid_cover_artifact'],
            'wrong contract' => [$set('contract_version', 'imports.v0'), 422, 'validation_failed'],
            'unknown field' => [$set('absolute_path', '/library'), 422, 'validation_failed'],
        ];
    }

    public function testStaleIfMatchConflicts(): void
    {
        $draft = $this->createInterpretedDraft($this->dustRoadObservation());

        $this->approve((string) $draft['id'], $this->approvalFromDefaults($draft), '"1"')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'draft_revision_conflict');
    }

    public function testGenreOutsideLibraryListIsRejectedWithSuggestion(): void
    {
        $draftId = (string) $this->createInterpretedDraft($this->dustRoadObservation())['id'];
        $patched = (array) $this->patchDraft($draftId, ['metadata' => ['genres' => ['Epic Fantasy']]], '"3"')
            ->assertOk()->json('draft');

        $this->approve($draftId, $this->approvalFromDefaults($patched))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_genre')
            ->assertJsonPath('error.details.genre', 'Epic Fantasy')
            ->assertJsonPath('error.details.suggestion', 'Fantasy');
    }

    public function testRequiredWarningsMustBeAcknowledged(): void
    {
        $draft = $this->createInterpretedDraft($this->dustRoadObservation(['Access denied: Disc 02/private']));
        $payload = $this->approvalFromDefaults($draft);

        $this->approve((string) $draft['id'], array_merge($payload, ['acknowledged_warning_ids' => []]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'warnings_not_acknowledged')
            ->assertJsonPath('error.details.warning_ids', ['source_warning_1']);

        $this->approve((string) $draft['id'], array_merge($payload, ['acknowledged_warning_ids' => ['source_warning_1']]))
            ->assertOk()
            ->assertJsonPath('draft.plan.acknowledged_warning_ids', ['source_warning_1']);
    }

    #[DataProvider('duplicateCombinationProvider')]
    public function testDuplicateActionMustFitTarget(string $action, string $target, string $expectedCode): void
    {
        $this->createLibraryBook('Dust Road', 'Jane Author', 'Fantasy/Jane Author/Dust Road');
        $this->createLibraryDirectory('Fantasy/Jane Author/Dust Road', true);
        $draft = $this->createInterpretedDraft($this->dustRoadObservation());
        $payload = array_merge($this->approvalFromDefaults($draft), [
            'duplicate_action' => $action,
            'target' => ['candidate_id' => $target],
        ]);

        $response = $this->approve((string) $draft['id'], $payload);

        if ($expectedCode === 'ok') {
            $response->assertOk()->assertJsonPath('draft.plan.duplicate_action', $action);
        } else {
            $response->assertStatus(422)->assertJsonPath('error.code', $expectedCode);
        }
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function duplicateCombinationProvider(): array
    {
        return [
            'new book into occupied folder' => ['create_new', 'recommended', 'target_unavailable'],
            'new book into renamed folder' => ['create_new', 'renamed', 'ok'],
            'replace needs the existing book folder' => ['replace', 'renamed', 'target_incompatible'],
            'replace existing book' => ['replace', 'existing_book', 'ok'],
            'skip accepts any offered target' => ['skip', 'recommended', 'ok'],
        ];
    }

    public function testTargetOccupiedAfterReviewIsRejected(): void
    {
        $draft = $this->createInterpretedDraft($this->dustRoadObservation());
        $this->createLibraryDirectory('Fantasy/Jane Author/Dust Road', true);

        $this->approve((string) $draft['id'], $this->approvalFromDefaults($draft))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'target_unavailable');
    }

    public function testUserWithoutPermissionCannotApprove(): void
    {
        $draft = $this->createInterpretedDraft($this->dustRoadObservation());
        $this->actingAsUser(User::factory()->create(['role' => 'library-user']));

        $this->approve((string) $draft['id'], $this->approvalFromDefaults($draft))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'import_not_permitted');
    }

    public function testSecondApprovalIsInvalidButRetryReplays(): void
    {
        $draft = $this->createInterpretedDraft($this->dustRoadObservation());
        $payload = $this->approvalFromDefaults($draft);
        $key = (string) Str::uuid();
        $url = self::DRAFTS_URL . '/' . $draft['id'] . '/approve';

        $first = $this->withDraftHeaders(['Idempotency-Key' => $key])->postJson($url, $payload)->assertOk();
        $this->withDraftHeaders(['Idempotency-Key' => $key])->postJson($url, $payload)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('draft.plan', $first->json('draft.plan'));

        $this->approve((string) $draft['id'], $payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'draft_revision_conflict');
        $payload['expected_revision'] = 4;
        $this->approve((string) $draft['id'], $payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invalid_state_transition');
        $this->assertSame(1, ImportPlan::query()->count());
    }
}
