# Backend Changes for Event Synchronization System

**Date:** 2026-02-14
**Priority:** HIGH
**Status:** Specification Ready for Implementation

---

## 🎯 Overview

The event synchronization system requires backend support for:
1. **Event Storage** - Store events from all devices
2. **Event Synchronization** - Bidirectional sync across devices
3. **Event Deduplication** - Prevent duplicate events
4. **Statistics Derivation** - Calculate stats from events
5. **Migration Support** - Handle migrated events

---

## 📊 Database Schema Changes

### New Table: `listening_events`

```sql
CREATE TABLE listening_events (
    -- Primary identification
    id VARCHAR(255) PRIMARY KEY,  -- UUID from client
    user_id BIGINT UNSIGNED NOT NULL,
    book_id BIGINT UNSIGNED NOT NULL,

    -- Event details
    event_type VARCHAR(50) NOT NULL,  -- PLAY_START, SESSION_END, etc.
    timestamp_ms BIGINT UNSIGNED NOT NULL,  -- When event occurred (UTC)
    position_ms BIGINT UNSIGNED NOT NULL,  -- Playback position

    -- Metadata (JSON)
    metadata JSON DEFAULT NULL,  -- Type-specific data

    -- Device tracking
    device_id VARCHAR(255) NOT NULL,  -- Device that generated event
    timezone VARCHAR(50) NOT NULL,  -- User's timezone

    -- Sync tracking
    sync_status VARCHAR(20) NOT NULL DEFAULT 'SYNCED',  -- Always SYNCED on backend
    created_at BIGINT UNSIGNED NOT NULL,  -- When event was created on client
    synced_at BIGINT UNSIGNED NOT NULL,  -- When event was received by backend

    -- Migration tracking
    migrated_from VARCHAR(50) DEFAULT NULL,  -- Source system (if migrated)
    migration_source_id VARCHAR(255) DEFAULT NULL,  -- Original record ID

    -- Indexes
    INDEX idx_user_book (user_id, book_id),
    INDEX idx_user_timestamp (user_id, timestamp_ms),
    INDEX idx_user_event_type (user_id, event_type),
    INDEX idx_book_timestamp (book_id, timestamp_ms),
    INDEX idx_device (device_id),
    INDEX idx_synced_at (synced_at),

    -- Foreign keys
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,

    -- Constraints
    CONSTRAINT chk_event_type CHECK (event_type IN (
        'PLAY_START', 'PLAY_PAUSE', 'PLAY_RESUME', 'PLAY_STOP',
        'SESSION_START', 'SESSION_END',
        'BOOK_START', 'BOOK_FINISH', 'BOOK_MARK_COMPLETE', 'BOOK_UNMARK_COMPLETE',
        'BOOKMARK_CREATE', 'BOOKMARK_DELETE',
        'SEEK'
    )),
    CONSTRAINT chk_sync_status CHECK (sync_status IN (
        'LOCAL_ONLY', 'PENDING_SYNC', 'SYNCED', 'SYNC_FAILED'
    ))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Event Metadata Structure

The `metadata` JSON field contains type-specific data:

```json
// For playback events (PLAY_START, PLAY_PAUSE, etc.)
{
  "playbackSpeed": 1.5,
  "chapterIndex": 3,
  "chapterName": "Chapter 4: The Journey"
}

// For session end events
{
  "sessionDurationMs": 3600000,
  "adjustedDurationMs": 2400000,
  "playbackSpeed": 1.5,
  "pauseCount": 5
}

// For book finish events
{
  "completionCount": 1
}

// For manual completion events
{
  "manuallyMarked": true,
  "completionCount": 1
}

// For bookmark events
{
  "bookmarkId": "uuid-here",
  "note": "Important quote"
}
```

---

## 🔌 API Endpoints

### 1. Sync Events (Bidirectional)

**POST** `/api/v1/events/sync`

**Purpose:** Push local events to backend and pull remote events from other devices.

**Request:**
```json
{
  "events": [
    {
      "id": "uuid-1234-5678",
      "bookId": 42,
      "eventType": "SESSION_END",
      "timestampMs": 1707945600000,
      "positionMs": 1234567,
      "metadata": {
        "sessionDurationMs": 3600000,
        "adjustedDurationMs": 2400000,
        "playbackSpeed": 1.5,
        "pauseCount": 5
      },
      "deviceId": "android-device-123",
      "timezone": "America/Denver",
      "syncStatus": "PENDING_SYNC",
      "createdAt": 1707945600000,
      "syncedAt": null,
      "migratedFrom": null,
      "migrationSourceId": null
    }
  ],
  "lastSyncTimestamp": 1707945000000  // Last time this device synced
}
```

**Response:**
```json
{
  "success": true,
  "received": 1,  // Number of events received from client
  "remoteEvents": [  // Events from other devices since lastSyncTimestamp
    {
      "id": "uuid-9999-8888",
      "bookId": 42,
      "eventType": "BOOKMARK_CREATE",
      "timestampMs": 1707945700000,
      "positionMs": 2345678,
      "metadata": {
        "bookmarkId": "bookmark-uuid",
        "note": "Remember this"
      },
      "deviceId": "ios-device-456",
      "timezone": "America/Denver",
      "syncStatus": "SYNCED",
      "createdAt": 1707945700000,
      "syncedAt": 1707945705000,
      "migratedFrom": null,
      "migrationSourceId": null
    }
  ],
  "serverTimestamp": 1707945800000
}
```

**Logic:**
1. Validate user authentication
2. For each incoming event:
   - Check if event ID already exists (deduplication)
   - If exists and `migratedFrom` is set, skip (migrated events are LOCAL_ONLY)
   - If exists and not migrated, skip (already synced)
   - If new, insert into `listening_events` table
3. Query events from other devices since `lastSyncTimestamp`
4. Return remote events and current server timestamp

**Error Responses:**
```json
// 401 Unauthorized
{
  "success": false,
  "error": "Unauthorized"
}

// 400 Bad Request
{
  "success": false,
  "error": "Invalid event data",
  "details": ["Event ID is required", "Invalid event type"]
}

// 500 Internal Server Error
{
  "success": false,
  "error": "Database error"
}
```

---

### 2. Get Book Events

**GET** `/api/v1/events/book/:bookId`

**Purpose:** Get all events for a specific book (for debugging/analytics).

**Query Parameters:**
- `startTime` (optional): Filter events after this timestamp (ms)
- `endTime` (optional): Filter events before this timestamp (ms)
- `eventType` (optional): Filter by event type
- `limit` (optional): Limit number of results (default: 100, max: 1000)

**Response:**
```json
{
  "success": true,
  "bookId": 42,
  "events": [
    {
      "id": "uuid-1234",
      "eventType": "SESSION_END",
      "timestampMs": 1707945600000,
      "positionMs": 1234567,
      "metadata": { ... },
      "deviceId": "android-device-123",
      "timezone": "America/Denver",
      "createdAt": 1707945600000,
      "syncedAt": 1707945605000
    }
  ],
  "count": 1,
  "hasMore": false
}
```

---

### 3. Get Recent Events

**GET** `/api/v1/events/recent`

**Purpose:** Get recent events for the authenticated user.

**Query Parameters:**
- `days` (optional): Number of days to look back (default: 30, max: 365)
- `eventType` (optional): Filter by event type
- `limit` (optional): Limit number of results (default: 100, max: 1000)

**Response:**
```json
{
  "success": true,
  "events": [ ... ],
  "count": 42,
  "hasMore": false
}
```

---

### 4. Get Event Statistics

**GET** `/api/v1/events/stats`

**Purpose:** Get aggregated statistics from events.

**Query Parameters:**
- `startTime` (optional): Start of time range (ms)
- `endTime` (optional): End of time range (ms)
- `bookId` (optional): Filter by book

**Response:**
```json
{
  "success": true,
  "stats": {
    "totalEvents": 1234,
    "totalListeningTime": 86400000,  // ms
    "booksStarted": 10,
    "booksFinished": 5,
    "booksFinishedByListening": 4,
    "booksMarkedComplete": 1,
    "bookmarksCreated": 15,
    "sessionsCount": 50,
    "averageSessionDuration": 1800000,  // ms
    "uniqueDevices": 2,
    "earliestEvent": 1707000000000,
    "latestEvent": 1707945600000
  }
}
```

---

### 5. Delete Events (Admin/Debug)

**DELETE** `/api/v1/events/:eventId`

**Purpose:** Delete a specific event (admin only, for debugging).

**Response:**
```json
{
  "success": true,
  "message": "Event deleted"
}
```

---

## 🔄 Migration Considerations

### Handling Migrated Events

**Important:** Events with `migratedFrom` set should be marked as `LOCAL_ONLY` on the client and **should not be synced to the backend**.

However, if a migrated event does arrive at the backend:
1. Accept it (don't error)
2. Store it in the database
3. **Do not** send it back to other devices
4. Mark it with a flag for exclusion from cross-device sync

**Why?** Migrated events represent historical data from the old system. Each device will migrate its own local data, so we don't want to duplicate this across devices.

### Migration Query

```sql
-- Get events that should NOT be synced to other devices
SELECT * FROM listening_events
WHERE user_id = ?
  AND migrated_from IS NOT NULL;
```

---

## 📈 Statistics Derivation

### Replace Existing Statistics Logic

The backend currently has statistics logic in `StatisticsService` or similar. This should be **refactored to derive statistics from events**.

### Example Queries

**Total Listening Time:**
```sql
SELECT SUM(JSON_EXTRACT(metadata, '$.adjustedDurationMs')) as total_time
FROM listening_events
WHERE user_id = ?
  AND event_type = 'SESSION_END'
  AND timestamp_ms >= ?  -- start of period
  AND timestamp_ms <= ?; -- end of period
```

**Books Finished (by listening):**
```sql
SELECT DISTINCT book_id
FROM listening_events
WHERE user_id = ?
  AND event_type = 'BOOK_FINISH';
```

**Books Marked Complete (manually):**
```sql
SELECT DISTINCT book_id
FROM listening_events
WHERE user_id = ?
  AND event_type = 'BOOK_MARK_COMPLETE';
```

**Current Book Position:**
```sql
-- Get most recent position event for a book
SELECT position_ms
FROM listening_events
WHERE user_id = ?
  AND book_id = ?
  AND event_type IN ('PLAY_START', 'PLAY_PAUSE', 'PLAY_STOP', 'SEEK')
ORDER BY timestamp_ms DESC
LIMIT 1;
```

**Session Count:**
```sql
SELECT COUNT(*) as session_count
FROM listening_events
WHERE user_id = ?
  AND event_type = 'SESSION_END'
  AND timestamp_ms >= ?
  AND timestamp_ms <= ?;
```

---

## 🔐 Security Considerations

### Authentication
- All endpoints require authentication
- User can only access their own events
- Admin endpoints require admin role

### Authorization
```php
// Ensure user can only access their own events
if ($event->user_id !== Auth::id()) {
    abort(403, 'Unauthorized');
}
```

### Rate Limiting
```php
// Limit sync requests to prevent abuse
RateLimiter::for('event-sync', function (Request $request) {
    return Limit::perMinute(10)->by($request->user()->id);
});
```

### Input Validation
```php
$validated = $request->validate([
    'events' => 'required|array|max:100',  // Max 100 events per request
    'events.*.id' => 'required|string|max:255',
    'events.*.bookId' => 'required|integer|exists:books,id',
    'events.*.eventType' => 'required|in:PLAY_START,PLAY_PAUSE,...',
    'events.*.timestampMs' => 'required|integer|min:0',
    'events.*.positionMs' => 'required|integer|min:0',
    'events.*.deviceId' => 'required|string|max:255',
    'events.*.timezone' => 'required|string|max:50',
    'lastSyncTimestamp' => 'required|integer|min:0',
]);
```

---

## 🏗️ Implementation Guide

### Phase 1: Database Setup

1. **Create Migration:**
```bash
php artisan make:migration create_listening_events_table
```

2. **Run Migration:**
```bash
php artisan migrate
```

3. **Create Model:**
```php
// app/Models/ListeningEvent.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ListeningEvent extends Model
{
    protected $table = 'listening_events';
    public $incrementing = false;  // UUID primary key
    protected $keyType = 'string';
    public $timestamps = false;  // We manage timestamps manually

    protected $fillable = [
        'id', 'user_id', 'book_id', 'event_type', 'timestamp_ms',
        'position_ms', 'metadata', 'device_id', 'timezone',
        'sync_status', 'created_at', 'synced_at',
        'migrated_from', 'migration_source_id'
    ];

    protected $casts = [
        'metadata' => 'array',
        'timestamp_ms' => 'integer',
        'position_ms' => 'integer',
        'created_at' => 'integer',
        'synced_at' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function book()
    {
        return $this->belongsTo(Book::class);
    }
}
```

---

### Phase 2: Create Controller

```php
// app/Http/Controllers/Api/EventController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ListeningEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EventController extends Controller
{
    /**
     * Sync events (bidirectional)
     */
    public function sync(Request $request)
    {
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

        $userId = Auth::id();
        $receivedCount = 0;
        $serverTimestamp = now()->timestamp * 1000;  // Convert to ms

        DB::beginTransaction();
        try {
            // Process incoming events
            foreach ($validated['events'] as $eventData) {
                // Skip if event already exists (deduplication)
                if (ListeningEvent::where('id', $eventData['id'])->exists()) {
                    continue;
                }

                // Skip migrated events (LOCAL_ONLY)
                if (!empty($eventData['migratedFrom'])) {
                    continue;
                }

                // Create event
                ListeningEvent::create([
                    'id' => $eventData['id'],
                    'user_id' => $userId,
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
            $remoteEvents = ListeningEvent::where('user_id', $userId)
                ->where('synced_at', '>', $validated['lastSyncTimestamp'])
                ->where('device_id', '!=', $validated['events'][0]['deviceId'] ?? '')
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
            return response()->json([
                'success' => false,
                'error' => 'Failed to sync events',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get events for a specific book
     */
    public function getBookEvents(Request $request, $bookId)
    {
        $validated = $request->validate([
            'startTime' => 'nullable|integer|min:0',
            'endTime' => 'nullable|integer|min:0',
            'eventType' => 'nullable|string',
            'limit' => 'nullable|integer|min:1|max:1000',
        ]);

        $query = ListeningEvent::where('user_id', Auth::id())
            ->where('book_id', $bookId);

        if (!empty($validated['startTime'])) {
            $query->where('timestamp_ms', '>=', $validated['startTime']);
        }

        if (!empty($validated['endTime'])) {
            $query->where('timestamp_ms', '<=', $validated['endTime']);
        }

        if (!empty($validated['eventType'])) {
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
            'bookId' => (int)$bookId,
            'events' => $events,
            'count' => $events->count(),
            'hasMore' => $hasMore,
        ]);
    }

    /**
     * Get event statistics
     */
    public function getStats(Request $request)
    {
        $validated = $request->validate([
            'startTime' => 'nullable|integer|min:0',
            'endTime' => 'nullable|integer|min:0',
            'bookId' => 'nullable|integer',
        ]);

        $query = ListeningEvent::where('user_id', Auth::id());

        if (!empty($validated['startTime'])) {
            $query->where('timestamp_ms', '>=', $validated['startTime']);
        }

        if (!empty($validated['endTime'])) {
            $query->where('timestamp_ms', '<=', $validated['endTime']);
        }

        if (!empty($validated['bookId'])) {
            $query->where('book_id', $validated['bookId']);
        }

        // Calculate statistics
        $totalEvents = $query->count();

        $totalListeningTime = ListeningEvent::where('user_id', Auth::id())
            ->where('event_type', 'SESSION_END')
            ->when(!empty($validated['startTime']), fn($q) => $q->where('timestamp_ms', '>=', $validated['startTime']))
            ->when(!empty($validated['endTime']), fn($q) => $q->where('timestamp_ms', '<=', $validated['endTime']))
            ->when(!empty($validated['bookId']), fn($q) => $q->where('book_id', $validated['bookId']))
            ->get()
            ->sum(fn($event) => $event->metadata['adjustedDurationMs'] ?? 0);

        $booksStarted = ListeningEvent::where('user_id', Auth::id())
            ->where('event_type', 'BOOK_START')
            ->when(!empty($validated['startTime']), fn($q) => $q->where('timestamp_ms', '>=', $validated['startTime']))
            ->when(!empty($validated['endTime']), fn($q) => $q->where('timestamp_ms', '<=', $validated['endTime']))
            ->distinct('book_id')
            ->count('book_id');

        $booksFinishedByListening = ListeningEvent::where('user_id', Auth::id())
            ->where('event_type', 'BOOK_FINISH')
            ->when(!empty($validated['startTime']), fn($q) => $q->where('timestamp_ms', '>=', $validated['startTime']))
            ->when(!empty($validated['endTime']), fn($q) => $q->where('timestamp_ms', '<=', $validated['endTime']))
            ->distinct('book_id')
            ->count('book_id');

        $booksMarkedComplete = ListeningEvent::where('user_id', Auth::id())
            ->where('event_type', 'BOOK_MARK_COMPLETE')
            ->when(!empty($validated['startTime']), fn($q) => $q->where('timestamp_ms', '>=', $validated['startTime']))
            ->when(!empty($validated['endTime']), fn($q) => $q->where('timestamp_ms', '<=', $validated['endTime']))
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
```

---

### Phase 3: Add Routes

```php
// routes/api.php
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('v1/events')->group(function () {
        Route::post('/sync', [EventController::class, 'sync']);
        Route::get('/book/{bookId}', [EventController::class, 'getBookEvents']);
        Route::get('/stats', [EventController::class, 'getStats']);
    });
});
```

---

## 🧪 Testing

### Unit Tests

```php
// tests/Feature/EventSyncTest.php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Book;
use App\Models\ListeningEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

class EventSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_creates_new_events()
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/events/sync', [
            'events' => [
                [
                    'id' => 'test-event-1',
                    'bookId' => $book->id,
                    'eventType' => 'SESSION_END',
                    'timestampMs' => 1707945600000,
                    'positionMs' => 1234567,
                    'metadata' => [
                        'sessionDurationMs' => 3600000,
                        'adjustedDurationMs' => 2400000,
                    ],
                    'deviceId' => 'test-device',
                    'timezone' => 'UTC',
                    'createdAt' => 1707945600000,
                ],
            ],
            'lastSyncTimestamp' => 0,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'received' => 1,
        ]);

        $this->assertDatabaseHas('listening_events', [
            'id' => 'test-event-1',
            'user_id' => $user->id,
            'book_id' => $book->id,
        ]);
    }

    public function test_sync_deduplicates_events()
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();

        // Create event
        ListeningEvent::create([
            'id' => 'test-event-1',
            'user_id' => $user->id,
            'book_id' => $book->id,
            'event_type' => 'SESSION_END',
            'timestamp_ms' => 1707945600000,
            'position_ms' => 1234567,
            'device_id' => 'test-device',
            'timezone' => 'UTC',
            'sync_status' => 'SYNCED',
            'created_at' => 1707945600000,
            'synced_at' => 1707945605000,
        ]);

        // Try to sync same event again
        $response = $this->actingAs($user)->postJson('/api/v1/events/sync', [
            'events' => [
                [
                    'id' => 'test-event-1',
                    'bookId' => $book->id,
                    'eventType' => 'SESSION_END',
                    'timestampMs' => 1707945600000,
                    'positionMs' => 1234567,
                    'deviceId' => 'test-device',
                    'timezone' => 'UTC',
                    'createdAt' => 1707945600000,
                ],
            ],
            'lastSyncTimestamp' => 0,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'received' => 0,  // Should be 0 because event already exists
        ]);
    }
}
```

---

## 📋 Implementation Checklist

### Database
- [ ] Create `listening_events` table migration
- [ ] Run migration in development
- [ ] Run migration in production
- [ ] Create `ListeningEvent` model
- [ ] Add relationships to User and Book models

### API Endpoints
- [ ] Create `EventController`
- [ ] Implement `sync()` method
- [ ] Implement `getBookEvents()` method
- [ ] Implement `getStats()` method
- [ ] Add routes to `api.php`
- [ ] Add authentication middleware
- [ ] Add rate limiting

### Testing
- [ ] Write unit tests for EventController
- [ ] Write integration tests for sync endpoint
- [ ] Test deduplication logic
- [ ] Test migrated event handling
- [ ] Test cross-device sync
- [ ] Test statistics calculations

### Documentation
- [ ] Update API documentation
- [ ] Document event metadata structure
- [ ] Document sync protocol
- [ ] Add examples to API docs

### Deployment
- [ ] Deploy database migration
- [ ] Deploy new endpoints
- [ ] Monitor for errors
- [ ] Verify sync is working

---

## 🚀 Deployment Strategy

### Phase 1: Database (Week 1)
1. Create migration
2. Test in development
3. Deploy to staging
4. Deploy to production

### Phase 2: API Endpoints (Week 2)
1. Implement endpoints
2. Write tests
3. Deploy to staging
4. Test with staging client
5. Deploy to production

### Phase 3: Statistics Migration (Week 3)
1. Refactor existing statistics logic
2. Derive stats from events
3. Run parallel (old + new)
4. Validate accuracy
5. Deprecate old statistics

---

## ⚠️ Important Notes

1. **Migrated Events:** Do NOT sync migrated events to other devices
2. **Deduplication:** Always check for existing event ID before inserting
3. **Rate Limiting:** Prevent abuse with rate limiting
4. **Validation:** Validate all incoming event data
5. **Transactions:** Use database transactions for sync operations
6. **Performance:** Add indexes for common queries
7. **Monitoring:** Monitor sync endpoint for errors and performance

---

**Questions?** Review this document or check the client-side documentation in `/docs/EVENT_SYNC_INTEGRATION_GUIDE.md`.

**Ready to implement?** Start with Phase 1: Database Setup.
