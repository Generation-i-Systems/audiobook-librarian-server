<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JobControllerRetryCancelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function testRetryDoesNotMutateJobStatusAndReportsNotImplemented(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

        $mockStore = Mockery::mock(DocumentStoreServiceInterface::class);
        $mockStore->shouldReceive('getJob')->with('job-1')->andReturn([
            'id' => 'job-1',
            'status' => 'failed',
            'log' => [],
        ]);
        $mockStore->shouldNotReceive('updateJob');
        $this->app->instance(DocumentStoreServiceInterface::class, $mockStore);

        $response = $this->actingAs($admin)->post('/admin/jobs/job-1/retry');

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    #[Test]
    public function testCancelDoesNotMutateJobStatusAndReportsNotImplemented(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

        $mockStore = Mockery::mock(DocumentStoreServiceInterface::class);
        $mockStore->shouldReceive('getJob')->with('job-2')->andReturn([
            'id' => 'job-2',
            'status' => 'processing',
            'log' => [],
        ]);
        $mockStore->shouldNotReceive('updateJob');
        $this->app->instance(DocumentStoreServiceInterface::class, $mockStore);

        $response = $this->actingAs($admin)->post('/admin/jobs/job-2/cancel');

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }
}
