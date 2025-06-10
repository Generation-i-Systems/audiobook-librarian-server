<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use Google\Cloud\Core\Timestamp as GoogleTimestamp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    protected $firestore;

    public function __construct(FirestoreService $firestore)
    {
        $this->firestore = $firestore;
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        // Check if email already exists in Firestore
        $existingUser = $this->firestore->getClient()
            ->collection('users')
            ->where('email', '=', $request->email)
            ->documents();

        if (! $existingUser->isEmpty()) {
            return response()->json(['email' => ['The email has already been taken.']], 400);
        }

        // Check if username already exists in Firestore
        $existingUsername = $this->firestore->getClient()
            ->collection('users')
            ->where('username', '=', $request->username)
            ->documents();

        if (! $existingUsername->isEmpty()) {
            return response()->json(['username' => ['The username has already been taken.']], 400);
        }

        // Create the user in Firestore
        $userData = [
            'name' => $request->name,
            'username' => $request->username,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'unverified',
            'created_at' => new GoogleTimestamp(new \DateTime),
            'updated_at' => new GoogleTimestamp(new \DateTime),
        ];

        $userRef = $this->firestore->getClient()
            ->collection('users')
            ->add($userData);

        // Notify all admins about the new user
        $adminUsers = $this->firestore->getClient()
            ->collection('users')
            ->where('role', '=', 'admin')
            ->documents();

        $messagesRef = $this->firestore->getClient()->collection('messages');
        $now = new GoogleTimestamp(new \DateTime);
        foreach ($adminUsers as $admin) {
            $messagesRef->add([
                'user_id' => $admin->id(),
                'content' => 'New user registered: '.$request->name.' ('.$request->email.')',
                'is_from_admin' => false,
                'created_at' => $now,
                'updated_at' => $now,
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
        $client = $this->firestore->getClient();

        // Try to find user by email or username
        $users = $client->collection('users')
            ->where('email', '=', $loginField)
            ->limit(1)
            ->documents();

        if ($users->isEmpty()) {
            $users = $client->collection('users')
                ->where('username', '=', $loginField)
                ->limit(1)
                ->documents();
        }

        if ($users->isEmpty() || ! Hash::check($request->password, $users->rows()[0]->data()['password'])) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $userData = $users->rows()[0];
        $user = $userData->data();
        $userId = $userData->id();

        // Check if user is approved
        if ($user['role'] === 'unverified') {
            return response()->json(['message' => 'Account pending admin approval'], 403);
        }

        // Create a simple token (in a real app, use Laravel Sanctum/Passport)
        $token = hash('sha256', $userId.now()->timestamp.uniqid());

        // Store the token in Firestore
        $client->collection('api_tokens')
            ->add([
                'user_id' => $userId,
                'token' => $token,
                'created_at' => new GoogleTimestamp(new \DateTime),
                'expires_at' => new GoogleTimestamp(now()->addDays(30)->toDateTime()),
            ]);

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
            $client = $this->firestore->getClient();
            $tokens = $client->collection('api_tokens')
                ->where('token', '=', $token)
                ->documents();

            foreach ($tokens as $tokenDoc) {
                $tokenDoc->reference()->delete();
            }
        }

        return response()->json(['message' => 'Successfully logged out']);
    }
}
