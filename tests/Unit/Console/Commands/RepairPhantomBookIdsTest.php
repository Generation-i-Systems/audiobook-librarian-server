<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands;

use App\Models\Book;
use App\Models\BookPosition;
use App\Models\ListeningEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairPhantomBookIdsTest extends TestCase
{
    use RefreshDatabase;

    public function testDryRunDoesNotWriteAnyChanges(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create([
            'directory_path' => "Jeffery H. Haskell/Grimm's War/09 The Longest Battle",
        ]);

        $this->createPhantomRows($user->id, $book->id, 999999999, "Jeffery H. Haskell/Grimm's War/09 The Longest Battle_{$book->id}");

        $this->artisan('books:repair-phantom-book-ids')->assertSuccessful();

        $this->assertDatabaseHas('listening_events', ['id' => 'evt-1', 'book_id' => 999999999]);
        $this->assertDatabaseHas('book_positions', ['user_id' => $user->id, 'book_id' => 999999999, 'device_id' => 'device-1']);
    }

    public function testApplyReassignsListeningEventsAndBookPositions(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create([
            'directory_path' => "Jeffery H. Haskell/Grimm's War/09 The Longest Battle",
        ]);

        $this->createPhantomRows($user->id, $book->id, 999999999, "Jeffery H. Haskell/Grimm's War/09 The Longest Battle_{$book->id}");

        $this->artisan('books:repair-phantom-book-ids', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseHas('listening_events', ['id' => 'evt-1', 'book_id' => $book->id]);
        $this->assertDatabaseMissing('listening_events', ['id' => 'evt-1', 'book_id' => 999999999]);
        $this->assertDatabaseHas('book_positions', ['user_id' => $user->id, 'book_id' => $book->id, 'device_id' => 'device-1']);
        $this->assertDatabaseMissing('book_positions', ['user_id' => $user->id, 'book_id' => 999999999, 'device_id' => 'device-1']);
    }

    public function testConflictIsMergedWhenAGenuineCompletionEventExists(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create([
            'directory_path' => "Jeffery H. Haskell/Grimm's War/09 The Longest Battle",
        ]);

        // The fixture's phantom row includes a real BOOK_FINISH event (position_ms=40877824).
        $this->createPhantomRows($user->id, $book->id, 999999999, "Jeffery H. Haskell/Grimm's War/09 The Longest Battle_{$book->id}");

        // A row already exists at the real (user, book, device) key, e.g. a partial listen
        // recorded before the finish under the phantom id was ever resolved.
        BookPosition::query()->create([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'device_id' => 'device-1',
            'position_ms' => 500,
            'progress_percentage' => 1,
            'completed' => false,
            'last_event_timestamp_ms' => now()->subMinute()->valueOf(),
            'last_event_id' => 'evt-existing',
        ]);

        $this->artisan('books:repair-phantom-book-ids', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseHas('book_positions', [
            'user_id' => $user->id,
            'book_id' => $book->id,
            'device_id' => 'device-1',
            'position_ms' => 40877824,
            'completed' => true,
        ]);
        // The now-redundant phantom row is removed rather than left duplicated.
        $this->assertDatabaseMissing('book_positions', ['user_id' => $user->id, 'book_id' => 999999999, 'device_id' => 'device-1']);
        $this->assertDatabaseHas('listening_events', ['id' => 'evt-1', 'book_id' => $book->id]);
    }

    public function testConflictWithNoCompletionEvidenceIsSkipped(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create([
            'directory_path' => "Jeffery H. Haskell/Grimm's War/09 The Longest Battle",
        ]);

        ListeningEvent::query()->create([
            'id' => 'evt-partial',
            'user_id' => $user->id,
            'book_id' => 999999999,
            'event_type' => 'SESSION_END',
            'timestamp_ms' => now()->getTimestampMs(),
            'position_ms' => 1000000,
            'metadata' => [
                'bookPath' => "Jeffery H. Haskell/Grimm's War/09 The Longest Battle_{$book->id}",
                'fallbackTitle' => 'The Longest Battle',
            ],
            'device_id' => 'device-1',
            'timezone' => 'UTC',
            'sync_status' => 'synced',
            'created_at' => now()->getTimestampMs(),
            'synced_at' => now()->getTimestampMs(),
        ]);

        BookPosition::query()->create([
            'user_id' => $user->id,
            'book_id' => 999999999,
            'device_id' => 'device-1',
            'position_ms' => 1000000,
            'progress_percentage' => 50,
            'completed' => false,
            'last_event_timestamp_ms' => now()->valueOf(),
            'last_event_id' => 'evt-partial',
        ]);

        // A row already exists at the real (user, book, device) key — with no BOOK_FINISH
        // anywhere on either side, this genuinely is just "which position is current" and
        // must be left for a human, not auto-merged.
        BookPosition::query()->create([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'device_id' => 'device-1',
            'position_ms' => 500,
            'progress_percentage' => 1,
            'completed' => false,
            'last_event_timestamp_ms' => now()->subMinute()->valueOf(),
            'last_event_id' => 'evt-existing',
        ]);

        $this->artisan('books:repair-phantom-book-ids', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseHas('book_positions', ['user_id' => $user->id, 'book_id' => 999999999, 'device_id' => 'device-1']);
        $this->assertDatabaseHas('book_positions', ['user_id' => $user->id, 'book_id' => $book->id, 'device_id' => 'device-1', 'position_ms' => 500]);
    }

    public function testKeepExistingOnConflictDiscardsThePhantomRowWithNoCompletionEvidence(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create([
            'directory_path' => "Jeffery H. Haskell/Grimm's War/09 The Longest Battle",
        ]);

        ListeningEvent::query()->create([
            'id' => 'evt-partial',
            'user_id' => $user->id,
            'book_id' => 999999999,
            'event_type' => 'SESSION_END',
            'timestamp_ms' => now()->getTimestampMs(),
            'position_ms' => 1000000,
            'metadata' => [
                'bookPath' => "Jeffery H. Haskell/Grimm's War/09 The Longest Battle_{$book->id}",
                'fallbackTitle' => 'The Longest Battle',
            ],
            'device_id' => 'device-1',
            'timezone' => 'UTC',
            'sync_status' => 'synced',
            'created_at' => now()->getTimestampMs(),
            'synced_at' => now()->getTimestampMs(),
        ]);

        BookPosition::query()->create([
            'user_id' => $user->id,
            'book_id' => 999999999,
            'device_id' => 'device-1',
            'position_ms' => 1000000,
            'progress_percentage' => 50,
            'completed' => false,
            'last_event_timestamp_ms' => now()->valueOf(),
            'last_event_id' => 'evt-partial',
        ]);

        BookPosition::query()->create([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'device_id' => 'device-1',
            'position_ms' => 500,
            'progress_percentage' => 1,
            'completed' => false,
            'last_event_timestamp_ms' => now()->subMinute()->valueOf(),
            'last_event_id' => 'evt-existing',
        ]);

        $this->artisan('books:repair-phantom-book-ids', ['--apply' => true, '--keep-existing-on-conflict' => true])->assertSuccessful();

        $this->assertDatabaseMissing('book_positions', ['user_id' => $user->id, 'book_id' => 999999999, 'device_id' => 'device-1']);
        $this->assertDatabaseHas('book_positions', ['user_id' => $user->id, 'book_id' => $book->id, 'device_id' => 'device-1', 'position_ms' => 500]);
    }

    public function testUnresolvableIdsAreLeftAlone(): void
    {
        $user = User::factory()->create();

        ListeningEvent::query()->create([
            'id' => 'evt-unresolvable',
            'user_id' => $user->id,
            'book_id' => 999999994,
            'event_type' => 'SESSION_END',
            'timestamp_ms' => now()->getTimestampMs(),
            'position_ms' => 1000,
            'metadata' => ['fallbackTitle' => 'Nothing Matches This'],
            'device_id' => 'device-1',
            'timezone' => 'UTC',
            'sync_status' => 'synced',
            'created_at' => now()->getTimestampMs(),
            'synced_at' => now()->getTimestampMs(),
        ]);

        $this->artisan('books:repair-phantom-book-ids', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseHas('listening_events', ['id' => 'evt-unresolvable', 'book_id' => 999999994]);
    }

    public function testUserOptionScopesToOneUser(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $book = Book::factory()->create([
            'directory_path' => "Jeffery H. Haskell/Grimm's War/09 The Longest Battle",
        ]);

        $this->createPhantomRows($userA->id, $book->id, 999999993, "Jeffery H. Haskell/Grimm's War/09 The Longest Battle_{$book->id}", 'evt-a');
        $this->createPhantomRows($userB->id, $book->id, 999999993, "Jeffery H. Haskell/Grimm's War/09 The Longest Battle_{$book->id}", 'evt-b');

        $this->artisan('books:repair-phantom-book-ids', ['--apply' => true, '--user' => $userA->id])->assertSuccessful();

        $this->assertDatabaseHas('listening_events', ['id' => 'evt-a', 'book_id' => $book->id]);
        $this->assertDatabaseHas('listening_events', ['id' => 'evt-b', 'book_id' => 999999993]);
    }

    private function createPhantomRows(int $userId, int $realBookId, int $phantomBookId, string $bookPath, string $eventId = 'evt-1'): void
    {
        ListeningEvent::query()->create([
            'id' => $eventId,
            'user_id' => $userId,
            'book_id' => $phantomBookId,
            'event_type' => 'BOOK_FINISH',
            'timestamp_ms' => now()->getTimestampMs(),
            'position_ms' => 40877824,
            'metadata' => ['bookPath' => $bookPath, 'fallbackTitle' => 'The Longest Battle'],
            'device_id' => 'device-1',
            'timezone' => 'UTC',
            'sync_status' => 'synced',
            'created_at' => now()->getTimestampMs(),
            'synced_at' => now()->getTimestampMs(),
        ]);

        BookPosition::query()->create([
            'user_id' => $userId,
            'book_id' => $phantomBookId,
            'device_id' => 'device-1',
            'position_ms' => 40877824,
            'progress_percentage' => 100,
            'completed' => true,
            'last_event_timestamp_ms' => now()->valueOf(),
            'last_event_id' => $eventId,
        ]);
    }
}
