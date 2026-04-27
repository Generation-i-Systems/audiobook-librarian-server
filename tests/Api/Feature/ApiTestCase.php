<?php

declare(strict_types=1);

namespace Tests\Api\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test user and authenticate
        $this->user = User::factory()->create([
            'role' => 'library-user',
            'email_verified_at' => now(),
        ]);

        // Authenticate the user for guards that rely on session
        Sanctum::actingAs($this->user);

        // Also create and attach a personal access token so our custom api.auth
        // middleware (which expects a Bearer token) authorizes requests.
        $token = $this->user->createToken('api-token')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer ' . $token);
    }
}
