<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Recommendations\Strategies;

use App\Models\Book;
use App\Models\Series;
use App\Models\User;
use App\Models\UserBookStatus;
use App\Services\Recommendations\Strategies\ContinueSeriesStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContinueSeriesStrategyTest extends TestCase
{
    use RefreshDatabase;

    public function testGeneratesNoShelvesWithoutAnyStartedBook(): void
    {
        $user = User::factory()->create();

        $shelves = (new ContinueSeriesStrategy())->generate($user);

        $this->assertSame([], $shelves);
    }

    public function testSurfacesNextUnreadBookInSequenceOrder(): void
    {
        $user = User::factory()->create();
        $series = Series::factory()->create(['name' => 'The Saga']);

        $book1 = Book::factory()->create(['title' => 'Book One']);
        $series->books()->attach($book1->id, ['series_number' => '1']);
        UserBookStatus::factory()->create(['user_id' => $user->id, 'book_id' => $book1->id, 'status' => 'completed']);

        $book3 = Book::factory()->create(['title' => 'Book Three']);
        $series->books()->attach($book3->id, ['series_number' => '3']);

        $book2 = Book::factory()->create(['title' => 'Book Two']);
        $series->books()->attach($book2->id, ['series_number' => '2']);

        $shelves = (new ContinueSeriesStrategy())->generate($user);

        $this->assertCount(1, $shelves);
        $this->assertSame('continue_series:' . $series->id, $shelves[0]->shelfKey);
        $this->assertSame('Continue: The Saga', $shelves[0]->title);
        $this->assertSame([['book_id' => $book2->id, 'score' => null]], $shelves[0]->books);
    }

    public function testProducesNoShelfWhenSeriesIsFullyEngaged(): void
    {
        $user = User::factory()->create();
        $series = Series::factory()->create(['name' => 'Short Series']);

        $book1 = Book::factory()->create();
        $series->books()->attach($book1->id, ['series_number' => '1']);
        UserBookStatus::factory()->create(['user_id' => $user->id, 'book_id' => $book1->id, 'status' => 'completed']);

        $book2 = Book::factory()->create();
        $series->books()->attach($book2->id, ['series_number' => '2']);
        UserBookStatus::factory()->create(['user_id' => $user->id, 'book_id' => $book2->id, 'status' => 'queue']);

        $shelves = (new ContinueSeriesStrategy())->generate($user);

        $this->assertSame([], $shelves);
    }
}
