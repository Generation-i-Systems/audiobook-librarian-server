<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DemoMode
{
    private const BLOCKED_PREFIXES = [
        'admin/books',
        'admin/authors',
        'admin/narrators',
        'admin/genres',
        'admin/series',
        'admin/tags',
        'admin/library',
        'admin/import',
        'admin/repair',
        'admin/librivox',
        'api/v1/books/import',
        'api/v1/books/*/edit',
        'api/v1/admin',
    ];

    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        if (!config('app.demo_mode', false)) {
            return $next($request);
        }

        if (!in_array($request->method(), self::MUTATING_METHODS, true)) {
            return $next($request);
        }

        $path = ltrim($request->path(), '/');

        foreach (self::BLOCKED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                if ($request->wantsJson() || $request->is('api/*')) {
                    return response()->json([
                        'error' => true,
                        'message' => 'This is a read-only demo. Write operations are disabled.',
                        'demo_mode' => true,
                    ], 403);
                }

                return back()->withErrors([
                    'demo' => 'This is a read-only demo. Write operations are disabled.',
                ]);
            }
        }

        return $next($request);
    }
}
