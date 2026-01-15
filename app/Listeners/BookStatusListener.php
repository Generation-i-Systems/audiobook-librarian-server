<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\BookStatusUpdated;
use App\Models\UserStatistic;
use App\Services\BadgeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class BookStatusListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(private readonly BadgeService $badgeService)
    {
    }

    /**
     * Handle the event.
     */
    public function handle(BookStatusUpdated $event): void
    {
        Log::info('BookStatusUpdated received', [
            'user_id' => $event->user->id,
            'book_id' => $event->book->id,
            'status' => $event->status,
        ]);

        $this->updateListeningStatistics($event);
        $this->evaluateBadges($event);
    }

    /**
     * Update listening statistics based on status change.
     */
    protected function updateListeningStatistics(BookStatusUpdated $event): void
    {
        if ($event->status === 'completed' && $event->previousStatus !== 'completed') {
            // Increment books_completed count
            UserStatistic::incrementUserStatistic($event->user->id, 'books_completed');
            UserStatistic::incrementUserStatistic($event->user->id, 'total_books_read');
        } elseif ($event->previousStatus === 'completed' && $event->status !== 'completed') {
            // Decrement if marked uncompleted
            UserStatistic::decrementUserStatistic($event->user->id, 'books_completed');
        }
    }

    /**
     * Evaluate badges for the user.
     */
    protected function evaluateBadges(BookStatusUpdated $event): void
    {
        // Re-evaluate all badges related to completion and social (e.g., recommendation badges)
        $this->badgeService->evaluateBadgesForUser($event->user);
    }
}
