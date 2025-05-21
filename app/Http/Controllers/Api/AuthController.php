<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400); // Bad Request
        }

        // Hash password here
        $password = Hash::make($request->password);

        // Create the user with 'unverified' role
        $user = User::create([
            'name' => $request->name,
            'username' => $request->username,
            'email' => $request->email,
            'password' => $password,
            'role' => 'unverified',
        ]);

        // Notify all admins about the new user
        $adminUsers = User::where('role', 'admin')->get();
        foreach ($adminUsers as $admin) {
            Message::create([
                'user_id' => $admin->id,
                'content' => 'New user registered: ' . $user->name . ' (' . $user->email . '). <a href="' .
                    url('/admin/users/' . $user->id . '/edit') . '">Edit User</a>',
                'is_from_admin' => false,
            ]);
        }

        return response()->json(['message' => 'Account created. Waiting for admin approval.'], 201); // Created
    }


    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'sometimes|required_without:username|string|email|max:255',
            'username' => 'sometimes|required_without:email|string|max:255',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400); // Bad Request
        }

        $loginField = $request->input('email') ?? $request->input('username');

        $user = User::where('email', $loginField)
            ->orWhere('username', $loginField)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401); // Unauthorized
        }

        $token = $user->createToken('mobile_app_token')->plainTextToken;

        $userArr = $user->toArray();
        return response()->json(array_merge($userArr, [
            'authToken' => $token,
            'refreshToken' => $token,
            'token' => $token,
        ]));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }
}
