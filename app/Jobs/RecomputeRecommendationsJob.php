<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\Recommendations\RecommendationEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecomputeRecommendationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 3;

    public function __construct(protected int $userId)
    {
        $this->onQueue('recommendations');
    }

    public function handle(): void
    {
        $user = User::find($this->userId);
        if (!$user) {
            return;
        }

        RecommendationEngine::fromConfig()->recompute($user);
    }
}
