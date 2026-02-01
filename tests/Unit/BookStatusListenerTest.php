<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Events\BookStatusUpdated;
use App\Listeners\BookStatusListener;
use App\Models\Book;
use App\Models\User;
use App\Models\UserStatistic;
use App\Services\BadgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Mockery;

class BookStatusListenerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Book $book;
    /** @var \Mockery\MockInterface */
    protected $badgeServiceMock;
    protected BookStatusListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->book = Book::factory()->create();

        // Mock BadgeService
        /** @var \Mockery\MockInterface $badgeServiceMock */
        $badgeServiceMock = Mockery::mock(BadgeService::class);
        $this->badgeServiceMock = $badgeServiceMock;
        $this->app->instance(BadgeService::class, $this->badgeServiceMock);

        // Initialize listener with the mock
        /** @var BadgeService $badgeService */
        $badgeService = $this->badgeServiceMock;
        $this->listener = new BookStatusListener($badgeService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_completing_a_book_increments_statistics_and_evaluates_badges(): void
    {
        $this->badgeServiceMock->shouldReceive('evaluateBadgesForUser')
            ->once()
            ->with(Mockery::on(fn ($user) => $user->id === $this->user->id));

        $event = new BookStatusUpdated($this->user, $this->book, 'completed', 'in_progress');

        $this->listener->handle($event);

        // Assert statistics were updated
        $this->assertDatabaseHas('user_statistics', [
            'user_id' => $this->user->id,
            'statistic_key' => 'books_completed',
            'value' => 1,
        ]);

        $this->assertDatabaseHas('user_statistics', [
            'user_id' => $this->user->id,
            'statistic_key' => 'total_books_read',
            'value' => 1,
        ]);
    }

    public function test_changing_status_to_in_progress_does_not_update_completion_statistic_but_evaluates_badges(): void
    {
        $this->badgeServiceMock->shouldReceive('evaluateBadgesForUser')->once();

        $event = new BookStatusUpdated($this->user, $this->book, 'in_progress', 'queue');

        $this->listener->handle($event);

        // Assert statistics were NOT updated (values should be 0, based on fresh database)
        $this->assertDatabaseMissing('user_statistics', [
            'user_id' => $this->user->id,
            'statistic_key' => 'books_completed',
            'value' => 1,
        ]);
    }

    public function test_reverting_completed_status_decrements_books_completed_statistic(): void
    {
        $this->badgeServiceMock->shouldReceive('evaluateBadgesForUser')->once();

        // Setup: User already completed the book once
        UserStatistic::incrementUserStatistic($this->user->id, 'books_completed', 1);
        UserStatistic::incrementUserStatistic($this->user->id, 'total_books_read', 1);

        $event = new BookStatusUpdated($this->user, $this->book, 'dropped', 'completed');

        $this->listener->handle($event);

        // Assert statistics were decremented
        $this->assertDatabaseHas('user_statistics', [
            'user_id' => $this->user->id,
            'statistic_key' => 'books_completed',
            'value' => 0,
        ]);
    }
}
