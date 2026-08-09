<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\RecomputeRecommendationsJob;
use App\Models\Book;
use App\Models\Genre;
use App\Models\RecommendationShelf;
use App\Models\User;
use App\Models\UserBookStatus;
use App\Services\Recommendations\Strategies\GenreAffinityStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecomputeRecommendationsJobTest extends TestCase
{
    use RefreshDatabase;

    public function testHandleDoesNothingWhenUserDoesNotExist(): void
    {
        (new RecomputeRecommendationsJob(999999))->handle();

        $this->assertDatabaseCount('recommendation_shelves', 0);
    }

    public function testHandleRunsRealConfiguredStrategiesForUser(): void
    {
        // Scoped to a strategy with no AI/embedding dependency — the AI-dependent
        // strategies (SimilarToRecentBooksStrategy/NewForYouStrategy) are covered by
        // their own mocked unit tests and would otherwise attempt a real network call
        // here via their SQL+AI fallback path.
        config(['recommendations.strategies' => [GenreAffinityStrategy::class]]);

        $user = User::factory()->create();
        $genre = Genre::factory()->create(['name' => 'Fantasy']);

        $completedBook = Book::factory()->create();
        $completedBook->genres()->attach($genre->id);
        UserBookStatus::factory()->create([
            'user_id' => $user->id,
            'book_id' => $completedBook->id,
            'status' => 'completed',
        ]);

        $unreadBook = Book::factory()->create();
        $unreadBook->genres()->attach($genre->id);

        (new RecomputeRecommendationsJob($user->id))->handle();

        $this->assertDatabaseHas('recommendation_shelves', [
            'user_id' => $user->id,
            'shelf_key' => 'genre_affinity:' . $genre->id,
        ]);
        $shelf = RecommendationShelf::where('user_id', $user->id)->where('shelf_key', 'genre_affinity:' . $genre->id)->first();
        $this->assertNotNull($shelf);
        $this->assertDatabaseHas('recommendation_shelf_books', ['shelf_id' => $shelf->id, 'book_id' => $unreadBook->id]);
    }
}
