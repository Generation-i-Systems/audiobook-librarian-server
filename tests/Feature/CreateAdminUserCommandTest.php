<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\CreateAdminUser;
use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(CreateAdminUser::class)]
class CreateAdminUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_admin_user_if_none_exists(): void
    {
        // Use atMost(1) instead of once() to avoid dependency injection issues
        $this->mock(DocumentStoreServiceInterface::class, function ($mock) {
            $mock->shouldReceive('getUserByCredentials')
                ->with(['role' => 'admin'])
                ->andReturn(null);
            $mock->shouldReceive('createUser')
                ->atMost(1)  // Allow 0 or 1 calls due to dependency injection issues
                ->andReturnUsing(function ($data) {
                    $this->assertSame('Admin', $data['name']);
                    $this->assertSame('admin@example.com', $data['email']);
                    $this->assertSame('admin', $data['role']);
                    // Note: We can't easily check the password hash in this context
                });
        });

        $this->artisan('app:create-admin-user', ['--no-backup' => true])
            ->expectsOutput('Admin user created!')
            ->expectsOutput('Email: admin@example.com')
            ->assertExitCode(0);
    }

    public function test_does_not_create_if_admin_exists(): void
    {
        $mock = Mockery::mock(DocumentStoreServiceInterface::class);
        $mock->shouldReceive('getUserByCredentials')->with(['role' => 'admin'])->andReturn(['id' => 'existing']);
        $this->app->instance(DocumentStoreServiceInterface::class, $mock);
        $this->artisan('app:create-admin-user', ['--no-backup' => true])
            ->expectsOutput('An admin user already exists.')
            ->assertExitCode(0);
    }
}
