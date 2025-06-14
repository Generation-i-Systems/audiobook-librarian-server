<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\CreateAdminUser;
use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(CreateAdminUser::class)]
class CreateAdminUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function testCreatesAdminUserIfNoneExists(): void
    {
        $mock = Mockery::mock(DocumentStoreServiceInterface::class);
        $mock->shouldReceive('getUserByCredentials')->with(['role' => 'admin'])->andReturn(null);
        $mock->shouldReceive('createUser')->once()->andReturnUsing(function ($data) {
            $this->assertSame('Admin', $data['name']);
            $this->assertSame('admin@example.com', $data['email']);
            $this->assertSame('admin', $data['role']);
            $this->assertTrue(Hash::check($data['password'], Hash::make($data['password'])));
        });
        $this->app->instance(DocumentStoreServiceInterface::class, $mock);
        $this->artisan('app:create-admin-user')
            ->expectsOutput('Admin user created!')
            ->expectsOutput('Email: admin@example.com')
            ->assertExitCode(0);
    }

    public function testDoesNotCreateIfAdminExists(): void
    {
        $mock = Mockery::mock(DocumentStoreServiceInterface::class);
        $mock->shouldReceive('getUserByCredentials')->with(['role' => 'admin'])->andReturn(['id' => 'existing']);
        $this->app->instance(DocumentStoreServiceInterface::class, $mock);
        $this->artisan('app:create-admin-user')
            ->expectsOutput('An admin user already exists.')
            ->assertExitCode(0);
    }
}
