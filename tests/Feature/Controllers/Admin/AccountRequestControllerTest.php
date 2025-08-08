<?php

namespace Tests\Feature\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use Mockery;
use Tests\TestCase;

class AccountRequestControllerTest extends TestCase
{
    protected $mockDocumentStoreService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockDocumentStoreService = Mockery::mock(DocumentStoreServiceInterface::class);
        $this->app->instance(DocumentStoreServiceInterface::class, $this->mockDocumentStoreService);

        // Mock authentication for admin user
        $this->mockDocumentStoreService->shouldReceive('isAdmin')
            ->andReturn(true);

        // Set up session
        $this->withSession([
            '_token' => 'test-token',
            '_flash' => ['old' => [], 'new' => []]
        ]);

        // Mock the user
        $this->actingAs(new \App\Models\User(['id' => 1, 'name' => 'Admin', 'email' => 'admin@example.com']));

        // Mock middleware
        $this->withoutMiddleware();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testIndex()
    {
        // Mock the getPendingAccountRequests method
        $this->mockDocumentStoreService->shouldReceive('getPendingAccountRequests')
            ->once()
            ->andReturn([
                [
                    'id' => '1',
                    'name' => 'Test User',
                    'email' => 'test@example.com',
                    'username' => 'testuser',
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            ]);

        // Make the request
        $response = $this->get(route('admin.account_requests.index'));

        // Assert the response
        $response->assertStatus(200);
        $response->assertViewIs('admin.account_requests.index');
        $response->assertViewHas('accountRequests');
    }

    public function testApproveSuccess()
    {
        // Mock the getAccountRequest method
        $this->mockDocumentStoreService->shouldReceive('getAccountRequest')
            ->with('1')
            ->once()
            ->andReturn([
                'id' => '1',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'created_at' => now(),
            ]);

        // Mock the approveAccountRequest method
        $this->mockDocumentStoreService->shouldReceive('approveAccountRequest')
            ->with('1')
            ->once()
            ->andReturn(true);

        // Make the request
        $response = $this->withSession(['_flash' => ['new' => [], 'old' => []]])
            ->put(route('admin.account_requests.update', ['account_request' => '1']));

        // Assert the response
        $response->assertSessionHas('success');
        $response->assertRedirect();
    }

    public function testApproveNotFound()
    {
        // Mock the getAccountRequest method
        $this->mockDocumentStoreService->shouldReceive('getAccountRequest')
            ->with('999')
            ->once()
            ->andReturn(null);

        // Make the request
        $response = $this->withSession(['_flash' => ['new' => [], 'old' => []]])
            ->put(route('admin.account_requests.update', ['account_request' => '999']));

        // Flash errors manually since we're testing the controller's behavior
        $response->assertRedirect();
        $this->assertTrue(session()->has('errors'));
    }

    public function testApproveFailed()
    {
        // Mock the getAccountRequest method
        $this->mockDocumentStoreService->shouldReceive('getAccountRequest')
            ->once()
            ->with('1')
            ->andReturn([
                'id' => '1',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'username' => 'testuser',
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        // Mock the approveAccountRequest method
        $this->mockDocumentStoreService->shouldReceive('approveAccountRequest')
            ->once()
            ->with('1')
            ->andReturn(false);

        // Make the request
        $response = $this->put(route('admin.account_requests.update', ['account_request' => '1']));

        // Assert the response
        $response->assertSessionHasErrors();
        $response->assertRedirect();
    }

    public function testRejectSuccess()
    {
        // Mock the rejectAccountRequest method
        $this->mockDocumentStoreService->shouldReceive('rejectAccountRequest')
            ->once()
            ->with('1')
            ->andReturn(true);

        // Make the request
        $response = $this->delete(route('admin.account_requests.destroy', ['account_request' => '1']));

        // Assert the response
        $response->assertSessionHas('success', 'Account request rejected!');
        $response->assertRedirect();
    }

    public function testRejectFailed()
    {
        // Mock the rejectAccountRequest method
        $this->mockDocumentStoreService->shouldReceive('rejectAccountRequest')
            ->once()
            ->with('1')
            ->andReturn(false);

        // Make the request
        $response = $this->delete(route('admin.account_requests.destroy', ['account_request' => '1']));

        // Assert the response
        $response->assertSessionHasErrors();
        $response->assertRedirect();
    }
}
