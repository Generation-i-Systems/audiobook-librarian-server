<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that the /me endpoint returns the authenticated user's name and email.
     */
    public function testMeEndpointReturnsUserNameAndEmail(): void
    {
        // Create a test user
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'role' => 'library-user',
        ]);

        // Create a personal access token and authenticate via Authorization header
        $token = $user->createToken('api-token')->plainTextToken;
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v1/me');

        // Assert the response is successful
        $response->assertStatus(200);

        // Assert the response contains only name and email
        $response->assertJson([
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
        ]);

        // Assert the response does not contain sensitive information
        $response->assertJsonMissing([
            'password',
            'remember_token',
            'id',
            'created_at',
            'updated_at',
        ]);
    }

    /**
     * Test that the /me endpoint requires authentication.
     */
    public function testMeEndpointRequiresAuthentication(): void
    {
        // Make a GET request to the /me endpoint without authentication
        $response = $this->getJson('/api/v1/me');

        // Assert the response is unauthorized
        $response->assertStatus(401);
        $response->assertJson([
            'error' => 'Unauthorized',
        ]);
    }

    /**
     * Test that the /me endpoint works with library-user role.
     */
    public function testMeEndpointWorksWithLibraryUserRole(): void
    {
        $user = User::factory()->create([
            'name' => "Test library user",
            'email' => "test.libraryuser@example.com",
            'role' => 'library-user',
        ]);

        $token = $user->createToken('api-token')->plainTextToken;
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v1/me');

        $response->assertStatus(200)
            ->assertJson([
                'name' => "Test library user",
                'email' => "test.libraryuser@example.com",
            ]);
    }

    /**
     * Test that the /me endpoint works with admin role.
     */
    public function testMeEndpointWorksWithAdminRole(): void
    {
        $user = User::factory()->create([
            'name' => "Test admin",
            'email' => "test.admin@example.com",
            'role' => 'admin',
        ]);

        $token = $user->createToken('api-token')->plainTextToken;
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v1/me');

        $response->assertStatus(200)
            ->assertJson([
                'name' => "Test admin",
                'email' => "test.admin@example.com",
            ]);
    }

    /**
     * Test that the /me endpoint handles users with null names gracefully.
     */
    public function testMeEndpointHandlesNullName(): void
    {
        // Create a test user with empty name (column is NOT NULL)
        $user = User::factory()->create([
            'name' => '',
            'email' => 'no.name@example.com',
            'role' => 'library-user',
        ]);

        // Create a token and authenticate via Authorization header
        $token = $user->createToken('api-token')->plainTextToken;
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v1/me');

        // Assert the response is successful
        $response->assertStatus(200);

        // Assert the response contains empty name and correct email
        $response->assertJson([
            'name' => '',
            'email' => 'no.name@example.com',
        ]);
    }

    /**
     * Test that the /me endpoint works with expired tokens (should fail).
     */
    public function testMeEndpointWithInvalidToken(): void
    {
        // Make a GET request with an invalid token
        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid-token-here',
        ])->getJson('/api/v1/me');

        // Assert the response is unauthorized
        $response->assertStatus(401);
        $response->assertJson([
            'error' => 'Invalid or expired token',
        ]);
    }
}
