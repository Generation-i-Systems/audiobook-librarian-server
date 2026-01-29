<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if ($user && ($user->role ?? null) === 'disabled') {
            Auth::logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            $message = 'Your account has been disabled. Please contact support.';

            if ($request->expectsJson() || $request->wantsJson()) {
                return response()->json([
                    'code' => 'ACCOUNT_DISABLED',
                    'message' => $message,
                ], 403);
            }

            return redirect()->route('login')->withErrors(['login' => $message]);
        }

        return $next($request);
    }
}
