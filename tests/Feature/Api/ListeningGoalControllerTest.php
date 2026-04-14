<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Book;
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
}
