<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\MySqlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MySqlServiceLoginTest extends TestCase
{
    use RefreshDatabase;

    private MySqlService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MySqlService::class);
    }

    public function testGetUserByCredentialsWithEmail()
    {
        $password = 'password';
        /** @var User $user */
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make($password),
        ]);

        $credentials = ['email' => 'test@example.com', 'password' => $password];
        $result = $this->service->getUserByCredentials($credentials);

        $this->assertNotNull($result);
        $this->assertEquals($user->getKey(), $result['id']);
    }

    public function testGetUserByCredentialsWithUsername()
    {
        $password = 'password';
        /** @var User $user */
        $user = User::factory()->create([
            'username' => 'testuser',
            'password' => Hash::make($password),
        ]);

        $credentials = ['username' => 'testuser', 'password' => $password];
        $result = $this->service->getUserByCredentials($credentials);

        $this->assertNotNull($result, 'Should find user by username');
        $this->assertEquals($user->getKey(), $result['id']);
    }
}
