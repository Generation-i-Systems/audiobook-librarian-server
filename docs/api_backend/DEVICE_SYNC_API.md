# Device Sync API

## API Conventions (matching existing patterns)
- All endpoints under `/api/v1/` with `api.auth` + `standard` middleware
- Device identified by `X-Device-ID` header (string, e.g. "a1b2c3d4e5f6")
- Device name sent via `X-Device-Name` header (string, e.g. "Pixel 8 Pro-dev")
- Auth: Bearer token (Sanctum) — same as all existing authenticated routes
- Timestamps: ISO 8601 strings (2026-02-06T19:00:00.000Z)
- Idempotency: write endpoints under `sync/` use idempotency middleware (accepts `Idempotency-Key` header)

## 1. Device Management

### GET /api/v1/devices
List all known devices for the authenticated user.

**Response 200:**
```json
{
  "devices": [
    {
      "device_id": "a1b2c3d4e5f6",
      "name": "Pixel 8 Pro",
      "last_seen": "2026-02-06T19:00:00.000Z",
      "sync_enabled": true,
      "created_at": "2026-01-15T10:00:00.000Z"
    }
  ]
}
```

**Implementation notes:**
- Devices are auto-registered on first POST /sync/positions or POST /sync/bookmarks call using X-Device-ID and X-Device-Name headers
- last_seen updated on every authenticated request from that device

### PUT /api/v1/devices/{deviceId}
Rename a device.

**Request body:**
```json
{
  "name": "Living Room Tablet"
}
```

**Response 200:**
```json
{
  "device_id": "a1b2c3d4e5f6",
  "name": "Living Room Tablet",
  "last_seen": "2026-02-06T19:00:00.000Z",
  "sync_enabled": true
}
```

**Response 404:**
```json
{ "error": "Device not found" }
```

### DELETE /api/v1/devices/{deviceId}
Remove a device and all its progress/bookmark associations.

**Response 204:** No content

**Response 404:**
```json
{ "error": "Device not found" }
```

### PUT /api/v1/devices/{deviceId}/sync-enabled
Enable or disable sync from a specific device.

**Request body:**
```json
{
  "enabled": false
}
```

**Response 200:**
```json
{
  "device_id": "a1b2c3d4e5f6",
  "sync_enabled": false
}
```

## 2. Position Sync

### GET /api/v1/sync/positions
Get the latest listening position per book across all of the user's enabled devices (excluding the requesting device).

**Query params:**
- `since` (optional, ISO 8601) — only return positions updated after this timestamp (for incremental sync)
- `book_ids` (optional, comma-separated) — filter to specific books

**Response 200:**
```json
{
  "server_timestamp": "2026-02-06T19:00:00.000Z",
  "positions": [
    {
      "book_id": 42,
      "device_id": "x9y8z7w6",
      "device_name": "Pixel 8 Pro",
      "position_ms": 3723000,
      "progress_percentage": 45.2,
      "current_chapter": 12,
      "current_chapter_name": "The Journey Home",
      "is_finished": false,
      "updated_at": "2026-02-06T18:55:00.000Z"
    }
  ]
}
```

**Implementation notes:**
- Returns the most recent position per book across all other enabled devices
- Excludes the requesting device's own positions (identified by X-Device-ID header)
- Client stores server_timestamp and passes it as `since` on next call

### GET /api/v1/sync/positions/{bookId}
Get positions for a specific book from all devices (including requesting device).

**Response 200:**
```json
{
  "book_id": 42,
  "positions": [
    {
      "device_id": "a1b2c3d4e5f6",
      "device_name": "Pixel 8 Pro",
      "position_ms": 3723000,
      "progress_percentage": 45.2,
      "is_finished": false,
      "updated_at": "2026-02-06T18:55:00.000Z"
    },
    {
      "device_id": "x9y8z7w6",
      "device_name": "Car Tablet",
      "position_ms": 3500000,
      "progress_percentage": 42.1,
      "is_finished": false,
      "updated_at": "2026-02-06T17:30:00.000Z"
    }
  ]
}
```

### POST /api/v1/sync/positions (idempotency middleware)
Batch push positions from this device.

**Request headers:**
- `X-Device-ID`: a1b2c3d4e5f6
- `X-Device-Name`: Pixel 8 Pro-dev
- `Idempotency-Key`: <uuid> (optional)

**Request body:**
```json
{
  "client_timestamp": "2026-02-06T19:00:00.000Z",
  "positions": [
    {
      "book_id": 42,
      "position_ms": 3723000,
      "progress_percentage": 45.2,
      "current_chapter": 12,
      "current_chapter_name": "The Journey Home",
      "is_finished": false,
      "updated_at": "2026-02-06T18:55:00.000Z"
    }
  ]
}
```

**Validation:**
- `client_timestamp`: required|date
- `positions`: required|array|min:1
- `positions.*.book_id`: required|integer
- `positions.*.position_ms`: required|integer|min:0
- `positions.*.progress_percentage`: required|numeric|min:0|max:100
- `positions.*.current_chapter`: nullable|integer|min:1
- `positions.*.current_chapter_name`: nullable|string|max:255
- `positions.*.is_finished`: nullable|boolean
- `positions.*.updated_at`: required|date

**Response 200:**
```json
{
  "server_timestamp": "2026-02-06T19:00:01.000Z",
  "accepted": 1,
  "conflicts": [
    {
      "book_id": 42,
      "server_position_ms": 3800000,
      "server_device_id": "x9y8z7w6",
      "server_device_name": "Car Tablet",
      "server_updated_at": "2026-02-06T18:58:00.000Z"
    }
  ]
}
```

**Implementation notes:**
- Upsert into `book_progress` keyed on (book_id, device_id) — same table as existing progress
- Auto-register device in `devices` table if not exists (using X-Device-ID + X-Device-Name)
- `conflicts` array: if another device updated the same book more recently than the pushed updated_at, include it so the client can show a conflict notification
- Merge logic: always accept the push (last-writer-wins per device), but report if another device has a newer position

## 3. Bookmark Sync

### GET /api/v1/sync/bookmarks
Get all bookmarks for the user across all books and devices.

**Query params:**
- `since` (optional, ISO 8601) — only return bookmarks created/updated/deleted after this timestamp
- `book_id` (optional, integer) — filter to a specific book
- `include_deleted` (optional, boolean, default true) — include soft-deleted bookmarks so client can remove them locally

**Response 200:**
```json
{
  "server_timestamp": "2026-02-06T19:00:00.000Z",
  "bookmarks": [
    {
      "string_id": "550e8400-e29b-41d4-a716-446655440000",
      "book_id": 42,
      "device_id": "a1b2c3d4e5f6",
      "device_name": "Pixel 8 Pro",
      "position_ms": 1800000,
      "title": "Chapter 5 start",
      "note": "Great scene",
      "is_auto": false,
      "chapter_number": 5,
      "chapter_title": "The Discovery",
      "created_at": "2026-02-05T14:00:00.000Z",
      "updated_at": "2026-02-05T14:00:00.000Z",
      "deleted_at": null
    }
  ]
}
```

**Implementation notes:**
- `string_id` is the client-generated UUID — this is the dedup key
- Soft-deleted bookmarks have `deleted_at` set; client should remove them locally
- Client stores server_timestamp and passes as `since` on next call

### GET /api/v1/sync/bookmarks/{bookId}
Get bookmarks for a specific book from all devices.

**Response 200:** Same shape as above, filtered to one book.

### POST /api/v1/sync/bookmarks (idempotency middleware)
Batch push bookmarks from this device.

**Request headers:**
- `X-Device-ID`: a1b2c3d4e5f6
- `X-Device-Name`: Pixel 8 Pro-dev

**Request body:**
```json
{
  "client_timestamp": "2026-02-06T19:00:00.000Z",
  "bookmarks": [
    {
      "string_id": "550e8400-e29b-41d4-a716-446655440000",
      "book_id": 42,
      "position_ms": 1800000,
      "title": "Chapter 5 start",
      "note": "Great scene",
      "is_auto": false,
      "chapter_number": 5,
      "chapter_title": "The Discovery",
      "created_at": "2026-02-05T14:00:00.000Z"
    }
  ]
}
```

**Validation:**
- `client_timestamp`: required|date
- `bookmarks`: required|array|min:1
- `bookmarks.*.string_id`: required|uuid
- `bookmarks.*.book_id`: required|integer
- `bookmarks.*.position_ms`: required|integer|min:0
- `bookmarks.*.title`: nullable|string|max:255
- `bookmarks.*.note`: nullable|string
- `bookmarks.*.is_auto`: nullable|boolean
- `bookmarks.*.chapter_number`: nullable|integer
- `bookmarks.*.chapter_title`: nullable|string|max:255
- `bookmarks.*.created_at`: required|date

**Response 200:**
```json
{
  "server_timestamp": "2026-02-06T19:00:01.000Z",
  "accepted": 1,
  "duplicates_skipped": 0
}
```

**Implementation notes:**
- Upsert by (user_id, string_id) — if string_id already exists for this user, update fields; otherwise insert
- Set device_id and device_name from headers
- If a bookmark with the same string_id exists but was soft-deleted, un-delete it and update

### DELETE /api/v1/sync/bookmarks/{stringId}
Soft-delete a bookmark by its client UUID. Propagates to all devices on next pull.

**Response 200:**
```json
{
  "string_id": "550e8400-e29b-41d4-a716-446655440000",
  "deleted_at": "2026-02-06T19:00:00.000Z"
}
```

**Response 404:**
```json
{ "error": "Bookmark not found" }
```

## Implementation Status

✅ All endpoints implemented and tested
✅ Database migrations applied
✅ Device identification middleware
✅ Idempotency middleware for sync endpoints
✅ 31 comprehensive tests passing
✅ Conflict detection for position sync
✅ Soft deletes for bookmarks
✅ Auto-registration of devices

## Client Implementation Notes

No changes required from the original specification.
