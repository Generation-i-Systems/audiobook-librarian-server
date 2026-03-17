<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Book;
use App\Models\ReadingSession;
use App\Models\User;
use App\Services\UserReadingStatsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserReadingStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function recordReadingSessionPersistsProvidedValues(): void
    {
        $service = new UserReadingStatsService();
        $user = User::factory()->create();
        $book = Book::factory()->create();

        $session = $service->recordReadingSession((string) $user->id, (string) $book->id, [
            'started_at' => '2026-03-16 10:00:00',
            'ended_at' => '2026-03-16 10:30:00',
            'duration_seconds' => 1800,
            'pages' => 25,
            'position_start' => 10,
            'position_end' => 50,
            'device' => 'ios',
        ]);

        $this->assertSame((string) $user->id, (string) $session['user_id']);
        $this->assertSame((string) $book->id, (string) $session['book_id']);
        $this->assertSame(1800, $session['duration_seconds']);
        $this->assertSame('ios', $session['device']);
    }

    #[Test]
    public function statsMethodsAggregateSessionsByDayBookAndUser(): void
    {
        CarbonImmutable::setTestNow('2026-03-17 12:00:00');

        $service = new UserReadingStatsService();
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $bookA = Book::factory()->create();
        $bookB = Book::factory()->create();

        ReadingSession::query()->create([
            'user_id' => $user->id,
            'book_id' => $bookA->id,
            'started_at' => '2026-03-15 09:00:00',
            'ended_at' => '2026-03-15 09:10:00',
            'duration_seconds' => 600,
        ]);

        ReadingSession::query()->create([
            'user_id' => $user->id,
            'book_id' => $bookA->id,
            'started_at' => '2026-03-16 09:00:00',
            'ended_at' => '2026-03-16 09:20:00',
            'duration_seconds' => 1200,
        ]);

        ReadingSession::query()->create([
            'user_id' => $user->id,
            'book_id' => $bookB->id,
            'started_at' => '2026-03-16 11:00:00',
            'ended_at' => '2026-03-16 11:15:00',
            'duration_seconds' => 900,
        ]);

        ReadingSession::query()->create([
            'user_id' => $user->id,
            'book_id' => $bookA->id,
            'started_at' => '2026-03-17 08:00:00',
            'ended_at' => '2026-03-17 08:30:00',
            'duration_seconds' => 1800,
        ]);

        ReadingSession::query()->create([
            'user_id' => $otherUser->id,
            'book_id' => $bookA->id,
            'started_at' => '2026-03-17 09:00:00',
            'ended_at' => '2026-03-17 09:05:00',
            'duration_seconds' => 300,
        ]);

        $daily = $service->getDailyStats((string) $user->id, '2026-03-15', '2026-03-17');
        $bookStats = $service->getBookStats((string) $user->id, (string) $bookA->id);
        $userStats = $service->getUserStats((string) $user->id);
        $streaks = $service->getStreaks((string) $user->id);

        $this->assertCount(3, $daily);
        $this->assertSame('2026-03-15', $daily[0]['date']);
        $this->assertSame(600, $daily[0]['duration_seconds']);
        $this->assertSame(2100, $daily[1]['duration_seconds']);
        $this->assertSame(2, $daily[1]['sessions']);
        $this->assertSame(2, $daily[1]['books']);

        $this->assertSame(3600, $bookStats['total_duration_seconds']);
        $this->assertSame(3, $bookStats['sessions']);
        $this->assertStringStartsWith('2026-03-15T09:00:00', (string) $bookStats['first_started_at']);
        $this->assertStringStartsWith('2026-03-17T08:30:00', (string) $bookStats['last_ended_at']);

        $this->assertSame(4500, $userStats['total_duration_seconds']);
        $this->assertSame(4, $userStats['sessions']);
        $this->assertSame(3, $userStats['active_days']);
        $this->assertSame(3, $userStats['streak_current']);
        $this->assertSame(3, $userStats['streak_longest']);

        $this->assertSame(3, $streaks['current']);
        $this->assertSame(3, $streaks['longest']);
        $this->assertSame('2026-03-17', $streaks['last_active_date']);

        CarbonImmutable::setTestNow();
    }
}
