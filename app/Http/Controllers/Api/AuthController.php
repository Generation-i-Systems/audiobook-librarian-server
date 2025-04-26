<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\AccountRequest;
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

        // Create an account request instead of a user
        AccountRequest::create([
            'name' => $request->name,
            'username' => $request->username,
            'email' => $request->email,
            'password' => $password,
        ]);

        return response()->json(['message' => 'Account request submitted. Please wait for approval.'], 201); // Created
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
            'token' => $token
        ]));
    }

     public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }
}
