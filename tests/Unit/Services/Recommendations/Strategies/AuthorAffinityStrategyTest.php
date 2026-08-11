<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Recommendations\Strategies;

use App\Models\Author;
use App\Models\Book;
use App\Models\User;
use App\Models\UserBookStatus;
use App\Services\Recommendations\Strategies\AuthorAffinityStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorAffinityStrategyTest extends TestCase
{
    use RefreshDatabase;

    public function testRequiresAtLeastTwoCompletedBooksByAnAuthor(): void
    {
        $user = User::factory()->create();
        $author = Author::factory()->create(['name' => 'Only One']);

        $completedBook = Book::factory()->create();
        $completedBook->authors()->attach($author->id);
        UserBookStatus::factory()->create(['user_id' => $user->id, 'book_id' => $completedBook->id, 'status' => 'completed', 'finished_at' => now()]);

        $shelves = (new AuthorAffinityStrategy())->generate($user);

        $this->assertSame([], $shelves);
    }

    public function testGeneratesShelfForAuthorWithTwoOrMoreCompletedBooks(): void
    {
        $user = User::factory()->create();
        $author = Author::factory()->create(['name' => 'Prolific Writer']);

        foreach (range(1, 2) as $_) {
            $completedBook = Book::factory()->create();
            $completedBook->authors()->attach($author->id);
            UserBookStatus::factory()->create(['user_id' => $user->id, 'book_id' => $completedBook->id, 'status' => 'completed', 'finished_at' => now()]);
        }

        $unreadBook = Book::factory()->create();
        $unreadBook->authors()->attach($author->id);

        $shelves = (new AuthorAffinityStrategy())->generate($user);

        $this->assertCount(1, $shelves);
        $this->assertSame('author_affinity:' . $author->id, $shelves[0]->shelfKey);
        $this->assertSame('More by Prolific Writer', $shelves[0]->title);
        $this->assertSame([['book_id' => $unreadBook->id, 'score' => null]], $shelves[0]->books);
    }
}
