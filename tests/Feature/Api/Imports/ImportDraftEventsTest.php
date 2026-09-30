<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Imports;

use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\ApiTestCase;

class ImportDraftEventsTest extends ApiTestCase
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

    public function testEventsArePagedOldestFirstWithStableCursor(): void
    {
        config(['import_drafts.event_page_size' => 2]);
        $draftId = (string) $this->createInterpretedDraft($this->dustRoadObservation())['id'];
        $url = self::DRAFTS_URL . '/' . $draftId . '/events';

        $first = $this->getJson($url)->assertOk()
            ->assertJsonPath('contract_version', 'imports.v1')
            ->assertJsonPath('draft', ['id' => $draftId, 'revision' => 3, 'state' => 'awaiting_review'])
            ->assertJsonPath('has_more', true);
        $this->assertSame(['created', 'interpreting'], array_column(array_column($first->json('data'), 'data'), 'state'));
        $this->assertSame([1, 2], array_column($first->json('data'), 'revision'));
        $this->assertSame('state_changed', $first->json('data.0.event'));
        $this->assertSame($draftId, $first->json('data.0.draft_id'));
        $cursor = (string) $first->json('next_cursor');
        $this->assertSame($first->json('data.1.id'), $cursor);

        $second = $this->getJson($url . '?after=' . $cursor)->assertOk()->assertJsonPath('has_more', false);
        $this->assertSame(['awaiting_review'], array_column(array_column($second->json('data'), 'data'), 'state'));
        $lastCursor = (string) $second->json('next_cursor');

        $this->getJson($url . '?after=' . $lastCursor)->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('next_cursor', $lastCursor)
            ->assertJsonPath('has_more', false);
    }

    public function testLastEventIdHeaderActsAsCursor(): void
    {
        $draftId = (string) $this->createInterpretedDraft($this->dustRoadObservation())['id'];
        $url = self::DRAFTS_URL . '/' . $draftId . '/events';
        $all = $this->getJson($url)->json('data');

        $this->withHeader('Last-Event-ID', (string) $all[1]['id'])
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.0.id', $all[2]['id']);
    }

    #[DataProvider('invalidCursorProvider')]
    public function testInvalidCursorIsRejected(string $cursor): void
    {
        $draftId = (string) $this->createInterpretedDraft($this->dustRoadObservation())['id'];

        $this->getJson(self::DRAFTS_URL . '/' . $draftId . '/events?after=' . urlencode($cursor))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidCursorProvider(): array
    {
        return ['negative' => ['-1'], 'text' => ['abc'], 'decimal' => ['1.5']];
    }

    public function testOtherUsersCannotReadEvents(): void
    {
        $draftId = (string) $this->createInterpretedDraft($this->dustRoadObservation())['id'];
        $other = User::factory()->create(['role' => 'library-user']);
        $this->grantImportPermission($other);
        $this->actingAsUser($other);

        $this->getJson(self::DRAFTS_URL . '/' . $draftId . '/events')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'draft_not_found');
    }
}
