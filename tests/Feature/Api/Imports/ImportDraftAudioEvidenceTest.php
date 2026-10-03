<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Imports;

use App\Models\Imports\ImportDraft;
use App\Models\User;
use App\Services\AIBookProcessor;
use App\Services\Imports\ImportInterpretationService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Feature\Api\ApiTestCase;

/**
 * The server may ask the client for a short audio sample when tags, NFO and online lookups leave the
 * title or author unproven; the client answers with the sample or says it is unavailable.
 */
class ImportDraftAudioEvidenceTest extends ApiTestCase
{
    use BuildsImportObservations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpImportDrafts();
        config([
            'import_drafts.ai_enabled' => true,
            'import_drafts.audio_evidence.enabled' => true,
            'import_drafts.audio_evidence.request_ttl_seconds' => 300,
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownImportDrafts();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function untaggedObservation(bool $capable = true, array $tags = []): array
    {
        $observation = $this->observation('Mystery Book', [$this->audioFile('f_01', 'part1.m4b', $tags)]);
        if ($capable) {
            $observation['client']['capabilities'] = ['audio_snippet'];
        }

        return $observation;
    }

    private function mockAi(?array $audioResult = null, int $audioCalls = 0): AIBookProcessor
    {
        $ai = Mockery::mock(AIBookProcessor::class);
        $ai->shouldReceive('processBookDirectory')->andReturn(['title' => 'Mystery Book', 'confidence' => 25]);
        if ($audioCalls > 0) {
            $ai->shouldReceive('processAudioSample')->times($audioCalls)->andReturnUsing(function (string $path) use ($audioResult) {
                $this->assertFileExists($path);
                $this->assertSame('SNIPPET-BYTES', file_get_contents($path));

                return $audioResult;
            });
        } else {
            $ai->shouldReceive('processAudioSample')->never();
        }
        $this->app->instance(AIBookProcessor::class, $ai);

        return $ai;
    }

    /** @return array<string, mixed> */
    private function answerBody(string $bytes = 'SNIPPET-BYTES'): array
    {
        return [
            'status' => 'provided',
            'media_type' => 'audio/mpeg',
            'sha256' => hash('sha256', $bytes),
            'data_base64' => base64_encode($bytes),
        ];
    }

    /** @param array<string, mixed> $body */
    private function answer(string $draftId, string $requestId, array $body): TestResponse
    {
        return $this->withDraftHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson(self::DRAFTS_URL . '/' . $draftId . '/evidence/' . $requestId, $body);
    }

    public function testLowConfidenceBookGetsAnAudioSnippetRequest(): void
    {
        $this->mockAi();

        $draft = $this->createInterpretedDraft($this->untaggedObservation());

        $this->assertSame('interpreting', $draft['state']);
        $request = $draft['evidence_requests'][0];
        $this->assertSame('audio_snippet', $request['type']);
        $this->assertSame('pending', $request['status']);
        $this->assertSame('f_01', $request['file_id']);
        $this->assertSame(0, $request['start_ms']);
        $this->assertSame(20_000, $request['duration_ms']);
        $this->assertSame(2 * 1024 * 1024, $request['max_bytes']);
        $this->assertContains('audio/mpeg', $request['media_types']);
        $this->assertNotEmpty($request['expires_at']);

        $events = $this->getJson(self::DRAFTS_URL . '/' . $draft['id'] . '/events')->json('data');
        $requested = array_values(array_filter($events, fn (array $e) => $e['event'] === 'evidence_requested'));
        $this->assertSame($request['id'], $requested[0]['data']['request_id']);
    }

    public function testNoRequestWhenTagsAlreadyProveTheBook(): void
    {
        $this->mockAi();

        $draft = $this->createInterpretedDraft($this->untaggedObservation(true, [
            'album' => ['Dust Road'],
            'artist' => ['Jane Author'],
        ]));

        $this->assertSame('awaiting_review', $draft['state']);
        $this->assertSame([], $draft['evidence_requests']);
    }

    public function testNoRequestWithoutTheFeatureOrTheClientCapability(): void
    {
        $this->mockAi();
        $this->assertSame('awaiting_review', $this->createInterpretedDraft($this->untaggedObservation(false))['state']);

        config(['import_drafts.audio_evidence.enabled' => false]);
        $this->assertSame('awaiting_review', $this->createInterpretedDraft($this->untaggedObservation())['state']);
    }

    public function testAnsweringWithASnippetMergesAudioAnalysisWithProvenance(): void
    {
        $this->mockAi(['title' => 'Spoken Title', 'author' => ['Spoken Author'], 'narrator' => ['Spoken Narrator']], 1);
        $draft = $this->createInterpretedDraft($this->untaggedObservation());
        $requestId = $draft['evidence_requests'][0]['id'];

        $response = $this->answer($draft['id'], $requestId, $this->answerBody())->assertOk();

        $result = $response->json('draft');
        $this->assertSame('awaiting_review', $result['state']);
        $this->assertSame('answered', $result['evidence_requests'][0]['status']);
        $this->assertSame(['Spoken Author'], $result['recommendation']['metadata']['authors']);
        $this->assertSame(['Spoken Narrator'], $result['recommendation']['metadata']['narrators']);
        $this->assertContains(
            'audio_analysis',
            array_column($result['recommendation']['field_provenance']['authors'], 'source_id')
        );
    }

    public function testUnavailableAnswerFinishesWithoutAudioAndAWarning(): void
    {
        $this->mockAi();
        $draft = $this->createInterpretedDraft($this->untaggedObservation());

        $result = $this->answer($draft['id'], $draft['evidence_requests'][0]['id'], ['status' => 'unavailable'])
            ->assertOk()->json('draft');

        $this->assertSame('awaiting_review', $result['state']);
        $this->assertSame('unavailable', $result['evidence_requests'][0]['status']);
        $this->assertContains('audio_evidence_unavailable', array_column($result['recommendation']['warnings'], 'id'));
    }

    public function testAnExpiredRequestCompletesWithoutAudioAndRejectsLateAnswers(): void
    {
        $this->mockAi();
        $draft = $this->createInterpretedDraft($this->untaggedObservation());
        $requestId = $draft['evidence_requests'][0]['id'];

        $this->travel(301)->seconds();
        app(ImportInterpretationService::class)->resume(ImportDraft::query()->where('public_id', $draft['id'])->value('id'));

        $result = $this->getJson(self::DRAFTS_URL . '/' . $draft['id'])->json('draft');
        $this->assertSame('awaiting_review', $result['state']);
        $this->assertSame('expired', $result['evidence_requests'][0]['status']);
        $this->assertContains('audio_evidence_expired', array_column($result['recommendation']['warnings'], 'id'));

        $this->answer($draft['id'], $requestId, $this->answerBody())
            ->assertStatus(409)->assertJsonPath('error.code', 'evidence_request_closed');
    }

    public function testResumeBeforeExpiryChangesNothing(): void
    {
        $this->mockAi();
        $draft = $this->createInterpretedDraft($this->untaggedObservation());

        app(ImportInterpretationService::class)->resume(ImportDraft::query()->where('public_id', $draft['id'])->value('id'));

        $this->assertSame('interpreting', $this->getJson(self::DRAFTS_URL . '/' . $draft['id'])->json('draft.state'));
    }

    public function testAnotherUserCannotAnswer(): void
    {
        $this->mockAi();
        $draft = $this->createInterpretedDraft($this->untaggedObservation());
        $other = User::factory()->create(['role' => 'library-user']);
        $this->grantImportPermission($other);
        $this->actingAsUser($other);

        $this->answer($draft['id'], $draft['evidence_requests'][0]['id'], ['status' => 'unavailable'])->assertNotFound();
    }

    public function testRepeatingTheSameAnswerIsIdempotentAndADifferentOneConflicts(): void
    {
        $this->mockAi(['title' => 'Spoken Title', 'author' => ['Spoken Author']], 1);
        $draft = $this->createInterpretedDraft($this->untaggedObservation());
        $requestId = $draft['evidence_requests'][0]['id'];

        $this->answer($draft['id'], $requestId, $this->answerBody())->assertOk();
        $this->answer($draft['id'], $requestId, $this->answerBody())->assertOk();
        $this->answer($draft['id'], $requestId, $this->answerBody('OTHER'))
            ->assertStatus(409)->assertJsonPath('error.code', 'evidence_request_closed');
    }

    public function testBadAnswersAreRefused(): void
    {
        $this->mockAi();
        $draft = $this->createInterpretedDraft($this->untaggedObservation());
        $requestId = $draft['evidence_requests'][0]['id'];

        $this->answer($draft['id'], 'ev_missing', ['status' => 'unavailable'])->assertNotFound();

        $wrongHash = $this->answerBody();
        $wrongHash['sha256'] = str_repeat('a', 64);
        $this->answer($draft['id'], $requestId, $wrongHash)->assertStatus(422);

        $tooBig = $this->answerBody(str_repeat('x', 2 * 1024 * 1024 + 1));
        $this->answer($draft['id'], $requestId, $tooBig)->assertStatus(422);

        $wrongType = $this->answerBody();
        $wrongType['media_type'] = 'application/pdf';
        $this->answer($draft['id'], $requestId, $wrongType)->assertStatus(422);

        $this->answer($draft['id'], $requestId, ['status' => 'maybe'])->assertStatus(422);
    }
}
