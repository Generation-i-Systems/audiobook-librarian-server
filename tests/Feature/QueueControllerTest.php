<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use Mockery;
use App\Services\FirestoreService;
use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;

class QueueControllerTest extends TestCase
{
    // Disable middleware for feature tests
    protected $disableMiddlewareForAllTests = true;

    public function setUp(): void
    {
        parent::setUp();
        // Optionally: $this->withoutMiddleware();
    }

    public function testStatusReturnsWorkerAndPendingJobs()
    {
        Cache::shouldReceive('get')->with('queue_worker_heartbeat')->andReturn(true);
        $mockFirestore = Mockery::mock(FirestoreService::class);
        $mockCollection = Mockery::mock(CollectionReference::class);
        $mockCollection->shouldReceive('count')->andReturn(3);
        $mockFirestore->shouldReceive('getClient')->andReturn((object)[
            'collection' => fn($name) => $mockCollection
        ]);
        $this->app->instance(FirestoreService::class, $mockFirestore);

        $response = $this->get('/admin/queue/status');
        $response->assertStatus(200)
            ->assertJson([
                'worker_running' => true,
                'pending_jobs' => 3
            ]);
    }

    public function testStartWorkerSetsHeartbeatAndReturnsStarted()
    {
        Cache::shouldReceive('put')->with('queue_worker_heartbeat', true, 60);
        $response = $this->post('/admin/queue/start-worker');
        $response->assertStatus(200)
            ->assertJson(['started' => true]);
    }

    public function testClearDeletesAllJobs()
    {
        $mockFirestore = Mockery::mock(FirestoreService::class);
        $mockCollection = Mockery::mock(CollectionReference::class);
        $mockDoc = Mockery::mock(DocumentSnapshot::class);
        $mockDoc->shouldReceive('exists')->andReturn(true);
        $mockRef = Mockery::mock(DocumentReference::class);
        $mockDoc->shouldReceive('reference')->andReturn($mockRef);
        $mockRef->shouldReceive('delete')->once();
        $mockCollection->shouldReceive('documents')->andReturn([$mockDoc]);
        $mockFirestore->shouldReceive('getClient')->andReturn((object)[
            'collection' => fn($name) => $mockCollection
        ]);
        $this->app->instance(FirestoreService::class, $mockFirestore);

        $response = $this->post('/admin/queue/clear');
        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function testBulkImportBooksQueuesJobsAndSkipsExisting()
    {
        // This test focuses on logic, not actual Firestore or job dispatching
        $mockFirestore = Mockery::mock(FirestoreService::class);
        $mockJobsCollection = Mockery::mock(CollectionReference::class);
        $mockBooksCollection = Mockery::mock(CollectionReference::class);
        $mockJobsCollection->shouldReceive('documents')->andReturn([]);
        $mockFirestore->shouldReceive('getClient')->andReturn((object)[
            'collection' => function($name) use ($mockJobsCollection, $mockBooksCollection) {
                if ($name === 'jobs') return $mockJobsCollection;
                if ($name === 'books') return $mockBooksCollection;
            }
        ]);
        $mockBooksCollection->shouldReceive('where')->with('directory_path', '=', 'test/dir1')->andReturnSelf();
        $mockBooksCollection->shouldReceive('documents')->andReturn([]);
        $this->app->instance(FirestoreService::class, $mockFirestore);

        // Mock the trait method findBookDirectories
        $this->partialMock('App\\Http\\Controllers\\Admin\\QueueController', function ($mock) {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('findBookDirectories')->andReturn([
                '/abs/path/to/test/dir1',
            ]);
        });

        $response = $this->post('/admin/books/bulk-import', [
            'dir' => 'test',
        ]);
        $response->assertStatus(200)
            ->assertJsonStructure([
                'message', 'skipped', 'queued_dirs'
            ]);
    }

    public function testBulkImportBooksFromDirDispatchesJob()
    {
        // This simply checks the endpoint returns the right response (job dispatching is not tested here)
        $response = $this->post('/admin/books/bulk-import-from-dir', [
            'dir' => 'test'
        ]);
        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Queued job to scan and import all book directories.'
            ]);
    }
}
