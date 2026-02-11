<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientEvent;
use App\Services\BadgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class AnalyticsController extends Controller
{
    /**
     * Record generic client event (OpenAPI spec)
     */
    public function recordEvent(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'event_type' => 'required|string',
                'timestamp'  => 'required|integer',
                'metadata'   => 'nullable|array',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'error'   => 'Validation failed',
                'message' => 'Invalid input data',
                'errors'  => $e->errors(),
            ], 422);
        }

        $userId   = Auth::id();
        $deviceId = $request->header('X-Device-ID', 'unknown');

        $clientEvent = ClientEvent::create([
            'user_id'         => $userId,
            'device_id'       => $deviceId,
            'event_type'      => $validated['event_type'],
            'event_timestamp' => \Carbon\Carbon::createFromTimestampMs($validated['timestamp']),
            'metadata'        => $validated['metadata'] ?? [],
        ]);

        $badgesEarned = [];
        if ($userId) {
            $cacheKey = "user_stats_{$userId}" . ($deviceId && $deviceId !== 'unknown' ? "_{$deviceId}" : '');
            Cache::forget($cacheKey);

            $badgeService = app(BadgeService::class);
            $newBadges    = $badgeService->evaluateUserBadges(
                (string) $userId,
                $deviceId !== 'unknown' ? $deviceId : null
            );
            $badgesEarned = array_map(fn ($ub) => [
                'badge_id'   => $ub->badge_id,
                'badge_name' => $ub->badge?->name,
                'badge_key'  => $ub->badge?->key,
            ], $newBadges);
        }

        return response()->json([
            'success'       => true,
            'message'       => 'Event recorded successfully',
            'data'          => $clientEvent,
            'badges_earned' => $badgesEarned,
        ], 201);
    }
}
