<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RequireStandardRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();
        // If not logged in or role is not set, block
        if (!$user || !isset($user->role)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        // Only allow if role is standard or higher
        $allowedRoles = ['standard', 'admin', 'superadmin'];
        if (!in_array($user->role, $allowedRoles)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return $next($request);
    }
}
