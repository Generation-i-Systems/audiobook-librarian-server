<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (!Auth::check()) {
            Log::warning('Permission access denied: User not authenticated', [
                'ip' => $request->ip(),
                'uri' => $request->getRequestUri(),
                'method' => $request->getMethod(),
                'permission' => $permission,
            ]);
            abort(403, 'Unauthorized.');
        }

        $user = Auth::user();

        if (!$user->hasPermission($permission)) {
            Log::warning('Permission access denied: User lacks permission', [
                'ip' => $request->ip(),
                'uri' => $request->getRequestUri(),
                'method' => $request->getMethod(),
                'user_id' => $user->id,
                'user_role' => $user->role ?? null,
                'permission' => $permission,
            ]);
            abort(403, 'Unauthorized.');
        }

        return $next($request);
    }
}
