<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Job;
use App\Services\JobWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JobWorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function listJobsFiltersAndPaginatesResults(): void
    {
        $service = new JobWorkflowService();

        $first = Job::query()->create([
            'type' => 'import',
            'status' => 'pending',
            'payload' => ['directoryPath' => 'library/a'],
            'error_message' => null,
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);

        $second = Job::query()->create([
            'type' => 'import',
            'status' => 'running',
            'payload' => ['directoryPath' => 'library/b'],
            'error_message' => null,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $third = Job::query()->create([
            'type' => 'scan',
            'status' => 'pending',
            'payload' => ['directoryPath' => 'library/c'],
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $importJobs = $service->listJobs('import', null, 10, 'created_at', 'DESC');

        $this->assertCount(2, $importJobs);
        $this->assertSame(['import', 'import'], array_column($importJobs, 'type'));

        $limited = $service->listJobs(null, null, 1, 'created_at', 'DESC');
        $this->assertCount(1, $limited);
    }

    #[Test]
    public function updateAndClearJobMethodsManageRows(): void
    {
        $service = new JobWorkflowService();

        $job = Job::query()->create([
            'type' => 'import',
            'status' => 'pending',
            'payload' => ['directoryPath' => 'library/a'],
            'error_message' => null,
        ]);

        $this->assertTrue($service->updateJob((string) $job->id, ['status' => 'running']));
        $this->assertSame('running', Job::query()->findOrFail($job->id)->status);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('explicit destructive-operation confirmation');

        $service->clearJobs();
    }

    #[Test]
    public function clearJobsDeletesRowsWhenConfirmed(): void
    {
        $service = new JobWorkflowService();

        Job::query()->create([
            'type' => 'import',
            'status' => 'pending',
            'payload' => ['directoryPath' => 'library/a'],
            'error_message' => null,
        ]);

        $this->assertTrue($service->clearJobs(true));
        $this->assertSame(0, Job::query()->count());
    }
}
