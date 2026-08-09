<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Recommendations\Strategies;

use App\Models\Book;
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
}
