<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\BookStatusUpdated;
use App\Jobs\RecomputeRecommendationsJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A user's reading status change is exactly what the discovery-shelf strategies key
 * off of (last-finished books, in-progress series, completed-book counts by
 * genre/author), so any status change is a full recompute trigger.
 */
class RefreshRecommendationsListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(BookStatusUpdated $event): void
    {
        RecomputeRecommendationsJob::dispatch($event->user->id);
    }
}
