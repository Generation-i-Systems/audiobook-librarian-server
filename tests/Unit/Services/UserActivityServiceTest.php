<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Badge;
use App\Models\Book;
use App\Models\BookProgress;
use App\Models\ListeningEvent;
use App\Models\Review;
use App\Models\User;
use App\Models\UserBadge;
use App\Models\UserBookStatus;
use App\Models\UserRecommendation;
use App\Services\UserActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserActivityServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function getBadgeTipsReturnsNextUnearnedBadgePerCategory(): void
    {
        $service = new UserActivityService();
        $user = User::factory()->create();

        $earnedBadge = Badge::query()->create([
            'key' => 'listen-bronze',
            'name' => 'Listening Bronze',
            'description' => 'Listen a little',
            'icon' => 'B',
            'category' => 'listening',
            'tier' => 'bronze',
            'points' => 10,
            'criteria' => [],
            'is_active' => true,
            'is_repeatable' => false,
            'sort_order' => 1,
        ]);

        Badge::query()->create([
            'key' => 'listen-silver',
            'name' => 'Listening Silver',
            'description' => 'Listen more',
            'icon' => 'S',
            'category' => 'listening',
            'tier' => 'silver',
            'points' => 20,
            'criteria' => [],
            'is_active' => true,
            'is_repeatable' => false,
            'sort_order' => 2,
        ]);

        UserBadge::query()->create([
            'user_id' => $user->id,
            'badge_id' => $earnedBadge->id,
            'earned_at' => now(),
            'criteria_met' => [],
            'tier_level' => 1,
        ]);

        $tips = $service->getBadgeTips((string) $user->id);

        $this->assertCount(1, $tips);
        $this->assertSame('listening', $tips[0]['category']);
        $this->assertSame('Listening Silver', $tips[0]['badge_name']);
    }

    #[Test]
    public function getUserActivityDataBuildsBadgesProgressReviewsRecommendationsAndStatuses(): void
    {
        $service = new UserActivityService();
        $user = User::factory()->create();
        $sender = User::factory()->create(['name' => 'Recommender']);
        $book = Book::factory()->create(['title' => 'Deep Work', 'duration' => 1000]);

        $badge = Badge::query()->create([
            'key' => 'habit-bronze',
            'name' => 'Habit Bronze',
            'description' => 'Build a habit',
            'icon' => 'H',
            'category' => 'habit',
            'tier' => 'bronze',
            'points' => 10,
            'criteria' => [],
            'is_active' => true,
            'is_repeatable' => false,
            'sort_order' => 1,
        ]);

        Badge::query()->create([
            'key' => 'habit-silver',
            'name' => 'Habit Silver',
            'description' => 'Keep going',
            'icon' => 'HS',
            'category' => 'habit',
            'tier' => 'silver',
            'points' => 20,
            'criteria' => [],
            'is_active' => true,
            'is_repeatable' => false,
            'sort_order' => 2,
        ]);

        UserBadge::query()->create([
            'user_id' => $user->id,
            'badge_id' => $badge->id,
            'earned_at' => now(),
            'criteria_met' => [],
            'tier_level' => 1,
        ]);

        BookProgress::query()->create([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'device_id' => 'device-1',
            'current_position_seconds' => 200,
            'total_duration_seconds' => 1000,
            'progress_percentage' => 20,
            'completed' => false,
            'last_listened_at' => now(),
        ]);

        Review::query()->create([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'comment' => 'Great book',
            'age_rating' => 12,
            'content_rating' => 'clean',
        ]);

        UserRecommendation::query()->create([
            'sender_id' => $sender->id,
            'recipient_id' => $user->id,
            'book_id' => $book->id,
            'message' => 'You should read this.',
        ]);

        UserBookStatus::query()->create([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'status' => 'queue',
            'order' => 0,
        ]);

        ListeningEvent::query()->create([
            'id' => 'evt-1',
            'user_id' => $user->id,
            'book_id' => $book->id,
            'event_type' => 'SESSION_END',
            'timestamp_ms' => now()->getTimestampMs(),
            'position_ms' => 800000,
            'metadata' => ['progress_percentage' => 80],
            'device_id' => 'device-1',
            'timezone' => 'UTC',
            'sync_status' => 'synced',
            'created_at' => now()->getTimestampMs(),
            'synced_at' => now()->getTimestampMs(),
        ]);

        $activity = $service->getUserActivityData((string) $user->id);

        $this->assertSame('Habit Bronze', $activity['badges_by_category']['habit'][0]['name']);
        $this->assertTrue($activity['badges_by_category']['habit'][0]['is_earned']);
        $this->assertSame('Deep Work', $activity['progress'][0]['book_title']);
        $this->assertSame(80.0, $activity['progress'][0]['percentage']);
        $this->assertSame('In Progress', $activity['statuses'][0]['status']);
        $this->assertSame('Great book', $activity['reviews'][0]['comment']);
        $this->assertSame('Recommender', $activity['recommendations'][0]['sender_name']);
        $this->assertSame('You should read this.', $activity['recommendations'][0]['message']);
        $this->assertCount(1, $activity['tips']);
    }

    #[Test]
    public function downloadOnlyBooksAreMarkedDownloadedNotInProgress(): void
    {
        // Regression: a book that was only ever downloaded (never played) must not show up
        // as "In Progress" just because DOWNLOAD_COMPLETE happens to be its latest event.
        $service = new UserActivityService();
        $user = User::factory()->create();
        $book = Book::factory()->create(['title' => 'Never Started', 'duration' => 1000]);

        ListeningEvent::query()->create([
            'id' => 'evt-download',
            'user_id' => $user->id,
            'book_id' => $book->id,
            'event_type' => 'DOWNLOAD_COMPLETE',
            'timestamp_ms' => now()->getTimestampMs(),
            'position_ms' => 0,
            'metadata' => [],
            'device_id' => 'device-1',
            'timezone' => 'UTC',
            'sync_status' => 'synced',
            'created_at' => now()->getTimestampMs(),
            'synced_at' => now()->getTimestampMs(),
        ]);

        $activity = $service->getUserActivityData((string) $user->id);

        $this->assertSame('Downloaded', $activity['progress'][0]['status']);
        $this->assertSame(0.0, $activity['progress'][0]['percentage']);
    }

    #[Test]
    public function fallsBackToEventMetadataTitleWhenBookNoLongerResolves(): void
    {
        // A phantom book_id (client sent an id that was never matched to a real catalog book)
        // must not display as "Unknown Book" when the event carries a usable title.
        $service = new UserActivityService();
        $user = User::factory()->create();

        ListeningEvent::query()->create([
            'id' => 'evt-phantom',
            'user_id' => $user->id,
            'book_id' => 999999999,
            'event_type' => 'SESSION_END',
            'timestamp_ms' => now()->getTimestampMs(),
            'position_ms' => 1000,
            'metadata' => ['fallbackTitle' => 'A Book Not In The Catalog'],
            'device_id' => 'device-1',
            'timezone' => 'UTC',
            'sync_status' => 'synced',
            'created_at' => now()->getTimestampMs(),
            'synced_at' => now()->getTimestampMs(),
        ]);

        $activity = $service->getUserActivityData((string) $user->id);

        $this->assertSame('A Book Not In The Catalog', $activity['progress'][0]['book_title']);
    }

    #[Test]
    public function usesMaterializedPositionOverAStaleZeroPositionLatestEvent(): void
    {
        // Regression: a CHAPTER_CHANGE(position=0) fired moments after BOOK_FINISH (an app-
        // internal state reset) was previously the "latest event" used for progress, hiding a
        // real completion behind a stale 0%. The materialized book_positions row must win.
        $service = new UserActivityService();
        $user = User::factory()->create();
        $book = Book::factory()->create(['title' => 'Actually Finished', 'duration' => 1000]);

        ListeningEvent::query()->create([
            'id' => 'evt-finish',
            'user_id' => $user->id,
            'book_id' => $book->id,
            'event_type' => 'BOOK_FINISH',
            'timestamp_ms' => now()->subSecond()->getTimestampMs(),
            'position_ms' => 1000000,
            'metadata' => [],
            'device_id' => 'device-1',
            'timezone' => 'UTC',
            'sync_status' => 'synced',
            'created_at' => now()->subSecond()->getTimestampMs(),
            'synced_at' => now()->subSecond()->getTimestampMs(),
        ]);

        ListeningEvent::query()->create([
            'id' => 'evt-chapter-reset',
            'user_id' => $user->id,
            'book_id' => $book->id,
            'event_type' => 'CHAPTER_CHANGE',
            'timestamp_ms' => now()->getTimestampMs(),
            'position_ms' => 0,
            'metadata' => [],
            'device_id' => 'device-1',
            'timezone' => 'UTC',
            'sync_status' => 'synced',
            'created_at' => now()->getTimestampMs(),
            'synced_at' => now()->getTimestampMs(),
        ]);

        \App\Models\BookPosition::query()->create([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'device_id' => 'device-1',
            'position_ms' => 1000000,
            'progress_percentage' => 100,
            'completed' => true,
            'last_event_timestamp_ms' => now()->subSecond()->valueOf(),
            'last_event_id' => 'evt-finish',
        ]);

        $activity = $service->getUserActivityData((string) $user->id);

        $this->assertSame('Finished', $activity['progress'][0]['status']);
        $this->assertSame(100.0, $activity['progress'][0]['percentage']);
    }

    #[Test]
    public function doesNotTrustAPhantomBookIdsCompletedFlag(): void
    {
        // Regression: a phantom book_id (never matched to a real catalog book) had its
        // completed flag set true after only ~27% of a plausible duration — its title
        // resolved correctly via fallbackTitle, so it displayed as "Finished" under a
        // legitimate-looking title despite there being no real book to sanity-check the
        // completion against.
        $service = new UserActivityService();
        $user = User::factory()->create();

        ListeningEvent::query()->create([
            'id' => 'evt-phantom-complete',
            'user_id' => $user->id,
            'book_id' => 999999996,
            'event_type' => 'SESSION_END',
            'timestamp_ms' => now()->getTimestampMs(),
            'position_ms' => 1000000,
            'metadata' => ['fallbackTitle' => 'Some Phantom Book'],
            'device_id' => 'device-1',
            'timezone' => 'UTC',
            'sync_status' => 'synced',
            'created_at' => now()->getTimestampMs(),
            'synced_at' => now()->getTimestampMs(),
        ]);

        \App\Models\BookPosition::query()->create([
            'user_id' => $user->id,
            'book_id' => 999999996,
            'device_id' => 'device-1',
            'position_ms' => 1000000,
            'progress_percentage' => 100,
            'completed' => true,
            'last_event_timestamp_ms' => now()->valueOf(),
            'last_event_id' => 'evt-phantom-complete',
        ]);

        $activity = $service->getUserActivityData((string) $user->id);

        $this->assertSame('Some Phantom Book', $activity['progress'][0]['book_title']);
        $this->assertNotSame('Finished', $activity['progress'][0]['status']);
    }

    #[Test]
    public function findsBookFinishEventForAPhantomIdEvenWhenItIsNotTheLatestEvent(): void
    {
        // Regression: real data — a phantom book_id's genuine BOOK_FINISH event was followed
        // moments later by a CHAPTER_CHANGE(position=0) app-internal reset, which then became
        // "the latest event" and hid a real completion behind a stale 0%/"In Progress".
        $service = new UserActivityService();
        $user = User::factory()->create();

        ListeningEvent::query()->create([
            'id' => 'evt-phantom-finish',
            'user_id' => $user->id,
            'book_id' => 999999995,
            'event_type' => 'BOOK_FINISH',
            'timestamp_ms' => now()->subMinute()->getTimestampMs(),
            'position_ms' => 40877824,
            'metadata' => ['fallbackTitle' => 'A Finished Phantom Book'],
            'device_id' => 'device-1',
            'timezone' => 'UTC',
            'sync_status' => 'synced',
            'created_at' => now()->subMinute()->getTimestampMs(),
            'synced_at' => now()->subMinute()->getTimestampMs(),
        ]);

        ListeningEvent::query()->create([
            'id' => 'evt-phantom-chapter-reset',
            'user_id' => $user->id,
            'book_id' => 999999995,
            'event_type' => 'CHAPTER_CHANGE',
            'timestamp_ms' => now()->getTimestampMs(),
            'position_ms' => 0,
            'metadata' => ['fallbackTitle' => 'A Finished Phantom Book'],
            'device_id' => 'device-1',
            'timezone' => 'UTC',
            'sync_status' => 'synced',
            'created_at' => now()->getTimestampMs(),
            'synced_at' => now()->getTimestampMs(),
        ]);

        $activity = $service->getUserActivityData((string) $user->id);

        $this->assertSame('Finished', $activity['progress'][0]['status']);
    }
}
