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
            'event_type' => 'BOOK_PROGRESS',
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
}
