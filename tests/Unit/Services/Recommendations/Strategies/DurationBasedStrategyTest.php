<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Recommendations\Strategies;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use App\Models\UserBookStatus;
use App\Services\Recommendations\FavoredGenreResolver;
use App\Services\Recommendations\Strategies\DurationBasedStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DurationBasedStrategyTest extends TestCase
{
    use RefreshDatabase;

    public function testGeneratesNoShelvesWithoutFavoredGenres(): void
    {
        $user = User::factory()->create();

        $shelves = (new DurationBasedStrategy(new FavoredGenreResolver()))->generate($user);

        $this->assertSame([], $shelves);
    }

    public function testGeneratesQuickAndEpicShelvesForFavoredGenre(): void
    {
        $user = User::factory()->create();
        $genre = Genre::factory()->create(['name' => 'Sci-Fi']);

        $completedBook = Book::factory()->create(['duration' => 10000]);
        $completedBook->genres()->attach($genre->id);
        UserBookStatus::factory()->create(['user_id' => $user->id, 'book_id' => $completedBook->id, 'status' => 'completed', 'finished_at' => now()]);

        $quickBook = Book::factory()->create(['duration' => 3600]); // 1hr, well under 5hr threshold
        $quickBook->genres()->attach($genre->id);

        $epicBook = Book::factory()->create(['duration' => 90000]); // 25hr, over 20hr threshold
        $epicBook->genres()->attach($genre->id);

        $midLengthBook = Book::factory()->create(['duration' => 36000]); // 10hr, neither bucket
        $midLengthBook->genres()->attach($genre->id);

        $shelves = (new DurationBasedStrategy(new FavoredGenreResolver()))->generate($user);

        $this->assertCount(2, $shelves);
        $shelvesByKey = collect($shelves)->keyBy('shelfKey');

        $this->assertSame('Quick Listens', $shelvesByKey['quick_listens']->title);
        $this->assertSame([['book_id' => $quickBook->id, 'score' => null]], $shelvesByKey['quick_listens']->books);

        $this->assertSame('Epic Listens', $shelvesByKey['epic_listens']->title);
        $this->assertSame([['book_id' => $epicBook->id, 'score' => null]], $shelvesByKey['epic_listens']->books);
    }
}
