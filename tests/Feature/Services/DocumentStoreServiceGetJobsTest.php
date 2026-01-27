<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Job;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DocumentStoreServiceGetJobsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function getJobsReturnsQueueControllerFriendlyShape(): void
    {
        Job::query()->create([
            'type' => 'import',
            'status' => 'pending',
            'payload' => ['directoryPath' => 'foo/bar', 'attempts' => 0],
            'error_message' => null,
        ]);

        Job::query()->create([
            'type' => 'scan',
            'status' => 'running',
            'payload' => ['message' => 'working'],
            'error_message' => null,
        ]);

        $service = app(DocumentStoreServiceInterface::class);
        $jobs = $service->getJobs();

        $this->assertCount(2, $jobs);

        $first = $jobs[0];
        $this->assertIsArray($first);
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('type', $first);
        $this->assertArrayHasKey('status', $first);
        $this->assertArrayHasKey('data', $first);
        $this->assertArrayHasKey('startedAt', $first);

        $this->assertIsArray($first['data']);
    }
}
