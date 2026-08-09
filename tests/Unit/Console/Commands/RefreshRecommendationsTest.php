<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands;

use App\Jobs\RecomputeRecommendationsJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RefreshRecommendationsTest extends TestCase
{
    use RefreshDatabase;

    public function testDispatchesRecomputeForEveryUser(): void
    {
        $users = User::factory()->count(3)->create();
        Queue::fake();

        $this->artisan('books:refresh-recommendations')->assertSuccessful();

        Queue::assertPushed(RecomputeRecommendationsJob::class, 3);
        foreach ($users as $user) {
            Queue::assertPushed(fn (RecomputeRecommendationsJob $job) => $this->jobTargetsUser($job, $user->id));
        }
    }

    private function jobTargetsUser(RecomputeRecommendationsJob $job, int $userId): bool
    {
        $reflection = new \ReflectionProperty($job, 'userId');
        $reflection->setAccessible(true);
        return $reflection->getValue($job) === $userId;
    }
}
