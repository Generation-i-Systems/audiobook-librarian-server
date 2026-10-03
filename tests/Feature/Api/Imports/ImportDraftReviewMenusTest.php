<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Imports;

use App\Models\Imports\ImportDraft;
use App\Services\Imports\ImportMetadataEnricher;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Str;
use Tests\Feature\Api\ApiTestCase;

/**
 * The server side of the terminal importer's review menus: year, a typed destination folder, existing series
 * locations as destinations, and an on-demand online lookup compared with the current details.
 */
class ImportDraftReviewMenusTest extends ApiTestCase
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
     * @return array<string, mixed>
     */
    private function observationWithYear(): array
    {
        return $this->observation('Dust Road', [
            $this->audioFile('f_01', 'Dust Road.m4b', [
                'album' => ['Dust Road'],
                'artist' => ['Jane Author'],
                'genre' => ['Fantasy'],
                'year' => ['2020'],
            ]),
        ]);
    }

    // ---- year -----------------------------------------------------------------------------------------------

    public function testYearComesFromTagsAndCanBeEdited(): void
    {
        $draft = $this->createInterpretedDraft($this->observationWithYear());

        $this->assertSame(2020, $draft['recommendation']['metadata']['year']);

        $this->patchDraft((string) $draft['id'], ['metadata' => ['year' => 2021]], '"' . $draft['revision'] . '"')
            ->assertOk()
            ->assertJsonPath('draft.recommendation.metadata.year', 2021)
            ->assertJsonPath('draft.recommendation.field_provenance.year.0.source_id', 'user_edit');
    }

    public function testYearCanBeCleared(): void
    {
        $draft = $this->createInterpretedDraft($this->observationWithYear());

        $this->patchDraft((string) $draft['id'], ['metadata' => ['year' => null]], '"' . $draft['revision'] . '"')
            ->assertOk()
            ->assertJsonPath('draft.recommendation.metadata.year', null);
    }

    /**
     * @param mixed $year
     */
    #[DataProvider('badYearProvider')]
    public function testBadYearIsRejected(mixed $year): void
    {
        $draft = $this->createInterpretedDraft($this->observationWithYear());

        $this->patchDraft((string) $draft['id'], ['metadata' => ['year' => $year]], '"' . $draft['revision'] . '"')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badYearProvider(): array
    {
        return ['text' => ['soon'], 'too small' => [99], 'too large' => [12000], 'fraction' => [2020.5]];
    }

    // ---- a typed destination folder ----------------------------------------------------------------------------

    public function testTypedDirectoryBecomesTheFirstDestinationAndDefault(): void
    {
        $draft = $this->createInterpretedDraft($this->observationWithYear());
        $directory = 'Fantasy/Jane Author/Dust Road (Sam Voice)';

        $response = $this->patchDraft(
            (string) $draft['id'],
            ['custom_directory' => $directory],
            '"' . $draft['revision'] . '"'
        )->assertOk();

        $candidates = $response->json('draft.recommendation.target_candidates');
        $this->assertSame(['id' => 'custom', 'relative_directory' => $directory, 'available' => true], array_intersect_key(
            $candidates[0],
            array_flip(['id', 'relative_directory', 'available'])
        ));
        $this->assertSame('recommended', $candidates[1]['id']);
        $this->assertSame(
            'custom',
            array_column($response->json('draft.recommendation.required_decisions'), 'default', 'id')['target']
        );
    }

    public function testApprovalStoresTheTypedDirectoryExactly(): void
    {
        $draft = $this->createInterpretedDraft($this->observationWithYear());
        $directory = 'Fantasy/Jane Author/Dust Road (Sam Voice)';
        $patched = $this->patchDraft(
            (string) $draft['id'],
            ['custom_directory' => $directory],
            '"' . $draft['revision'] . '"'
        )->assertOk()->json('draft');

        $payload = $this->approvalFromDefaults($patched);
        $payload['target'] = ['candidate_id' => 'custom'];

        $this->approve((string) $draft['id'], $payload, '"' . $patched['revision'] . '"')
            ->assertOk()
            ->assertJsonPath('draft.plan.target.candidate_id', 'custom')
            ->assertJsonPath('draft.plan.target.relative_directory', $directory);
    }

    public function testTypedDirectoryCanBeCleared(): void
    {
        $draft = $this->createInterpretedDraft($this->observationWithYear());
        $first = $this->patchDraft(
            (string) $draft['id'],
            ['custom_directory' => 'Fantasy/Jane Author/Other'],
            '"' . $draft['revision'] . '"'
        )->assertOk()->json('draft');

        $cleared = $this->patchDraft((string) $draft['id'], ['custom_directory' => null], '"' . $first['revision'] . '"')
            ->assertOk();

        $this->assertNotContains('custom', array_column($cleared->json('draft.recommendation.target_candidates'), 'id'));
    }

    public function testTypedDirectoryThatIsTakenIsNotAvailable(): void
    {
        $this->createLibraryDirectory('Fantasy/Jane Author/Taken', true);
        $draft = $this->createInterpretedDraft($this->observationWithYear());

        $response = $this->patchDraft(
            (string) $draft['id'],
            ['custom_directory' => 'Fantasy/Jane Author/Taken'],
            '"' . $draft['revision'] . '"'
        )->assertOk();

        $custom = $response->json('draft.recommendation.target_candidates.0');
        $this->assertSame('custom', $custom['id']);
        $this->assertFalse($custom['available']);
    }

    /**
     * @param mixed $directory
     */
    #[DataProvider('badDirectoryProvider')]
    public function testUnsafeTypedDirectoryIsRejectedNotRewritten(mixed $directory): void
    {
        $draft = $this->createInterpretedDraft($this->observationWithYear());

        $this->patchDraft((string) $draft['id'], ['custom_directory' => $directory], '"' . $draft['revision'] . '"')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
        $this->assertSame(
            $draft['revision'],
            ImportDraft::query()->where('public_id', $draft['id'])->value('revision')
        );
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badDirectoryProvider(): array
    {
        return [
            'absolute' => ['/media/audiobooks/x'],
            'parent segment' => ['Fantasy/../../etc'],
            'empty segment' => ['Fantasy//Book'],
            'backslash' => ['Fantasy\\Book'],
            'blank' => ['   '],
            'trailing slash' => ['Fantasy/Book/'],
            'control character' => ["Fantasy/Bo\x00ok"],
            'too long' => [str_repeat('a', 501)],
            'not text' => [['Fantasy']],
        ];
    }

    // ---- existing series locations as destinations ------------------------------------------------------------

    public function testExistingSeriesLocationsAreOfferedAsDestinations(): void
    {
        $this->createLibraryDirectory('Science Fiction/Jane Author/Road Saga/Book One', true);
        $this->createLibraryDirectory('Science Fiction/Jane Author/Road Saga/Book Two', true);
        $draft = $this->createInterpretedDraft($this->observation('Dust Road', [
            $this->audioFile('f_01', 'Dust Road.m4b', [
                'album' => ['Dust Road'],
                'artist' => ['Jane Author'],
                'genre' => ['Fantasy'],
                'grouping' => ['Road Saga'],
            ]),
        ]));
        $patched = $this->patchDraft(
            (string) $draft['id'],
            ['metadata' => ['series' => ['name' => 'Road Saga', 'number' => 3]]],
            '"' . $draft['revision'] . '"'
        )->assertOk()->json('draft');

        $series = array_values(array_filter(
            $patched['recommendation']['target_candidates'],
            static fn (array $candidate): bool => str_starts_with($candidate['id'], 'series_location_')
        ));

        $this->assertCount(1, $series);
        $this->assertSame('Science Fiction/Jane Author/Road Saga', substr($series[0]['relative_directory'], 0, 37));
        $this->assertSame(2, $series[0]['book_count']);
        $this->assertTrue($series[0]['available']);
        // The computed destination stays the default.
        $this->assertSame('recommended', $this->decisionDefault($patched, 'target'));
    }

    // ---- an online lookup compared with the current details ------------------------------------------------

    public function testEnrichmentIsComparedWithTheCurrentDetails(): void
    {
        config(['import_drafts.enrichment.enabled' => true]);
        $enricher = Mockery::mock(ImportMetadataEnricher::class);
        $enricher->shouldReceive('enrich')->once()->andReturn([
            'title' => 'Dust Road',
            'author' => ['Jane Author'],
            'narrator' => ['Sam Voice'],
            'series' => 'Road Saga',
            'series_number' => 2,
            'year' => 2019,
            'genre' => ['Fantasy'],
            '_enrichment_results' => ['audible' => 'success'],
        ]);
        $this->app->instance(ImportMetadataEnricher::class, $enricher);
        $draft = $this->createInterpretedDraft($this->observationWithYear());

        $response = $this->withDraftHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/imports/drafts/' . $draft['id'] . '/enrichment')
            ->assertOk()
            ->assertJsonPath('draft_revision', $draft['revision']);

        $rows = array_column($response->json('fields'), null, 'field');
        $this->assertSame(['narrators', 'series', 'year'], array_keys($rows));
        $this->assertSame([], $rows['narrators']['current']);
        $this->assertSame(['Sam Voice'], $rows['narrators']['enriched']);
        $this->assertSame(2020, $rows['year']['current']);
        $this->assertSame(2019, $rows['year']['enriched']);
        $this->assertSame(['name' => 'Road Saga', 'number' => 2], $rows['series']['enriched']);
        $this->assertSame($draft['revision'], ImportDraft::query()->where('public_id', $draft['id'])->value('revision'));
    }

    public function testEnrichmentThatDoesNotMatchTheBookSaysSo(): void
    {
        config(['import_drafts.enrichment.enabled' => true]);
        $enricher = Mockery::mock(ImportMetadataEnricher::class);
        $enricher->shouldReceive('enrich')->once()->andReturn(['title' => 'Completely Different Thing', 'author' => ['Someone Else']]);
        $this->app->instance(ImportMetadataEnricher::class, $enricher);
        $draft = $this->createInterpretedDraft($this->observationWithYear());

        $this->withDraftHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/imports/drafts/' . $draft['id'] . '/enrichment')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'enrichment_no_match');
    }

    public function testEnrichmentIsRefusedWhenTheLibraryHasItOff(): void
    {
        config(['import_drafts.enrichment.enabled' => false]);
        $draft = $this->createInterpretedDraft($this->observationWithYear());

        $this->withDraftHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/imports/drafts/' . $draft['id'] . '/enrichment')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'enrichment_not_available');
    }

    public function testEnrichmentOfAnotherPersonsDraftIsNotFound(): void
    {
        config(['import_drafts.enrichment.enabled' => true]);
        $draft = $this->createInterpretedDraft($this->observationWithYear());
        ImportDraft::query()->where('public_id', $draft['id'])->update(['owner_user_id' => $this->user->id + 999]);

        $this->withDraftHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/imports/drafts/' . $draft['id'] . '/enrichment')
            ->assertStatus(404);
    }
}
