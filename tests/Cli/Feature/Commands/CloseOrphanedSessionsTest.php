<?php

declare(strict_types=1);

namespace Tests\Cli\Feature\Commands;

use App\Models\Book;
use App\Models\ListeningEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CloseOrphanedSessionsTest extends TestCase
{
    use RefreshDatabase;

    private function createSessionStart(User $user, Book $book, int $timestampMs, string $deviceId = 'real-device'): ListeningEvent
    {
        return ListeningEvent::create([
            'id'           => (string) Str::uuid(),
            'user_id'      => $user->id,
            'book_id'      => $book->id,
            'event_type'   => 'SESSION_START',
            'timestamp_ms' => $timestampMs,
            'position_ms'  => 0,
            'metadata'     => [],
            'device_id'    => $deviceId,
            'timezone'     => 'UTC',
            'sync_status'  => 'SYNCED',
            'created_at'   => $timestampMs,
            'synced_at'    => $timestampMs,
        ]);
    }

    #[Test]
    public function it_synthesizes_one_end_event_for_an_orphaned_session(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();
        $start = now()->subHours(6)->getTimestampMs();

        $this->createSessionStart($user, $book, $start);

        Artisan::call('sessions:close-orphaned');

        $ends = ListeningEvent::where('user_id', $user->id)
            ->where('book_id', $book->id)
            ->where('event_type', 'SESSION_END')
            ->get();

        $this->assertCount(1, $ends);
        $this->assertSame('server-synthesized', $ends->first()->device_id);
    }

    #[Test]
    public function it_does_not_create_a_duplicate_end_event_on_a_second_run(): void
    {
        // Regression test: the command used to only recognize a matching SESSION_END if its
        // device_id equalled the original SESSION_START's device_id. Since synthesized events
        // are written with device_id = 'server-synthesized', that check could never match its
        // own prior output - every scheduled rerun treated the orphan as still open and
        // synthesized another duplicate SESSION_END, without bound (observed in production as
        // a single orphaned session accumulating 100+ duplicate end events).
        $user = User::factory()->create();
        $book = Book::factory()->create();
        $start = now()->subHours(6)->getTimestampMs();

        $this->createSessionStart($user, $book, $start);

        Artisan::call('sessions:close-orphaned');
        Artisan::call('sessions:close-orphaned');
        Artisan::call('sessions:close-orphaned');

        $ends = ListeningEvent::where('user_id', $user->id)
            ->where('book_id', $book->id)
            ->where('event_type', 'SESSION_END')
            ->get();

        $this->assertCount(1, $ends);
    }

    #[Test]
    public function synthesized_metadata_uses_the_same_camel_case_keys_real_clients_send(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();
        $start = now()->subHours(2)->getTimestampMs();

        $this->createSessionStart($user, $book, $start);

        Artisan::call('sessions:close-orphaned');

        $end = ListeningEvent::where('user_id', $user->id)
            ->where('book_id', $book->id)
            ->where('event_type', 'SESSION_END')
            ->firstOrFail();

        $this->assertArrayHasKey('sessionDurationMs', $end->metadata);
        $this->assertArrayHasKey('adjustedDurationMs', $end->metadata);
        $this->assertGreaterThan(0, $end->metadata['sessionDurationMs']);
    }

    #[Test]
    public function it_does_not_touch_sessions_with_a_real_matching_end(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();
        $start = now()->subHours(2)->getTimestampMs();

        $this->createSessionStart($user, $book, $start, 'real-device');
        ListeningEvent::create([
            'id'           => (string) Str::uuid(),
            'user_id'      => $user->id,
            'book_id'      => $book->id,
            'event_type'   => 'SESSION_END',
            'timestamp_ms' => $start + 600_000,
            'position_ms'  => 0,
            'metadata'     => ['sessionDurationMs' => 600000, 'adjustedDurationMs' => 600000],
            'device_id'    => 'real-device',
            'timezone'     => 'UTC',
            'sync_status'  => 'SYNCED',
            'created_at'   => $start + 600_000,
            'synced_at'    => $start + 600_000,
        ]);

        Artisan::call('sessions:close-orphaned');

        $ends = ListeningEvent::where('user_id', $user->id)
            ->where('book_id', $book->id)
            ->where('event_type', 'SESSION_END')
            ->get();

        $this->assertCount(1, $ends);
        $this->assertSame('real-device', $ends->first()->device_id);
    }
}
