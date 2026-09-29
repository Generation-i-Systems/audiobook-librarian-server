<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\CommunityStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses community endpoints when the features are switched off, and on a demo
 * install where users are strangers to each other. The error code lets the app
 * explain that community features need a self-hosted server.
 */
class EnsureCommunityAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        $mode = CommunityStatus::mode();

        if ($mode === CommunityStatus::MODE_FULL) {
            return $next($request);
        }

        if ($mode === CommunityStatus::MODE_DEMO) {
            return response()->json([
                'error' => 'community_demo_only',
                'message' => 'Community features are only available on self-hosted servers.',
            ], 403);
        }

        return response()->json([
            'error' => 'community_disabled',
            'message' => 'Community features are turned off on this server.',
        ], 403);
    }
}
