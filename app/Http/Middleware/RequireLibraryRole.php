<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class RequireLibraryRole
{
    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();
        $clientIp = $request->ip();
        $userAgent = $request->header('User-Agent');
        $requestUri = $request->getRequestUri();
        $requestMethod = $request->getMethod();

        // If not logged in or role is not set, block
        if (!$user || !isset($user->role)) {
            Log::warning('Library role access denied: User not authenticated or missing role', [
                'ip' => $clientIp,
                'user_agent' => $userAgent,
                'uri' => $requestUri,
                'method' => $requestMethod,
                'user_id' => $user->id ?? null,
                'user_email' => $user->email ?? null,
                'user_role' => $user->role ?? 'not_set',
                'reason' => !$user ? 'not_authenticated' : 'role_not_set'
            ]);
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Only allow if role is library-level or higher
        $allowedRoles = ['library-user', 'standard', 'admin', 'super-admin'];
        if (!in_array($user->role, $allowedRoles, true)) {
            Log::warning('Library role access denied: Insufficient role', [
                'ip' => $clientIp,
                'user_agent' => $userAgent,
                'uri' => $requestUri,
                'method' => $requestMethod,
                'user_id' => $user->id,
                'user_email' => $user->email,
                'user_role' => $user->role,
                'allowed_roles' => $allowedRoles,
                'reason' => 'insufficient_role'
            ]);
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
