<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ListeningEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EventController extends Controller
{
    /**
     * Sync events (bidirectional).
     *
     * Push local events to backend and pull remote events from other devices.
     */
    public function sync(Request $request): JsonResponse
    {
        $user = auth()->user();
        $deviceId = $request->header('X-Device-ID');

        if (! $deviceId) {
            return response()->json([
                'success' => false,
                'error' => 'X-Device-ID header is required',
            ], 400);
        }

        $validated = $request->validate([
            'events' => 'required|array|max:100',
            'events.*.id' => 'required|string|max:255',
            'events.*.bookId' => 'required|integer',
            'events.*.eventType' => 'required|string',
            'events.*.timestampMs' => 'required|integer|min:0',
            'events.*.positionMs' => 'required|integer|min:0',
            'events.*.metadata' => 'nullable|array',
            'events.*.deviceId' => 'required|string|max:255',
            'events.*.timezone' => 'required|string|max:50',
            'events.*.createdAt' => 'required|integer|min:0',
            'events.*.migratedFrom' => 'nullable|string|max:50',
            'events.*.migrationSourceId' => 'nullable|string|max:255',
            'lastSyncTimestamp' => 'required|integer|min:0',
        ]);

        $receivedCount = 0;
        $serverTimestamp = (int) (now()->timestamp * 1000);

        DB::beginTransaction();
        try {
            // Process incoming events
            foreach ($validated['events'] as $eventData) {
                // Skip if event already exists (deduplication)
                if (ListeningEvent::where('id', $eventData['id'])->exists()) {
                    continue;
                }

                // Skip migrated events (LOCAL_ONLY)
                if (! empty($eventData['migratedFrom'])) {
                    continue;
                }

                // Create event
                ListeningEvent::create([
                    'id' => $eventData['id'],
                    'user_id' => $user->id,
                    'book_id' => $eventData['bookId'],
                    'event_type' => $eventData['eventType'],
                    'timestamp_ms' => $eventData['timestampMs'],
                    'position_ms' => $eventData['positionMs'],
                    'metadata' => $eventData['metadata'] ?? null,
                    'device_id' => $eventData['deviceId'],
                    'timezone' => $eventData['timezone'],
                    'sync_status' => 'SYNCED',
                    'created_at' => $eventData['createdAt'],
                    'synced_at' => $serverTimestamp,
                    'migrated_from' => $eventData['migratedFrom'] ?? null,
                    'migration_source_id' => $eventData['migrationSourceId'] ?? null,
                ]);

                $receivedCount++;
            }

            // Get remote events (from other devices since lastSyncTimestamp)
            $remoteEvents = ListeningEvent::where('user_id', $user->id)
                ->where('synced_at', '>', $validated['lastSyncTimestamp'])
                ->where('device_id', '!=', $deviceId)
                ->whereNull('migrated_from')  // Don't sync migrated events
                ->orderBy('synced_at', 'asc')
                ->limit(100)
                ->get()
                ->map(function ($event) {
                    return [
                        'id' => $event->id,
                        'bookId' => $event->book_id,
                        'eventType' => $event->event_type,
                        'timestampMs' => $event->timestamp_ms,
                        'positionMs' => $event->position_ms,
                        'metadata' => $event->metadata,
                        'deviceId' => $event->device_id,
                        'timezone' => $event->timezone,
                        'syncStatus' => $event->sync_status,
                        'createdAt' => $event->created_at,
                        'syncedAt' => $event->synced_at,
                        'migratedFrom' => $event->migrated_from,
                        'migrationSourceId' => $event->migration_source_id,
                    ];
                });

            DB::commit();

            return response()->json([
                'success' => true,
                'received' => $receivedCount,
                'remoteEvents' => $remoteEvents,
                'serverTimestamp' => $serverTimestamp,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Event sync failed', [
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to sync events',
            ], 500);
        }
    }

    /**
     * Get events for a specific book.
     */
    public function getBookEvents(Request $request, int $bookId): JsonResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'startTime' => 'nullable|integer|min:0',
            'endTime' => 'nullable|integer|min:0',
            'eventType' => 'nullable|string',
            'limit' => 'nullable|integer|min:1|max:1000',
        ]);

        $query = ListeningEvent::where('user_id', $user->id)
            ->where('book_id', $bookId);

        if (! empty($validated['startTime'])) {
            $query->where('timestamp_ms', '>=', $validated['startTime']);
        }

        if (! empty($validated['endTime'])) {
            $query->where('timestamp_ms', '<=', $validated['endTime']);
        }

        if (! empty($validated['eventType'])) {
            $query->where('event_type', $validated['eventType']);
        }

        $limit = $validated['limit'] ?? 100;
        $events = $query->orderBy('timestamp_ms', 'desc')
            ->limit($limit + 1)  // Get one extra to check if there are more
            ->get();

        $hasMore = $events->count() > $limit;
        if ($hasMore) {
            $events = $events->take($limit);
        }

        return response()->json([
            'success' => true,
            'bookId' => $bookId,
            'events' => $events,
            'count' => $events->count(),
            'hasMore' => $hasMore,
        ]);
    }

    /**
     * Get event statistics.
     */
    public function getStats(Request $request): JsonResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'startTime' => 'nullable|integer|min:0',
            'endTime' => 'nullable|integer|min:0',
            'bookId' => 'nullable|integer',
        ]);

        $query = ListeningEvent::where('user_id', $user->id);

        if (! empty($validated['startTime'])) {
            $query->where('timestamp_ms', '>=', $validated['startTime']);
        }

        if (! empty($validated['endTime'])) {
            $query->where('timestamp_ms', '<=', $validated['endTime']);
        }

        if (! empty($validated['bookId'])) {
            $query->where('book_id', $validated['bookId']);
        }

        // Calculate statistics
        $totalEvents = $query->count();

        $totalListeningTime = ListeningEvent::where('user_id', $user->id)
            ->where('event_type', 'SESSION_END')
            ->when(! empty($validated['startTime']), fn ($q) => $q->where('timestamp_ms', '>=', $validated['startTime']))
            ->when(! empty($validated['endTime']), fn ($q) => $q->where('timestamp_ms', '<=', $validated['endTime']))
            ->when(! empty($validated['bookId']), fn ($q) => $q->where('book_id', $validated['bookId']))
            ->get()
            ->sum(fn ($event) => $event->metadata['adjustedDurationMs'] ?? 0);

        $booksStarted = ListeningEvent::where('user_id', $user->id)
            ->where('event_type', 'BOOK_START')
            ->when(! empty($validated['startTime']), fn ($q) => $q->where('timestamp_ms', '>=', $validated['startTime']))
            ->when(! empty($validated['endTime']), fn ($q) => $q->where('timestamp_ms', '<=', $validated['endTime']))
            ->distinct('book_id')
            ->count('book_id');

        $booksFinishedByListening = ListeningEvent::where('user_id', $user->id)
            ->where('event_type', 'BOOK_FINISH')
            ->when(! empty($validated['startTime']), fn ($q) => $q->where('timestamp_ms', '>=', $validated['startTime']))
            ->when(! empty($validated['endTime']), fn ($q) => $q->where('timestamp_ms', '<=', $validated['endTime']))
            ->distinct('book_id')
            ->count('book_id');

        $booksMarkedComplete = ListeningEvent::where('user_id', $user->id)
            ->where('event_type', 'BOOK_MARK_COMPLETE')
            ->when(! empty($validated['startTime']), fn ($q) => $q->where('timestamp_ms', '>=', $validated['startTime']))
            ->when(! empty($validated['endTime']), fn ($q) => $q->where('timestamp_ms', '<=', $validated['endTime']))
            ->distinct('book_id')
            ->count('book_id');

        return response()->json([
            'success' => true,
            'stats' => [
                'totalEvents' => $totalEvents,
                'totalListeningTime' => $totalListeningTime,
                'booksStarted' => $booksStarted,
                'booksFinished' => $booksFinishedByListening + $booksMarkedComplete,
                'booksFinishedByListening' => $booksFinishedByListening,
                'booksMarkedComplete' => $booksMarkedComplete,
            ],
        ]);
    }
}
