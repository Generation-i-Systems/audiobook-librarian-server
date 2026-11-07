<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Events\BookStatusUpdated;
use App\Models\Book;
use App\Models\BookPosition;
use App\Models\BookProgress;
use App\Models\User;
use App\Models\UserBookStatus;
use App\Services\ReadToFinishedPromoter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReadToFinishedPromotionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
        $this->book = Book::factory()->create(['duration' => 10000]);
        \Laravel\Sanctum\Sanctum::actingAs($this->user);
    }

    private function givenPosition(int $positionMs, string $deviceId = 'phone', ?int $timestampMs = null): void
    {
        BookPosition::create([
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'device_id' => $deviceId,
            'position_ms' => $positionMs,
            'last_event_timestamp_ms' => $timestampMs ?? (int) now()->subHour()->getTimestampMs(),
            'last_event_id' => 'evt-' . $deviceId,
        ]);
    }

    private function markRead(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/status/' . $this->book->id . '/read', ['is_read' => true]);
    }

    public function testMarkingReadWithProgressOverNinetyPercentFinishesTheBook(): void
    {
        Event::fake([BookStatusUpdated::class]);
        $this->givenPosition(9_500_000); // 95% of 10000 s

        $this->markRead()->assertOk()->assertJson(['is_read' => false, 'finished' => true]);

        $status = UserBookStatus::where('user_id', $this->user->id)->where('book_id', $this->book->id)->firstOrFail();
        $this->assertSame(
            ['status' => 'completed', 'marked_read_at' => null, 'read_count' => 1, 'has_finished_at' => true],
            [
                'status' => $status->status,
                'marked_read_at' => $status->marked_read_at,
                'read_count' => (int) $status->read_count,
                'has_finished_at' => $status->finished_at !== null,
            ],
        );
        $this->assertDatabaseHas('listening_events', [
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'event_type' => 'BOOK_FINISH',
            'position_ms' => 10_000_000,
            'device_id' => ReadToFinishedPromoter::EVENT_DEVICE_ID,
        ]);
        $this->assertDatabaseHas('book_positions', [
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'device_id' => ReadToFinishedPromoter::EVENT_DEVICE_ID,
            'position_ms' => 10_000_000,
            'completed' => true,
        ]);
        Event::assertDispatched(BookStatusUpdated::class, fn (BookStatusUpdated $e) => $e->status === 'completed');
    }

    public function testMarkingReadWithProgressAtNinetyPercentStaysRead(): void
    {
        $this->givenPosition(9_000_000); // exactly 90%

        $this->markRead()->assertOk()->assertJson(['is_read' => true, 'finished' => false]);

        $this->assertDatabaseMissing('user_book_status', ['book_id' => $this->book->id, 'status' => 'completed']);
        $this->assertDatabaseMissing('listening_events', ['book_id' => $this->book->id, 'event_type' => 'BOOK_FINISH']);
    }

    public function testMarkingReadUsesTheLastProgressNotTheHighest(): void
    {
        $this->givenPosition(9_800_000, 'tablet', (int) now()->subDays(2)->getTimestampMs());
        $this->givenPosition(1_000_000, 'phone', (int) now()->subHour()->getTimestampMs());

        $this->markRead()->assertOk()->assertJson(['is_read' => true, 'finished' => false]);
    }

    public function testLegacyProgressRowsAreSetToOneHundredPercent(): void
    {
        BookProgress::create([
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'device_id' => 'phone',
            'current_position_seconds' => 9_300,
            'total_duration_seconds' => 10_000,
            'progress_percentage' => 93,
            'last_listened_at' => now()->subMinute(),
            'completed' => false,
        ]);

        $this->markRead()->assertOk()->assertJson(['finished' => true]);

        $this->assertDatabaseHas('book_progress', [
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'current_position_seconds' => 10_000,
            'progress_percentage' => 100,
            'completed' => true,
        ]);
    }

    public function testProgressSyncedForAMarkedReadBookFinishesIt(): void
    {
        UserBookStatus::factory()->create([
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'status' => 'in_progress',
            'marked_read_at' => now()->subDay(),
        ]);
        $this->givenPosition(9_600_000);

        $promoted = app(ReadToFinishedPromoter::class)->promoteForUser($this->user, [(int) $this->book->id]);

        $this->assertSame([(int) $this->book->id], $promoted);
        $this->assertDatabaseHas('user_book_status', [
            'book_id' => $this->book->id,
            'status' => 'completed',
            'marked_read_at' => null,
        ]);
    }

    /**
     * @return array<string, array{string, callable(Book): array<string, mixed>}>
     */
    public static function syncEndpointProvider(): array
    {
        $nowMs = fn (): int => (int) now()->getTimestampMs();

        return [
            'event sync' => ['/api/v1/sync/events', fn (Book $book): array => [
                'events' => [[
                    'id' => 'evt-pause-1',
                    'bookId' => $book->id,
                    'eventType' => 'PLAY_PAUSE',
                    'timestampMs' => $nowMs(),
                    'positionMs' => 9_500_000,
                    'metadata' => [],
                    'deviceId' => 'phone',
                    'timezone' => 'UTC',
                    'createdAt' => $nowMs(),
                ]],
                'lastSyncTimestamp' => 0,
            ]],
            'position sync' => ['/api/v1/sync/positions', fn (Book $book): array => [
                'client_timestamp' => now()->toIso8601String(),
                'positions' => [[
                    'book_id' => $book->id,
                    'position_ms' => 9_500_000,
                    'progress_percentage' => 95.0,
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]],
            'legacy progress sync' => ['/api/v1/sync/progress', fn (Book $book): array => [
                'clientTimestamp' => now()->toIso8601String(),
                'progress' => [[
                    'bookId' => $book->id,
                    'positionMs' => 9_500_000,
                    'progressPercentage' => 95.0,
                    'lastUpdated' => now()->toIso8601String(),
                ]],
            ]],
        ];
    }

    /**
     * @param callable(Book): array<string, mixed> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('syncEndpointProvider')]
    public function testSyncingProgressOverNinetyPercentFinishesAMarkedReadBook(string $uri, callable $payload): void
    {
        UserBookStatus::factory()->create([
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'status' => 'in_progress',
            'marked_read_at' => now()->subDay(),
        ]);

        $this->postJson($uri, $payload($this->book), ['X-Device-ID' => 'phone', 'X-Device-Name' => 'Phone'])
            ->assertSuccessful();

        $this->assertDatabaseHas('user_book_status', [
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'status' => 'completed',
            'marked_read_at' => null,
        ]);
        $this->assertDatabaseHas('listening_events', [
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'event_type' => 'BOOK_FINISH',
            'device_id' => ReadToFinishedPromoter::EVENT_DEVICE_ID,
            'position_ms' => 10_000_000,
        ]);
    }

    public function testCommandDryRunChangesNothingAndRealRunFinishes(): void
    {
        UserBookStatus::factory()->create([
            'user_id' => $this->user->id,
            'book_id' => $this->book->id,
            'status' => 'queue',
            'marked_read_at' => now()->subDay(),
        ]);
        $this->givenPosition(9_900_000);

        $this->artisan('books:promote-read-to-finished', ['--dry-run' => true])
            ->expectsOutputToContain('1 book(s) would be marked finished.')
            ->assertSuccessful();
        $this->assertDatabaseHas('user_book_status', ['book_id' => $this->book->id, 'status' => 'queue']);

        $this->artisan('books:promote-read-to-finished')
            ->expectsOutputToContain('1 book(s) marked finished.')
            ->assertSuccessful();
        $this->assertDatabaseHas('user_book_status', [
            'book_id' => $this->book->id,
            'status' => 'completed',
            'marked_read_at' => null,
        ]);
    }
}
