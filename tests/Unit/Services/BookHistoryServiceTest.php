<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Audit;
use App\Models\Book;
use App\Models\Publisher;
use App\Models\User;
use App\Services\BookHistoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class BookHistoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private BookHistoryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new BookHistoryService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $timeline
     * @return list<array{event: string, user: ?string, fields: list<string>}>
     */
    private function summarise(array $timeline): array
    {
        return array_map(fn (array $entry) => [
            'event' => $entry['event'],
            'user' => $entry['user'],
            'fields' => array_column($entry['changes'], 'field'),
        ], $timeline['entries']);
    }

    public function testTimelineListsEditsNewestFirstEndingWithFirstImport(): void
    {
        $originalTitle = 'Original Title';
        $editedTitle = 'Edited Title';
        Carbon::setTestNow('2026-10-01 10:00:00');
        $book = Book::factory()->create(['title' => $originalTitle]);

        Carbon::setTestNow('2026-10-02 10:00:00');
        $editor = User::factory()->create(['name' => 'Eddie Editor']);
        Auth::login($editor);
        $book->update(['title' => $editedTitle]);
        Auth::logout();

        Carbon::setTestNow('2026-10-03 10:00:00');
        $book->forceFill(['directory_exists' => true, 'directory_last_checked' => now()])->save();

        $timeline = $this->service->timeline($book->fresh());

        $this->assertSame(
            [
                ['event' => 'updated', 'user' => 'Eddie Editor', 'fields' => ['title']],
                ['event' => 'created', 'user' => null, 'fields' => array_column($timeline['entries'][1]['changes'], 'field')],
            ],
            $this->summarise($timeline)
        );
        $this->assertSame(
            ['field' => 'title', 'old' => $originalTitle, 'new' => $editedTitle],
            $timeline['entries'][0]['changes'][0]
        );
        $this->assertContains('title', array_column($timeline['entries'][1]['changes'], 'field'));
        $this->assertSame(
            ['count' => 1, 'last' => '2026-10-03 10:00:00'],
            ['count' => $timeline['housekeeping']['count'], 'last' => $timeline['housekeeping']['last']?->toDateTimeString()]
        );
        $this->assertTrue($timeline['importRecorded']);
    }

    public function testImportEntryNormalisesCamelCaseKeysAndSkipsTimestamps(): void
    {
        $book = Book::factory()->create();
        Audit::query()->where('auditable_id', $book->id)->update([
            'new_values' => json_encode(json_encode([
                'title' => 'Imported',
                'directoryPath' => 'Fantasy/Imported',
                'createdAt' => '2026-10-01T10:00:00Z',
                'updatedAt' => '2026-10-01T10:00:00Z',
                'isbn' => null,
            ])),
        ]);

        $timeline = $this->service->timeline($book->fresh());

        $this->assertSame(
            [
                ['field' => 'title', 'old' => null, 'new' => 'Imported'],
                ['field' => 'directory_path', 'old' => null, 'new' => 'Fantasy/Imported'],
            ],
            $timeline['entries'][0]['changes']
        );
    }

    public function testTimelineFallsBackToAddedDateWhenImportAuditIsMissing(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $book = Book::factory()->create();
        Audit::query()->where('auditable_id', $book->id)->where('event', 'created')->delete();

        $timeline = $this->service->timeline($book->fresh());

        $this->assertSame(
            [false, [], '2026-10-01 10:00:00'],
            [$timeline['importRecorded'], $timeline['entries'], $timeline['addedAt']?->toDateTimeString()]
        );
    }

    public function testOtherDataListsOnlyFieldsTheFormDoesNotShow(): void
    {
        $publisher = Publisher::create(['name' => 'Podium Audio']);
        $book = Book::factory()->create([
            'isbn' => '9780000000001',
            'publisher_id' => $publisher->id,
            'audible_info' => ['asin' => 'B0TEST', 'rating' => 4.5],
            'ai_processed' => true,
        ]);

        $fields = [];
        foreach ($this->service->otherData($book->fresh()) as $row) {
            $fields[$row['field']] = $row['value'];
        }

        $expected = [
            'ai_processed' => 'yes',
            'audible_info' => "{\n    \"asin\": \"B0TEST\",\n    \"rating\": 4.5\n}",
            'isbn' => '9780000000001',
            'publisher_id' => $publisher->id . ' (Podium Audio)',
        ];
        $actual = array_intersect_key($fields, $expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
        $this->assertSame(
            [],
            array_intersect(array_keys($fields), ['title', 'description', 'directory_path', 'cover_image', 'release_date'])
        );
    }
}
