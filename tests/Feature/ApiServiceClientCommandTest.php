<?php

namespace Tests\Feature;

use App\Console\Commands\ApiServiceClient;
use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    public function itValidatesRequiredUrlArgument()
    {
        $this->artisan('api:client')
            ->expectsOutput('Error: URL argument is required')
            ->assertExitCode(1);
    }

    /** @test */
    public function itValidatesInvalidJsonData()
    {
        $this->artisan('api:client', [
            'url' => '/api/test',
            '--method' => 'POST',
            '--data' => '{invalid json}'
        ])
            ->expectsOutput('Error: Invalid JSON data: Syntax error')
            ->assertExitCode(1);
    }

    /** @test */
    public function itHandlesInvalidUrls()
    {
        $this->artisan('api:client', ['url' => 'invalid-url'])
            ->expectsOutput('Error: Invalid URL format')
            ->assertExitCode(1);
    }
}

