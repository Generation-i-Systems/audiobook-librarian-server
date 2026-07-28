<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    #[Test]
    public function testRejectsRequestWithNoIdToken(): void
    {
        $response = $this->postJson('/api/v1/auth/google', [
            'googleId' => 'attacker-supplied-id',
            'email' => 'victim@example.com',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['message' => 'Missing idToken']);
    }

    #[Test]
    public function testRejectsRequestWithNoIdTokenSentinel(): void
    {
        $response = $this->postJson('/api/v1/auth/google', [
            'idToken' => 'NO_ID_TOKEN_AVAILABLE',
            'googleId' => 'attacker-supplied-id',
            'email' => 'victim@example.com',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['message' => 'Missing idToken']);
    }

    #[Test]
    public function testRejectsMalformedIdTokenWithoutTrustingSuppliedGoogleIdAndEmail(): void
    {
        $response = $this->postJson('/api/v1/auth/google', [
            'idToken' => 'not-a-real-jwt',
            'googleId' => 'attacker-supplied-id',
            'email' => 'victim@example.com',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Invalid Google ID token']);
    }
}
