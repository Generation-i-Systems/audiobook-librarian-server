# Backend Analytics & Events API

To support detailed progress tracking, streaks, and badges, the client needs to send richer telemetry to the backend.

## 1. Enhanced Listening Session Reporting

The existing `ListeningSessionReport` payload should be enhanced to include the specific events that occurred during that session. This allows the backend to verify "actual" listening vs just time updates, and derive badges.

**Endpoint:** `POST /api/v1/statistics/report`

**Updated Request Body:**
```json
{
  "book_id": 123,
  "session_start": "2023-10-27T10:00:00Z",
  "session_end": "2023-10-27T10:30:00Z",
  "start_position_ms": 0,
  "end_position_ms": 1800000,
  "playback_speed": 1.0,
  "actual_duration_ms": 1800000, // New field: Actual wall-clock time spent listening
  "events": [
    {
      "timestamp": 1698393600000,
      "type": "PLAY",
      "position_ms": 0,
      "metadata": { "source": "WIDGET" }
    },
    {
      "timestamp": 1698394500000,
      "type": "SLEEP_TIMER_START",
      "position_ms": 900000,
      "metadata": { "duration_min": "15" }
    }
  ]
}
```

## 2. Generic Client Events

For badges and tracking that doesn't fit into a "listening session" (e.g., UI interactions, changing themes, viewing specific screens), we need a generic event endpoint.

**Endpoint:** `POST /api/v1/analytics/event`

**Request Body:**
```json
{
  "event_type": "THEME_CHANGED",
  "timestamp": 1698395000000,
  "metadata": {
    "previous_theme": "light",
    "new_theme": "dark_forest"
  }
}
```

### Common Event Types
*   **UI Interactions**: `VIEW_BADGES`, `VIEW_PROFILE`, `VIEW_STATS`
*   **Customization**: `THEME_CHANGED`, `APP_ICON_CHANGED`, `FONT_CHANGED`
*   **Feature Usage**: `DRIVE_MODE_ENABLED`, `DRIVE_MODE_DISABLED`, `DOWNLOAD_ALL`
*   **System**: `APP_OPEN`, `APP_Background`

## 3. Badge Logic (Server-Side)

The backend should process these incoming events (both from sessions and generic events) to award badges.

**Example Rules:**
*   **"Night Owl"**: `ListeningSession` occurs between 2 AM and 5 AM.
*   **"Marathoner"**: `ListeningSession` actual_duration > 4 hours.
*   **"Customizer"**: `THEME_CHANGED` event received > 5 times.
*   **"Sleepyhead"**: `SLEEP_TIMER_START` event received > 10 times.
*   **"Drive Time"**: `DRIVE_MODE_ENABLED` event received.

## 4. Derived Data

From `ListeningSessionReport`, the backend should calculate and store:
*   **Total Listening Time**: Aggregated `actual_duration_ms`.
*   **Daily Streaks**: Consecutive days with at least one session > 5 minutes.
*   **Heatmap Data**: Session start times mapped to hour/day.
