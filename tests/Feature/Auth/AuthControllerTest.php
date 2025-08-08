<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Support\Facades\Hash;
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

        // Initialize Firestore client with debug logging
        $config = [
            'projectId' => config('firebase.project_id'),
            'keyFilePath' => config('firebase.credentials.file'),
        ];

        fwrite(STDERR, "Initializing Firestore with config: " . json_encode($config) . "\n");

        $this->documentStore = new FirestoreClient($config);
        $this->usersCollection = $this->documentStore->collection('users');
        $this->tokensCollection = $this->documentStore->collection('api_tokens');

        fwrite(STDERR, "Firestore collections initialized. Users collection: " . $this->usersCollection->name() . "\n");

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


    protected function clearTestData(): void
    {
        if ($this->shouldSkipFirestoreTests()) {
            return;
        }

        fwrite(STDERR, "Clearing test data...\n");

        // Delete all test users
        $users = $this->usersCollection->where('email', '=', 'test@example.com')->documents();
        $userCount = 0;
        foreach ($users as $user) {
            fwrite(STDERR, "Deleting test user: " . $user->id() . "\n");
            $user->reference()->delete();
            $userCount++;
        }
        fwrite(STDERR, "Deleted $userCount test users\n");

        // Delete all test tokens
        $tokens = $this->tokensCollection->documents();
        $tokenCount = 0;

        foreach ($tokens as $token) {
            fwrite(STDERR, "Deleting token: " . $token->id() . "\n");
            $token->reference()->delete();
            $tokenCount++;
        }
        fwrite(STDERR, "Deleted $tokenCount test tokens\n");
    }


    #[Test]
    public function testUserCanRegister()
    {
        fwrite(STDERR, "\n=== Starting testUserCanRegister ===\n");

        $testEmail = 'test' . time() . '@example.com';
        $testUsername = 'testuser' . time();

        $userData = [
            'name' => 'Test User',
            'username' => $testUsername,
            'email' => $testEmail,
            'password' => 'password',
            'password_confirmation' => 'password',
        ];

        fwrite(STDERR, "\n=== Starting testUserCanRegister ===\n");

        // Debug: Check Firestore connection
        $this->assertNotNull($this->documentStore, 'Firestore client is not initialized');
        $this->assertNotNull($this->usersCollection, 'Users collection is not initialized');
        fwrite(STDERR, "Firestore client and collections initialized successfully\n");

        // Debug: Check if user already exists before test
        fwrite(STDERR, "Checking for existing test users...\n");
        $existingUsersQuery = $this->usersCollection->where('email', '=', 'test@example.com');
        $existingUsers = $existingUsersQuery->documents();
        $existingUsersArray = iterator_to_array($existingUsers);

        fwrite(STDERR, "Found " . count($existingUsersArray) . " existing test users before test\n");
        foreach ($existingUsersArray as $existingUser) {
            fwrite(STDERR, "Existing test user found before test: " . $existingUser->id() . "\n");
        }

        $this->assertCount(0, $existingUsersArray, 'Test user already exists before test');

        // Try to find the user by email with retry logic
        $maxRetries = 5;
        $retryDelay = 1; // seconds
        $usersArray = [];

        for ($i = 0; $i < $maxRetries; $i++) {
            fwrite(STDERR, "\n=== Attempt " . ($i + 1) . " to find user in Firestore ===\n");

            // Try direct query first
            $usersQuery = $this->usersCollection->where('email', '=', $testEmail);
            $users = $usersQuery->documents();
            $usersArray = iterator_to_array($users);

            fwrite(STDERR, "Direct query found " . count($usersArray) . " users with email: $testEmail\n");

            // If no users found, try manual iteration as fallback
            if (empty($usersArray)) {
                fwrite(STDERR, "No users found with direct query. Trying manual iteration...\n");
                $allUsers = $this->usersCollection->documents();
                $matchingUsers = [];

                foreach ($allUsers as $user) {
                    $userData = $user->data();
                    if (($userData['email'] ?? '') === $testEmail) {
                        $matchingUsers[] = $user;
                    }
                }

                fwrite(STDERR, "Manual iteration found " . count($matchingUsers) . " matching users\n");
                $usersArray = $matchingUsers;
            }

            // If we found users or this is the last attempt, break the loop
            if (!empty($usersArray) || $i === $maxRetries - 1) {
                break;
            }

            // Wait before retrying
            fwrite(STDERR, "User not found yet. Waiting $retryDelay seconds before retry...\n");
            sleep($retryDelay);
        }

        // Debug output: Log all users in the collection
        fwrite(STDERR, "\n=== Current Users in Firestore ===\n");
        $allUsers = $this->usersCollection->documents();
        $userCount = 0;
        foreach ($allUsers as $user) {
            $userData = $user->data();
            fwrite(STDERR, "- User ID: " . $user->id() . ", Email: " . ($userData['email'] ?? 'none') . ", Username: " . ($userData['username'] ?? 'none') . ", Role: " . ($userData['role'] ?? 'none') . "\n");
            $userCount++;
        }
        fwrite(STDERR, "Total users in Firestore: $userCount\n");

        // Prepare the registration data
        $registrationData = [
            'name' => 'Test User',
            'username' => $testUsername,
            'email' => $testEmail,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        fwrite(STDERR, "Sending registration request for email: $testEmail, username: $testUsername\n");
        fwrite(STDERR, "Request data: " . json_encode($registrationData, JSON_PRETTY_PRINT) . "\n");

        // Make the request with explicit headers
        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/register', $registrationData);

        // Log response for debugging
        $responseContent = $response->getContent();
        $statusCode = $response->status();
        fwrite(STDERR, "Registration response status: $statusCode\n");
        fwrite(STDERR, "Response content: $responseContent\n");

        // Assert the response
        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Account created. Waiting for admin approval.',
            ]);

        fwrite(STDERR, "Registration API call successful. Checking Firestore for new user...\n");

        // Check if user was created in Firestore collection
        fwrite(STDERR, "\nChecking all users in collection...\n");
        $allUsers = $this->usersCollection->documents();
        $allUsersArray = [];
        foreach ($allUsers as $user) {
            $allUsersArray[] = $user;
        }

        fwrite(STDERR, "Found " . count($allUsersArray) . " users in collection\n");
        foreach ($allUsersArray as $user) {
            $userData = $user->data();
            fwrite(STDERR, sprintf(
                "- User ID: %s, Email: %s, Username: %s, Role: %s\n",
                $user->id(),
                $userData['email'] ?? 'none',
                $userData['username'] ?? 'none',
                $userData['role'] ?? 'none'
            ));
        }

        // Verify user was created with unverified role
        fwrite(STDERR, "\nQuerying for test user with email: test@example.com\n");
        $query = $this->usersCollection->where('email', '=', 'test@example.com');
        $users = $query->documents();
        $usersArray = [];
        foreach ($users as $user) {
            $usersArray[] = $user;
        }

        fwrite(STDERR, "Found " . count($usersArray) . " matching users\n");

        if (empty($usersArray)) {
            // If no users found, try a different query approach
            fwrite(STDERR, "No users found with direct query. Trying alternative query...\n");
            $allUsers = $this->usersCollection->documents();
            $matchingUsers = [];
            foreach ($allUsers as $user) {
                $data = $user->data();
                if (($data['email'] ?? '') === 'test@example.com') {
                    $matchingUsers[] = $user;
                }
            }
            fwrite(STDERR, "Found " . count($matchingUsers) . " users with matching email in manual check\n");

            if (!empty($matchingUsers)) {
                $usersArray = $matchingUsers;
            }
        }

        $this->assertNotEmpty($usersArray, 'User was not created in Firestore');

        // Get the first user document
        $userDoc = $usersArray[0];
        $userData = $userDoc->data();

        fwrite(STDERR, "\nFound user data: " . json_encode($userData) . "\n");

        $this->assertArrayHasKey('username', $userData, 'User data is missing username');
        $this->assertArrayHasKey('role', $userData, 'User data is missing role');
        $this->assertArrayHasKey('password', $userData, 'User data is missing password');

        $this->assertEquals('testuser', $userData['username'], 'Username does not match');
        $this->assertEquals('unverified', $userData['role'], 'User role is not unverified');

        // Debug: Log the password hash for verification
        fwrite(STDERR, "Verifying password hash...\n");
        $passwordVerified = password_verify('password', $userData['password']);
        $this->assertTrue(
            $passwordVerified,
            'Password verification failed. Hash: ' . $userData['password']
        );
        fwrite(STDERR, "Password verified: " . ($passwordVerified ? 'true' : 'false') . "\n");
    }


    #[Test]
    public function testUserCanLogin()
    {
        // Create a test user
        $userData = [
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'role' => 'user',
        ];

        // Add user to Firestore
        $userRef = $this->usersCollection->add($userData);
        $userId = $userRef->id();

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
        // Create an unverified test user
        $this->usersCollection->add([
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
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
        // Create a test user
        $userData = [
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'role' => 'user',
        ];

        // Add user to Firestore
        $userRef = $this->usersCollection->add($userData);
        $userId = $userRef->id();

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

        // Verify token was deleted
        $tokens = $this->tokensCollection->where('token', '=', $token)
            ->documents();

        $this->assertTrue($tokens->isEmpty());
    }
}
