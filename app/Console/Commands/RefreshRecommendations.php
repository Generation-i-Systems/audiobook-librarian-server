<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RecomputeRecommendationsJob;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Daily catch-all sweep: recompute every user's discovery shelves, so newly-added
 * books eventually surface in genre/author/duration/new-for-you shelves even for
 * users who haven't triggered a status-change-based recompute recently.
 */
class RefreshRecommendations extends Command
{
    protected $signature = 'books:refresh-recommendations';

    protected $description = 'Queue a recommendation-shelf recompute for every user';

    public function handle(): int
    {
        $count = 0;
        User::query()->select('id')->chunkById(200, function ($users) use (&$count): void {
            foreach ($users as $user) {
                RecomputeRecommendationsJob::dispatch($user->id);
                $count++;
            }
        });

        $this->info("Queued recommendation recompute for {$count} user(s).");

        return self::SUCCESS;
    }
}
