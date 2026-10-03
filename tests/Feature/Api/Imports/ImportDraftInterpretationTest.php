<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Imports;

use App\Models\Imports\ImportDraft;
use App\Services\AIBookProcessor;
use App\Services\Imports\ImportMetadataEnricher;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Api\ApiTestCase;

class ImportDraftInterpretationTest extends ApiTestCase
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

    /**
     * @param array<string, mixed> $observation
     * @param array<string, mixed> $expectedMetadata
     * @param array<string, string> $expectedSources field => winning provenance source
     */
    #[DataProvider('observationProvider')]
    public function testInterpretationRecommendsMetadataWithProvenance(
        array $observation,
        array $expectedMetadata,
        array $expectedSources
    ): void {
        $draft = $this->createInterpretedDraft($observation);

        $this->assertSame('awaiting_review', $draft['state']);
        $this->assertSame(3, $draft['revision']);
        $metadata = $draft['recommendation']['metadata'];
        foreach ($expectedMetadata as $field => $value) {
            $this->assertEquals($value, $metadata[$field], "metadata.$field");
        }
        foreach ($expectedSources as $field => $source) {
            $this->assertSame($source, $draft['recommendation']['field_provenance'][$field][0]['source_id'], "provenance.$field");
        }
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>, array<string, string>}>
     */
    public static function observationProvider(): array
    {
        $fixtures = ImportObservationFixtures::class;
        [$nfoFile, $nfoArtifact] = $fixtures::nfoFileAndArtifact(
            "Title: Dust Road\nAuthor: Jane Author\nNarrator: Sam Voice\nSeries: Road Saga\n"
            . "Book Number: 2\nGenre: Fantasy\nDescription: A long walk.\n"
        );
        [$xmlFile, $xmlArtifact] = $fixtures::nfoFileAndArtifact(
            '<audiobook><title>Other Title</title><author>Other Author</author>'
            . '<narrator>Sam Voice</narrator></audiobook>'
        );
        $observations = [
            'tags' => $fixtures::observation('Unrelated Folder Name', [
                $fixtures::audioFile('f_01', 'track01.mp3', [
                    'album' => ['Dust Road'],
                    'artist' => ['Jane Author'],
                    'narrator' => ['Sam Voice', 'Ann Reader'],
                    'genre' => ['Fantasy'],
                    'language' => ['en'],
                ]),
                $fixtures::audioFile('f_02', 'track02.mp3'),
            ]),
            'filename' => $fixtures::observation('Jane Author - Road Saga #2 - Dust Road.m4b', [
                $fixtures::audioFile('f_01', 'Jane Author - Road Saga #2 - Dust Road.m4b'),
            ]),
            'nfo' => $fixtures::observation('Some Folder', [
                $fixtures::audioFile('f_01', 'Disc 1/01.mp3'),
                $fixtures::audioFile('f_02', 'Disc 1/02.mp3'),
                $nfoFile,
            ], [$nfoArtifact]),
            'tags win over nfo' => $fixtures::observation('Folder', [
                $fixtures::audioFile('f_01', 'a.mp3', ['album' => ['Dust Road'], 'artist' => ['Jane Author']]),
                $xmlFile,
            ], [$xmlArtifact]),
        ];

        return [
            'tags only' => [
                $observations['tags'],
                [
                    'title' => 'Dust Road',
                    'authors' => ['Jane Author'],
                    'narrators' => ['Sam Voice', 'Ann Reader'],
                    'genres' => ['Fantasy'],
                    'language' => 'en',
                    'series' => null,
                ],
                ['title' => 'embedded_tag', 'authors' => 'embedded_tag', 'narrators' => 'embedded_tag'],
            ],
            'filename only' => [
                $observations['filename'],
                [
                    'title' => 'Dust Road',
                    'authors' => ['Jane Author'],
                    'series' => ['name' => 'Road Saga', 'number' => 2],
                    'genres' => [],
                ],
                ['title' => 'filename', 'authors' => 'filename', 'series' => 'filename'],
            ],
            'nfo only' => [
                $observations['nfo'],
                [
                    'title' => 'Dust Road',
                    'authors' => ['Jane Author'],
                    'narrators' => ['Sam Voice'],
                    'series' => ['name' => 'Road Saga', 'number' => 2],
                    'genres' => ['Fantasy'],
                    'description' => 'A long walk.',
                ],
                ['title' => 'nfo', 'authors' => 'nfo', 'narrators' => 'nfo', 'description' => 'nfo'],
            ],
            'tags take precedence over nfo' => [
                $observations['tags win over nfo'],
                ['title' => 'Dust Road', 'authors' => ['Jane Author'], 'narrators' => ['Sam Voice']],
                ['title' => 'embedded_tag', 'authors' => 'embedded_tag', 'narrators' => 'nfo'],
            ],
        ];
    }

    public function testProvenanceUsesReadableSourceLabels(): void
    {
        [$file, $artifact] = $this->nfoFileAndArtifact("Narrator: Sam Voice\n");
        $draft = $this->createInterpretedDraft($this->observation('Jane Author - Dust Road', [
            $this->audioFile('f_01', 'Disc 1/01.mp3', ['album' => ['Dust Road'], 'artist' => ['Jane Author']]),
            $this->audioFile('f_02', 'Disc 1/02.mp3'),
            $file,
        ], [$artifact]));

        $this->assertSame([
            ['source' => 'file tags', 'source_id' => 'embedded_tag', 'value' => 'Dust Road', 'confidence' => 1],
            ['source' => 'folder name', 'source_id' => 'filename', 'value' => 'Dust Road', 'confidence' => 0.5],
        ], $draft['recommendation']['field_provenance']['title']);
        $this->assertSame('NFO', $draft['recommendation']['field_provenance']['narrators'][0]['source']);
    }

    public function testRecommendationOffersRelativeTargetAndDefaultDecisions(): void
    {
        $draft = $this->createInterpretedDraft($this->dustRoadObservation());
        $recommendation = $draft['recommendation'];

        $this->assertSame([[
            'id' => 'recommended',
            'relative_directory' => 'Fantasy/Jane Author/Dust Road',
            'available' => true,
            'duplicate_actions' => ['create_new', 'skip'],
        ]], $recommendation['target_candidates']);
        $this->assertSame([], $recommendation['duplicate_candidates']);
        $this->assertSame([
            ['id' => 'duplicate_action', 'type' => 'duplicate_action', 'plan_field' => 'duplicate_action',
                'options' => ['create_new'], 'default' => 'create_new', 'related_book_id' => null],
            ['id' => 'target', 'type' => 'target', 'plan_field' => 'target.candidate_id',
                'options' => ['recommended'], 'default' => 'recommended'],
            ['id' => 'file_operation', 'type' => 'file_operation', 'plan_field' => 'file_operation',
                'options' => ['copy', 'move'], 'default' => 'copy'],
        ], $recommendation['required_decisions']);
        $this->assertStringNotContainsString($this->booksRoot, (string) json_encode($draft));
    }

    public function testDuplicateWithAudioOffersSkipReplaceOrRenamedTarget(): void
    {
        $book = $this->createLibraryBook('Dust Road', 'Jane Author', 'Fantasy/Jane Author/Dust Road');
        $this->createLibraryDirectory('Fantasy/Jane Author/Dust Road', true);

        $recommendation = $this->createInterpretedDraft($this->dustRoadObservation())['recommendation'];

        $this->assertSame([
            'book_id' => $book->id,
            'title' => 'Dust Road',
            'match_reasons' => ['title_author'],
            'confidence' => 0.95,
        ], $recommendation['duplicate_candidates'][0]);
        $this->assertSame(
            [['recommended', false], ['renamed', true], ['existing_book', true]],
            array_map(static fn (array $c): array => [$c['id'], $c['available']], $recommendation['target_candidates'])
        );
        $this->assertSame('Fantasy/Jane Author/Dust Road_01', $recommendation['target_candidates'][1]['relative_directory']);
        $decision = $recommendation['required_decisions'][0];
        $this->assertSame(['skip', 'replace', 'create_new'], $decision['options']);
        $this->assertSame('skip', $decision['default']);
        $this->assertSame($book->id, $decision['related_book_id']);
        $this->assertContains('duplicate_found', array_column($recommendation['warnings'], 'code'));
        $this->assertContains('target_unavailable', array_column($recommendation['warnings'], 'code'));
    }

    public function testDuplicateWithoutAudioOffersMerge(): void
    {
        $this->createLibraryBook('Dust Road', 'Jane Author', 'Fantasy/Jane Author/Dust Road');

        $recommendation = $this->createInterpretedDraft($this->dustRoadObservation())['recommendation'];

        $this->assertSame(['merge', 'skip'], $recommendation['required_decisions'][0]['options']);
        $this->assertSame('merge', $recommendation['required_decisions'][0]['default']);
        $this->assertSame('existing_book', $recommendation['required_decisions'][1]['default']);
    }

    public function testSourceWarningsRequireAcknowledgment(): void
    {
        $recommendation = $this->createInterpretedDraft(
            $this->dustRoadObservation(['Access denied: Disc 02/private'])
        )['recommendation'];

        $this->assertSame([
            'id' => 'source_warning_1',
            'code' => 'source_incomplete',
            'message' => 'Some files could not be read on your computer: Access denied: Disc 02/private',
            'requires_acknowledgment' => true,
        ], $recommendation['warnings'][0]);
        $this->assertSame([
            'id' => 'acknowledged_warning_ids',
            'type' => 'acknowledgment',
            'plan_field' => 'acknowledged_warning_ids',
            'options' => ['source_warning_1'],
            'default' => [],
        ], end($recommendation['required_decisions']));
    }

    public function testDraftWithoutAudioNeedsAttention(): void
    {
        [$file, $artifact] = $this->nfoFileAndArtifact("Title: Dust Road\n");

        $draft = $this->createInterpretedDraft($this->observation('Only Notes', [$file], [$artifact]));

        $this->assertSame('needs_attention', $draft['state']);
        $this->assertNull($draft['recommendation']);
        $this->assertSame('no_audio_files', $draft['interpretation_error']['code']);
    }

    public function testUnexpectedInterpreterErrorFailsDraftWithSafeCode(): void
    {
        config(['import_drafts.enrichment.enabled' => true]);
        $this->app->instance(ImportMetadataEnricher::class, new class () implements ImportMetadataEnricher {
            public function enrich(array $metadata): array
            {
                throw new \Error('boom /secret/path');
            }
        });

        $draft = $this->createInterpretedDraft($this->observation('Dust Road', [
            $this->audioFile('f_01', 'Dust Road.m4b', ['album' => ['Dust Road'], 'artist' => ['Jane Author']]),
        ], [], [], ['external_enrichment']));

        // Enrichment is fail-soft: an enricher error never fails the draft.
        $this->assertSame('awaiting_review', $draft['state']);
        $this->assertContains('enrichment_unavailable', array_column($draft['recommendation']['warnings'], 'code'));
        $this->assertStringNotContainsString('/secret/path', (string) json_encode($draft));
    }

    public function testEnrichmentFillsMissingFieldsOnlyWhenEnabledAndRequested(): void
    {
        config(['import_drafts.enrichment.enabled' => true]);
        $enricher = Mockery::mock(ImportMetadataEnricher::class);
        $enricher->shouldReceive('enrich')->once()->andReturn([
            'title' => 'Dust Road',
            'series' => 'Road Saga',
            'series_number' => 2,
            'description' => 'From the web.',
        ]);
        $this->app->instance(ImportMetadataEnricher::class, $enricher);

        $draft = $this->createInterpretedDraft($this->observation('Dust Road', [
            $this->audioFile('f_01', 'Dust Road.m4b', ['album' => ['Dust Road'], 'artist' => ['Jane Author']]),
        ], [], [], ['external_enrichment']));

        $metadata = $draft['recommendation']['metadata'];
        $this->assertSame('Dust Road', $metadata['title']);
        $this->assertSame(['name' => 'Road Saga', 'number' => 2], $metadata['series']);
        $this->assertSame('From the web.', $metadata['description']);
        $this->assertSame('external_enrichment', $draft['recommendation']['field_provenance']['series'][0]['source_id']);
    }

    public function testObservedFolderUsesExistingImporterAnalysis(): void
    {
        config(['import_drafts.ai_enabled' => true]);
        $ai = Mockery::mock(AIBookProcessor::class);
        $ai->shouldReceive('processBookDirectory')->once()->with(
            '/observed/16 - USS Crusader-Mark Wayne McGinnis/USS_Crusader.m4b',
            ['USS_Crusader.m4b'],
            ['USS_Crusader.m4b' => [
                'title' => 'USS Crusader: Echoes of Sheentah',
                'album' => 'USS Hamilton',
                'artist' => 'Mark Wayne McGinnis',
                'track' => '16',
            ]],
            null,
            []
        )->andReturn([
            'title' => 'USS Crusader-Mark Wayne McGinnis',
            'author' => ['Mark Wayne McGinnis'],
            'series' => 'USS Crusader: Echoes of Sheentah',
            'series_number' => 16,
            'genre' => 'Science Fiction',
            'confidence' => 25,
        ]);
        $this->app->instance(AIBookProcessor::class, $ai);

        $observation = $this->observation('USS_Crusader.m4b', [
            $this->audioFile('f_01', 'USS_Crusader.m4b', [
                'title' => ['USS Crusader: Echoes of Sheentah'],
                'album' => ['USS Hamilton'],
                'artist' => ['Mark Wayne McGinnis'],
                'track' => ['16'],
            ]),
        ]);
        $observation['source']['relative_context'] = '16 - USS Crusader-Mark Wayne McGinnis/USS_Crusader.m4b';
        $draft = $this->createInterpretedDraft($observation);

        $this->assertSame('awaiting_review', $draft['state']);
        $this->assertSame('USS Crusader: Echoes of Sheentah', $draft['recommendation']['metadata']['title']);
        $this->assertSame(
            ['name' => 'USS Hamilton', 'number' => 16],
            $draft['recommendation']['metadata']['series']
        );
        $this->assertSame(['Science Fiction'], $draft['recommendation']['metadata']['genres']);
        $this->assertSame('embedded_tag', $draft['recommendation']['field_provenance']['title'][0]['source_id']);
        $this->assertSame('embedded_tag', $draft['recommendation']['field_provenance']['series'][0]['source_id']);
        $this->assertSame('USS Crusader: Echoes of Sheentah', $draft['source_evidence']['files'][0]['raw_tags']['title'][0]);
        $this->assertSame('USS Hamilton', $draft['source_evidence']['files'][0]['raw_tags']['album'][0]);
    }

    public function testColonTitleFromFileTagsSurvivesWithoutAi(): void
    {
        config(['import_drafts.ai_enabled' => false]);
        $draft = $this->createInterpretedDraft($this->observation('16 - USS Crusader-Mark Wayne McGinnis', [
            $this->audioFile('f_01', 'USS_Crusader.m4b', [
                'title' => ['USS Crusader: Echoes of Sheentah'],
                'album' => ['USS Hamilton'],
                'artist' => ['Mark Wayne McGinnis'],
            ]),
        ]));

        $this->assertSame('USS Crusader: Echoes of Sheentah', $draft['recommendation']['metadata']['title']);
        $this->assertSame('embedded_tag', $draft['recommendation']['field_provenance']['title'][0]['source_id']);
    }

    public function testObservedMetadataJsonKeepsExistingImporterAuthority(): void
    {
        config(['import_drafts.ai_enabled' => true]);
        $ai = Mockery::mock(AIBookProcessor::class);
        $ai->shouldReceive('processBookDirectory')->once()->andReturn([
            'title' => 'Wrong guess', 'author' => ['Jane Author'], 'genre' => 'Other', 'confidence' => 20,
        ]);
        $this->app->instance(AIBookProcessor::class, $ai);
        $json = json_encode(['title' => 'Dust Road', 'authors' => ['Jane Author'], 'genres' => ['Fantasy']]);
        $artifactId = 'text_metadata';
        $observation = $this->observation('Dust Road', [
            $this->audioFile('f_01', 'book.m4b'),
            [
                'file_id' => 'f_json', 'relative_path' => 'metadata.json', 'role' => 'sidecar',
                'bytes' => strlen($json), 'text_artifact_id' => $artifactId,
            ],
        ], [[
            'artifact_id' => $artifactId, 'kind' => 'text', 'media_type' => 'text/plain',
            'sha256' => hash('sha256', $json), 'inline_utf8' => $json,
        ]]);

        $draft = $this->createInterpretedDraft($observation);

        $this->assertSame('awaiting_review', $draft['state']);
        $this->assertSame('Dust Road', $draft['recommendation']['metadata']['title']);
        $this->assertSame(['Fantasy'], $draft['recommendation']['metadata']['genres']);
    }

    public function testObservedMetadataJsonKeepsItsAuthorityWhenAiIsUnavailable(): void
    {
        config(['import_drafts.ai_enabled' => false]);
        $json = json_encode(['title' => 'Dust Road', 'authors' => ['Jane Author'], 'genres' => ['Fantasy']]);
        $observation = $this->observation('Dust Road', [
            $this->audioFile('f_01', 'book.m4b', ['title' => ['Wrong title'], 'album' => ['Wrong album']]),
            [
                'file_id' => 'f_json', 'relative_path' => 'metadata.json', 'role' => 'sidecar',
                'bytes' => strlen($json), 'text_artifact_id' => 'text_metadata',
            ],
        ], [[
            'artifact_id' => 'text_metadata', 'kind' => 'text', 'media_type' => 'text/plain',
            'sha256' => hash('sha256', $json), 'inline_utf8' => $json,
        ]]);

        $draft = $this->createInterpretedDraft($observation);

        $this->assertSame('awaiting_review', $draft['state']);
        $this->assertSame('Dust Road', $draft['recommendation']['metadata']['title']);
        $this->assertSame(['Jane Author'], $draft['recommendation']['metadata']['authors']);
        $this->assertSame(['Fantasy'], $draft['recommendation']['metadata']['genres']);
        $this->assertSame('metadata_json', $draft['recommendation']['field_provenance']['title'][0]['source_id']);
    }

    public function testEnrichmentIsSkippedWhenNotRequested(): void
    {
        config(['import_drafts.enrichment.enabled' => true]);
        $enricher = Mockery::mock(ImportMetadataEnricher::class);
        $enricher->shouldNotReceive('enrich');
        $this->app->instance(ImportMetadataEnricher::class, $enricher);

        $this->assertSame('awaiting_review', $this->createInterpretedDraft($this->dustRoadObservation())['state']);
    }

    public function testCancelledDraftIsNotInterpreted(): void
    {
        $draft = ImportDraft::query()->create([
            'public_id' => 'imp_TESTCANCELLED',
            'owner_user_id' => (int) $this->user->id,
            'state' => 'cancelled',
            'revision' => 2,
            'source_mode' => 'upload',
            'source_display_name' => 'x',
            'observation_schema_version' => 1,
            'client_metadata' => [],
            'transfer_summary' => [],
        ]);

        app(\App\Services\Imports\ImportInterpretationService::class)->run($draft->id);

        $draft->refresh();
        $this->assertSame('cancelled', $draft->state->value);
        $this->assertSame(2, $draft->revision);
        $this->assertNull($draft->recommendation);
    }

    public function testInterpretationRecordsStateEvents(): void
    {
        $draftId = (string) $this->createInterpretedDraft($this->dustRoadObservation())['id'];

        $events = ImportDraft::query()->where('public_id', $draftId)->firstOrFail()->events()->get();

        $this->assertSame(
            [['state_changed', 1, 'created'], ['state_changed', 2, 'interpreting'], ['state_changed', 3, 'awaiting_review']],
            $events->map(fn ($e) => [$e->event_type, $e->observed_revision, $e->payload['state']])->all()
        );
    }

    public function testRuntimeExceptionClassIsNeverExposed(): void
    {
        $this->app->bind(\App\Services\Imports\ImportObservationInterpreter::class, function () {
            $mock = Mockery::mock(\App\Services\Imports\ImportObservationInterpreter::class);
            $mock->shouldReceive('interpret')->andThrow(new RuntimeException('SQLSTATE secret'));

            return $mock;
        });

        $draft = $this->createInterpretedDraft($this->dustRoadObservation());

        $this->assertSame('failed', $draft['state']);
        $this->assertSame('interpretation_failed', $draft['interpretation_error']['code']);
        $this->assertStringNotContainsString('SQLSTATE', (string) json_encode($draft));
    }
}
