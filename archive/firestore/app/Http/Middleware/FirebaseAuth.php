<?php

namespace App\Http\Middleware;

use App\Services\FirebaseAuthService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FirebaseAuth
{
    public function handle(Request $request, Closure $next)
    {
        $authHeader = $request->header('Authorization');
        $clientIp = $request->ip();
        $userAgent = $request->header('User-Agent');
        $requestUri = $request->getRequestUri();
        $requestMethod = $request->getMethod();

        if (!$authHeader || !preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
            Log::warning('Firebase Auth failed: Missing or malformed Authorization header', [
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

        $idToken = $matches[1];
        $tokenPreview = substr($idToken, 0, 12) . '...' . substr($idToken, -8); // Show first 12 and last 8 chars

        $firebaseAuth = new FirebaseAuthService();

        try {
            $uid = $firebaseAuth->verifyIdToken($idToken);
            if (!$uid) {
                Log::warning('Firebase Auth failed: Token verification returned null', [
                    'ip' => $clientIp,
                    'user_agent' => $userAgent,
                    'uri' => $requestUri,
                    'method' => $requestMethod,
                    'token_preview' => $tokenPreview,
                    'token_length' => strlen($idToken),
                    'reason' => 'token_verification_failed'
                ]);
                return response()->json(['error' => 'Invalid or expired Firebase token'], 401);
            }
        } catch (\Exception $e) {
            Log::warning('Firebase Auth failed: Exception during token verification', [
                'ip' => $clientIp,
                'user_agent' => $userAgent,
                'uri' => $requestUri,
                'method' => $requestMethod,
                'token_preview' => $tokenPreview,
                'token_length' => strlen($idToken),
                'exception' => $e->getMessage(),
                'exception_class' => get_class($e),
                'reason' => 'token_verification_exception'
            ]);
            return response()->json(['error' => 'Invalid or expired Firebase token'], 401);
        }

        // Attach UID to request for controller access
        $request->attributes->set('firebase_uid', $uid);

        return $next($request);
    }
}
