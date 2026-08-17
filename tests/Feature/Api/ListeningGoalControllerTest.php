<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookPosition;
use App\Models\BookProgress;
use App\Models\Device;
use App\Models\ListeningGoal;
use App\Models\ListeningStatistic;
use App\Models\Genre;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Models\UserBookStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListeningGoalControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_playlist_goal_progress_counts_listening_from_user_devices(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        Device::create([
            'device_id' => 'goal-device-a',
            'user_id' => $user->id,
            'name' => 'Goal Device A',
            'sync_enabled' => true,
        ]);

        Device::create([
            'device_id' => 'goal-device-b',
            'user_id' => $user->id,
            'name' => 'Goal Device B',
            'sync_enabled' => true,
        ]);

        $playlist = Playlist::create([
            'user_id' => $user->id,
            'name' => 'Road Trip',
            'sort_order' => 1,
        ]);

        $book = Book::factory()->create();

        UserBookStatus::create([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'playlist_id' => $playlist->id,
            'status' => 'queue',
            'order' => 1,
        ]);

        ListeningGoal::create([
            'user_id' => $user->id,
            'period_type' => 'week',
            'metric' => 'playlist_hours',
            'target_minutes' => 60,
            'playlist_id' => $playlist->id,
            'is_active' => true,
        ]);

        ListeningStatistic::create([
            'user_id' => $user->id,
            'device_id' => 'goal-device-a',
            'book_id' => $book->id,
            'listening_date' => now()->toDateString(),
            'seconds_listened' => 1800,
            'session_type' => 'listening',
        ]);

        ListeningStatistic::create([
            'user_id' => null,
            'device_id' => 'goal-device-b',
            'book_id' => $book->id,
            'listening_date' => now()->toDateString(),
            'seconds_listened' => 1800,
            'session_type' => 'listening',
        ]);

        $response = $this->getJson('/api/v1/goals/listening', ['X-Acting-As-Test' => '1']);

        $response->assertOk()
            ->assertJsonCount(1, 'goals')
            ->assertJsonPath('goals.0.metric', 'playlist_hours')
            ->assertJsonPath('goals.0.progress_minutes', 60)
            ->assertJsonPath('goals.0.progress_percent', 100);
    }

    public function test_genre_goal_ignores_soft_deleted_genres(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $genre = Genre::factory()->create(['name' => 'Deleted Goal Genre']);
        $book = Book::factory()->create();
        $book->genres()->attach($genre);

        ListeningGoal::create([
            'user_id' => $user->id,
            'period_type' => 'week',
            'metric' => 'genre_hours',
            'target_minutes' => 60,
            'genre_id' => $genre->getKey(),
            'is_active' => true,
        ]);

        ListeningStatistic::create([
            'user_id' => $user->id,
            'device_id' => 'deleted-genre-goal-device',
            'book_id' => $book->id,
            'listening_date' => now()->toDateString(),
            'seconds_listened' => 1800,
            'session_type' => 'listening',
        ]);

        $genre->delete();

        $response = $this->getJson('/api/v1/goals/listening', ['X-Acting-As-Test' => '1']);

        $response->assertOk()
            ->assertJsonCount(1, 'goals')
            ->assertJsonPath('goals.0.metric', 'genre_hours')
            ->assertJsonPath('goals.0.genre_name', null)
            ->assertJsonPath('goals.0.progress_minutes', 0)
            ->assertJsonPath('goals.0.progress_percent', 0);
    }

    public function test_series_goal_progress_counts_listening_from_series_books(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $series = Series::factory()->create(['name' => 'The Trilogy']);
        $inSeriesBook = Book::factory()->create();
        $inSeriesBook->series()->attach($series, ['series_number' => 1]);
        $otherBook = Book::factory()->create();

        ListeningGoal::create([
            'user_id' => $user->id,
            'period_type' => 'week',
            'metric' => 'series_hours',
            'target_minutes' => 60,
            'series_id' => $series->id,
            'is_active' => true,
        ]);

        ListeningStatistic::create([
            'user_id' => $user->id,
            'device_id' => 'series-goal-device',
            'book_id' => $inSeriesBook->id,
            'listening_date' => now()->toDateString(),
            'seconds_listened' => 1800,
            'session_type' => 'listening',
        ]);
        ListeningStatistic::create([
            'user_id' => $user->id,
            'device_id' => 'series-goal-device',
            'book_id' => $otherBook->id,
            'listening_date' => now()->toDateString(),
            'seconds_listened' => 3600,
            'session_type' => 'listening',
        ]);

        $response = $this->getJson('/api/v1/goals/listening');

        $response->assertOk()
            ->assertJsonPath('goals.0.metric', 'series_hours')
            ->assertJsonPath('goals.0.series_name', 'The Trilogy')
            ->assertJsonPath('goals.0.progress_minutes', 30);
    }

    public function test_author_goal_progress_counts_listening_from_author_books(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $author = Author::factory()->create(['name' => 'Jane Doe']);
        $authorBook = Book::factory()->create();
        $authorBook->authors()->attach($author);
        $otherBook = Book::factory()->create();

        ListeningGoal::create([
            'user_id' => $user->id,
            'period_type' => 'week',
            'metric' => 'author_hours',
            'target_minutes' => 60,
            'author_id' => $author->id,
            'is_active' => true,
        ]);

        ListeningStatistic::create([
            'user_id' => $user->id,
            'device_id' => 'author-goal-device',
            'book_id' => $authorBook->id,
            'listening_date' => now()->toDateString(),
            'seconds_listened' => 1200,
            'session_type' => 'listening',
        ]);
        ListeningStatistic::create([
            'user_id' => $user->id,
            'device_id' => 'author-goal-device',
            'book_id' => $otherBook->id,
            'listening_date' => now()->toDateString(),
            'seconds_listened' => 3600,
            'session_type' => 'listening',
        ]);

        $response = $this->getJson('/api/v1/goals/listening');

        $response->assertOk()
            ->assertJsonPath('goals.0.metric', 'author_hours')
            ->assertJsonPath('goals.0.author_name', 'Jane Doe')
            ->assertJsonPath('goals.0.progress_minutes', 20);
    }

    public function test_book_goal_progress_counts_listening_for_that_book_only(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $targetBook = Book::factory()->create();
        $otherBook = Book::factory()->create();

        ListeningGoal::create([
            'user_id' => $user->id,
            'period_type' => 'week',
            'metric' => 'book_hours',
            'target_minutes' => 60,
            'book_id' => $targetBook->id,
            'is_active' => true,
        ]);

        ListeningStatistic::create([
            'user_id' => $user->id,
            'device_id' => 'book-goal-device',
            'book_id' => $targetBook->id,
            'listening_date' => now()->toDateString(),
            'seconds_listened' => 900,
            'session_type' => 'listening',
        ]);
        ListeningStatistic::create([
            'user_id' => $user->id,
            'device_id' => 'book-goal-device',
            'book_id' => $otherBook->id,
            'listening_date' => now()->toDateString(),
            'seconds_listened' => 3600,
            'session_type' => 'listening',
        ]);

        $response = $this->getJson('/api/v1/goals/listening');

        $response->assertOk()
            ->assertJsonPath('goals.0.metric', 'book_hours')
            ->assertJsonPath('goals.0.book_title', $targetBook->title)
            ->assertJsonPath('goals.0.progress_minutes', 15);
    }

    public function test_books_finished_goal_progress_counts_completed_books(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $book = Book::factory()->create();
        UserBookStatus::create([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'status' => 'completed',
            'finished_at' => now(),
            'order' => 1,
        ]);

        ListeningGoal::create([
            'user_id' => $user->id,
            'period_type' => 'month',
            'metric' => 'books_finished',
            'target_minutes' => 2,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/goals/listening', ['X-Acting-As-Test' => '1']);

        $response->assertOk()
            ->assertJsonPath('goals.0.metric', 'books_finished')
            ->assertJsonPath('goals.0.progress_minutes', 1)
            ->assertJsonPath('goals.0.progress_percent', 50);
    }

    public function test_books_finished_goal_respects_genre_filter(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $genre = Genre::factory()->create(['name' => 'Sci-Fi Goal Genre']);
        $matchingBook = Book::factory()->create();
        $matchingBook->genres()->attach($genre);
        $otherBook = Book::factory()->create();

        UserBookStatus::create([
            'user_id' => $user->id, 'book_id' => $matchingBook->id,
            'status' => 'completed', 'finished_at' => now(), 'order' => 1,
        ]);
        UserBookStatus::create([
            'user_id' => $user->id, 'book_id' => $otherBook->id,
            'status' => 'completed', 'finished_at' => now(), 'order' => 2,
        ]);

        ListeningGoal::create([
            'user_id' => $user->id,
            'period_type' => 'month',
            'metric' => 'books_finished',
            'target_minutes' => 5,
            'genre_id' => $genre->getKey(),
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/goals/listening', ['X-Acting-As-Test' => '1']);

        $response->assertOk()->assertJsonPath('goals.0.progress_minutes', 1);
    }

    public function test_books_finished_goal_merges_all_three_completion_sources_without_duplicate_count(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $statusBook = Book::factory()->create();
        UserBookStatus::create([
            'user_id' => $user->id, 'book_id' => $statusBook->id,
            'status' => 'completed', 'finished_at' => now(), 'order' => 1,
        ]);

        $positionBook = Book::factory()->create(['duration' => 7_200]);
        BookPosition::create([
            'user_id' => $user->id,
            'book_id' => $positionBook->id,
            'device_id' => 'goal-completion-device',
            'position_ms' => 7_200_000,
            'progress_percentage' => 100,
            'completed' => true,
            'last_event_timestamp_ms' => now()->getTimestampMs(),
            'last_event_id' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        ListeningGoal::create([
            'user_id' => $user->id,
            'period_type' => 'month',
            'metric' => 'books_finished',
            'target_minutes' => 5,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/goals/listening', ['X-Acting-As-Test' => '1']);

        $response->assertOk()->assertJsonPath('goals.0.progress_minutes', 2);
    }

    public function test_store_rejects_period_type_custom_without_dates(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/goals/listening', [
            'period_type' => 'custom',
            'metric' => 'total_hours',
            'target_minutes' => 60,
        ], ['X-Acting-As-Test' => '1']);

        $response->assertStatus(422);
    }

    public function test_store_rejects_custom_period_with_end_date_but_null_start_date_without_crashing(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        // Regression: Laravel's after_or_equal:start_date rule falls back to parsing the
        // literal string "start_date" as a date when the compared field resolves to null,
        // raising a DateMalformedStringException (500) instead of a clean 422.
        $response = $this->postJson('/api/v1/goals/listening', [
            'period_type' => 'custom',
            'metric' => 'total_hours',
            'target_minutes' => 60,
            'start_date' => null,
            'end_date' => now()->addDays(10)->toDateString(),
        ], ['X-Acting-As-Test' => '1']);

        $response->assertStatus(422);
    }

    public function test_store_rejects_end_date_before_start_date(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/goals/listening', [
            'period_type' => 'custom',
            'metric' => 'total_hours',
            'target_minutes' => 60,
            'start_date' => now()->toDateString(),
            'end_date' => now()->subDays(1)->toDateString(),
        ], ['X-Acting-As-Test' => '1']);

        $response->assertStatus(422);
    }

    public function test_store_accepts_custom_period_with_start_and_end_date(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/goals/listening', [
            'period_type' => 'custom',
            'metric' => 'total_hours',
            'target_minutes' => 60,
            'start_date' => now()->subDays(10)->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
        ], ['X-Acting-As-Test' => '1']);

        $response->assertCreated()
            ->assertJsonPath('goal.period_type', 'custom')
            ->assertJsonPath('goal.start_date', now()->subDays(10)->toDateString())
            ->assertJsonPath('goal.end_date', now()->addDays(10)->toDateString());
    }

    public function test_store_rejects_non_custom_period_with_dates(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/goals/listening', [
            'period_type' => 'week',
            'metric' => 'total_hours',
            'target_minutes' => 60,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
        ], ['X-Acting-As-Test' => '1']);

        $response->assertStatus(422);
    }

    public function test_update_can_change_period_type_and_metric(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $goal = ListeningGoal::create([
            'user_id' => $user->id,
            'period_type' => 'week',
            'metric' => 'total_hours',
            'target_minutes' => 60,
            'is_active' => true,
        ]);

        $response = $this->putJson("/api/v1/goals/listening/{$goal->id}", [
            'period_type' => 'month',
            'metric' => 'fiction_hours',
        ], ['X-Acting-As-Test' => '1']);

        $response->assertOk()
            ->assertJsonPath('goal.period_type', 'month')
            ->assertJsonPath('goal.metric', 'fiction_hours');
    }

    public function test_update_rejects_editing_another_users_goal(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $intruder = User::factory()->create(['role' => 'admin']);

        $goal = ListeningGoal::create([
            'user_id' => $owner->id,
            'period_type' => 'week',
            'metric' => 'total_hours',
            'target_minutes' => 60,
            'is_active' => true,
        ]);

        Sanctum::actingAs($intruder);

        $response = $this->putJson("/api/v1/goals/listening/{$goal->id}", [
            'target_minutes' => 120,
        ], ['X-Acting-As-Test' => '1']);

        $response->assertStatus(403);
    }

    public function test_index_returns_elapsed_percent_and_start_end_dates(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        ListeningGoal::create([
            'user_id' => $user->id,
            'period_type' => 'day',
            'metric' => 'total_hours',
            'target_minutes' => 60,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/goals/listening', ['X-Acting-As-Test' => '1']);

        $response->assertOk()
            ->assertJsonPath('goals.0.start_date', now()->startOfDay()->toDateString())
            ->assertJsonPath('goals.0.end_date', now()->endOfDay()->toDateString());
        $this->assertIsNumeric($response->json('goals.0.elapsed_percent'));
    }

    public function test_breakdown_returns_book_entries_for_books_finished_metric(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $book = Book::factory()->create(['title' => 'The Breakdown Book']);
        UserBookStatus::create([
            'user_id' => $user->id, 'book_id' => $book->id,
            'status' => 'completed', 'finished_at' => now(), 'order' => 1,
        ]);

        $goal = ListeningGoal::create([
            'user_id' => $user->id,
            'period_type' => 'month',
            'metric' => 'books_finished',
            'target_minutes' => 2,
            'is_active' => true,
        ]);

        $response = $this->getJson("/api/v1/goals/listening/{$goal->id}/breakdown", ['X-Acting-As-Test' => '1']);

        $response->assertOk()
            ->assertJsonPath('metric', 'books_finished')
            ->assertJsonPath('entries.0.type', 'book')
            ->assertJsonPath('entries.0.book_id', $book->id)
            ->assertJsonPath('entries.0.title', 'The Breakdown Book');
    }

    public function test_breakdown_returns_daily_book_grouped_entries_for_hour_metric(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $book = Book::factory()->create(['title' => 'The Hour Book']);

        $goal = ListeningGoal::create([
            'user_id' => $user->id,
            'period_type' => 'week',
            'metric' => 'total_hours',
            'target_minutes' => 60,
            'is_active' => true,
        ]);

        ListeningStatistic::create([
            'user_id' => $user->id,
            'device_id' => 'breakdown-device',
            'book_id' => $book->id,
            'listening_date' => now()->toDateString(),
            'seconds_listened' => 1800,
            'session_type' => 'listening',
        ]);

        $response = $this->getJson("/api/v1/goals/listening/{$goal->id}/breakdown", ['X-Acting-As-Test' => '1']);

        $response->assertOk()
            ->assertJsonPath('metric', 'total_hours')
            ->assertJsonPath('entries.0.type', 'day')
            ->assertJsonPath('entries.0.date', now()->toDateString())
            ->assertJsonPath('entries.0.minutes', 30)
            ->assertJsonPath('entries.0.books.0.book_id', $book->id)
            ->assertJsonPath('entries.0.books.0.minutes', 30);
    }

    public function test_breakdown_rejects_other_users_goal(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $intruder = User::factory()->create(['role' => 'admin']);

        $goal = ListeningGoal::create([
            'user_id' => $owner->id,
            'period_type' => 'week',
            'metric' => 'total_hours',
            'target_minutes' => 60,
            'is_active' => true,
        ]);

        Sanctum::actingAs($intruder);

        $response = $this->getJson("/api/v1/goals/listening/{$goal->id}/breakdown", ['X-Acting-As-Test' => '1']);

        $response->assertStatus(403);
    }

    public function test_store_book_completion_goal_derives_target_minutes_from_book_duration(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $book = Book::factory()->create(['duration' => 3600]);

        $response = $this->postJson('/api/v1/goals/listening', [
            'period_type' => 'custom',
            'metric' => 'book_completion',
            'book_id' => $book->id,
            'target_minutes' => 5,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
        ], ['X-Acting-As-Test' => '1']);

        $response->assertCreated()
            ->assertJsonPath('goal.metric', 'book_completion')
            ->assertJsonPath('goal.book_id', $book->id)
            ->assertJsonPath('goal.target_minutes', 60);
    }

    public function test_store_book_completion_goal_requires_custom_period(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $book = Book::factory()->create(['duration' => 3600]);

        $response = $this->postJson('/api/v1/goals/listening', [
            'period_type' => 'week',
            'metric' => 'book_completion',
            'book_id' => $book->id,
        ], ['X-Acting-As-Test' => '1']);

        $response->assertStatus(422);
    }

    public function test_store_book_completion_goal_requires_book_id(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/goals/listening', [
            'period_type' => 'custom',
            'metric' => 'book_completion',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
        ], ['X-Acting-As-Test' => '1']);

        $response->assertStatus(422);
    }

    public function test_book_completion_goal_progress_uses_book_progress_position_not_listening_statistics(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $book = Book::factory()->create(['duration' => 3600]);

        $goal = ListeningGoal::create([
            'user_id' => $user->id,
            'period_type' => 'custom',
            'metric' => 'book_completion',
            'target_minutes' => 60,
            'book_id' => $book->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
            'is_active' => true,
        ]);

        // Accumulated listening_statistics minutes should be ignored for this metric.
        ListeningStatistic::create([
            'user_id' => $user->id,
            'device_id' => 'completion-device',
            'book_id' => $book->id,
            'listening_date' => now()->toDateString(),
            'seconds_listened' => 3600,
            'session_type' => 'listening',
        ]);

        BookProgress::create([
            'book_id' => $book->id,
            'user_id' => (string) $user->id,
            'device_id' => 'completion-device',
            'current_position_seconds' => 900,
            'total_duration_seconds' => 3600,
            'progress_percentage' => 25,
        ]);

        $response = $this->getJson('/api/v1/goals/listening');

        $response->assertOk()
            ->assertJsonPath('goals.0.metric', 'book_completion')
            ->assertJsonPath('goals.0.progress_minutes', 15)
            ->assertJsonPath('goals.0.progress_percent', 25);
    }

    public function test_book_completion_goal_shows_full_progress_when_book_status_completed(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $book = Book::factory()->create(['duration' => 3600]);

        $goal = ListeningGoal::create([
            'user_id' => $user->id,
            'period_type' => 'custom',
            'metric' => 'book_completion',
            'target_minutes' => 60,
            'book_id' => $book->id,
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'is_active' => true,
        ]);

        UserBookStatus::create([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'status' => 'completed',
            'order' => 1,
        ]);

        $response = $this->getJson('/api/v1/goals/listening');

        $response->assertOk()
            ->assertJsonPath('goals.0.progress_minutes', 60)
            ->assertJsonPath('goals.0.progress_percent', 100);
    }

    public function test_history_returns_expired_custom_goal_and_excludes_it_from_index(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $book = Book::factory()->create(['duration' => 3600]);

        $expiredGoal = ListeningGoal::create([
            'user_id' => $user->id,
            'period_type' => 'custom',
            'metric' => 'book_completion',
            'target_minutes' => 60,
            'book_id' => $book->id,
            'start_date' => now()->subDays(20)->toDateString(),
            'end_date' => now()->subDays(1)->toDateString(),
            'is_active' => true,
        ]);

        $activeGoal = ListeningGoal::create([
            'user_id' => $user->id,
            'period_type' => 'custom',
            'metric' => 'book_completion',
            'target_minutes' => 60,
            'book_id' => $book->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
            'is_active' => true,
        ]);

        $indexResponse = $this->getJson('/api/v1/goals/listening');
        $indexResponse->assertOk();
        $indexIds = collect($indexResponse->json('goals'))->pluck('id')->all();
        $this->assertContains($activeGoal->id, $indexIds);
        $this->assertNotContains($expiredGoal->id, $indexIds);

        $historyResponse = $this->getJson('/api/v1/goals/listening/history');
        $historyResponse->assertOk();
        $historyIds = collect($historyResponse->json('goals'))->pluck('id')->all();
        $this->assertContains($expiredGoal->id, $historyIds);
        $this->assertNotContains($activeGoal->id, $historyIds);
    }

    public function test_index_includes_custom_goal_with_null_end_date(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        // A row that predates the create/update validation guaranteeing end_date on custom
        // goals (e.g. seeded directly) must not become permanently invisible: NULL >= date is
        // NULL in SQL, not true, so the naive predicate silently drops it from both index and history.
        $goal = ListeningGoal::create([
            'user_id' => $user->id,
            'period_type' => 'custom',
            'metric' => 'total_hours',
            'target_minutes' => 60,
            'start_date' => null,
            'end_date' => null,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/goals/listening');

        $response->assertOk();
        $ids = collect($response->json('goals'))->pluck('id')->all();
        $this->assertContains($goal->id, $ids);
    }

    public function test_store_book_completion_goal_rejects_book_with_no_duration(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $book = Book::factory()->create(['duration' => null]);

        $response = $this->postJson('/api/v1/goals/listening', [
            'period_type' => 'custom',
            'metric' => 'book_completion',
            'book_id' => $book->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
        ], ['X-Acting-As-Test' => '1']);

        $response->assertStatus(422);
    }

    public function test_book_completion_goal_progress_uses_furthest_position_across_devices(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $book = Book::factory()->create(['duration' => 3600]);

        ListeningGoal::create([
            'user_id' => $user->id,
            'period_type' => 'custom',
            'metric' => 'book_completion',
            'target_minutes' => 60,
            'book_id' => $book->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
            'is_active' => true,
        ]);

        // Furthest-along device synced first; a behind device syncs later. Progress must not
        // regress just because the stale row has a newer updated_at.
        BookProgress::create([
            'book_id' => $book->id,
            'user_id' => (string) $user->id,
            'device_id' => 'tablet',
            'current_position_seconds' => 1800,
            'total_duration_seconds' => 3600,
            'progress_percentage' => 50,
        ]);
        BookProgress::create([
            'book_id' => $book->id,
            'user_id' => (string) $user->id,
            'device_id' => 'phone',
            'current_position_seconds' => 300,
            'total_duration_seconds' => 3600,
            'progress_percentage' => 8.3,
        ]);

        $response = $this->getJson('/api/v1/goals/listening');

        $response->assertOk()
            ->assertJsonPath('goals.0.progress_minutes', 30);
    }

    public function test_store_book_completion_goal_accepts_title_and_author_without_book_id(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/goals/listening', [
            'period_type' => 'custom',
            'metric' => 'book_completion',
            'target_minutes' => 45,
            'book_title' => 'Local Only Book',
            'book_author' => 'Some Author',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
        ], ['X-Acting-As-Test' => '1']);

        $response->assertCreated()
            ->assertJsonPath('goal.metric', 'book_completion')
            ->assertJsonPath('goal.book_id', null)
            ->assertJsonPath('goal.book_title', 'Local Only Book')
            ->assertJsonPath('goal.book_author', 'Some Author')
            ->assertJsonPath('goal.target_minutes', 45);
    }

    public function test_store_book_completion_goal_without_book_id_requires_target_minutes(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/goals/listening', [
            'period_type' => 'custom',
            'metric' => 'book_completion',
            'book_title' => 'Local Only Book',
            'book_author' => 'Some Author',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
        ], ['X-Acting-As-Test' => '1']);

        $response->assertStatus(422);
    }

    public function test_book_completion_goal_without_book_id_progress_uses_client_book_position(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        ListeningGoal::create([
            'user_id' => $user->id,
            'period_type' => 'custom',
            'metric' => 'book_completion',
            'target_minutes' => 60,
            'book_title' => 'Local Only Book',
            'book_author' => 'Some Author',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
            'is_active' => true,
        ]);

        $clientBook = \App\Models\ClientBook::create([
            'title' => 'Local Only Book',
            'author' => 'Some Author',
        ]);

        \App\Models\BookProgress::create([
            'client_book_id' => $clientBook->id,
            'user_id' => (string) $user->id,
            'device_id' => 'completion-device',
            'current_position_seconds' => 900,
            'total_duration_seconds' => 3600,
            'progress_percentage' => 25,
        ]);

        $response = $this->getJson('/api/v1/goals/listening');

        $response->assertOk()
            ->assertJsonPath('goals.0.progress_minutes', 15);
    }

    public function test_book_completion_goal_without_book_id_shows_full_progress_when_status_completed(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        ListeningGoal::create([
            'user_id' => $user->id,
            'period_type' => 'custom',
            'metric' => 'book_completion',
            'target_minutes' => 60,
            'book_title' => 'Local Only Book',
            'book_author' => 'Some Author',
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'is_active' => true,
        ]);

        UserBookStatus::create([
            'user_id' => $user->id,
            'title' => 'Local Only Book',
            'author' => 'Some Author',
            'status' => 'completed',
            'order' => 1,
        ]);

        $response = $this->getJson('/api/v1/goals/listening');

        $response->assertOk()
            ->assertJsonPath('goals.0.progress_minutes', 60)
            ->assertJsonPath('goals.0.progress_percent', 100);
    }

    public function test_book_hours_goal_without_book_id_matches_by_title_and_author(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        ListeningGoal::create([
            'user_id' => $user->id,
            'period_type' => 'week',
            'metric' => 'book_hours',
            'target_minutes' => 60,
            'book_title' => 'Local Only Book',
            'book_author' => 'Some Author',
            'is_active' => true,
        ]);

        ListeningStatistic::create([
            'user_id' => $user->id,
            'device_id' => 'book-hours-device',
            'title' => 'Local Only Book',
            'author' => 'Some Author',
            'listening_date' => now()->toDateString(),
            'seconds_listened' => 900,
            'session_type' => 'listening',
        ]);

        $response = $this->getJson('/api/v1/goals/listening');

        $response->assertOk()
            ->assertJsonPath('goals.0.progress_minutes', 15);
    }
}
