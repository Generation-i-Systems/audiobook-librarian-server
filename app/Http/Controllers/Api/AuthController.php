<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
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

        // Create the user in document store
        $userData = [
            'name' => $request->name,
            'username' => $request->username,
            'email' => $request->email,
            'password' => $request->password,
            'role' => 'unverified',
        ];

        $createdId = $this->documentStoreService->createUser($userData);
        if (!$createdId) {
            Log::error('Failed to create user in document store');
            return response()->json(['message' => 'Registration failed'], 500);
        }

        // TODO: Notify all admins about the new user
        // This would require a Message model and notification system

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

        $user = $request->filled('email')
            ? $this->documentStoreService->getUserByEmail($request->input('email'))
            : $this->documentStoreService->getUserByUsername($request->input('username'));

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
