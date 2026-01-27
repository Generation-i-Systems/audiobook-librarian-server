<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Contracts\DocumentStoreServiceInterface;
use App\Services\NewUserRegistrationNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    protected NewUserRegistrationNotifier $registrationNotifier;

    public function __construct(DocumentStoreServiceInterface $documentStoreService, NewUserRegistrationNotifier $registrationNotifier)
    {
        $this->documentStoreService = $documentStoreService;
        $this->registrationNotifier = $registrationNotifier;
    }

    public function register(Request $request)
    {
        // Log incoming request data
        Log::debug('Registration request received', [
            'request_data' => $request->all(),
            'headers' => $request->headers->all(),
            'content_type' => $request->header('Content-Type'),
            'accept' => $request->header('Accept'),
        ]);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            Log::error('Registration validation failed', [
                'errors' => $validator->errors(),
                'input' => $request->all(),
            ]);
            return response()->json($validator->errors(), 400);
        }

        // Check for existing email/username in document store
        if ($this->documentStoreService->userExistsByEmail($request->email)) {
            return response()->json(['email' => ['The email has already been taken.']], 400);
        }
        if ($this->documentStoreService->userExistsByUsername($request->username)) {
            return response()->json(['username' => ['The username has already been taken.']], 400);
        }

        // Build the user payload
        $userData = [
            'name' => $request->name,
            'username' => $request->username,
            'email' => $request->email,
            'password' => $request->password,
            'role' => 'unverified',
        ];

        // Block obvious spam registrations before creating the user
        if ($this->registrationNotifier->isSpamRegistration($userData, $request)) {
            Log::info('Registration blocked as spam', [
                'email' => $userData['email'] ?? null,
                'username' => $userData['username'] ?? null,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Registration rejected.',
            ], 400);
        }

        // Create the user in document store
        $createdId = $this->documentStoreService->createUser($userData);
        if (!$createdId) {
            Log::error('Failed to create user in document store');
            return response()->json(['message' => 'Registration failed'], 500);
        }

        // Send admin notification email about the new registration
        $completeUserData = $this->documentStoreService->getUserById($createdId) ?? array_merge($userData, [
            'id' => $createdId,
        ]);

        $this->registrationNotifier->send($completeUserData, 'api', $request);

        return response()->json([
            'message' => 'Account created. Waiting for admin approval.',
        ], 201);
    }


    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'sometimes|required_without:username|string|email|max:255',
            'username' => 'sometimes|required_without:email|string|max:255',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $loginField = $request->input('email') ?? $request->input('username');

        // Find user by email or username using document store
        $lookupBy = $request->filled('email') ? 'email' : 'username';
        $lookupValue = $request->input($lookupBy);
        Log::debug('Auth login lookup', ['by' => $lookupBy, 'value' => $lookupValue]);

        $user = $request->filled('email') ? $this->documentStoreService->getUserByEmail($request->input('email')) : $this->documentStoreService->getUserByUsername($request->input('username'));

        Log::debug('Auth login user fetched', [
            'found' => (bool) $user,
            'id' => $user['id'] ?? null,
            'role' => $user['role'] ?? null,
            'has_password' => isset($user['password']),
        ]);

        $passwordOk = $user && isset($user['password']) && password_verify($request->password, $user['password']);
        if (!$passwordOk) {
            Log::warning('Auth login invalid credentials', [
                'found' => (bool) $user,
                'has_password' => isset($user['password']),
            ]);
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // Check if user is approved
        if (($user['role'] ?? '') === 'unverified') {
            return response()->json(['message' => 'Account pending admin approval'], 403);
        }

        // Create an API token in the document store
        $tokenValue = bin2hex(random_bytes(32));
        $tokenData = [
            'user_id' => (string) ($user['id'] ?? ''),
            'token' => $tokenValue,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $this->documentStoreService->createApiToken($tokenData);

        return response()->json([
            'id' => (string) ($user['id'] ?? ''),
            'name' => $user['name'] ?? null,
            'username' => $user['username'] ?? null,
            'email' => $user['email'] ?? null,
            'photo_url' => $user['photo_url'] ?? null,
            'role' => $user['role'] ?? null,
            'authToken' => $tokenValue,
            'refreshToken' => $tokenValue,
            'token' => $tokenValue,
        ]);
    }


    public function googleLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'idToken' => 'required|string',
        ]);

        if ($validator->fails()) {
            Log::error('Google login validation failed', ['errors' => $validator->errors()]);
            return response()->json($validator->errors(), 400);
        }

        try {
            // Verify the Google ID token
            $client = new \Google_Client(['client_id' => config('services.google.client_id')]);
            $payload = $client->verifyIdToken($request->idToken);

            if (!$payload) {
                Log::error('Invalid Google ID token');
                return response()->json(['message' => 'Invalid Google ID token'], 401);
            }

            $email = $payload['email'] ?? null;
            $name = $payload['name'] ?? null;
            $googleId = $payload['sub'] ?? null;
            $photoUrl = $payload['picture'] ?? null;

            if (!$email || !$googleId) {
                Log::error('Missing required fields from Google token', ['payload' => $payload]);
                return response()->json(['message' => 'Invalid token payload'], 401);
            }

            Log::debug('Google login verified', [
                'email' => $email,
                'name' => $name,
                'google_id' => $googleId,
            ]);

            // Check if user exists by email
            $user = $this->documentStoreService->getUserByEmail($email);
            $isNewUser = false;
            $createdId = null;

            if (!$user) {
                // Create new user with Google info treated as a registration
                $userData = [
                    'name' => $name,
                    'username' => explode('@', $email)[0],
                    'email' => $email,
                    'google_id' => $googleId,
                    'photo_url' => $photoUrl,
                    'role' => 'unverified',
                    'password' => null,
                ];

                $createdId = $this->documentStoreService->createUser($userData);
                if (!$createdId) {
                    Log::error('Failed to create Google user in document store');
                    return response()->json(['message' => 'Registration failed'], 500);
                }

                // Fetch the newly created user
                $user = $this->documentStoreService->getUserByEmail($email);
                if (!$user) {
                    Log::error('Failed to fetch newly created Google user');
                    return response()->json(['message' => 'User creation failed'], 500);
                }

                $isNewUser = true;
                Log::info('New Google user created', ['email' => $email, 'id' => $createdId]);
            } else {
                // Update existing user's Google info if not set
                if (empty($user['google_id'])) {
                    $this->documentStoreService->updateUser($user['id'], [
                        'google_id' => $googleId,
                        'photo_url' => $photoUrl ?? $user['photo_url'],
                    ]);
                    Log::debug('Updated existing user with Google ID', ['user_id' => $user['id']]);
                }
            }

            if ($isNewUser) {
                // @phpstan-ignore-next-line
                $userIdForNotification = (string) ($user['id'] ?? $createdId ?? '');
                $completeUserData = $userIdForNotification !== '' ? $this->documentStoreService->getUserById($userIdForNotification) ?? $user : $user;

                $this->registrationNotifier->send((array) $completeUserData, 'api-google', $request);
            }

            // Check if user is approved
            if (($user['role'] ?? '') === 'unverified') {
                return response()->json(['message' => 'Account pending admin approval'], 403);
            }

            // Create an API token
            $tokenValue = bin2hex(random_bytes(32));
            $tokenData = [
                'user_id' => (string) ($user['id'] ?? ''),
                'token' => $tokenValue,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $this->documentStoreService->createApiToken($tokenData);

            return response()->json([
                'id' => (string) ($user['id'] ?? ''),
                'name' => $user['name'] ?? null,
                'username' => $user['username'] ?? null,
                'email' => $user['email'] ?? null,
                'photo_url' => $user['photo_url'] ?? null,
                'role' => $user['role'] ?? null,
                'authToken' => $tokenValue,
                'refreshToken' => $tokenValue,
                'token' => $tokenValue,
            ]);
        } catch (\Exception $e) {
            Log::error('Google login failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Google authentication failed: ' . $e->getMessage()], 500);
        }
    }

    public function logout(Request $request)
    {
        // Extract bearer token and delete from document store
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            if (!empty($token)) {
                $this->documentStoreService->deleteApiTokenByValue($token);
            }
        }

        return response()->json(['message' => 'Successfully logged out']);
    }
}
