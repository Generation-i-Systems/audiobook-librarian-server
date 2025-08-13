<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

        // Log every request that hits this middleware for debugging
        Log::info('ApiAuth middleware called', [
            'uri' => $requestUri,
            'method' => $requestMethod,
            'ip' => $clientIp,
            'has_auth_header' => !empty($authHeader)
        ]);

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

        // Handle duplicate Authorization headers (fix for client sending multiple headers)
        if (strpos($token, ',Bearer ') !== false) {
            $tokens = explode(',Bearer ', $token);
            $token = trim($tokens[0]); // Use the first token
            Log::info('Duplicate Authorization headers detected, using first token', [
                'uri' => $requestUri,
                'duplicate_count' => count($tokens),
                'original_length' => strlen($matches[1]),
                'cleaned_length' => strlen($token)
            ]);
        }
        $tokenPreview = substr($token, 0, 8) . '...' . substr($token, -4); // Show first 8 and last 4 chars

        // Log the exact token details for debugging hash mismatches
        Log::info('Token details for debugging', [
            'uri' => $requestUri,
            'token_preview' => $tokenPreview,
            'token_length' => strlen($token),
            'token_starts_with' => substr($token, 0, 10),
            'token_ends_with' => substr($token, -10),
            'token_has_spaces' => strpos($token, ' ') !== false,
            'token_has_plus' => strpos($token, '+') !== false,
            'raw_auth_header' => $authHeader
        ]);

        // Try to find the token in the personal_access_tokens table
        try {
            $accessToken = PersonalAccessToken::findToken($token);

            // Log additional debug info for failed token lookups
            if (!$accessToken) {
                // Try to get more info about what's in the database
                $tokenHash = hash('sha256', $token);
                $directLookup = DB::table('personal_access_tokens')
                    ->where('token', $tokenHash)
                    ->first();

                // Get info about what tokens DO exist for this user/token ID pattern
                $tokenPrefix = substr($token, 0, strpos($token, '|'));
                $similarTokens = DB::table('personal_access_tokens')
                    ->where('id', $tokenPrefix)
                    ->orWhere('name', 'api-token')
                    ->get(['id', 'name', 'created_at', 'expires_at']);

                Log::warning('API Auth failed: Token not found in database', [
                    'ip' => $clientIp,
                    'user_agent' => $userAgent,
                    'uri' => $requestUri,
                    'method' => $requestMethod,
                    'token_preview' => $tokenPreview,
                    'token_length' => strlen($token),
                    'token_hash_preview' => substr($tokenHash, 0, 16) . '...',
                    'token_prefix' => $tokenPrefix,
                    'direct_lookup_found' => $directLookup !== null,
                    'similar_tokens_count' => $similarTokens->count(),
                    'similar_tokens' => $similarTokens->toArray(),
                    'db_connection' => DB::connection()->getName(),
                    'reason' => 'token_not_found'
                ]);
                return response()->json(['error' => 'Invalid or expired token'], 401);
            }
        } catch (\Exception $e) {
            Log::error('API Auth failed: Database exception during token lookup', [
                'ip' => $clientIp,
                'user_agent' => $userAgent,
                'uri' => $requestUri,
                'method' => $requestMethod,
                'token_preview' => $tokenPreview,
                'exception' => $e->getMessage(),
                'exception_class' => get_class($e),
                'db_connection' => DB::connection()->getName(),
                'reason' => 'database_exception'
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

        // Log successful authentication with token preview for comparison
        Log::info('API Auth successful', [
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
            'reason' => 'auth_success'
        ]);

        // Set the user in the request
        Auth::setUser($user);
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        return $next($request);
    }
}
