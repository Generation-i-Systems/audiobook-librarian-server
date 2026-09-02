<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\ApiTokenController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTokenControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testProfilePageRendersTokenSection(): void
    {
        $user = User::factory()->create();
        $user->createToken('ABB Bridge', [ApiTokenController::SERVICE_TOKEN_ABILITY]);

        $response = $this->actingAs($user)->get('/profile');

        $response->assertOk();
        $response->assertSee('API / Service Tokens');
        $response->assertSee('ABB Bridge');
    }

    public function testProfilePageDoesNotListNonServiceTokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('debug-check');
        $user->createToken('api-service-client-temp', ['*']);

        $response = $this->actingAs($user)->get('/profile');

        $response->assertOk();
        $response->assertDontSee('debug-check');
        $response->assertDontSee('api-service-client-temp');
    }

    public function testUserCanCreateAndSeeANewToken(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/profile/tokens', [
            'name' => 'ABB Bridge',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('new_token');
        $response->assertSessionHas('new_token_name', 'ABB Bridge');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
            'name' => 'ABB Bridge',
        ]);
    }

    public function testUserCanRevokeTheirOwnServiceToken(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('ABB Bridge', [ApiTokenController::SERVICE_TOKEN_ABILITY]);

        $response = $this->actingAs($user)->delete('/profile/tokens/' . $token->accessToken->id);

        $response->assertRedirect();
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->accessToken->id,
        ]);
    }

    public function testUserCannotRevokeANonServiceToken(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('debug-check', ['*']);

        $response = $this->actingAs($user)->delete('/profile/tokens/' . $token->accessToken->id);

        $response->assertForbidden();
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token->accessToken->id,
        ]);
    }

    public function testUserCannotRevokeAnotherUsersToken(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $owner->createToken('ABB Bridge', [ApiTokenController::SERVICE_TOKEN_ABILITY]);

        $response = $this->actingAs($otherUser)->delete('/profile/tokens/' . $token->accessToken->id);

        $response->assertForbidden();
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token->accessToken->id,
        ]);
    }
}
