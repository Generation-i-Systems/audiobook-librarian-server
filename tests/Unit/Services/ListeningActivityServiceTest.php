<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Book;
use App\Models\ListeningEvent;
use App\Models\User;
use App\Services\ListeningActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ListeningActivityServiceTest extends TestCase
{
    use RefreshDatabase;

    private function createSessionEndEvent(User $user, Book $book, int $timestampMs, int $secondsListened = 600): ListeningEvent
    {
        return ListeningEvent::create([
            'id'           => (string) Str::uuid(),
            'user_id'      => $user->id,
            'book_id'      => $book->id,
            'event_type'   => 'SESSION_END',
            'timestamp_ms' => $timestampMs,
            'position_ms'  => 0,
            'metadata'     => [
                'sessionDurationMs'  => $secondsListened * 1000,
                'adjustedDurationMs' => $secondsListened * 1000,
                'playbackSpeed'      => 1.0,
            ],
            'device_id'    => 'test-device',
            'timezone'     => 'UTC',
            'sync_status'  => 'SYNCED',
            'created_at'   => $timestampMs,
            'synced_at'    => $timestampMs,
        ]);
    }

    #[Test]
    public function getSessionsDeduplicatesRepeatedSyncsOfTheSameSession(): void
    {
        // Regression test: the client can sync the exact same logical session more than once
        // (e.g. retrying after a timed-out sync response), each time with a new UUID `id` since
        // that's the only field the server dedupes on at write time. Left unaddressed, this
        // inflates listening totals arbitrarily - observed in production as >24h of "listening"
        // reported on a single day for real users.
        $user = User::factory()->create();
        $book = Book::factory()->create();
        $timestampMs = now()->setTime(12, 0)->getTimestampMs();

        // Same user/book/device/timestamp, 50 times, each with a distinct id (as a real client
        // retry storm would produce) - this must collapse to one real session.
        for ($i = 0; $i < 50; $i++) {
            $this->createSessionEndEvent($user, $book, $timestampMs, 1800);
        }

        $service = app(ListeningActivityService::class);
        $sessions = $service->getSessions($user->id);

        $this->assertCount(1, $sessions);
        $this->assertSame(1800, $sessions->first()->seconds_listened);
    }

    #[Test]
    public function getSessionsKeepsGenuinelyDistinctSessionsForTheSameBook(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();
        $firstTimestampMs = now()->setTime(9, 0)->getTimestampMs();
        $secondTimestampMs = now()->setTime(20, 0)->getTimestampMs();

        $this->createSessionEndEvent($user, $book, $firstTimestampMs, 1200);
        $this->createSessionEndEvent($user, $book, $secondTimestampMs, 900);

        $service = app(ListeningActivityService::class);
        $sessions = $service->getSessions($user->id);

        $this->assertCount(2, $sessions);
        $this->assertSame(2100, $sessions->sum('seconds_listened'));
    }
}
