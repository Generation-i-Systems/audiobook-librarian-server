<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckAdminRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $clientIp = $request->ip();
        $userAgent = $request->header('User-Agent');
        $requestUri = $request->getRequestUri();
        $requestMethod = $request->getMethod();

        if (!Auth::check()) {
            Log::warning('Admin access denied: User not authenticated', [
                'ip' => $clientIp,
                'user_agent' => $userAgent,
                'uri' => $requestUri,
                'method' => $requestMethod,
                'reason' => 'not_authenticated'
            ]);
            abort(403, 'Unauthorized. Admin access required.');
        }

        $user = Auth::user();

        if (!$user->isAdmin()) {
            Log::warning('Admin access denied: User lacks admin role', [
                'ip' => $clientIp,
                'user_agent' => $userAgent,
                'uri' => $requestUri,
                'method' => $requestMethod,
                'user_id' => $user->id ?? null,
                'user_email' => $user->email ?? null,
                'user_role' => $user->role ?? null,
                'reason' => 'insufficient_privileges'
            ]);
            abort(403, 'Unauthorized. Admin access required.');
        }

        return $next($request);
    }
}
