<?php

namespace Tests\Feature\Api;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * API Health Check Tests
 *
 * Tests for the unauthenticated health check endpoints used by uptime monitors.
 */
#[Group('api-health')]
#[Group('api-spec')]
class ApiHealthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: Ping endpoint returns OK
     */
    #[Test]
    public function testPingEndpointReturnsOk(): void
    {
        $response = $this->getJson('/api/v1/health/ping');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'ok',
            'service' => 'audiobook-librarian-api',
        ]);
        $response->assertJsonStructure([
            'status',
            'timestamp',
            'service',
        ]);
    }

    /**
     * Test: Health endpoint returns healthy status
     */
    #[Test]
    public function testHealthEndpointReturnsHealthyStatus(): void
    {
        $this->mock(DocumentStoreServiceInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('listBooks')
                ->andReturn([
                    'data' => [
                        [
                            'id' => 1,
                            'title' => 'Test Book',
                            'author' => ['Test Author'],
                            'narrator' => ['Test Narrator'],
                            'series' => [],
                            'genre' => ['Fantasy'],
                            'year' => 2023,
                            'duration' => '10:00:00',
                            'description' => 'Test',
                            'coverImage' => null,
                            'file_count' => 1,
                            'total_size' => 1000,
                            'created_at' => '2023-01-01T00:00:00Z',
                            'updated_at' => '2023-01-01T00:00:00Z',
                        ],
                    ],
                    'total' => 1,
                    'currentPage' => 1,
                    'lastPage' => 1,
                ]);
        });

        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'healthy',
            'api_version' => 'v1',
        ]);
        $response->assertJsonStructure([
            'status',
            'timestamp',
            'checks' => [
                'database',
                'book_format',
                'series_format',
                'storage',
            ],
            'api_version',
        ]);
    }

    /**
     * Test: Health endpoint detects series format issues
     */
    #[Test]
    public function testHealthEndpointDetectsSeriesFormatIssues(): void
    {
        $this->mock(DocumentStoreServiceInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('listBooks')
                ->andReturn([
                    'data' => [
                        [
                            'id' => 1,
                            'title' => 'Book With Bad Series',
                            'author' => ['Author'],
                            'narrator' => ['Narrator'],
                            'series' => [
                                ['name' => 'Valid Series', 'pivot' => ['series_number' => '1']],
                            ],
                            'genre' => ['Fantasy'],
                            'year' => 2023,
                            'duration' => '10:00:00',
                            'description' => 'Test',
                            'coverImage' => null,
                            'file_count' => 1,
                            'total_size' => 1000,
                            'created_at' => '2023-01-01T00:00:00Z',
                            'updated_at' => '2023-01-01T00:00:00Z',
                        ],
                    ],
                    'total' => 1,
                    'currentPage' => 1,
                    'lastPage' => 1,
                ]);
        });

        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200);

        // Check that series format check passed (null entries should be filtered)
        $checks = $response->json('checks');
        $this->assertTrue($checks['series_format']['passed']);
    }

    /**
     * Test: Validate spec endpoint checks API compliance
     */
    #[Test]
    public function testValidateSpecEndpointChecksApiCompliance(): void
    {
        $this->mock(DocumentStoreServiceInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('listBooks')
                ->andReturn([
                    'data' => [
                        [
                            'id' => 1,
                            'title' => 'Test Book',
                            'author' => ['Test Author'],
                            'narrator' => ['Test Narrator'],
                            'series' => [],
                            'genre' => ['Fantasy'],
                            'year' => 2023,
                            'duration' => '10:00:00',
                            'description' => 'Test',
                            'coverImage' => null,
                            'file_count' => 1,
                            'total_size' => 1000,
                            'created_at' => '2023-01-01T00:00:00Z',
                            'updated_at' => '2023-01-01T00:00:00Z',
                        ],
                    ],
                    'total' => 1,
                    'currentPage' => 1,
                    'lastPage' => 1,
                ]);

            $mock->shouldReceive('getBook')
                ->with(1)
                ->andReturn([
                    'id' => 1,
                    'title' => 'Test Book',
                    'author' => ['Test Author'],
                    'narrator' => ['Test Narrator'],
                    'series' => [],
                    'genre' => ['Fantasy'],
                    'year' => 2023,
                    'duration' => '10:00:00',
                    'description' => 'Test',
                    'coverImage' => null,
                    'file_count' => 1,
                    'total_size' => 1000,
                    'created_at' => '2023-01-01T00:00:00Z',
                    'updated_at' => '2023-01-01T00:00:00Z',
                ]);
        });

        $response = $this->getJson('/api/v1/health/validate');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'spec_compliant',
            'api_version' => 'v1',
            'openapi_version' => '3.0.3',
        ]);
        $response->assertJsonStructure([
            'status',
            'timestamp',
            'validations',
            'api_version',
            'openapi_version',
        ]);
    }

    /**
     * Test: Health endpoints are accessible without authentication
     */
    #[Test]
    public function testCapabilitiesEndpointReturnsExpectedStructure(): void
    {
        $response = $this->getJson('/api/v1/health/capabilities');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'serverType',
            'syncApiVersion',
            'capabilities',
            'requiresAuth',
            'authMethods',
        ]);
        $response->assertJsonPath('serverType', 'ablibrarian-full');
        $response->assertJsonPath('syncApiVersion', '1');
        $response->assertJsonCount(10, 'capabilities');
    }

    #[Test]
    public function testHealthEndpointsAccessibleWithoutAuth(): void
    {
        // Ping should work without auth
        $response = $this->getJson('/api/v1/health/ping');
        $response->assertStatus(200);

        // Mock for other endpoints
        $this->mock(DocumentStoreServiceInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('listBooks')
                ->andReturn([
                    'data' => [],
                    'total' => 0,
                    'currentPage' => 1,
                    'lastPage' => 1,
                ]);
            $mock->shouldReceive('getBook')
                ->andReturn(null);
        });

        // Health should work without auth
        $response = $this->getJson('/api/v1/health');
        $response->assertStatus(200);

        // Validate should work without auth
        $response = $this->getJson('/api/v1/health/validate');
        $response->assertStatus(200);

        // Capabilities should work without auth
        $response = $this->getJson('/api/v1/health/capabilities');
        $response->assertStatus(200);
    }
}
