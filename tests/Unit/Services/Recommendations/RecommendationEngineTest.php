<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Recommendations;

use App\Models\Book;
use App\Models\RecommendationShelf;
use App\Models\User;
use App\Services\Recommendations\RecommendationEngine;
use App\Services\Recommendations\RecommendationStrategyInterface;
use App\Services\Recommendations\ShelfResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RecommendationEngineTest extends TestCase
{
    use RefreshDatabase;

    public function testRecomputeStoresShelvesFromEnabledStrategiesInOrder(): void
    {
        $user = User::factory()->create();
        $book1 = Book::factory()->create();
        $book2 = Book::factory()->create();

        $first = Mockery::mock(RecommendationStrategyInterface::class);
        $first->shouldReceive('isEnabled')->andReturn(true);
        $first->shouldReceive('key')->andReturn('first');
        $first->shouldReceive('generate')->once()->andReturn([
            new ShelfResult('shelf_a', 'Shelf A', [['book_id' => $book1->id, 'score' => null]]),
        ]);

        $second = Mockery::mock(RecommendationStrategyInterface::class);
        $second->shouldReceive('isEnabled')->andReturn(true);
        $second->shouldReceive('key')->andReturn('second');
        $second->shouldReceive('generate')->once()->andReturn([
            new ShelfResult('shelf_b', 'Shelf B', [['book_id' => $book2->id, 'score' => 0.5]]),
        ]);

        (new RecommendationEngine([$first, $second]))->recompute($user);

        $shelves = RecommendationShelf::where('user_id', $user->id)->orderBy('sort_order')->with('shelfBooks')->get();

        $this->assertCount(2, $shelves);
        $this->assertSame('shelf_a', $shelves[0]->shelf_key);
        $this->assertSame(0, $shelves[0]->sort_order);
        $this->assertSame($book1->id, $shelves[0]->shelfBooks->first()->book_id);
        $this->assertSame('shelf_b', $shelves[1]->shelf_key);
        $this->assertSame(1, $shelves[1]->sort_order);
        $this->assertSame(0.5, $shelves[1]->shelfBooks->first()->score);
    }

    public function testSkipsDisabledStrategies(): void
    {
        $user = User::factory()->create();

        $disabled = Mockery::mock(RecommendationStrategyInterface::class);
        $disabled->shouldReceive('isEnabled')->andReturn(false);
        $disabled->shouldNotReceive('generate');

        (new RecommendationEngine([$disabled]))->recompute($user);

        $this->assertDatabaseCount('recommendation_shelves', 0);
    }

    public function testOneFailingStrategyDoesNotPreventOthersFromProducingShelves(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();

        $failing = Mockery::mock(RecommendationStrategyInterface::class);
        $failing->shouldReceive('isEnabled')->andReturn(true);
        $failing->shouldReceive('key')->andReturn('failing');
        $failing->shouldReceive('generate')->andThrow(new \RuntimeException('boom'));

        $working = Mockery::mock(RecommendationStrategyInterface::class);
        $working->shouldReceive('isEnabled')->andReturn(true);
        $working->shouldReceive('key')->andReturn('working');
        $working->shouldReceive('generate')->andReturn([
            new ShelfResult('shelf_working', 'Working Shelf', [['book_id' => $book->id, 'score' => null]]),
        ]);

        (new RecommendationEngine([$failing, $working]))->recompute($user);

        $shelves = RecommendationShelf::where('user_id', $user->id)->get();
        $this->assertCount(1, $shelves);
        $this->assertSame('shelf_working', $shelves[0]->shelf_key);
    }

    public function testRecomputeReplacesPreviousShelvesEntirely(): void
    {
        $user = User::factory()->create();
        $oldBook = Book::factory()->create();
        $newBook = Book::factory()->create();

        $strategy = Mockery::mock(RecommendationStrategyInterface::class);
        $strategy->shouldReceive('isEnabled')->andReturn(true);
        $strategy->shouldReceive('key')->andReturn('strategy');
        $strategy->shouldReceive('generate')->andReturn([
            new ShelfResult('shelf_old', 'Old Shelf', [['book_id' => $oldBook->id, 'score' => null]]),
        ]);

        (new RecommendationEngine([$strategy]))->recompute($user);
        $this->assertDatabaseHas('recommendation_shelves', ['user_id' => $user->id, 'shelf_key' => 'shelf_old']);

        $strategy2 = Mockery::mock(RecommendationStrategyInterface::class);
        $strategy2->shouldReceive('isEnabled')->andReturn(true);
        $strategy2->shouldReceive('key')->andReturn('strategy');
        $strategy2->shouldReceive('generate')->andReturn([
            new ShelfResult('shelf_new', 'New Shelf', [['book_id' => $newBook->id, 'score' => null]]),
        ]);

        (new RecommendationEngine([$strategy2]))->recompute($user);

        $shelves = RecommendationShelf::where('user_id', $user->id)->get();
        $this->assertCount(1, $shelves);
        $this->assertSame('shelf_new', $shelves[0]->shelf_key);
        $this->assertDatabaseCount('recommendation_shelf_books', 1);
    }

    public function testBookAppearsOnlyOnTheFirstShelfThatContainsIt(): void
    {
        $user = User::factory()->create();
        $books = Book::factory()->count(4)->create();
        [$a, $b, $c, $d] = $books->pluck('id')->all();

        $strategy = Mockery::mock(RecommendationStrategyInterface::class);
        $strategy->shouldReceive('isEnabled')->andReturn(true);
        $strategy->shouldReceive('key')->andReturn('strategy');
        $strategy->shouldReceive('generate')->andReturn([
            new ShelfResult('one', 'One', [['book_id' => $a, 'score' => 0.9], ['book_id' => $b, 'score' => 0.8]]),
            new ShelfResult('two', 'Two', [['book_id' => $b, 'score' => 0.7], ['book_id' => $c, 'score' => 0.6]]),
            new ShelfResult('three', 'Three', [['book_id' => $a, 'score' => 0.5], ['book_id' => $c, 'score' => 0.4]]),
            new ShelfResult('four', 'Four', [['book_id' => $d, 'score' => 0.3], ['book_id' => $a, 'score' => 0.2]]),
        ]);

        (new RecommendationEngine([$strategy]))->recompute($user);

        $actual = [];
        $shelves = RecommendationShelf::where('user_id', $user->id)->orderBy('sort_order')->with('shelfBooks')->get();
        foreach ($shelves as $shelf) {
            $pairs = [];
            foreach ($shelf->shelfBooks->sortBy('rank') as $shelfBook) {
                $pairs[] = [$shelfBook->book_id, $shelfBook->rank];
            }
            $actual[] = [$shelf->shelf_key, $shelf->sort_order, $pairs];
        }

        $this->assertSame([
            ['one', 0, [[$a, 0], [$b, 1]]],
            ['two', 1, [[$c, 0]]],
            ['four', 2, [[$d, 0]]],
        ], $actual);
    }

    public function testDismissedShelfDoesNotClaimBooksFromVisibleShelves(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();
        \App\Models\RecommendationShelfDismissal::create(['user_id' => $user->id, 'shelf_key' => 'dismissed']);

        $strategy = Mockery::mock(RecommendationStrategyInterface::class);
        $strategy->shouldReceive('isEnabled')->andReturn(true);
        $strategy->shouldReceive('key')->andReturn('strategy');
        $strategy->shouldReceive('generate')->andReturn([
            new ShelfResult('dismissed', 'Dismissed', [['book_id' => $book->id, 'score' => null]]),
            new ShelfResult('visible', 'Visible', [['book_id' => $book->id, 'score' => null]]),
        ]);

        (new RecommendationEngine([$strategy]))->recompute($user);

        $shelves = RecommendationShelf::where('user_id', $user->id)->get();
        $this->assertSame(['visible'], $shelves->pluck('shelf_key')->all());
        $this->assertDatabaseCount('recommendation_shelf_books', 1);
    }

    public function testJitterReordersShelves(): void
    {
        $user = User::factory()->create();
        $ids = Book::factory()->count(4)->create()->pluck('id')->all();
        $asBooks = fn (array $list): array => array_map(fn (int $id): array => ['book_id' => $id, 'score' => null], $list);

        $strategy = Mockery::mock(RecommendationStrategyInterface::class);
        $strategy->shouldReceive('isEnabled')->andReturn(true);
        $strategy->shouldReceive('key')->andReturn('strategy');
        $strategy->shouldReceive('generate')->andReturn([
            new ShelfResult('shuffled', 'Shuffled', $asBooks($ids)),
        ]);

        // jitter 10 with fixed randoms; the last value is consumed by the 4th book.
        $values = [0.9, 0.5, 0.1, 0.0];
        $random = function () use (&$values): float {
            return array_shift($values) ?? 0.0;
        };

        (new RecommendationEngine([$strategy], 10, $random))->recompute($user);

        $shelves = RecommendationShelf::where('user_id', $user->id)->orderBy('sort_order')->with('shelfBooks')->get();
        $order = fn (RecommendationShelf $shelf): array => $shelf->shelfBooks->sortBy('rank')->pluck('book_id')->values()->all();

        // keys: 0+9=9, 1+5=6, 2+1=3, 3+0=3 (tie keeps original order) -> ids[2], ids[3], ids[1], ids[0]
        $this->assertSame([$ids[2], $ids[3], $ids[1], $ids[0]], $order($shelves[0]));
    }

    public function testZeroJitterKeepsStrategyOrder(): void
    {
        $user = User::factory()->create();
        $ids = Book::factory()->count(3)->create()->pluck('id')->all();

        $strategy = Mockery::mock(RecommendationStrategyInterface::class);
        $strategy->shouldReceive('isEnabled')->andReturn(true);
        $strategy->shouldReceive('key')->andReturn('strategy');
        $strategy->shouldReceive('generate')->andReturn([
            new ShelfResult('s', 'S', array_map(fn (int $id): array => ['book_id' => $id, 'score' => null], $ids)),
        ]);

        (new RecommendationEngine([$strategy], 0, fn (): float => 0.99))->recompute($user);

        $this->assertSame($ids, RecommendationShelf::where('user_id', $user->id)->first()->shelfBooks()->orderBy('rank')->pluck('book_id')->all());
    }
}
