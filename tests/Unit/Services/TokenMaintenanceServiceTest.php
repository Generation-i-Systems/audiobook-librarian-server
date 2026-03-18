<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Job;
use App\Services\TokenMaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TokenMaintenanceServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function apiTokenHelpersCreateFetchAndDeleteTokens(): void
    {
        $service = new TokenMaintenanceService();

        $tokenId = $service->createApiToken([
            'user_id' => '42',
            'token' => 'token-value-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNotNull($tokenId);

        $token = $service->getApiTokenByValue('token-value-1');
        $this->assertSame('42', $token['user_id']);

        $this->assertTrue($service->deleteApiTokenByValue('token-value-1'));
        $this->assertNull($service->getApiTokenByValue('token-value-1'));
    }

    #[Test]
    public function cleanupOldJobsDeletesOnlyTerminalJobsOlderThanThreshold(): void
    {
        $service = new TokenMaintenanceService();

        $oldCompleted = Job::query()->create([
            'type' => 'import',
            'status' => 'completed',
            'payload' => ['old' => true],
        ]);
        $oldCompleted->created_at = now()->subDays(10);
        $oldCompleted->updated_at = now()->subDays(10);
        $oldCompleted->save();

        $oldRunning = Job::query()->create([
            'type' => 'import',
            'status' => 'running',
            'payload' => ['old' => true],
        ]);
        $oldRunning->created_at = now()->subDays(10);
        $oldRunning->updated_at = now()->subDays(10);
        $oldRunning->save();

        $recentFailed = Job::query()->create([
            'type' => 'scan',
            'status' => 'failed',
            'payload' => ['recent' => true],
        ]);
        $recentFailed->created_at = now()->subDay();
        $recentFailed->updated_at = now()->subDay();
        $recentFailed->save();

        $deleted = $service->cleanupOldJobs(7);

        $this->assertSame(1, $deleted);
        $this->assertSame(2, Job::query()->count());
    }
}
