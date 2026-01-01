<?php

namespace Tests\Feature;

use App\Contracts\DocumentStatsServiceInterface;
use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;

class ReadingStatsApiControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $mock = Mockery::mock(DocumentStatsServiceInterface::class);

        $mock->shouldReceive('recordReadingSession')->andReturnUsing(function (string $userId, string $bookId, array $data) {
            return array_merge([
                'id' => 'sess-1',
                'user_id' => $userId,
                'book_id' => $bookId,
            ], $data);
        });
        $mock->shouldReceive('getDailyStats')->andReturn([
            ['date' => '2025-08-01', 'duration_seconds' => 3600, 'sessions' => 2, 'books' => 1],
        ]);
        $mock->shouldReceive('getBookStats')->andReturn([
            'total_duration_seconds' => 7200,
            'sessions' => 4,
            'first_started_at' => '2025-08-01T10:00:00Z',
            'last_ended_at' => '2025-08-02T12:00:00Z',
        ]);
        $mock->shouldReceive('getUserStats')->andReturn([
            'total_duration_seconds' => 18000,
            'sessions' => 10,
            'active_days' => 5,
            'streak_current' => 3,
            'streak_longest' => 4,
        ]);
        $mock->shouldReceive('getStreaks')->andReturn([
            'current' => 3,
            'longest' => 4,
            'last_active_date' => '2025-08-02',
        ]);

        $this->app->instance(DocumentStatsServiceInterface::class, $mock);

        // Also mock DocumentStoreServiceInterface for auth provider to avoid hitting DB
        $storeMock = Mockery::mock(DocumentStoreServiceInterface::class);
        $storeMock->shouldReceive('getUserById')->andReturnUsing(function ($id) {
            return ['id' => (string) $id, 'name' => 'Test User', 'email' => 'test' . $id . '@example.com'];
        });
        $this->app->instance(DocumentStoreServiceInterface::class, $storeMock);
        $this->withoutMiddleware();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_records_a_reading_session()
    {
        $userId = 100;
        Auth::loginUsingId($userId);
        $bookId = 'book-abc';

        $payload = [
            'started_at' => '2025-08-02T10:00:00Z',
            'ended_at' => '2025-08-02T11:00:00Z',
            'duration_seconds' => 3600,
            'device' => 'android',
        ];

        $resp = $this->postJson("/api/v1/books/{$bookId}/reading-stats/sessions", $payload);
        $resp->assertStatus(201)
            ->assertJsonPath('session.user_id', (string) $userId)
            ->assertJsonPath('session.book_id', $bookId)
            ->assertJsonPath('session.duration_seconds', 3600);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function record_session_validates_input()
    {
        $userId = 101;
        Auth::loginUsingId($userId);
        $bookId = 'book-xyz';

        $payload = [
            'ended_at' => 'not-a-date',
            'duration_seconds' => -5,
        ];

        $resp = $this->postJson("/api/v1/books/{$bookId}/reading-stats/sessions", $payload);
        $resp->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_gets_daily_stats()
    {
        $userId = 102;
        Auth::loginUsingId($userId);

        $resp = $this->getJson('/api/v1/reading-stats/daily?from=2025-08-01&to=2025-08-02');
        $resp->assertOk()
            ->assertJsonStructure(['data'])
            ->assertJsonPath('data.0.sessions', 2);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_gets_book_stats()
    {
        $userId = 103;
        Auth::loginUsingId($userId);
        $bookId = 'book-123';

        $resp = $this->getJson("/api/v1/books/{$bookId}/reading-stats");
        $resp->assertOk()
            ->assertJsonPath('data.sessions', 4)
            ->assertJsonPath('data.total_duration_seconds', 7200);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_gets_user_stats()
    {
        $userId = 104;
        Auth::loginUsingId($userId);

        $resp = $this->getJson('/api/v1/reading-stats/user');
        $resp->assertOk()
            ->assertJsonPath('data.sessions', 10)
            ->assertJsonPath('data.active_days', 5);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_gets_streaks()
    {
        $userId = 105;
        Auth::loginUsingId($userId);

        $resp = $this->getJson('/api/v1/reading-stats/streaks');
        $resp->assertOk()
            ->assertJsonPath('data.current', 3)
            ->assertJsonPath('data.longest', 4);
    }
}
