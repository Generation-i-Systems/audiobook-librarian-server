<?php

namespace Tests\Api\Feature;

use App\Models\Book;
use App\Models\BookProgress;
use App\Models\ListeningStatistic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class StatisticsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test user without running the problematic seeders
        $this->user = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        // Create a Sanctum token for API authentication
        $this->token = $this->user->createToken('test-token')->plainTextToken;

        // Use Sanctum::actingAs for API tests
        Sanctum::actingAs($this->user);
    }

    public function test_record_session_creates_listening_statistic()
    {
        $book = Book::factory()->create();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->postJson('/api/v1/statistics/sessions', [
                'book_id' => $book->id,
                'device_id' => 'test-device',
                'seconds_listened' => 1800, // 30 minutes
                'start_position_seconds' => 0,
                'end_position_seconds' => 1800,
                'session_type' => 'listening',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Listening session recorded successfully',
                'data' => [
                    'book_id' => $book->id,
                    'device_id' => 'test-device',
                    'seconds_listened' => 1800,
                    'session_type' => 'listening',
                ],
            ]);

        $this->assertDatabaseHas('listening_statistics', [
            'book_id' => $book->id,
            'device_id' => 'test-device',
            'seconds_listened' => 1800,
            'session_type' => 'listening',
        ]);
    }

    public function test_record_session_validates_input()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->postJson('/api/v1/statistics/sessions', [
                'book_id' => 999, // Non-existent book
                'device_id' => '', // Empty device_id
                'seconds_listened' => -1, // Invalid negative value
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'error',
                'message',
                'errors' => [
                    'book_id',
                    'device_id',
                    'seconds_listened',
                ],
            ]);
    }

    public function test_get_daily_stats_returns_correct_data()
    {
        $book1 = Book::factory()->create();
        $book2 = Book::factory()->create();
        $today = now()->toDateString();

        // Create listening statistics directly in database for more reliable testing
        ListeningStatistic::create([
            'user_id' => $this->user->id,
            'book_id' => $book1->id,
            'device_id' => 'test-device',
            'seconds_listened' => 1800,
            'start_position_seconds' => 0,
            'end_position_seconds' => 1800,
            'session_type' => 'listening',
            'listening_date' => $today,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ListeningStatistic::create([
            'user_id' => $this->user->id,
            'book_id' => $book2->id,
            'device_id' => 'test-device',
            'seconds_listened' => 3600,
            'start_position_seconds' => 1800,
            'end_position_seconds' => 5400,
            'session_type' => 'listening',
            'listening_date' => $today,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->getJson("/api/v1/statistics/legacy-daily?device_id=test-device&date={$today}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'date' => $today,
                    'total_seconds' => 5400, // 1800 + 3600
                    'books_listened' => 2,
                    'session_count' => 2,
                ],
            ]);
    }

    public function test_overview_aggregates_listening_stats_across_multiple_devices_for_authenticated_user()
    {
        $book1 = Book::factory()->create();
        $book2 = Book::factory()->create();

        ListeningStatistic::create([
            'user_id' => $this->user->id,
            'book_id' => $book1->id,
            'device_id' => 'device-a',
            'seconds_listened' => 600,
            'session_type' => 'listening',
            'listening_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ListeningStatistic::create([
            'user_id' => $this->user->id,
            'book_id' => $book2->id,
            'device_id' => 'device-b',
            'seconds_listened' => 900,
            'session_type' => 'listening',
            'listening_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Device-ID' => 'device-a',
        ])->getJson('/api/v1/statistics/overview?period=all_time');

        $response->assertOk()
            ->assertJsonPath('total_listening_time_ms', (600 + 900) * 1000)
            ->assertJsonPath('books_started', 2)
            ->assertJsonPath('listening_minutes.day', 25)
            ->assertJsonPath('listening_minutes.week', 25)
            ->assertJsonPath('listening_minutes.month', 25);
    }

    public function test_overview_uses_completed_progress_for_books_finished_instead_of_completed_sessions(): void
    {
        $book = Book::factory()->create();

        ListeningStatistic::create([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'device_id' => 'device-a',
            'seconds_listened' => 1200,
            'session_type' => 'listening',
            'listening_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        BookProgress::create([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'device_id' => 'device-a',
            'current_position_seconds' => 7200,
            'total_duration_seconds' => 7200,
            'progress_percentage' => 100,
            'completed' => true,
            'completed_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Device-ID' => 'device-a',
        ])->getJson('/api/v1/statistics/overview?period=all_time');

        $response->assertOk()
            ->assertJsonPath('books_started', 1)
            ->assertJsonPath('books_finished', 1);
    }

    public function test_timeline_returns_detail_for_specific_day_with_books(): void
    {
        $book1 = Book::factory()->create(['title' => 'Timeline One']);
        $book2 = Book::factory()->create(['title' => 'Timeline Two']);
        $date = now()->subDays(2)->toDateString();

        \App\Models\Device::create([
            'device_id' => 'device-a',
            'user_id' => $this->user->id,
            'name' => 'Device A',
            'sync_enabled' => true,
        ]);

        \App\Models\Device::create([
            'device_id' => 'device-b',
            'user_id' => $this->user->id,
            'name' => 'Device B',
            'sync_enabled' => true,
        ]);

        ListeningStatistic::create([
            'user_id' => $this->user->id,
            'book_id' => $book1->id,
            'device_id' => 'device-a',
            'seconds_listened' => 900,
            'session_type' => 'listening',
            'listening_date' => $date,
        ]);

        ListeningStatistic::create([
            'user_id' => $this->user->id,
            'book_id' => $book2->id,
            'device_id' => 'device-b',
            'seconds_listened' => 1500,
            'session_type' => 'listening',
            'listening_date' => $date,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Device-ID' => 'device-a',
            'X-Acting-As-Test' => '1',
        ])->getJson('/api/v1/statistics/timeline?from=' . $date . '&to=' . $date . '&detail_period_type=day&detail_period=' . $date);

        $response->assertOk()
            ->assertJsonPath('summary.total_listening_time_ms', 2400000)
            ->assertJsonPath('detail.total_seconds', 2400)
            ->assertJsonPath('detail.total_minutes', 40)
            ->assertJsonPath('detail.books_count', 2)
            ->assertJsonPath('detail.books.0.title', 'Timeline Two')
            ->assertJsonPath('detail.books.0.total_seconds', 1500)
            ->assertJsonPath('detail.books.1.title', 'Timeline One')
            ->assertJsonPath('detail.books.1.total_seconds', 900);
    }

    public function test_timeline_supports_month_aggregation_for_custom_date_range(): void
    {
        $book = Book::factory()->create();

        ListeningStatistic::create([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'device_id' => 'device-a',
            'seconds_listened' => 1800,
            'session_type' => 'listening',
            'listening_date' => '2026-01-15',
        ]);

        ListeningStatistic::create([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'device_id' => 'device-a',
            'seconds_listened' => 2400,
            'session_type' => 'listening',
            'listening_date' => '2026-02-10',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Device-ID' => 'device-a',
        ])->getJson('/api/v1/statistics/timeline?from=2026-01-01&to=2026-02-28&group_by=month');

        $response->assertOk()
            ->assertJsonPath('summary.group_by', 'month')
            ->assertJsonCount(2, 'bars')
            ->assertJsonPath('bars.0.period', '2026-01')
            ->assertJsonPath('bars.0.listening_time_ms', 1800000)
            ->assertJsonPath('bars.1.period', '2026-02')
            ->assertJsonPath('bars.1.listening_time_ms', 2400000);
    }

    public function test_timeline_supports_weekend_only_filtering(): void
    {
        $book = Book::factory()->create();

        ListeningStatistic::create([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'device_id' => 'device-a',
            'seconds_listened' => 1200,
            'session_type' => 'listening',
            'listening_date' => '2026-04-03',
        ]);

        ListeningStatistic::create([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'device_id' => 'device-a',
            'seconds_listened' => 1800,
            'session_type' => 'listening',
            'listening_date' => '2026-04-04',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Device-ID' => 'device-a',
        ])->getJson('/api/v1/statistics/timeline?from=2026-04-01&to=2026-04-05&day_filter=weekend');

        $response->assertOk()
            ->assertJsonPath('summary.day_filter', 'weekend')
            ->assertJsonCount(1, 'bars')
            ->assertJsonPath('bars.0.period', '2026-04-04')
            ->assertJsonPath('bars.0.listening_time_ms', 1800000);
    }

    public function test_timeline_supports_specific_weekday_filtering(): void
    {
        $book = Book::factory()->create();

        ListeningStatistic::create([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'device_id' => 'device-a',
            'seconds_listened' => 900,
            'session_type' => 'listening',
            'listening_date' => '2026-04-02',
        ]);

        ListeningStatistic::create([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'device_id' => 'device-a',
            'seconds_listened' => 600,
            'session_type' => 'listening',
            'listening_date' => '2026-04-03',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Device-ID' => 'device-a',
        ])->getJson('/api/v1/statistics/timeline?from=2026-04-01&to=2026-04-07&weekdays[]=3');

        $response->assertOk()
            ->assertJsonPath('summary.weekdays.0', 3)
            ->assertJsonCount(1, 'bars')
            ->assertJsonPath('bars.0.period', '2026-04-02')
            ->assertJsonPath('bars.0.listening_time_ms', 900000);
    }

    public function test_get_weekly_stats_returns_aggregated_data()
    {
        $book = Book::factory()->create();
        $startOfWeek = now()->startOfWeek();

        // Create statistics for different days of the week
        for ($i = 0; $i < 5; $i++) {
            ListeningStatistic::create([
                'book_id' => $book->id,
                'device_id' => 'test-device',
                'listening_date' => $startOfWeek->copy()->addDays($i),
                'seconds_listened' => 1800, // 30 minutes each day
                'session_start' => $startOfWeek->copy()->addDays($i)->addHours(10),
                'session_end' => $startOfWeek->copy()->addDays($i)->addHours(10)->addMinutes(30),
            ]);
        }

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->getJson("/api/v1/statistics/weekly?device_id=test-device&start_date={$startOfWeek->toDateString()}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'week_start' => $startOfWeek->toDateString(),
                    'week_end' => $startOfWeek->copy()->endOfWeek()->toDateString(),
                    'total_seconds' => 9000, // 5 * 1800
                    'total_books' => 1,
                ],
            ])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'week_start',
                    'week_end',
                    'total_seconds',
                    'total_books',
                    'formatted_total_duration',
                    'daily_breakdown',
                ],
            ]);
    }

    public function test_get_book_stats_returns_book_specific_data()
    {
        $book = Book::factory()->create(['title' => 'Test Book']);

        // Create multiple sessions for the same book
        ListeningStatistic::create([
            'book_id' => $book->id,
            'device_id' => 'test-device',
            'listening_date' => now()->toDateString(),
            'seconds_listened' => 1800,
        ]);

        ListeningStatistic::create([
            'book_id' => $book->id,
            'device_id' => 'test-device',
            'listening_date' => now()->subDay()->toDateString(),
            'seconds_listened' => 2400,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->getJson("/api/v1/books/{$book->id}/statistics?device_id=test-device");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'book_id' => $book->id,
                    'total_seconds' => 4200, // 1800 + 2400
                    'session_count' => 2,
                    'book' => [
                        'id' => $book->id,
                        'title' => 'Test Book',
                    ],
                ],
            ]);
    }

    public function test_get_listening_trends_returns_trend_data()
    {
        $book = Book::factory()->create();
        $today = now();

        // Create data for the last 7 days
        for ($i = 0; $i < 7; $i++) {
            $date = $today->copy()->subDays($i);
            ListeningStatistic::create([
                'book_id' => $book->id,
                'device_id' => 'test-device',
                'listening_date' => $date->toDateString(),
                'seconds_listened' => ($i + 1) * 600, // Increasing listening time
                'session_start' => $date->addHours(10),
                'session_end' => $date->addHours(10)->addMinutes(($i + 1) * 10),
            ]);
        }

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->getJson('/api/v1/statistics/trends?device_id=test-device&period=week');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'period',
                    'start_date',
                    'end_date',
                    'total_seconds',
                    'total_books',
                    'formatted_total_duration',
                    'average_daily_seconds',
                    'trends' => [
                        '*' => [
                            'date',
                            'total_seconds',
                            'books_listened',
                            'session_count',
                            'avg_session_duration',
                            'formatted_duration',
                            'formatted_avg_session',
                        ],
                    ],
                ],
            ]);
    }

    public function test_get_top_books_returns_most_listened_books()
    {
        $book1 = Book::factory()->create(['title' => 'Popular Book']);
        $book2 = Book::factory()->create(['title' => 'Less Popular Book']);
        $book3 = Book::factory()->create(['title' => 'Rarely Listened Book']);

        // Create different amounts of listening time for each book
        ListeningStatistic::create([
            'book_id' => $book1->id,
            'device_id' => 'test-device',
            'listening_date' => now()->toDateString(),
            'seconds_listened' => 7200, // 2 hours
        ]);

        ListeningStatistic::create([
            'book_id' => $book2->id,
            'device_id' => 'test-device',
            'listening_date' => now()->toDateString(),
            'seconds_listened' => 3600, // 1 hour
        ]);

        ListeningStatistic::create([
            'book_id' => $book3->id,
            'device_id' => 'test-device',
            'listening_date' => now()->toDateString(),
            'seconds_listened' => 1800, // 30 minutes
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->getJson('/api/v1/statistics/top-books?device_id=test-device&limit=2');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonCount(2, 'data') // Should return only top 2 books
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'book_id',
                        'book' => [
                            'id',
                            'title',
                            'cover_image',
                        ],
                        'total_seconds',
                        'session_count',
                        'days_listened',
                        'formatted_duration',
                    ],
                ],
            ]);

        // Check that the most listened book is first
        $data = $response->json('data');
        $this->assertEquals($book1->id, $data[0]['book_id']);
        $this->assertEquals(7200, $data[0]['total_seconds']);
    }

    public function test_get_dashboard_stats_returns_comprehensive_data()
    {
        // Freeze to a Wednesday mid-month so startOfWeek()+1 (Tuesday) and
        // startOfMonth()+1 (2nd) never collide — avoids flakiness when the
        // test runs on the first Monday of a month.
        $this->travelTo(\Carbon\Carbon::parse('2024-06-12'));

        $book = Book::factory()->create();
        $today = now();
        $weekDate = now()->startOfWeek()->copy()->addDays(1);
        $monthDate = now()->startOfMonth()->copy()->addDays(1);

        // Create statistics for today, this week, and this month
        ListeningStatistic::create([
            'book_id' => $book->id,
            'device_id' => 'test-device',
            'listening_date' => $today->toDateString(),
            'seconds_listened' => 1800,
        ]);

        ListeningStatistic::create([
            'book_id' => $book->id,
            'device_id' => 'test-device',
            'listening_date' => $weekDate->toDateString(),
            'seconds_listened' => 3600,
        ]);

        ListeningStatistic::create([
            'book_id' => $book->id,
            'device_id' => 'test-device',
            'listening_date' => $monthDate->toDateString(),
            'seconds_listened' => 2400,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->getJson('/api/v1/statistics/dashboard?device_id=test-device');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonPath('data.listening_minutes.day', 30)
            ->assertJsonPath('data.listening_minutes.week', 90)
            ->assertJsonPath('data.listening_minutes.month', 130)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'today' => [
                        'total_seconds',
                        'books_listened',
                        'session_count',
                        'formatted_duration',
                    ],
                    'user_tracking' => [
                        'total_completed',
                        'completed_this_month',
                        'upcoming_goals',
                        'overdue_goals',
                    ],
                    'listening_overview' => [
                        'total_seconds',
                        'total_books',
                        'days_active',
                        'formatted_total_duration',
                    ],
                    'listening_minutes' => [
                        'day',
                        'week',
                        'month',
                    ],
                ],
            ]);
    }

    public function test_dashboard_uses_completed_progress_for_user_completion_tracking(): void
    {
        $book = Book::factory()->create();

        BookProgress::create([
            'user_id' => $this->user->id,
            'book_id' => $book->id,
            'device_id' => 'test-device',
            'current_position_seconds' => 7200,
            'total_duration_seconds' => 7200,
            'progress_percentage' => 100,
            'completed' => true,
            'completed_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Device-ID' => 'test-device',
        ])->getJson('/api/v1/statistics/dashboard?device_id=test-device');

        $response->assertOk()
            ->assertJsonPath('data.user_tracking.total_completed', 1)
            ->assertJsonPath('data.user_tracking.completed_this_month', 1);
    }

    public function test_get_reading_history_stats()
    {
        /** @var User $user */
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user, 'api');

        $book1 = Book::factory()->create();
        $book2 = Book::factory()->create();

        \App\Models\UserBookStatus::create([
            'user_id' => $user->id,
            'book_id' => $book1->id,
            'status' => 'completed',
            'order' => 0,
            'finished_at' => now()->startOfMonth()->subMonth(),
        ]);

        \App\Models\UserBookStatus::create([
            'user_id' => $user->id,
            'book_id' => $book2->id,
            'status' => 'completed',
            'order' => 0,
            'finished_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/statistics/reading-history', ['X-Acting-As-Test' => '1']);

        $response->assertStatus(200)
            ->assertJsonCount(2)
            ->assertJsonStructure([
                '*' => [
                    'period',
                    'count',
                ],
            ]);
    }

    public function test_reading_history_includes_completed_progress_without_user_book_status(): void
    {
        /** @var User $user */
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user, 'api');

        $book = Book::factory()->create();

        BookProgress::create([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'device_id' => 'test-device',
            'current_position_seconds' => 3600,
            'total_duration_seconds' => 3600,
            'progress_percentage' => 100,
            'completed' => true,
            'completed_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/statistics/reading-history', ['X-Acting-As-Test' => '1']);

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.count', 1);
    }

    public function test_session_type_validation_accepts_valid_types()
    {
        $book = Book::factory()->create();

        $validTypes = ['listening', 'completed', 'resumed', 'paused'];

        foreach ($validTypes as $type) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])
                ->postJson('/api/v1/statistics/sessions', [
                    'book_id' => $book->id,
                    'device_id' => 'test-device',
                    'seconds_listened' => 600,
                    'session_type' => $type,
                ]);

            $response->assertStatus(201);
        }

        $this->assertDatabaseCount('listening_statistics', 4);
    }

    public function test_session_with_metadata_stores_additional_data()
    {
        $book = Book::factory()->create();

        $metadata = [
            'playback_speed' => 1.25,
            'chapter' => 5,
            'app_version' => '2.1.0',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])
            ->postJson('/api/v1/statistics/sessions', [
                'book_id' => $book->id,
                'device_id' => 'test-device',
                'seconds_listened' => 1200,
                'metadata' => $metadata,
            ]);

        $response->assertStatus(201);

        $statistic = ListeningStatistic::where('book_id', $book->id)->first();
        $this->assertEquals($metadata, $statistic->metadata);
    }
}
