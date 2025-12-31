<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    // No external document store in tests; we use the database


    protected function setUp(): void
    {
        parent::setUp();

        // Ensure we use MySQL-backed document store
        config(['documentstore.driver' => 'mysql']);

        // Clean up is handled by RefreshDatabase trait
        // No need to manually delete users here
    }


    // MySQL-based authentication - no external helpers required


    protected function tearDown(): void
    {
    }


    // MySQL cleanup - no external cleanup required


    #[Test]
    public function testUserCanRegister()
    {
        // fwrite(STDERR, "\n=== Starting testUserCanRegister ===\n");

        $testEmail = 'test' . time() . '@example.com';
        $testUsername = 'testuser' . time();

        $userData = [
            'name' => 'Test User',
            'username' => $testUsername,
            'email' => $testEmail,
            'password' => 'password',
            'password_confirmation' => 'password',
        ];

        // fwrite(STDERR, "\n=== Starting testUserCanRegister ===\n");

        // Ensure a clean slate
        $this->assertFalse(User::where('email', $testEmail)->exists(), 'Test user already exists before test');

        // Prepare the registration data
        $registrationData = [
            'name' => 'Test User',
            'username' => $testUsername,
            'email' => $testEmail,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        // fwrite(STDERR, "Sending registration request for email: $testEmail, username: $testUsername\n");
        // fwrite(STDERR, "Request data: " . json_encode($registrationData, JSON_PRETTY_PRINT) . "\n");

        // Make the request with explicit headers
        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/register', $registrationData);

        // Log response for debugging
        $responseContent = $response->getContent();
        $statusCode = $response->status();
        // fwrite(STDERR, "Registration response status: $statusCode\n");
        // fwrite(STDERR, "Response content: $responseContent\n");

        // Assert the response
        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Account created. Waiting for admin approval.',
            ]);

        // Verify user was created in DB with unverified role and password hashed
        $created = User::where('email', $testEmail)->first();
        $this->assertNotNull($created, 'User was not created in the database');
        $this->assertSame($testUsername, $created->username, 'Username does not match');
        $this->assertSame('unverified', $created->role, 'User role is not unverified');
        $this->assertTrue(Hash::check('password123', $created->password), 'Password verification failed');
    }


    #[Test]
    public function testUserCanLogin()
    {
        // Create a test user in the database
        $user = User::create([
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'role' => 'user',
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'id',
                'name',
                'email',
                'username',
                'role',
                'token',
                'authToken',
                'refreshToken',
            ]);

        $responseData = $response->json();
        $this->assertEquals('test@example.com', $responseData['email']);
        $this->assertNotNull($responseData['token']);
        $this->assertNotNull($responseData['authToken']);
        $this->assertNotNull($responseData['refreshToken']);
    }


    #[Test]
    public function testUnverifiedUserCannotLogin(): void
    {
        // Create an unverified test user in the database
        User::create([
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'role' => 'unverified',
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'Account pending admin approval',
            ]);
    }


    #[Test]
    public function testUserCanLogout(): void
    {
        // Create a test user in the database
        User::create([
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'role' => 'standard',
        ]);

        // Log in to get a valid token
        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('token');

        // Log out with the valid token
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/v1/logout');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Successfully logged out',
            ]);

        // Verify token was deleted from DB
        $tokenRow = DB::table('api_tokens')->where('token', $token)->first();
        $this->assertNull($tokenRow);
    }
}
