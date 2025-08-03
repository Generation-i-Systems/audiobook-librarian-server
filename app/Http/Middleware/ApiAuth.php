<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class ApiAuth
{
    public function handle(Request $request, Closure $next)
    {
        $authHeader = $request->header('Authorization');
        if (!$authHeader || !preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $token = $matches[1];
        
        // Try to find the token in the personal_access_tokens table
        $accessToken = PersonalAccessToken::findToken($token);
        
        if (!$accessToken || $accessToken->expires_at && $accessToken->expires_at->isPast()) {
            return response()->json(['error' => 'Invalid or expired token'], 401);
        }

        // Get the user associated with the token
        $user = $accessToken->tokenable;
        
        if (!$user) {
            return response()->json(['error' => 'User not found'], 401);
        }

        // Check if user is approved (not unverified)
        if ($user->role === 'unverified') {
            return response()->json(['error' => 'Account pending admin approval'], 403);
        }

        // Set the user in the request
        Auth::setUser($user);
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        return $next($request);
    }
}