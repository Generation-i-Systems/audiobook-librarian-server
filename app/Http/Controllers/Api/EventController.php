<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\ListeningEvent;
use App\Services\BadgeService;
use App\Services\PositionMaterializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\ControllerDatabaseService as ControllerDatabase;
use Illuminate\Support\Facades\Log;

class EventController extends Controller
{
    public function __construct(
        private readonly PositionMaterializer $positionMaterializer,
    ) {
    }

    /**
     * Sync events (bidirectional).
     *
     * Push local events to backend and pull remote events from other devices.
     * Supports time-range cursor pagination for pulling remote events.
     */
    public function sync(Request $request): JsonResponse
    {
        $user     = auth()->user();
        $deviceId = $request->header('X-Device-ID');

        if (! $deviceId) {
            return response()->json([
                'success' => false,
                'error'   => 'X-Device-ID header is required',
            ], 400);
        }

        $validated = $request->validate([
            'events'                     => 'present|array|max:100',
            'events.*.id'                => 'required|string|max:255',
            'events.*.bookId'            => 'required|integer',
            'events.*.bookPath'          => 'nullable|string|max:500',
            'events.*.eventType'         => 'required|string|max:50',
            'events.*.timestampMs'       => 'required|integer|min:0',
            'events.*.positionMs'        => 'required|integer|min:0',
            'events.*.metadata'          => 'nullable|array',
            'events.*.deviceId'          => 'required|string|max:255',
            'events.*.timezone'          => 'required|string|max:50',
            'events.*.createdAt'         => 'required|integer|min:0',
            'events.*.migratedFrom'      => 'nullable|string|max:50',
            'events.*.migrationSourceId' => 'nullable|string|max:255',
            'lastSyncTimestamp'          => 'required|integer|min:0',
            'syncAfter'                  => 'nullable|integer|min:0',
        ]);

        $receivedCount   = 0;
        $skippedCount    = 0;
        $serverTimestamp = (int) (now()->timestamp * 1000);
        $hasSessionEnd   = false;

        ControllerDatabase::beginTransaction();
        try {
            foreach ($validated['events'] as $eventData) {
                if (ListeningEvent::where('id', $eventData['id'])->exists()) {
                    continue;
                }

                if (! empty($eventData['migratedFrom'])) {
                    continue;
                }

                $resolvedBookId = $eventData['bookId'];
                if (! Book::where('id', $resolvedBookId)->exists()) {
                    $bookPath = $eventData['bookPath'] ?? null;
                    if ($bookPath) {
                        $book = Book::where('directory_path', 'like', '%' . basename($bookPath) . '%')->first();
                        if ($book) {
                            $resolvedBookId = $book->id;
                        }
                    }
                }

                ListeningEvent::create([
                    'id'                  => $eventData['id'],
                    'user_id'             => $user->id,
                    'book_id'             => $resolvedBookId,
                    'event_type'          => $eventData['eventType'],
                    'timestamp_ms'        => $eventData['timestampMs'],
                    'position_ms'         => $eventData['positionMs'],
                    'metadata'            => $eventData['metadata'] ?? null,
                    'device_id'           => $eventData['deviceId'],
                    'timezone'            => $eventData['timezone'],
                    'sync_status'         => 'SYNCED',
                    'created_at'          => $eventData['createdAt'],
                    'synced_at'           => $serverTimestamp,
                    'migrated_from'       => $eventData['migratedFrom'] ?? null,
                    'migration_source_id' => $eventData['migrationSourceId'] ?? null,
                ]);

                $this->positionMaterializer->materialize([
                    'id'           => $eventData['id'],
                    'user_id'      => $user->id,
                    'book_id'      => $resolvedBookId,
                    'event_type'   => $eventData['eventType'],
                    'timestamp_ms' => $eventData['timestampMs'],
                    'position_ms'  => $eventData['positionMs'],
                    'metadata'     => $eventData['metadata'] ?? [],
                    'device_id'    => $eventData['deviceId'],
                ]);

                if ($eventData['eventType'] === 'SESSION_END') {
                    $hasSessionEnd = true;
                }

                $receivedCount++;
            }

            // Time-range cursor pagination for remote events
            $syncAfter = $validated['syncAfter'] ?? $validated['lastSyncTimestamp'];

            $remoteQuery = ListeningEvent::where('user_id', $user->id)
                ->where('synced_at', '>=', $syncAfter)
                ->whereNull('migrated_from')
                ->orderBy('synced_at', 'asc')
                ->limit(101);

            $remoteEvents = $remoteQuery->get();
            $hasMore      = $remoteEvents->count() > 100;
            if ($hasMore) {
                $remoteEvents = $remoteEvents->take(100);
            }

            if ($remoteEvents->isNotEmpty()) {
                // Advance cursor by 1ms past the last returned event so the next
                // >= syncAfter request does not re-fetch boundary events.
                // Duplicates that slip through are de-duplicated on the client by ID.
                $nextSyncAfter = $remoteEvents->last()->synced_at + 1;
            } else {
                // Keep cursor where it was — do not advance past events
                // that other devices may push between now and the next sync.
                $nextSyncAfter = $syncAfter;
            }

            $mappedEvents = $remoteEvents->map(function ($event) {
                return [
                    'id'                => $event->id,
                    'bookId'            => $event->book_id,
                    'eventType'         => $event->event_type,
                    'timestampMs'       => $event->timestamp_ms,
                    'positionMs'        => $event->position_ms,
                    'metadata'          => $event->metadata,
                    'deviceId'          => $event->device_id,
                    'timezone'          => $event->timezone,
                    'syncStatus'        => $event->sync_status,
                    'createdAt'         => $event->created_at,
                    'syncedAt'          => $event->synced_at,
                    'migratedFrom'      => $event->migrated_from,
                    'migrationSourceId' => $event->migration_source_id,
                ];
            });

            ControllerDatabase::commit();

            // Evaluate badges on SESSION_END events (outside transaction)
            $badgesEarned = [];
            if ($hasSessionEnd) {
                try {
                    $badgeService = app(BadgeService::class);
                    $newBadges    = $badgeService->evaluateUserBadges((string) $user->id, $deviceId);
                    if (! empty($newBadges)) {
                        $badgesEarned = array_map(function ($userBadge) {
                            return [
                                'id'          => $userBadge->badge->id,
                                'key'         => $userBadge->badge->key,
                                'name'        => $userBadge->badge->name,
                                'description' => $userBadge->badge->description,
                                'icon'        => $userBadge->badge->icon,
                                'tier'        => $userBadge->badge->tier,
                                'points'      => $userBadge->badge->points,
                                'earned_at'   => $userBadge->earned_at->toISOString(),
                            ];
                        }, $newBadges);
                    }
                } catch (\Exception $e) {
                    Log::warning('Badge evaluation failed during event sync', [
                        'user_id' => $user->id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'success'         => true,
                'received'        => $receivedCount,
                'skipped'         => $skippedCount,
                'remoteEvents'    => $mappedEvents,
                'serverTimestamp' => $serverTimestamp,
                'hasMore'         => $hasMore,
                'nextSyncAfter'   => $nextSyncAfter,
                'badgesEarned'    => $badgesEarned,
            ]);
        } catch (\Exception $e) {
            ControllerDatabase::rollBack();
            Log::error('Event sync failed', [
                'user_id'   => $user->id,
                'device_id' => $deviceId,
                'error'     => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error'   => 'Failed to sync events',
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
            'endTime'   => 'nullable|integer|min:0',
            'eventType' => 'nullable|string',
            'limit'     => 'nullable|integer|min:1|max:1000',
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

        $limit  = $validated['limit'] ?? 100;
        $events = $query->orderBy('timestamp_ms', 'desc')
            ->limit($limit + 1)
            ->get();

        $hasMore = $events->count() > $limit;
        if ($hasMore) {
            $events = $events->take($limit);
        }

        return response()->json([
            'success' => true,
            'bookId'  => $bookId,
            'events'  => $events,
            'count'   => $events->count(),
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
            'endTime'   => 'nullable|integer|min:0',
            'bookId'    => 'nullable|integer',
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

        $totalEvents = $query->count();

        $startTime = $validated['startTime'] ?? null;
        $endTime   = $validated['endTime'] ?? null;
        $bookId    = $validated['bookId'] ?? null;

        $scopeQuery = fn ($q) => $q
            ->where('user_id', $user->id)
            ->when($startTime, fn ($q) => $q->where('timestamp_ms', '>=', $startTime))
            ->when($endTime, fn ($q) => $q->where('timestamp_ms', '<=', $endTime))
            ->when($bookId, fn ($q) => $q->where('book_id', $bookId));

        $totalListeningTime = ListeningEvent::query()
            ->where('event_type', 'SESSION_END')
            ->tap($scopeQuery)
            ->get()
            ->sum(fn ($event) => $event->metadata['adjustedDurationMs'] ?? 0);

        $booksStarted = ListeningEvent::query()
            ->where('event_type', 'BOOK_START')
            ->tap($scopeQuery)
            ->distinct('book_id')
            ->count('book_id');

        $booksFinishedByListening = ListeningEvent::query()
            ->where('event_type', 'BOOK_FINISH')
            ->tap($scopeQuery)
            ->distinct('book_id')
            ->count('book_id');

        $booksMarkedComplete = ListeningEvent::query()
            ->where('event_type', 'BOOK_MARK_COMPLETE')
            ->tap($scopeQuery)
            ->distinct('book_id')
            ->count('book_id');

        return response()->json([
            'success' => true,
            'stats'   => [
                'totalEvents'              => $totalEvents,
                'totalListeningTime'       => $totalListeningTime,
                'booksStarted'             => $booksStarted,
                'booksFinished'            => $booksFinishedByListening + $booksMarkedComplete,
                'booksFinishedByListening' => $booksFinishedByListening,
                'booksMarkedComplete'      => $booksMarkedComplete,
            ],
        ]);
    }
}
