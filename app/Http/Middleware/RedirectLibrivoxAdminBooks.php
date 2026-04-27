<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RedirectLibrivoxAdminBooks
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (config('library_profiles.active_source_mode') === 'librivox') {
            return redirect()->route('admin.librivox.index');
        }

        return $next($request);
    }
}
