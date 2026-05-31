<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\DocumentStoreServiceInterface;
use App\Jobs\CreateImportJobsForDirectory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class QueueControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set APP_KEY for testing
        $this->app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));

        // Create an admin user for authentication
        $adminUser = new \App\Auth\DocumentstoreUser([
            'id' => 'admin-user',
            'name' => 'Test Admin',
            'email' => 'admin@test.com',
            'role' => 'admin',
            'permissions' => ['admin.*'],
        ]);

        // Authenticate as admin user
        $this->actingAs($adminUser);
        $this->withHeader('Accept', 'application/json');
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
            \App\Http\Middleware\ResolveLibraryProfileFromHost::class,
        ]);

        $defaultDocumentStore = Mockery::mock(DocumentStoreServiceInterface::class);
        $defaultDocumentStore->shouldReceive('isAdmin')->andReturn(true);
        $this->app->instance(DocumentStoreServiceInterface::class, $defaultDocumentStore);
    }

    public function test_status_returns_worker_and_pending_jobs()
    {
        // Mock the Cache facade
        Cache::spy();
        Cache::shouldReceive('get')->with('queue_worker_heartbeat')->andReturn(now());

        // Mock the DocumentStoreService
        $mockDocumentStore = Mockery::mock(DocumentStoreServiceInterface::class);
        $mockDocumentStore->shouldReceive('isAdmin')->andReturn(true);
        $mockDocumentStore->shouldReceive('getJobCount')->andReturn(3);
        $this->app->instance(DocumentStoreServiceInterface::class, $mockDocumentStore);

        $response = $this->get('/admin/queue/status');
        $response->assertStatus(200)
            ->assertJson([
                'workerRunning' => true,
                'pendingJobs' => 3,
            ]);
    }

    public function test_start_worker_sets_heartbeat_and_returns_started()
    {
        Cache::spy();
        Cache::shouldReceive('put')->with('queue_worker_heartbeat', true, 60);
        $response = $this->post('/admin/queue/start');
        $response->assertStatus(200)
            ->assertJson(['started' => true]);
    }

    public function test_clear_deletes_all_jobs()
    {
        $mockDocumentStore = Mockery::mock(DocumentStoreServiceInterface::class);
        $mockDocumentStore->shouldReceive('isAdmin')->andReturn(true);
        $mockDocumentStore->shouldReceive('clearJobs')->with(true)->andReturn(true);
        $this->app->instance(DocumentStoreServiceInterface::class, $mockDocumentStore);

        $response = $this->post('/admin/queue/clear', ['confirm' => true]);
        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_clear_requires_confirmation()
    {
        $mockDocumentStore = Mockery::mock(DocumentStoreServiceInterface::class);
        $mockDocumentStore->shouldReceive('isAdmin')->andReturn(true);
        $mockDocumentStore->shouldNotReceive('clearJobs');
        $this->app->instance(DocumentStoreServiceInterface::class, $mockDocumentStore);

        $response = $this->post('/admin/queue/clear');
        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Confirmation is required to clear jobs.',
            ]);
    }

    public function test_bulk_import_books_queues_jobs_and_skips_existing()
    {
        // Mock the DocumentStoreService to return no existing jobs and books
        $mockDocumentStore = Mockery::mock(DocumentStoreServiceInterface::class);
        $mockDocumentStore->shouldReceive('listJobs')->andReturn([]);
        $mockDocumentStore->shouldReceive('getJobs')->andReturn([]);
        $mockDocumentStore->shouldReceive('bookExistsByDirectoryPath')->andReturn(false);
        $mockDocumentStore->shouldReceive('jobExistsByDirectoryPath')->andReturn(false);
        $this->app->instance(DocumentStoreServiceInterface::class, $mockDocumentStore);

        // Create a temporary directory structure for testing within the expected storage path
        $testDir = sys_get_temp_dir() . '/librarian_test_' . uniqid();
        mkdir($testDir, 0755, true);
        mkdir($testDir . '/subdir', 0755, true);
        mkdir($testDir . '/subdir/book1', 0755, true);
        file_put_contents($testDir . '/subdir/book1/metadata.abs', '{"title": "Test Book"}');

        // Configure books disk root to our test directory — controller prepends this to the relative dir
        $this->app['config']->set('filesystems.disks.books.root', $testDir);

        try {
            // Pass the relative path (the controller prepends the books disk root)
            $response = $this->post('/admin/books/bulk-import', [
                'dir' => 'subdir',
            ]);

            // Debug validation errors
            // if ($response->getStatusCode() === 422) {
            // }

            $response->assertStatus(200);

            if ($response->status() === 200) {
                $response->assertJsonStructure([
                   'message',
                   'skipped',
                   'queued_dirs',
                ]);
            }
        } finally {
            // Clean up test directory
            if (file_exists($testDir . '/subdir/book1/metadata.abs')) {
                unlink($testDir . '/subdir/book1/metadata.abs');
            }
            if (is_dir($testDir . '/subdir/book1')) {
                rmdir($testDir . '/subdir/book1');
            }
            if (is_dir($testDir . '/subdir')) {
                rmdir($testDir . '/subdir');
            }
            if (is_dir($testDir)) {
                rmdir($testDir);
            }
        }
    }

    public function test_bulk_import_books_from_dir_dispatches_job()
    {
        // Fake the queue to prevent actual job execution
        Queue::fake();

        // This simply checks the endpoint returns the right response (job dispatching is not tested here)
        $response = $this->post('/admin/books/bulk-import-dir', [
            'dir' => 'test',
        ]);
        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Queued job to scan and import all book directories.',
            ]);

        // Verify that the job was dispatched
        Queue::assertPushed(CreateImportJobsForDirectory::class);
    }
}
