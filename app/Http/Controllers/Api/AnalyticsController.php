<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientEvent;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
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
                'timestamp' => 'required|integer',
                'metadata' => 'nullable|array',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'message' => 'Invalid input data',
                'errors' => $e->errors()
            ], 422);
        }

        $userId = Auth::id();
        $deviceId = $request->header('X-Device-ID', 'unknown');

        $clientEvent = ClientEvent::create([
            'user_id' => $userId,
            'device_id' => $deviceId,
            'event_type' => $validated['event_type'],
            'event_timestamp' => \Carbon\Carbon::createFromTimestampMs($validated['timestamp']),
            'metadata' => $validated['metadata'] ?? [],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Event recorded successfully',
            'data' => $clientEvent,
        ], 201);
    }
}
