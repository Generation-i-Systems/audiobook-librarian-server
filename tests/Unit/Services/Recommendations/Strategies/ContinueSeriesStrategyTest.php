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
        UserBookStatus::factory()->create(['user_id' => $user->id, 'book_id' => $book1->id, 'status' => 'completed', 'finished_at' => now()]);

        $book3 = Book::factory()->create(['title' => 'Book Three']);
        $series->books()->attach($book3->id, ['series_number' => '3']);

        $book2 = Book::factory()->create(['title' => 'Book Two']);
        $series->books()->attach($book2->id, ['series_number' => '2']);

        $shelves = (new ContinueSeriesStrategy())->generate($user);

        $this->assertCount(1, $shelves);
        $this->assertSame('continue_series', $shelves[0]->shelfKey);
        $this->assertSame('Continue Your Series', $shelves[0]->title);
        $this->assertSame([['book_id' => $book2->id, 'score' => null]], $shelves[0]->books);
    }

    public function testConsolidatesMultipleStartedSeriesIntoOneShelf(): void
    {
        $user = User::factory()->create();

        $seriesA = Series::factory()->create(['name' => 'Series A']);
        $bookA1 = Book::factory()->create();
        $seriesA->books()->attach($bookA1->id, ['series_number' => '1']);
        UserBookStatus::factory()->create(['user_id' => $user->id, 'book_id' => $bookA1->id, 'status' => 'completed', 'finished_at' => now()]);
        $bookA2 = Book::factory()->create();
        $seriesA->books()->attach($bookA2->id, ['series_number' => '2']);

        $seriesB = Series::factory()->create(['name' => 'Series B']);
        $bookB1 = Book::factory()->create();
        $seriesB->books()->attach($bookB1->id, ['series_number' => '1']);
        UserBookStatus::factory()->create(['user_id' => $user->id, 'book_id' => $bookB1->id, 'status' => 'completed', 'finished_at' => now()]);
        $bookB2 = Book::factory()->create();
        $seriesB->books()->attach($bookB2->id, ['series_number' => '2']);

        $shelves = (new ContinueSeriesStrategy())->generate($user);

        $this->assertCount(1, $shelves);
        $this->assertSame('continue_series', $shelves[0]->shelfKey);
        $bookIds = collect($shelves[0]->books)->pluck('book_id')->all();
        $this->assertContains($bookA2->id, $bookIds);
        $this->assertContains($bookB2->id, $bookIds);
    }

    public function testProducesNoShelfWhenSeriesIsFullyEngaged(): void
    {
        $user = User::factory()->create();
        $series = Series::factory()->create(['name' => 'Short Series']);

        $book1 = Book::factory()->create();
        $series->books()->attach($book1->id, ['series_number' => '1']);
        UserBookStatus::factory()->create(['user_id' => $user->id, 'book_id' => $book1->id, 'status' => 'completed', 'finished_at' => now()]);

        $book2 = Book::factory()->create();
        $series->books()->attach($book2->id, ['series_number' => '2']);
        UserBookStatus::factory()->create(['user_id' => $user->id, 'book_id' => $book2->id, 'status' => 'queue']);

        $shelves = (new ContinueSeriesStrategy())->generate($user);

        $this->assertSame([], $shelves);
    }
}
