<?php

namespace App\Http\Middleware;

use App\Services\FirebaseAuthService;
use Closure;
use Illuminate\Http\Request;

class FirebaseAuth
{
    public function handle(Request $request, Closure $next)
    {
        $authHeader = $request->header('Authorization');
        if (!$authHeader || !preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $idToken = $matches[1];
        $firebaseAuth = new FirebaseAuthService();
        $uid = $firebaseAuth->verifyIdToken($idToken);
        if (!$uid) {
            return response()->json(['error' => 'Invalid or expired Firebase token'], 401);
        }

        // Attach UID to request for controller access
        $request->attributes->set('firebase_uid', $uid);

        return $next($request);
    }
}
