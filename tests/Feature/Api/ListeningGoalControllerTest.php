<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\BookPosition;
use App\Models\Device;
use App\Models\ListeningGoal;
use App\Models\ListeningStatistic;
use App\Models\Genre;
use App\Models\Playlist;
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

        $positionBook = Book::factory()->create();
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
        $this->assertIsFloat($response->json('goals.0.elapsed_percent'));
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
}
