<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Book;
use App\Models\BookPosition;
use App\Models\User;
use App\Models\UserBookStatus;
use App\Services\BookCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookCompletionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function testGetCompletedBookDatesForUserIgnoresBookPositionsReferencingNoRealBook(): void
    {
        // Regression test: some synced book_positions rows carry a book_id that was never
        // matched to a real server book (e.g. a client sending its own local-only id), which
        // must not be treated as a real completion — it previously poisoned "most recent
        // completions" ranking since the phantom row still had a (bogus) date.
        $user = User::factory()->create();
        $realBook = Book::factory()->create(['duration' => 3600]);

        BookPosition::query()->create([
            'user_id' => $user->id,
            'book_id' => $realBook->id,
            'device_id' => 'device-1',
            'position_ms' => 3600000,
            'progress_percentage' => 100,
            'completed' => true,
            'last_event_timestamp_ms' => now()->subDay()->valueOf(),
            'last_event_id' => 'event-1',
        ]);

        BookPosition::query()->create([
            'user_id' => $user->id,
            'book_id' => 999999999,
            'device_id' => 'device-1',
            'position_ms' => 3600000,
            'progress_percentage' => 100,
            'completed' => true,
            // Deliberately the most recent timestamp, to prove it doesn't outrank the real book.
            'last_event_timestamp_ms' => now()->valueOf(),
            'last_event_id' => 'event-2',
        ]);

        $service = new BookCompletionService();
        $dates = $service->getCompletedBookDatesForUser($user->id);

        $this->assertCount(1, $dates);
        $this->assertTrue($dates->has($realBook->id));
        $this->assertFalse($dates->has(999999999));
    }

    public function testGetCompletedBookDatesForUserIgnoresACompletedFlagFarShortOfTheBooksDuration(): void
    {
        // Regression: a real device sent a BOOK_FINISH event for a ~13-hour book after only
        // 37 seconds of playback (position_ms=37590 vs duration=46628s) — completed=true was
        // trusted at face value, so the book showed up as "just finished" in discovery shelves
        // despite the user not having listened to any meaningful portion of it.
        $user = User::factory()->create();
        $barelyStartedBook = Book::factory()->create(['duration' => 46628]);
        $genuinelyFinishedBook = Book::factory()->create(['duration' => 3600]);

        BookPosition::query()->create([
            'user_id' => $user->id,
            'book_id' => $barelyStartedBook->id,
            'device_id' => 'device-1',
            'position_ms' => 37590,
            'progress_percentage' => 100,
            'completed' => true,
            'last_event_timestamp_ms' => now()->valueOf(),
            'last_event_id' => 'event-bogus',
        ]);

        BookPosition::query()->create([
            'user_id' => $user->id,
            'book_id' => $genuinelyFinishedBook->id,
            'device_id' => 'device-1',
            'position_ms' => 3600000,
            'progress_percentage' => 100,
            'completed' => true,
            'last_event_timestamp_ms' => now()->valueOf(),
            'last_event_id' => 'event-real',
        ]);

        $service = new BookCompletionService();
        $dates = $service->getCompletedBookDatesForUser($user->id);

        $this->assertFalse($dates->has($barelyStartedBook->id));
        $this->assertTrue($dates->has($genuinelyFinishedBook->id));
    }

    public function testGetInProgressBookIdsForUserIgnoresPhantomBookIds(): void
    {
        $user = User::factory()->create();
        $realBook = Book::factory()->create();

        BookPosition::query()->create([
            'user_id' => $user->id,
            'book_id' => $realBook->id,
            'device_id' => 'device-1',
            'position_ms' => 1800000,
            'progress_percentage' => 50,
            'completed' => false,
            'last_event_timestamp_ms' => now()->valueOf(),
            'last_event_id' => 'event-3',
        ]);

        BookPosition::query()->create([
            'user_id' => $user->id,
            'book_id' => 888888888,
            'device_id' => 'device-1',
            'position_ms' => 1800000,
            'progress_percentage' => 50,
            'completed' => false,
            'last_event_timestamp_ms' => now()->valueOf(),
            'last_event_id' => 'event-4',
        ]);

        $service = new BookCompletionService();
        $ids = $service->getInProgressBookIdsForUser($user->id);

        $this->assertSame([$realBook->id], $ids);
    }

    public function testGetEngagedBookIdsForUserIncludesUserBookStatusRow(): void
    {
        // user_book_status.book_id has a real foreign key to books, so a phantom row can't
        // exist there — unlike book_progress/book_positions, whose sync-writable rows can.
        // This just confirms the merge still correctly surfaces a normal status row.
        $user = User::factory()->create();
        $realBook = Book::factory()->create();

        UserBookStatus::factory()->create([
            'user_id' => $user->id,
            'book_id' => $realBook->id,
            'status' => 'queue',
        ]);

        $service = new BookCompletionService();
        $ids = $service->getEngagedBookIdsForUser($user->id);

        $this->assertSame([$realBook->id], $ids);
    }
}
