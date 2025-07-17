<?php

namespace App\Http\Controllers\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    protected $documentStore;


    public function __construct(DocumentStoreServiceInterface $documentStore)
    {
        $this->documentStore = $documentStore;
    }


    public function register(Request $request)
    {
        // Log incoming request data
        Log::debug('Registration request received', [
            'request_data' => $request->all(),
            'headers' => $request->headers->all(),
            'content_type' => $request->header('Content-Type'),
            'accept' => $request->header('Accept')
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
                'input' => $request->all()
            ]);
            return response()->json($validator->errors(), 400);
        }

        // Check if email already exists
        $existingUser = $this->documentStore->getUserByEmail($request->email);

        if ($existingUser) {
            return response()->json(['email' => ['The email has already been taken.']], 400);
        }

        // Check if username already exists
        if ($this->documentStore->userExistsByUsername($request->username)) {
            return response()->json(['username' => ['The username has already been taken.']], 400);
        }

        // Create the user
        $userData = [
            'name' => $request->name,
            'username' => $request->username,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'unverified',
            // Let the service handle timestamps
        ];

        $userId = $this->documentStore->createUser($userData);

        // Notify all admins about the new user
        $adminUsers = $this->documentStore->getAdminUsers();

        foreach ($adminUsers as $admin) {
            $this->documentStore->createMessage([
                'user_id' => $admin['id'],
                'content' => 'New user registered: ' . $request->name . ' (' . $request->email . ')',
                'is_from_admin' => false,
                'created_at' => new \DateTime(),
                'updated_at' => new \DateTime(),
            ]);
        }

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

        // Try to find user by email
        $user = null;
        if (filter_var($loginField, FILTER_VALIDATE_EMAIL)) {
            $user = $this->documentStore->getUserByEmail($loginField);
        }

        // If not found by email, try to find by username
        if (!$user) {
            $users = $this->documentStore->getUsersForMessaging();
            foreach ($users as $potentialUser) {
                if (isset($potentialUser['username']) && $potentialUser['username'] === $loginField) {
                    $user = $potentialUser;
                    break;
                }
            }
        }

        if (!$user || !Hash::check($request->password, $user['password'])) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $userId = $user['id'];

        // Check if user is approved
        if ($user['role'] === 'unverified') {
            return response()->json(['message' => 'Account pending admin approval'], 403);
        }

        // Create a simple token (in a real app, use Laravel Sanctum/Passport)
        $token = hash('sha256', $userId . now()->timestamp . uniqid());

        // Store the token using the interface method
        $tokenData = [
            'user_id' => $userId,
            'token' => $token,
            'created_at' => new \DateTime(),
            'expires_at' => now()->addDays(30)->toDateTime(),
        ];

        // Use the createApiToken interface method
        $this->documentStore->createApiToken($tokenData);

        unset($user['password']);
        $user['id'] = $userId;

        return response()->json(array_merge($user, [
            'authToken' => $token,
            'refreshToken' => $token,
            'token' => $token,
        ]));
    }


    public function logout(Request $request)
    {
        $token = $request->bearerToken();
        if ($token) {
            // Use the deleteApiTokenByValue interface method
            $this->documentStore->deleteApiTokenByValue($token);
        }

        return response()->json(['message' => 'Successfully logged out']);
    }


}
