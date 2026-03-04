<?php

namespace Tests\Feature\Controllers\Admin;

use App\Auth\DocumentstoreUser;
use App\Contracts\DocumentStoreServiceInterface;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LibraryRepairControllerRefreshTest extends TestCase
{
    use RefreshDatabase;

    private DocumentstoreUser $admin;
    private $documentStoreService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser();
        $this->actingAs($this->admin);

        // Mock the document store service
        $this->documentStoreService = Mockery::mock(DocumentStoreServiceInterface::class);
        $this->documentStoreService->shouldReceive('isAdmin')
            ->with($this->admin->getAuthIdentifier())
            ->andReturn(true);

        // Mock the methods needed for the index page
        $this->documentStoreService->shouldReceive('listLibraryRepairIssues')
            ->andReturn([]);
        $this->documentStoreService->shouldReceive('countLibraryRepairIssues')
            ->andReturn(0);

        $this->app->instance(DocumentStoreServiceInterface::class, $this->documentStoreService);
    }

    private function createAdminUser(): DocumentstoreUser
    {
        return new DocumentstoreUser([
            'id' => 'admin-user',
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
            'password' => bcrypt('password'),
        ]);
    }

    #[Test]
    public function refresh_triggers_library_repair_scan(): void
    {
        // Mock the Artisan call to avoid actually running the scan
        Artisan::shouldReceive('call')
            ->once()
            ->with('library:repair-scan', ['--no-attempt-fixes' => false]);

        $response = $this->post(route('admin.library-repair.refresh'));

        $response->assertRedirect(route('admin.library-repair.index'));
        $response->assertSessionHas('success', 'Library repair scan started. The page will refresh to show updated results.');
    }

    #[Test]
    public function index_displays_last_run_date(): void
    {
        // Set a last run date
        SystemSetting::set('library_repair_last_run', '2026-01-05T10:00:00+00:00');

        $response = $this->get(route('admin.library-repair.index'));

        $response->assertOk();
        $response->assertViewHas('lastRunDate', '2026-01-05T10:00:00+00:00');
    }

    #[Test]
    public function index_handles_null_last_run_date(): void
    {
        // Ensure no last run date is set
        SystemSetting::where('key', 'library_repair_last_run')->delete();

        $response = $this->get(route('admin.library-repair.index'));

        $response->assertOk();
        $response->assertViewHas('lastRunDate', null);
    }

    #[Test]
    public function refresh_handles_errors_gracefully(): void
    {
        // Mock Artisan to throw an exception
        Artisan::shouldReceive('call')
            ->once()
            ->andThrow(new \Exception('Test error'));

        $response = $this->post(route('admin.library-repair.refresh'));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Unable to start library repair scan: Test error');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
