<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

class ApiAuth
{
    public function handle(Request $request, Closure $next)
    {
        $authHeader = $request->header('Authorization');
        $clientIp = $request->ip();
        $userAgent = $request->header('User-Agent');
        $requestUri = $request->getRequestUri();
        $requestMethod = $request->getMethod();
        
        if (!$authHeader || !preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
            Log::warning('API Auth failed: Missing or malformed Authorization header', [
                'ip' => $clientIp,
                'user_agent' => $userAgent,
                'uri' => $requestUri,
                'method' => $requestMethod,
                'auth_header_present' => !empty($authHeader),
                'auth_header_format' => $authHeader ? 'malformed' : 'missing',
                'reason' => 'missing_or_malformed_auth_header'
            ]);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $token = $matches[1];
        $tokenPreview = substr($token, 0, 8) . '...' . substr($token, -4); // Show first 8 and last 4 chars

        // Try to find the token in the personal_access_tokens table
        $accessToken = PersonalAccessToken::findToken($token);

        if (!$accessToken) {
            Log::warning('API Auth failed: Token not found in database', [
                'ip' => $clientIp,
                'user_agent' => $userAgent,
                'uri' => $requestUri,
                'method' => $requestMethod,
                'token_preview' => $tokenPreview,
                'token_length' => strlen($token),
                'reason' => 'token_not_found'
            ]);
            return response()->json(['error' => 'Invalid or expired token'], 401);
        }

        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            Log::warning('API Auth failed: Token expired', [
                'ip' => $clientIp,
                'user_agent' => $userAgent,
                'uri' => $requestUri,
                'method' => $requestMethod,
                'token_preview' => $tokenPreview,
                'token_id' => $accessToken->id,
                'token_name' => $accessToken->name,
                'expires_at' => $accessToken->expires_at->toISOString(),
                'expired_since' => $accessToken->expires_at->diffForHumans(),
                'reason' => 'token_expired'
            ]);
            return response()->json(['error' => 'Invalid or expired token'], 401);
        }

        // Get the user associated with the token
        $user = $accessToken->tokenable;

        if (!$user) {
            Log::warning('API Auth failed: User not found for token', [
                'ip' => $clientIp,
                'user_agent' => $userAgent,
                'uri' => $requestUri,
                'method' => $requestMethod,
                'token_preview' => $tokenPreview,
                'token_id' => $accessToken->id,
                'token_name' => $accessToken->name,
                'tokenable_type' => $accessToken->tokenable_type,
                'tokenable_id' => $accessToken->tokenable_id,
                'reason' => 'user_not_found'
            ]);
            return response()->json(['error' => 'User not found'], 401);
        }

        // Check if user is approved (not unverified)
        if ($user->role === 'unverified') {
            Log::warning('API Auth failed: User account pending approval', [
                'ip' => $clientIp,
                'user_agent' => $userAgent,
                'uri' => $requestUri,
                'method' => $requestMethod,
                'token_preview' => $tokenPreview,
                'token_id' => $accessToken->id,
                'token_name' => $accessToken->name,
                'user_id' => $user->id,
                'user_email' => $user->email,
                'user_role' => $user->role,
                'user_created_at' => $user->created_at->toISOString(),
                'reason' => 'account_pending_approval'
            ]);
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
