<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{


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
        $existingUser = User::where('email', $request->email)->first();

        if ($existingUser) {
            return response()->json(['email' => ['The email has already been taken.']], 400);
        }

        // Check if username already exists
        $existingUsername = User::where('username', $request->username)->first();
        if ($existingUsername) {
            return response()->json(['username' => ['The username has already been taken.']], 400);
        }

        // Create the user
        $user = User::create([
            'name' => $request->name,
            'username' => $request->username,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'unverified',
        ]);

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

        // Try to find user by email or username
        $user = User::where('email', $loginField)
            ->orWhere('username', $loginField)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // Check if user is approved
        if ($user->role === 'unverified') {
            return response()->json(['message' => 'Account pending admin approval'], 403);
        }

        // Create a Sanctum token
        $token = $user->createToken('api-token', ['*'], now()->addDays(30))->plainTextToken;

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'photo_url' => $user->photo_url,
            'role' => $user->role,
            'authToken' => $token,
            'refreshToken' => $token,
            'token' => $token,
        ]);
    }


    public function logout(Request $request)
    {
        // Delete the current access token
        $user = $request->user();
        if ($user) {
            $user->currentAccessToken()->delete();
        }

        return response()->json(['message' => 'Successfully logged out']);
    }


}
