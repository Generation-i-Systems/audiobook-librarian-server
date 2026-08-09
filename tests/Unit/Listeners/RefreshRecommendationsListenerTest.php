<?php

declare(strict_types=1);

namespace Tests\Unit\Listeners;

use App\Events\BookStatusUpdated;
use App\Jobs\RecomputeRecommendationsJob;
use App\Listeners\RefreshRecommendationsListener;
use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RefreshRecommendationsListenerTest extends TestCase
{
    use RefreshDatabase;

    public function testHandleDispatchesRecomputeForTheEventsUser(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $book = Book::factory()->create();

        (new RefreshRecommendationsListener())->handle(new BookStatusUpdated($user, $book, 'completed', 'in_progress'));

        Queue::assertPushed(RecomputeRecommendationsJob::class, function (RecomputeRecommendationsJob $job) use ($user) {
            $reflection = new \ReflectionProperty($job, 'userId');
            $reflection->setAccessible(true);
            return $reflection->getValue($job) === $user->id;
        });
    }

    public function testBookStatusUpdatedEventQueuesTheListener(): void
    {
        // RefreshRecommendationsListener implements ShouldQueue, so firing the event
        // queues the listener itself (as CallQueuedListener) rather than running its
        // handle() — and therefore dispatching RecomputeRecommendationsJob — inline.
        Queue::fake();
        $user = User::factory()->create();
        $book = Book::factory()->create();

        BookStatusUpdated::dispatch($user, $book, 'completed', 'in_progress');

        Queue::assertPushed(
            \Illuminate\Events\CallQueuedListener::class,
            fn (\Illuminate\Events\CallQueuedListener $job) => $job->class === RefreshRecommendationsListener::class
        );
    }
}
