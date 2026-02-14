<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Firebase\JWT\JWT;

class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['documentstore.driver' => 'mysql']);
        // Ensure configs are set for testing
        config(['services.facebook.client_id' => 'test-fb-app-id']);
        config(['services.apple.client_id' => 'test-apple-bundle-id']);
    }

    public function testFacebookLoginCreatesNewUser()
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'id' => '1234567890',
                'name' => 'Facebook User',
                'email' => 'fbuser@example.com',
                'picture' => [
                    'data' => [
                        'url' => 'http://example.com/fb-avatar.jpg'
                    ]
                ]
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/auth/facebook', [
            'accessToken' => 'valid-fb-token',
        ]);

        $response->assertStatus(403) // Registration returns 403 for unverified users
                 // Wait, MySqlService creates unverified users.
                 // And AuthController returns 403 "ACCOUNT_PENDING_APPROVAL" for unverified users.
                 ->assertJson([
                     'code' => 'ACCOUNT_PENDING_APPROVAL',
                 ]);

        $this->assertDatabaseHas('users', [
            'email' => 'fbuser@example.com',
            'facebook_id' => '1234567890',
            'name' => 'Facebook User',
        ]);
    }

    public function testFacebookLoginExistingUser()
    {
        $user = User::create([
            'name' => 'Existing User',
            'email' => 'fbuser@example.com',
            'username' => 'existinguser',
            'password' => 'password',
            'role' => 'user', // Verified
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'id' => '1234567890',
                'name' => 'Facebook User',
                'email' => 'fbuser@example.com',
                'picture' => [
                    'data' => [
                        'url' => 'http://example.com/fb-avatar.jpg'
                    ]
                ]
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/auth/facebook', [
            'accessToken' => 'valid-fb-token',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['token', 'authToken']);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'facebook_id' => '1234567890', // Should be updated
        ]);
    }

    public function testAppleLoginCreatesNewUser()
    {
        // 1. Generate RSA Key Pair
        $privateKey = openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($privateKey, $pemPrivateKey);
        $details = openssl_pkey_get_details($privateKey);

        // Convert to JWK (simplified for test)
        // Actually, since we use JWK::parseKeySet, we need to mock the JWKS response structure.
        // But verifying signature locally in test is complex.

        // Alternative: We can't easily mock static JWT::decode without an extension or Mockery on alias (hard).
        // BUT, we can make the test integration-style by generating a REAL token signed by a REAL key,
        // and mocking the JWKS endpoint to return the public component of that key.

        $n = base64_encode($details['rsa']['n']);
        $e = base64_encode($details['rsa']['e']);

        // Base64URL encode helpers
        $b64Url = function ($data) {
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        };

        $n_url = $b64Url($details['rsa']['n']);
        $e_url = $b64Url($details['rsa']['e']);

        $jwks = [
            'keys' => [
                [
                    'kty' => 'RSA',
                    'kid' => 'test-key-id',
                    'use' => 'sig',
                    'alg' => 'RS256',
                    'n' => $n_url,
                    'e' => $e_url,
                ]
            ]
        ];

        Http::fake([
            'appleid.apple.com/auth/keys' => Http::response($jwks, 200),
        ]);

        // Create JWT
        $payload = [
            'iss' => 'https://appleid.apple.com',
            'aud' => 'test-apple-bundle-id',
            'exp' => time() + 3600,
            'iat' => time(),
            'sub' => 'apple-unique-user-id',
            'email' => 'appleuser@example.com',
            'email_verified' => 'true',
        ];

        $jwt = JWT::encode($payload, $pemPrivateKey, 'RS256', 'test-key-id');

        $response = $this->postJson('/api/v1/auth/apple', [
            'idToken' => $jwt,
            'name' => 'Apple User',
        ]);

        $response->assertStatus(403) // Account pending
                 ->assertJson([
                     'code' => 'ACCOUNT_PENDING_APPROVAL',
                 ]);

        $this->assertDatabaseHas('users', [
            'email' => 'appleuser@example.com',
            'apple_id' => 'apple-unique-user-id',
            'name' => 'Apple User',
        ]);
    }

    public function testAppleLoginWithAlternativeClientId()
    {
        // Add a secondary allowed client ID
        config(['services.apple.allowed_client_ids' => ['primary-client-id', 'secondary-client-id']]);

        // 1. Generate RSA Key Pair
        $privateKey = openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($privateKey, $pemPrivateKey);
        $details = openssl_pkey_get_details($privateKey);

        $n_url = rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '=');
        $e_url = rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '=');

        $jwks = [
            'keys' => [
                [
                    'kty' => 'RSA',
                    'kid' => 'test-key-id',
                    'use' => 'sig',
                    'alg' => 'RS256',
                    'n' => $n_url,
                    'e' => $e_url,
                ]
            ]
        ];

        Http::fake([
            'appleid.apple.com/auth/keys' => Http::response($jwks, 200),
        ]);

        // Create JWT with the SECONDARY client ID
        $payload = [
            'iss' => 'https://appleid.apple.com',
            'aud' => 'secondary-client-id', // Match one of the allowed ones
            'exp' => time() + 3600,
            'iat' => time(),
            'sub' => 'apple-unique-user-id-2',
            'email' => 'appleuser2@example.com',
            'email_verified' => 'true',
        ];

        $jwt = JWT::encode($payload, $pemPrivateKey, 'RS256', 'test-key-id');

        $response = $this->postJson('/api/v1/auth/apple', [
            'idToken' => $jwt,
            'name' => 'Apple User 2',
        ]);

        $response->assertStatus(403) // Account pending
                 ->assertJson([
                     'code' => 'ACCOUNT_PENDING_APPROVAL',
                 ]);
    }

    public function testAppleLoginWithoutEmailFindsExistingUserByAppleId()
    {
        $existingUser = User::create([
            'name' => 'Existing Apple User',
            'email' => 'existing-apple-user@example.com',
            'username' => 'existingappleuser',
            'password' => 'password',
            'role' => 'user',
            'apple_id' => 'apple-stable-sub',
        ]);

        $privateKey = openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($privateKey, $pemPrivateKey);
        $details = openssl_pkey_get_details($privateKey);

        $b64Url = function ($data) {
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        };

        $jwks = [
            'keys' => [
                [
                    'kty' => 'RSA',
                    'kid' => 'test-key-id',
                    'use' => 'sig',
                    'alg' => 'RS256',
                    'n' => $b64Url($details['rsa']['n']),
                    'e' => $b64Url($details['rsa']['e']),
                ],
            ],
        ];

        Http::fake([
            'appleid.apple.com/auth/keys' => Http::response($jwks, 200),
        ]);

        $payload = [
            'iss' => 'https://appleid.apple.com',
            'aud' => 'test-apple-bundle-id',
            'exp' => time() + 3600,
            'iat' => time(),
            'sub' => 'apple-stable-sub',
        ];

        $jwt = JWT::encode($payload, $pemPrivateKey, 'RS256', 'test-key-id');

        $response = $this->postJson('/api/v1/auth/apple', [
            'idToken' => $jwt,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'authToken']);

        $this->assertDatabaseHas('users', [
            'id' => $existingUser->id,
            'apple_id' => 'apple-stable-sub',
        ]);
    }
}
