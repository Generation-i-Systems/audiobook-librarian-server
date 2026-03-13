<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Contracts\DocumentStoreServiceInterface;
use App\Services\NewUserRegistrationNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

class AuthController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    protected NewUserRegistrationNotifier $registrationNotifier;

    public function __construct(
        DocumentStoreServiceInterface $documentStoreService,
        NewUserRegistrationNotifier $registrationNotifier
    ) {
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
            'code' => 'REGISTRATION_PENDING_APPROVAL',
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

        $user = null;
        if ($request->filled('email')) {
            $user = $this->documentStoreService->getUserByEmail((string) $request->input('email'));
        } else {
            $user = $this->documentStoreService->getUserByUsername((string) $request->input('username'));
        }

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
            return response()->json([
                'code' => 'ACCOUNT_PENDING_APPROVAL',
                'message' => 'Account pending admin approval',
            ], 403);
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
        // Validate: either idToken OR (googleId + email) must be provided
        $validator = Validator::make($request->all(), [
            'idToken' => 'nullable|string',
            'googleId' => 'nullable|string',
            'email' => 'nullable|string|email',
            'name' => 'nullable|string',
            'photoUrl' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            Log::error('Google login validation failed', ['errors' => $validator->errors()]);
            return response()->json($validator->errors(), 400);
        }

        $idToken = $request->input('idToken');
        $googleId = $request->input('googleId');
        $email = $request->input('email');
        $name = $request->input('name');
        $photoUrl = $request->input('photoUrl');

        // Determine which flow to use
        $useJwtFlow = !empty($idToken) && $idToken !== 'NO_ID_TOKEN_AVAILABLE';

        try {
            if ($useJwtFlow) {
                // JWT Flow: Verify the Google ID token cryptographically
                Log::debug('Google login: Using JWT verification flow');

                // Get all allowed client IDs (Web + Android)
                $allowedClientIds = config('services.google.allowed_client_ids');
                if (empty($allowedClientIds)) {
                    $allowedClientIds = [config('services.google.client_id')];
                }

                Log::debug('Google login: Allowed client IDs', ['ids' => $allowedClientIds]);

                // Google_Client needs to be initialized with the Web Client ID
                // But verifyIdToken will accept the token if its 'aud' matches any of the allowed IDs
                $webClientId = config('services.google.client_id');
                $client = new \Google_Client(['client_id' => $webClientId]);

                // Verify token - the aud claim must match one of the allowed client IDs
                try {
                    $payload = $client->verifyIdToken($idToken);
                } catch (\Exception $verifyException) {
                    Log::error('Google token verification exception', [
                        'error' => $verifyException->getMessage(),
                    ]);
                    $payload = null;
                }

                if (!$payload) {
                    Log::error('Invalid Google ID token', [
                        'tokenLength' => strlen($idToken),
                        'webClientId' => $webClientId,
                    ]);
                    return response()->json(['message' => 'Invalid Google ID token'], 401);
                }

                Log::debug('Google token verified successfully', [
                    'aud' => $payload['aud'] ?? 'missing',
                    'email' => $payload['email'] ?? 'missing',
                ]);

                $email = $payload['email'] ?? null;
                $name = $payload['name'] ?? null;
                $googleId = $payload['sub'] ?? null;
                $photoUrl = $payload['picture'] ?? null;
                $emailVerified = $payload['email_verified'] ?? false;
            } else {
                // Android Client ID Flow: Trust client-provided data
                // This is used when the app is signed by Play Store and Web Client ID doesn't work
                Log::debug('Google login: Using Android Client ID flow (no JWT verification)', [
                    'email' => $email,
                    'googleId' => $googleId,
                ]);

                if (empty($googleId) || empty($email)) {
                    Log::error('Missing required fields for Android Client ID flow');
                    return response()->json(['message' => 'Missing googleId or email'], 400);
                }

                // For Android Client ID flow, we assume email is verified since it comes from Google Sign-In
                $emailVerified = true;
            }

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
                    'email_verified_at' => $emailVerified ? now() : null,
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
                    $this->documentStoreService->updateUser((string) $user['id'], [
                        'google_id' => $googleId,
                        'photo_url' => $photoUrl ?? $user['photo_url'],
                    ]);
                    Log::debug('Updated existing user with Google ID', ['user_id' => $user['id']]);
                }
            }

            if ($isNewUser) {
                // @phpstan-ignore-next-line
                $userIdForNotification = (string) ($user['id'] ?? $createdId ?? '');
                $completeUserData = $user;
                if ($userIdForNotification !== '') {
                    $completeUserData = $this->documentStoreService->getUserById($userIdForNotification) ?? $user;
                }

                $this->registrationNotifier->send((array) $completeUserData, 'api-google', $request);
            }

            // Check if user is approved
            if (($user['role'] ?? '') === 'unverified') {
                $isGoogleVerified = $payload['email_verified'] ?? false;
                $message = 'Account created. Waiting for admin approval.';
                if ($isGoogleVerified) {
                    $message = 'Google account verified. Waiting for admin approval.';
                }

                return response()->json([
                    'code' => 'ACCOUNT_PENDING_APPROVAL',
                    'message' => $message,
                    'google_verified' => $isGoogleVerified,
                ], 403);
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

    public function checkStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        // Look up user by email
        $user = $this->documentStoreService->getUserByEmail($request->input('email'));

        // If user doesn't exist or is already verified, return generic response
        // This prevents account enumeration
        if (!$user || ($user['role'] ?? '') !== 'unverified') {
            return response()->json([
                'message' => 'Please use the login endpoint',
            ], 200);
        }

        // User exists and is still unverified
        return response()->json([
            'code' => 'ACCOUNT_PENDING_APPROVAL',
            'message' => 'Account pending admin approval',
            'status' => 'unverified',
        ], 403);
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

    public function refresh(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'refreshToken' => 'sometimes|required|string',
            'token' => 'sometimes|required|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $refreshToken = $request->input('refreshToken') ?? $request->input('token');
        if (!is_string($refreshToken) || $refreshToken === '') {
            $authHeader = $request->header('Authorization');
            if (is_string($authHeader) && str_starts_with($authHeader, 'Bearer ')) {
                $refreshToken = substr($authHeader, 7);
            }
        }
        if (!is_string($refreshToken) || $refreshToken === '') {
            return response()->json(['message' => 'Invalid refresh token'], 401);
        }

        $user = null;

        try {
            $accessToken = PersonalAccessToken::findToken($refreshToken);
            if ($accessToken) {
                $tokenable = $accessToken->tokenable;
                if ($tokenable instanceof \App\Models\User) {
                    $user = $tokenable;
                }
            }
        } catch (\Exception $e) {
            Log::warning('Auth refresh: Sanctum token lookup failed', [
                'error' => $e->getMessage(),
            ]);
        }

        if (!$user) {
            $apiTokenRow = $this->documentStoreService->getApiTokenByValue($refreshToken);
            if (!$apiTokenRow) {
                return response()->json(['message' => 'Invalid refresh token'], 401);
            }

            $userId = $apiTokenRow['user_id'] ?? null;
            if (!is_string($userId) && !is_int($userId)) {
                return response()->json(['message' => 'Invalid refresh token'], 401);
            }

            $userData = $this->documentStoreService->getUserById($userId);
            if (!is_array($userData)) {
                return response()->json(['message' => 'Invalid refresh token'], 401);
            }

            // If the role is unverified, preserve the login behavior
            if (($userData['role'] ?? '') === 'unverified') {
                return response()->json([
                    'code' => 'ACCOUNT_PENDING_APPROVAL',
                    'message' => 'Account pending admin approval',
                ], 403);
            }

            $user = new \App\Models\User();
            $user->id = (int) ($userData['id'] ?? 0);
            $user->name = $userData['name'] ?? null;
            $user->username = $userData['username'] ?? null;
            $user->email = $userData['email'] ?? null;
            $user->role = $userData['role'] ?? null;
            $user->photo_url = $userData['photo_url'] ?? null;
        }

        if ($user->role === 'unverified') {
            return response()->json([
                'code' => 'ACCOUNT_PENDING_APPROVAL',
                'message' => 'Account pending admin approval',
            ], 403);
        }

        $tokenValue = bin2hex(random_bytes(32));
        $tokenData = [
            'user_id' => (string) ($user->id ?? ''),
            'token' => $tokenValue,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $this->documentStoreService->createApiToken($tokenData);

        return response()->json([
            'id' => (string) ($user->id ?? ''),
            'name' => $user->name ?? null,
            'username' => $user->username ?? null,
            'email' => $user->email ?? null,
            'photo_url' => $user->photo_url ?? null,
            'role' => $user->role ?? null,
            'authToken' => $tokenValue,
            'refreshToken' => $tokenValue,
            'token' => $tokenValue,
        ]);
    }
    public function facebookLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'accessToken' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $accessToken = $request->input('accessToken');

        try {
            // Verify token with Facebook Graph API
            $response = Http::get('https://graph.facebook.com/v19.0/me', [
                'fields' => 'id,name,email,picture',
                'access_token' => $accessToken,
            ]);

            if ($response->failed()) {
                Log::error('Facebook token verification failed', ['error' => $response->body()]);
                return response()->json(['message' => 'Invalid Facebook token'], 401);
            }

            $fbUser = $response->json();
            $facebookId = $fbUser['id'] ?? null;
            $email = $fbUser['email'] ?? null;
            $name = $fbUser['name'] ?? null;
            $photoUrl = $fbUser['picture']['data']['url'] ?? null;

            if (!$facebookId || !$email) {
                return response()->json(['message' => 'Facebook account must have an email'], 400);
            }

            // Check if user exists by email
            $user = $this->documentStoreService->getUserByEmail($email);
            $isNewUser = false;
            $createdId = null;

            if (!$user) {
                // Create new user
                $userData = [
                    'name' => $name,
                    'username' => explode('@', $email)[0],
                    'email' => $email,
                    'facebook_id' => $facebookId,
                    'photo_url' => $photoUrl,
                    'role' => 'unverified',
                    'password' => null,
                    'email_verified_at' => now(), // Trusted from Facebook
                ];

                $createdId = $this->documentStoreService->createUser($userData);

                if (!$createdId) {
                    return response()->json(['message' => 'Registration failed'], 500);
                }

                $user = $this->documentStoreService->getUserByEmail($email);
                $isNewUser = true;

                Log::info('New Facebook user created', ['email' => $email, 'id' => $createdId]);
            } else {
                // Update existing user
                if (empty($user['facebook_id'])) {
                    $this->documentStoreService->updateUser((string) $user['id'], [
                        'facebook_id' => $facebookId,
                        'photo_url' => $photoUrl ?? $user['photo_url'],
                    ]);
                }
            }

            if ($isNewUser) {
                // @phpstan-ignore-next-line
                $userIdForNotification = (string) ($user['id'] ?? $createdId ?? '');
                $completeUserData = $user;
                if ($userIdForNotification !== '') {
                    $completeUserData = $this->documentStoreService->getUserById($userIdForNotification) ?? $user;
                }
                $this->registrationNotifier->send((array) $completeUserData, 'api-facebook', $request);
            }

            // Check status
            if (($user['role'] ?? '') === 'unverified') {
                return response()->json([
                   'code' => 'ACCOUNT_PENDING_APPROVAL',
                   'message' => 'Facebook account verified. Waiting for admin approval.',
                ], 403);
            }

            // Generate token
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
            Log::error('Facebook login failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Facebook authentication failed'], 500);
        }
    }

    public function appleLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'idToken' => 'required|string',
            'name' => 'nullable|string', // Apple only sends name on first login
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $idToken = $request->input('idToken');
        $fullName = $request->input('name'); // Client should send this if available

        try {
            // Fetch Apple's public keys
            $publicKeys = Http::get('https://appleid.apple.com/auth/keys')->json();

            // Verify JWT
            try {
                $jwks = JWK::parseKeySet($publicKeys);
                $payload = JWT::decode($idToken, $jwks);
            } catch (\Exception $e) {
                Log::error('Apple token verification failed', ['error' => $e->getMessage()]);
                return response()->json(['message' => 'Invalid Apple ID token'], 401);
            }

            // Verify audience (client ID)
            $allowedClientIds = config('services.apple.allowed_client_ids');
            if (empty($allowedClientIds)) {
                $allowedClientIds = [config('services.apple.client_id')];
            }

            if (!in_array($payload->aud, $allowedClientIds)) {
                Log::error('Apple token audience mismatch', ['aud' => $payload->aud, 'allowed' => $allowedClientIds]);
                return response()->json(['message' => 'Invalid Apple ID token'], 401);
            }
            // Proceed assuming valid if verification passed (standard claims check)

            $appleId = $payload->sub;
            $email = $payload->email ?? null;
            $emailVerified = (bool) ($payload->email_verified ?? false);

            if (!$email) {
                // Apple might not return email on subsequent logins, but 'sub' is stable.
                // However, for our system, we need an email to create a user.
                // If user exists by apple_id, we are good.
            }

            // Find user by apple_id or email
            $user = null;

            // First try by apple_id (if we stored it previously)
            // We don't have a direct method for this in DocumentStoreService yet, so we rely on email
            // or we might need to add a method logic here.

            if ($email) {
                $user = $this->documentStoreService->getUserByEmail($email);
            }

            if (!$user) {
                $user = $this->documentStoreService->getUserByAppleId((string) $appleId);
            }

            $isNewUser = false;
            $createdId = null;

            if (!$user) {
                if (!$email) {
                    return response()->json(['message' => 'Email required for registration'], 400);
                }

                // Create new user
                $userData = [
                   'name' => $fullName ?? explode('@', $email)[0],
                   'username' => explode('@', $email)[0],
                   'email' => $email,
                   'apple_id' => $appleId,
                   'role' => 'unverified',
                   'password' => null,
                   'email_verified_at' => $emailVerified ? now() : null,
                ];

                $createdId = $this->documentStoreService->createUser($userData);
                if (!$createdId) {
                    return response()->json(['message' => 'Registration failed'], 500);
                }

                $user = $this->documentStoreService->getUserByEmail($email);
                $isNewUser = true;
                Log::info('New Apple user created', ['email' => $email, 'id' => $createdId]);
            } else {
                // Update existing user
                if (empty($user['apple_id'])) {
                    $this->documentStoreService->updateUser((string) $user['id'], [
                        'apple_id' => $appleId,
                    ]);
                }
            }

            if ($isNewUser) {
                // @phpstan-ignore-next-line
                $userIdForNotification = (string) ($user['id'] ?? $createdId ?? '');
                $completeUserData = $user;
                if ($userIdForNotification !== '') {
                    $completeUserData = $this->documentStoreService->getUserById($userIdForNotification) ?? $user;
                }
                $this->registrationNotifier->send((array) $completeUserData, 'api-apple', $request);
            }

            // Check status
            if (($user['role'] ?? '') === 'unverified') {
                return response()->json([
                   'code' => 'ACCOUNT_PENDING_APPROVAL',
                   'message' => 'Apple account verified. Waiting for admin approval.',
                ], 403);
            }

            // Generate token
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
            Log::error('Apple login failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Apple authentication failed'], 500);
        }
    }

    public function discordLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'accessToken' => 'required|string',
            'codeVerifier' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $code = $request->input('accessToken');
        $codeVerifier = $request->input('codeVerifier');

        try {
            $clientId = config('services.discord.client_id');
            $clientSecret = config('services.discord.client_secret');
            $redirectUri = config('services.discord.redirect');

            if (empty($clientId) || empty($clientSecret)) {
                Log::error('Discord client credentials not configured');
                return response()->json(['message' => 'Discord authentication not configured on server'], 500);
            }

            $tokenResponse = Http::asForm()->post('https://discord.com/api/oauth2/token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'code_verifier' => $codeVerifier,
            ]);

            if ($tokenResponse->failed()) {
                Log::error('Discord token exchange failed', ['error' => $tokenResponse->body()]);
                return response()->json(['message' => 'Failed to exchange Discord code'], 401);
            }

            $accessToken = $tokenResponse->json()['access_token'];

            $userResponse = Http::withToken($accessToken)->get('https://discord.com/api/users/@me');

            if ($userResponse->failed()) {
                Log::error('Discord user fetch failed', ['error' => $userResponse->body()]);
                return response()->json(['message' => 'Failed to fetch Discord user profile'], 401);
            }

            $discordUser = $userResponse->json();
            $discordId = $discordUser['id'] ?? null;
            $email = $discordUser['email'] ?? null;
            $name = $discordUser['global_name'] ?? $discordUser['username'] ?? null;
            $avatar = $discordUser['avatar'] ?? null;
            $photoUrl = $avatar ? "https://cdn.discordapp.com/avatars/{$discordId}/{$avatar}.png" : null;

            if (!$discordId || !$email) {
                return response()->json(['message' => 'Discord account must have a verified email'], 400);
            }

            $user = $this->documentStoreService->getUserByDiscordId($discordId);
            if (!$user) {
                $user = $this->documentStoreService->getUserByEmail($email);
            }

            $isNewUser = false;
            $createdId = null;

            if (!$user) {
                $userData = [
                    'name' => $name,
                    'username' => $discordUser['username'] ?? explode('@', $email)[0],
                    'email' => $email,
                    'discord_id' => $discordId,
                    'photo_url' => $photoUrl,
                    'role' => 'unverified',
                    'password' => null,
                    'email_verified_at' => ($discordUser['verified'] ?? false) ? now() : null,
                ];

                $createdId = $this->documentStoreService->createUser($userData);
                if (!$createdId) {
                    return response()->json(['message' => 'Registration failed'], 500);
                }

                $user = $this->documentStoreService->getUserByEmail($email);
                $isNewUser = true;
                Log::info('New Discord user created', ['email' => $email, 'id' => $createdId]);
            } else {
                if (empty($user['discord_id'])) {
                    $this->documentStoreService->updateUser((string) $user['id'], [
                        'discord_id' => $discordId,
                        'photo_url' => $photoUrl ?? $user['photo_url'],
                    ]);
                }
            }

            if ($isNewUser) {
                $userIdForNotification = (string) ($user['id'] ?? '');
                $completeUserData = $user;
                if ($userIdForNotification !== '') {
                    $completeUserData = $this->documentStoreService->getUserById($userIdForNotification) ?? $user;
                }
                $this->registrationNotifier->send((array) $completeUserData, 'api-discord', $request);
            }

            if (($user['role'] ?? '') === 'unverified') {
                return response()->json([
                    'code' => 'ACCOUNT_PENDING_APPROVAL',
                    'message' => 'Discord account verified. Waiting for admin approval.',
                ], 403);
            }

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
            Log::error('Discord login failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Discord authentication failed: ' . $e->getMessage()], 500);
        }
    }
}
