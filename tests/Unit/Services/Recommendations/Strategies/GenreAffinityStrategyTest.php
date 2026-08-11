<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Recommendations\Strategies;

use App\Models\Book;
use App\Models\BookPosition;
use App\Models\Genre;
use App\Models\User;
use App\Models\UserBookStatus;
use App\Services\Recommendations\Strategies\GenreAffinityStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenreAffinityStrategyTest extends TestCase
{
    use RefreshDatabase;

    public function testGeneratesNoShelvesWithoutCompletedBooks(): void
    {
        $user = User::factory()->create();

        $shelves = (new GenreAffinityStrategy())->generate($user);

        $this->assertSame([], $shelves);
    }

    public function testGeneratesShelfForCompletedGenreExcludingEngagedBooks(): void
    {
        $user = User::factory()->create();
        $genre = Genre::factory()->create(['name' => 'Mystery']);

        $completedBook = Book::factory()->create();
        $completedBook->genres()->attach($genre->id);
        UserBookStatus::factory()->create([
            'user_id' => $user->id,
            'book_id' => $completedBook->id,
            'status' => 'completed',
            'finished_at' => now(),
        ]);

        $unreadBook = Book::factory()->create();
        $unreadBook->genres()->attach($genre->id);

        $alreadyQueuedBook = Book::factory()->create();
        $alreadyQueuedBook->genres()->attach($genre->id);
        UserBookStatus::factory()->create([
            'user_id' => $user->id,
            'book_id' => $alreadyQueuedBook->id,
            'status' => 'queue',
        ]);

        $shelves = (new GenreAffinityStrategy())->generate($user);

        $this->assertCount(1, $shelves);
        $this->assertSame('genre_affinity:' . $genre->id, $shelves[0]->shelfKey);
        $this->assertSame('More in Mystery', $shelves[0]->title);
        $this->assertSame([['book_id' => $unreadBook->id, 'score' => null]], $shelves[0]->books);
    }

    public function testGeneratesShelfFromEventSourcedCompletionWithNoUserBookStatusRow(): void
    {
        // Regression test: many users only ever have book_positions completion data (the
        // modern event-sourced progress-sync path) and no user_book_status rows at all, so
        // the strategy must not rely solely on user_book_status to find completed books.
        $user = User::factory()->create();
        $genre = Genre::factory()->create(['name' => 'Sci-Fi']);

        $completedBook = Book::factory()->create();
        $completedBook->genres()->attach($genre->id);
        BookPosition::query()->create([
            'user_id' => $user->id,
            'book_id' => $completedBook->id,
            'device_id' => 'device-1',
            'position_ms' => 3600000,
            'progress_percentage' => 100,
            'completed' => true,
            'last_event_timestamp_ms' => now()->valueOf(),
            'last_event_id' => 'test-event-1',
        ]);

        $unreadBook = Book::factory()->create();
        $unreadBook->genres()->attach($genre->id);

        $shelves = (new GenreAffinityStrategy())->generate($user);

        $this->assertCount(1, $shelves);
        $this->assertSame('genre_affinity:' . $genre->id, $shelves[0]->shelfKey);
        $this->assertSame([['book_id' => $unreadBook->id, 'score' => null]], $shelves[0]->books);
    }
}
