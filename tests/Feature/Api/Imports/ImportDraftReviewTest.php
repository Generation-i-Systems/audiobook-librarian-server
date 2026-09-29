<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Imports;

use App\Models\Imports\ImportDraft;
use App\Models\Imports\ImportPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\ApiTestCase;

class ImportDraftReviewTest extends ApiTestCase
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

    public function testPatchUpdatesMetadataRecordsProvenanceAndRederivesTargets(): void
    {
        $draftId = (string) $this->createInterpretedDraft($this->dustRoadObservation())['id'];

        $response = $this->patchDraft($draftId, ['metadata' => ['title' => 'Dust Road Revised']], '"3"')
            ->assertOk()
            ->assertHeader('ETag', '"4"')
            ->assertJsonPath('draft.revision', 4)
            ->assertJsonPath('draft.state', 'awaiting_review')
            ->assertJsonPath('draft.recommendation.metadata.title', 'Dust Road Revised')
            ->assertJsonPath('draft.recommendation.field_provenance.title.0', [
                'source' => 'your edit',
                'source_id' => 'user_edit',
                'value' => 'Dust Road Revised',
                'confidence' => 1,
            ]);

        $this->assertSame(
            'Fantasy/Jane Author/Dust Road Revised',
            $response->json('draft.recommendation.target_candidates.0.relative_directory')
        );
        $draft = ImportDraft::query()->where('public_id', $draftId)->firstOrFail();
        $this->assertSame(['fields' => ['title']], $draft->events()->reorder('id', 'desc')->first()?->payload);
    }

    public function testPatchRederivesDuplicatesForEditedAuthor(): void
    {
        $book = $this->createLibraryBook('Dust Road', 'Other Writer', 'Fantasy/Other Writer/Dust Road');
        $draftId = (string) $this->createInterpretedDraft($this->dustRoadObservation())['id'];

        $this->patchDraft($draftId, ['metadata' => ['authors' => ['Other Writer']]], '"3"')
            ->assertOk()
            ->assertJsonPath('draft.recommendation.duplicate_candidates.0.book_id', $book->id)
            ->assertJsonPath('draft.recommendation.required_decisions.0.options', ['merge', 'skip']);
    }

    public function testStaleIfMatchConflicts(): void
    {
        $draftId = (string) $this->createInterpretedDraft($this->dustRoadObservation())['id'];

        $this->patchDraft($draftId, ['metadata' => ['title' => 'X']], '"2"')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'draft_revision_conflict')
            ->assertJsonPath('error.details.current_revision', 3);
    }

    public function testMissingIfMatchIsRejected(): void
    {
        $draftId = (string) $this->createInterpretedDraft($this->dustRoadObservation())['id'];

        $this->patchDraft($draftId, ['metadata' => ['title' => 'X']], null)
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'revision_required');
    }

    public function testMissingIdempotencyKeyIsRejected(): void
    {
        $draftId = (string) $this->createInterpretedDraft($this->dustRoadObservation())['id'];

        $this->withDraftHeaders(['If-Match' => '"3"'])
            ->patchJson(self::DRAFTS_URL . '/' . $draftId, ['metadata' => ['title' => 'X']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidPatchProvider')]
    public function testInvalidPatchIsRejected(array $body, string $code): void
    {
        $draftId = (string) $this->createInterpretedDraft($this->dustRoadObservation())['id'];

        $this->patchDraft($draftId, $body, '"3"')
            ->assertStatus(422)
            ->assertJsonPath('error.code', $code);
        $this->assertSame(3, ImportDraft::query()->where('public_id', $draftId)->value('revision'));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPatchProvider(): array
    {
        return [
            'blank title' => [['metadata' => ['title' => '  ']], 'validation_failed'],
            'authors not a list' => [['metadata' => ['authors' => 'Jane Author']], 'validation_failed'],
            'negative series number' => [['metadata' => ['series' => ['name' => 'Saga', 'number' => -1]]], 'validation_failed'],
            'series number without name' => [['metadata' => ['series' => ['number' => 2]]], 'validation_failed'],
            'unknown metadata field' => [['metadata' => ['publisher' => 'X']], 'validation_failed'],
            'unknown top-level field' => [['target' => ['candidate_id' => 'recommended']], 'validation_failed'],
            'local ai artifacts' => [['local_ai_artifacts' => []], 'local_ai_artifacts_not_supported'],
        ];
    }

    public function testNoOpPatchKeepsRevision(): void
    {
        $draftId = (string) $this->createInterpretedDraft($this->dustRoadObservation())['id'];

        $this->patchDraft($draftId, ['metadata' => ['title' => 'Dust Road']], '"3"')
            ->assertOk()
            ->assertJsonPath('draft.revision', 3);
    }

    public function testPatchIsRejectedOutsideReview(): void
    {
        [$file, $artifact] = $this->nfoFileAndArtifact("Title: Dust Road\n");
        $draftId = (string) $this->createInterpretedDraft($this->observation('Notes', [$file], [$artifact]))['id'];

        $this->patchDraft($draftId, ['metadata' => ['title' => 'X']], '"3"')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invalid_state_transition')
            ->assertJsonPath('error.details.state', 'needs_attention');
    }

    public function testPatchAfterApprovalInvalidatesPlan(): void
    {
        $draft = $this->createInterpretedDraft($this->dustRoadObservation());
        $draftId = (string) $draft['id'];
        $this->approve($draftId, $this->approvalFromDefaults($draft))->assertOk()->assertJsonPath('draft.plan_revision', 4);

        $this->patchDraft($draftId, ['metadata' => ['description' => 'Changed after approval.']], '"4"')
            ->assertOk()
            ->assertJsonPath('draft.state', 'awaiting_review')
            ->assertJsonPath('draft.revision', 5)
            ->assertJsonPath('draft.plan_revision', null)
            ->assertJsonPath('draft.plan', null);

        $plan = ImportPlan::query()->firstOrFail();
        $this->assertSame('metadata_edited_after_approval', $plan->invalidated_reason);
        $this->assertNotNull($plan->invalidated_at);
        $model = ImportDraft::query()->where('public_id', $draftId)->firstOrFail();
        $this->assertSame(
            ['plan_invalidated', 'state_changed', 'metadata_updated'],
            $model->events()->where('observed_revision', 5)->pluck('event_type')->all()
        );
    }
}
