<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\BookPosition;
use App\Models\ListeningEvent;
use Illuminate\Support\Str;

class StatisticsStreakTest extends ApiTestCase
{
    private function createSessionEndEvent(string $listeningDate, ?int $bookId = null): ListeningEvent
    {
        $timestampMs = \Carbon\Carbon::parse($listeningDate)->setTime(12, 0)->getTimestampMs();

        return ListeningEvent::create([
            'id'           => (string) Str::uuid(),
            'user_id'      => $this->user->id,
            'book_id'      => $bookId ?? Book::factory()->create()->id,
            'event_type'   => 'SESSION_END',
            'timestamp_ms' => $timestampMs,
            'position_ms'  => 0,
            'metadata'     => ['sessionDurationMs' => 600000, 'adjustedDurationMs' => 600000, 'playbackSpeed' => 1.0],
            'device_id'    => 'streak-device',
            'timezone'     => 'UTC',
            'sync_status'  => 'SYNCED',
            'created_at'   => $timestampMs,
            'synced_at'    => $timestampMs,
        ]);
    }

    public function test_streak_counts_consecutive_days_ending_yesterday_with_no_activity_today(): void
    {
        // Regression test: a user who listened on 5 consecutive days but hasn't opened the
        // app yet today should still see their streak, not 0. The old implementation required
        // activity on the literal current day or it returned 0 immediately.
        for ($i = 5; $i >= 1; $i--) {
            $this->createSessionEndEvent(now()->subDays($i)->toDateString());
        }

        $response = $this->withHeader('X-Device-ID', 'streak-device')
            ->getJson('/api/v1/statistics/overview');

        $response->assertOk();
        $this->assertSame(5, $response->json('current_streak'));
    }

    public function test_streak_is_zero_when_most_recent_activity_is_more_than_one_day_old(): void
    {
        $this->createSessionEndEvent(now()->subDays(3)->toDateString());

        $response = $this->withHeader('X-Device-ID', 'streak-device')
            ->getJson('/api/v1/statistics/overview');

        $response->assertOk();
        $this->assertSame(0, $response->json('current_streak'));
    }

    public function test_streak_counts_activity_through_today_when_present(): void
    {
        $this->createSessionEndEvent(now()->subDays(2)->toDateString());
        $this->createSessionEndEvent(now()->subDays(1)->toDateString());
        $this->createSessionEndEvent(now()->toDateString());

        $response = $this->withHeader('X-Device-ID', 'streak-device')
            ->getJson('/api/v1/statistics/overview');

        $response->assertOk();
        $this->assertSame(3, $response->json('current_streak'));
    }

    public function test_streak_is_zero_with_no_listening_events(): void
    {
        $response = $this->withHeader('X-Device-ID', 'streak-device')
            ->getJson('/api/v1/statistics/overview');

        $response->assertOk();
        $this->assertSame(0, $response->json('current_streak'));
    }

    public function test_longest_streak_finds_a_past_run_longer_than_the_current_one(): void
    {
        // Regression test: Carbon 3's diffInDays() returns a signed float ($this->diffInDays($other)
        // is $other - $this), so comparing the ascending-sorted date pairs against the literal
        // int 1 (instead of the absolute day count) never matched - every date "broke" the streak
        // and longest_streak was always 1, no matter how many consecutive days of real activity
        // existed. A past 4-day run followed by a shorter 2-day current run reproduces it.
        foreach ([10, 9, 8, 7] as $daysAgo) {
            $this->createSessionEndEvent(now()->subDays($daysAgo)->toDateString());
        }
        $this->createSessionEndEvent(now()->subDays(1)->toDateString());
        $this->createSessionEndEvent(now()->toDateString());

        $response = $this->withHeader('X-Device-ID', 'streak-device')
            ->getJson('/api/v1/statistics/overview');

        $response->assertOk();
        $this->assertSame(2, $response->json('current_streak'));
        $this->assertSame(4, $response->json('longest_streak'));
    }

    public function test_books_finished_counts_completions_from_modern_event_sourced_path(): void
    {
        // Regression test: the modern client finish path (BOOK_FINISH -> PositionMaterializer)
        // writes to book_positions, not book_progress/user_book_status. The overview endpoint
        // must count these or real finishes go missing from the user's stats.
        $book = Book::factory()->create(['duration' => 7_200]);

        BookPosition::create([
            'user_id'                 => $this->user->id,
            'book_id'                 => $book->id,
            'device_id'               => 'streak-device',
            'position_ms'             => 7_200_000,
            'progress_percentage'     => 100,
            'completed'               => true,
            'last_event_timestamp_ms' => now()->getTimestampMs(),
            'last_event_id'           => (string) Str::uuid(),
        ]);

        $response = $this->withHeader('X-Device-ID', 'streak-device')
            ->getJson('/api/v1/statistics/overview');

        $response->assertOk();
        $this->assertSame(1, $response->json('books_finished'));
    }
}
