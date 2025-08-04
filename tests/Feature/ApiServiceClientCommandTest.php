<?php

namespace Tests\Feature;

use App\Auth\DocumentstoreUser;
use App\Console\Commands\ApiServiceClient;
use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;

class ApiServiceClientCommandTest extends TestCase
{
    use RefreshDatabase;

    protected $mockDocumentStoreService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockDocumentStoreService = Mockery::mock(DocumentStoreServiceInterface::class);
        $this->app->instance(DocumentStoreServiceInterface::class, $this->mockDocumentStoreService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function itCanMakeApiCallWithDefaultAdminUser()
    {
        // Mock admin user data
        $adminUserData = [
            'id' => 'admin-123',
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin'
        ];

        $this->mockDocumentStoreService
            ->shouldReceive('getAdminUsers')
            ->once()
            ->andReturn([$adminUserData]);

        // Create a simple test route
        $this->app['router']->get('/api/test', function () {
            return response()->json(['message' => 'success', 'user' => Auth::user()->name]);
        });

        $this->artisan('api:client', ['url' => '/api/test'])
            ->expectsOutput('Making GET request as user: Admin User (admin-123)')
            ->expectsOutput('Request URI: /api/test')
            ->expectsOutput('Status Code: 200')
            ->assertExitCode(0);
    }

    /** @test */
    public function itCanMakeApiCallWithSpecificUser()
    {
        $specificUserData = [
            'id' => 'user-456',
            'name' => 'Specific User',
            'email' => 'user@example.com',
            'role' => 'user'
        ];

        $this->mockDocumentStoreService
            ->shouldReceive('getUserById')
            ->with('user-456')
            ->once()
            ->andReturn($specificUserData);

        // Create a simple test route
        $this->app['router']->get('/api/test', function () {
            return response()->json(['message' => 'success', 'user' => Auth::user()->name]);
        });

        $this->artisan('api:client', ['url' => '/api/test', '--user' => 'user-456'])
            ->expectsOutput('Making GET request as user: Specific User (user-456)')
            ->expectsOutput('Request URI: /api/test')
            ->expectsOutput('Status Code: 200')
            ->assertExitCode(0);
    }

    /** @test */
    public function itFallsBackToAdminUserWhenSpecificUserNotFound()
    {
        $adminUserData = [
            'id' => 'admin-123',
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin'
        ];

        $this->mockDocumentStoreService
            ->shouldReceive('getUserById')
            ->with('nonexistent-user')
            ->once()
            ->andReturn(null);

        $this->mockDocumentStoreService
            ->shouldReceive('getAdminUsers')
            ->once()
            ->andReturn([$adminUserData]);

        // Create a simple test route
        $this->app['router']->get('/api/test', function () {
            return response()->json(['message' => 'success']);
        });

        $this->artisan('api:client', ['url' => '/api/test', '--user' => 'nonexistent-user'])
            ->expectsOutput("User with ID 'nonexistent-user' not found, falling back to first admin user")
            ->expectsOutput('Making GET request as user: Admin User (admin-123)')
            ->assertExitCode(0);
    }

    /** @test */
    public function itHandlesFullUrlsCorrectly()
    {
        $adminUserData = [
            'id' => 'admin-123',
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin'
        ];

        $this->mockDocumentStoreService
            ->shouldReceive('getAdminUsers')
            ->once()
            ->andReturn([$adminUserData]);

        // Create a simple test route
        $this->app['router']->get('/api/v1/books', function () {
            return response()->json(['books' => []]);
        });

        $fullUrl = 'https://books.thelin.org/api/v1/books?page=1&per_page=10';

        $this->artisan('api:client', ['url' => $fullUrl])
            ->expectsOutput('Request URI: /api/v1/books?page=1&per_page=10')
            ->assertExitCode(0);
    }

    /** @test */
    public function itHandlesPostRequestsWithJsonData()
    {
        $adminUserData = [
            'id' => 'admin-123',
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin'
        ];

        $this->mockDocumentStoreService
            ->shouldReceive('getAdminUsers')
            ->once()
            ->andReturn([$adminUserData]);

        // Create a test POST route
        $this->app['router']->post('/api/test', function (\Illuminate\Http\Request $request) {
            return response()->json([
                'received_data' => $request->all(),
                'method' => $request->method()
            ]);
        });

        $jsonData = '{"name": "Test Book", "author": "Test Author"}';

        $this->artisan('api:client', [
            'url' => '/api/test',
            '--method' => 'POST',
            '--data' => $jsonData
        ])
            ->expectsOutput('Making POST request as user: Admin User (admin-123)')
            ->expectsOutput('Status Code: 200')
            ->assertExitCode(0);
    }

    /** @test */
    public function itHandlesInvalidJsonData()
    {
        $adminUserData = [
            'id' => 'admin-123',
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin'
        ];

        $this->mockDocumentStoreService
            ->shouldReceive('getAdminUsers')
            ->once()
            ->andReturn([$adminUserData]);

        $invalidJson = '{"name": "Test Book", "author":}'; // Invalid JSON

        $this->artisan('api:client', [
            'url' => '/api/test',
            '--method' => 'POST',
            '--data' => $invalidJson
        ])
            ->expectsOutput('Error: Invalid JSON data: Syntax error')
            ->assertExitCode(1);
    }

    /** @test */
    public function itHandlesInvalidUrls()
    {
        $adminUserData = [
            'id' => 'admin-123',
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin'
        ];

        $this->mockDocumentStoreService
            ->shouldReceive('getAdminUsers')
            ->once()
            ->andReturn([$adminUserData]);

        $invalidUrl = 'not-a-valid-url';

        $this->artisan('api:client', ['url' => $invalidUrl])
            ->expectsOutput('Error: Invalid URL format: not-a-valid-url')
            ->assertExitCode(1);
    }

    /** @test */
    public function itHandlesNoAdminUsersAvailable()
    {
        $this->mockDocumentStoreService
            ->shouldReceive('getAdminUsers')
            ->once()
            ->andReturn([]);

        $this->artisan('api:client', ['url' => '/api/test'])
            ->expectsOutput('Could not find user to impersonate')
            ->assertExitCode(1);
    }

    /** @test */
    public function itHandles404Responses()
    {
        $adminUserData = [
            'id' => 'admin-123',
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin'
        ];

        $this->mockDocumentStoreService
            ->shouldReceive('getAdminUsers')
            ->once()
            ->andReturn([$adminUserData]);

        $this->artisan('api:client', ['url' => '/api/nonexistent'])
            ->expectsOutput('Status Code: 404')
            ->assertExitCode(0);
    }

    /** @test */
    public function itSupportsNoColorOption()
    {
        $adminUserData = [
            'id' => 'admin-123',
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin'
        ];

        $this->mockDocumentStoreService
            ->shouldReceive('getAdminUsers')
            ->once()
            ->andReturn([$adminUserData]);

        // Create a simple test route
        $this->app['router']->get('/api/test', function () {
            return response()->json(['message' => 'success']);
        });

        $this->artisan('api:client', ['url' => '/api/test', '--no-color' => true])
            ->expectsOutput('Status Code: 200')
            ->assertExitCode(0);
    }

    /** @test */
    public function itDisplaysResponseHeaders()
    {
        $adminUserData = [
            'id' => 'admin-123',
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin'
        ];

        $this->mockDocumentStoreService
            ->shouldReceive('getAdminUsers')
            ->once()
            ->andReturn([$adminUserData]);

        // Create a simple test route
        $this->app['router']->get('/api/test', function () {
            return response()->json(['message' => 'success'])
                ->header('Content-Type', 'application/json');
        });

        $this->artisan('api:client', ['url' => '/api/test'])
            ->expectsOutput('Response Headers:')
            ->assertExitCode(0);
    }
}
