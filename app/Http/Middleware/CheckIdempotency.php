<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CheckIdempotency
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $key = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');

        // Check body for sessionId if header is missing (as per user plan requirement)
        // "must accept an Idempotency-Key header or sessionId in the body"
        if (!$key && $request->has('sessionId')) {
            $key = $request->input('sessionId');
        }

        if (!$key) {
            // If no key, proceed normally (or should we strictly require it?
            // Usually idempotent endpoints are optional to be idempotent unless mandated.
            // Let's assume optional but strongly encouraged for these endpoints)
            return $next($request);
        }

        $userId = auth('api')->id() ?? 'guest';
        $cacheKey = "idempotency:{$userId}:{$key}";

        if (Cache::has($cacheKey)) {
            Log::info("Idempotency hit for key: {$key}");
            $cachedResponse = Cache::get($cacheKey);

            // Reconstruct JsonResponse if it was stored as array
            return response()->json($cachedResponse['content'], $cachedResponse['status'], $cachedResponse['headers']);
        }

        $response = $next($request);

        // Only cache successful JSON responses
        if ($response instanceof JsonResponse && $response->status() >= 200 && $response->status() < 300) {
            Cache::put($cacheKey, [
                'content' => $response->getData(true),
                'status' => $response->status(),
                'headers' => $response->headers->all(),
            ], now()->addHour()); // Cache for 1 hour
        }

        return $response;
    }
}
