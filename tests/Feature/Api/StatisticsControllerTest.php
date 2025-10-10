<?php

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\ListeningStatistic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class StatisticsControllerTest extends TestCase
{
    // Not using RefreshDatabase to avoid SQLite issues

    protected $user;
    protected $token;
    protected static $migrationsRun = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$migrationsRun) {
            try {
                // Try to run migrations for the in-memory database
                $this->artisan('migrate')->execute();
            } catch (\Exception $e) {
                // If migrations fail, try migrate:fresh
                $this->artisan('migrate:fresh')->execute();
            }
            self::$migrationsRun = true;
        }

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

    protected function tearDown(): void
    {
        // Clean up created records but leave database structure
        if (isset($this->user)) {
            $this->user->tokens()->delete();
            $this->user->delete();
        }

        parent::tearDown();
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
        $book = Book::factory()->create();
        $today = now();

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
            'listening_date' => $today->subDays(2)->toDateString(),
            'seconds_listened' => 3600,
        ]);

        ListeningStatistic::create([
            'book_id' => $book->id,
            'device_id' => 'test-device',
            'listening_date' => $today->subWeeks(2)->toDateString(),
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
            ->assertJsonStructure([
                'success',
                'data' => [
                    'today' => [
                        'total_seconds',
                        'books_listened',
                        'session_count',
                        'formatted_duration',
                    ],
                    'this_week' => [
                        'total_seconds',
                        'books_listened',
                        'session_count',
                        'formatted_duration',
                    ],
                    'this_month' => [
                        'total_seconds',
                        'books_listened',
                        'session_count',
                        'formatted_duration',
                    ],
                    'all_time' => [
                        'total_seconds',
                        'books_listened',
                        'session_count',
                        'days_listened',
                        'first_day',
                        'last_day',
                        'formatted_duration',
                    ],
                ],
            ]);
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
