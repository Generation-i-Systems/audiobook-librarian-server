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
}
