<?php

namespace Tests\Feature\Auth;

use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    protected $documentStore;

    protected $usersCollection;

    protected $tokensCollection;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->shouldSkipFirestoreTests()) {
            $this->markTestSkipped('Firestore config missing: skipping Firestore-dependent tests.');
        }

        // Initialize Firestore client
        $this->documentStore = new FirestoreClient([
            'projectId' => config('firebase.project_id'),
            'keyFilePath' => config('firebase.credentials.file'),
        ]);

        $this->usersCollection = $this->documentStore->collection('users');
        $this->tokensCollection = $this->documentStore->collection('api_tokens');

        // Clear test data
        $this->clearTestData();
    }

    /**
     * Helper to check if Firestore config is missing.
     */
    protected function shouldSkipFirestoreTests(): bool
    {
        $projectId = config('firebase.project_id');
        $keyFile = config('firebase.credentials.file');

        return empty($projectId) || empty($keyFile) || !file_exists($keyFile);
    }

    protected function tearDown(): void
    {
        $this->clearTestData();
        parent::tearDown();
    }

    protected function clearTestData()
    {
        // Delete test users
        $users = $this->usersCollection->where('email', '=', 'test@example.com')
            ->documents();
        foreach ($users as $user) {
            $user->reference()->delete();
        }

        // Delete test tokens
        $tokens = $this->tokensCollection->documents();
        foreach ($tokens as $token) {
            $token->reference()->delete();
        }
    }

    #[Test]
    public function testUserCanRegister()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Account created. Waiting for admin approval.',
            ]);

        // Verify user was created in Firestore
        $user = $this->usersCollection->where('email', '=', 'test@example.com')
            ->documents()
            ->rows()[0] ?? null;

        $this->assertNotNull($user);
        $this->assertEquals('testuser', $user['username']);
        $this->assertEquals('unverified', $user['role']);
        $this->assertTrue(Hash::check('password', $user['password']));
    }

    #[Test]
    public function testUserCanLogin()
    {
        // Create a test user
        $this->usersCollection->add([
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'role' => 'user',
        ]);

        $response = $this->postJson('/api/login', [
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
            ]);
    }

    #[Test]
    public function testUnverifiedUserCannotLogin()
    {
        // Create an unverified test user
        $this->usersCollection->add([
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'role' => 'unverified',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'Account pending admin approval',
            ]);
    }

    #[Test]
    public function testUserCanLogout()
    {
        // Create a test user and token
        $userRef = $this->usersCollection->add([
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'role' => 'user',
        ]);

        $token = 'test_token_' . Str::random(32);
        $this->tokensCollection->add([
            'user_id' => $userRef->id(),
            'token' => $token,
            'created_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
            'expires_at' => new \Google\Cloud\Core\Timestamp(now()->addDays(30)),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/logout');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Successfully logged out',
            ]);

        // Verify token was deleted
        $tokens = $this->tokensCollection->where('token', '=', $token)
            ->documents();

        $this->assertTrue($tokens->isEmpty());
    }
}
