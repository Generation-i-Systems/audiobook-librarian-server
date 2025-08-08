<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

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
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        // Authenticate the user
        Sanctum::actingAs($this->user);
    }
}
