<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class RequireLibraryRole
{
    /**
     * Roles that may access library API endpoints.
     */
    private const ALLOWED_ROLES = ['library-user', 'librivox-user', 'hybrid-user', 'admin', 'super-admin'];

    /**
     * Roles that have a fixed source mode regardless of which host they access.
     * Hybrid users and admins follow the host-resolved source mode instead.
     */
    private const ROLE_SOURCE_MODE = [
        'library-user'  => 'local',
        'librivox-user' => 'librivox',
    ];

    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if (!$user || !isset($user->role)) {
            Log::warning('Library role access denied: not authenticated', [
                'uri' => $request->getRequestUri(),
                'reason' => !$user ? 'not_authenticated' : 'role_not_set',
            ]);
            if (!$request->expectsJson()) {
                return $next($request);
            }
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $role = $user->role;

        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            Log::warning('Library role access denied: insufficient role', [
                'uri' => $request->getRequestUri(),
                'user_id' => $user->id,
                'user_role' => $role,
            ]);
            if (!$request->expectsJson()) {
                return $next($request);
            }
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // For library-user and librivox-user, enforce source mode from the role
        // rather than from the host, so users always see their correct data
        // regardless of which hostname they connect through.
        // hybrid-user, admin, and super-admin use the host-resolved source mode.
        if (isset(self::ROLE_SOURCE_MODE[$role])) {
            config(['library_profiles.active_source_mode' => self::ROLE_SOURCE_MODE[$role]]);
        }

        return $next($request);
    }
}
