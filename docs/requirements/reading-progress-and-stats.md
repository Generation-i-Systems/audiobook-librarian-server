# Reading Progress & Statistics — Requirements (v1)

Status: Draft
Owner: Platform
Last Updated: 2025-08-08

## 1) Objectives
- MUST enable seamless cross‑device resume by syncing per‑book progress.
- MUST collect reading stats to power insights: start/finish dates, daily minutes, per‑book totals, and trends.
- SHOULD support high‑frequency ingestion, offline batching, and conflict‑free merges across devices.

## 2) Constraints & Conventions
- Controllers MUST be thin and MUST NOT access DB; use services via `DocumentStoreServiceInterface` only.
- Services using `DocumentStoreServiceInterface` MUST return arrays (no driver‑specific types).
- Series MUST use `seriesName` (NOT `name`).
- All timestamps MUST be UTC ISO‑8601. Endpoints SHOULD be rate‑limited. Updates SHOULD be idempotent.

## 3) Data Model (new tables)
- book_progress: user_id, book_id (unique), current_track_id?, position_ms, total_ms, percent_complete, started_at?, finished_at?, last_listened_at, last_device_id?, playback_rate, server_revision, timestamps.
- progress_events: user_id, book_id, device_id, track_id?, event_ts, received_at, position_ms, delta_ms, duration_ms, playback_rate, is_paused, event_type, idempotency_key?, app_version, offline, created_at. Index idempotency and time.
- reading_daily_totals: user_id, date (unique), total_clock_ms, total_content_ms, timestamps.
- reading_daily_book_totals: user_id, book_id, date (unique), total_clock_ms, total_content_ms, timestamps.
- book_stats: user_id, book_id (unique), first_listened_at, last_listened_at, started_at, finished_at, total_clock_ms, total_content_ms, completion_rate, re_listen_ratio, sessions_count, timestamps.

Notes: content time ≈ sum(delta_ms * playback_rate); clock time = elapsed playback.

## 4) API (v1)
Auth: existing (DocumentstoreUser). Controllers call services only.

Idempotency & Revisions:
- Each (user, book) has serverRevision.
- Client sends clientRevision. Accept iff clientRevision >= serverRevision; then increment serverRevision and return latest state.
- If clientRevision < serverRevision → 409 with canonical state.
- Optional idempotencyKey deduplicates retries.

Endpoints:
- GET /api/v1/progress/{bookId}: return canonical progress for user+book including serverRevision.
- POST /api/v1/progress/update:
  Body minimal example:
  {
    "bookId":"...","trackId":null,"positionMs":123456,"totalMs":3600000,
    "playbackRate":1.25,"eventTs":"2025-08-08T13:45:00Z","deviceId":"abc",
    "appVersion":"1.5.0","clientRevision":7,"idempotencyKey":"uuid?"
  }
  Behavior: upsert progress, log event, recalc start/finish, bump revision; 409 on conflict.
- POST /api/v1/progress/batch: array of update payloads; best‑effort per item; per‑item status + serverRevision.
- GET /api/v1/stats/summary?range=last_30d: daily totals, streaks, most active hours, in‑progress, recently finished.
- GET /api/v1/stats/books/{bookId}: book stats (start/finish, totals, sessions, lastListenedAt, completionRate).
- GET /api/v1/stats/daily?from=YYYY-MM-DD&to=YYYY-MM-DD: daily totals and per‑book totals.

Rate limits: suggest ~1 req/3s per (user, book, device). Batch size limit (e.g., 100 events).

## 5) Conflict & Thresholds
- Accept if clientRevision >= serverRevision; else 409. Always return serverRevision.
- started_at when percent_complete > 0.5 or first non‑zero event.
- finished_at when position_ms >= total_ms - tail_threshold_ms OR percent_complete >= 95.

## 6) Aggregation & Jobs
- Synchronous: update book_progress; append progress_events.
- Async jobs: update reading_daily_totals, reading_daily_book_totals, book_stats.
- Scheduled reconciliation hourly to catch offline batches and compute derived fields.

## 7) Client Requirements
- Send update every 15–30s while playing, and on pause/stop/background.
- Batch offline events and send via /progress/batch on reconnect.
- Maintain and apply serverRevision per (user, book). Keep eventTs in UTC.

## 8) Recommended Additional Metrics to Collect
Engagement & Habits
- streak_days, longest_streak_days, average_daily_minutes, variance_of_daily_minutes.
- average_session_minutes, median_session_minutes, sessions_per_day.
- day_of_week_distribution, time_of_day_distribution (hour buckets).
- abandonment_rate (stopped < 20%), time_to_completion_days, relisten_ratio (>100%).
- seek_forward_count, seek_backward_count, pause_count, resume_count, skip_track_count.

Content & Preferences
- top_genres, top_authors, top_narrators, top_seriesName.
- completion_rate_by_genre/author/narrator.
- new_vs_reread_ratio, concurrent_books_count.

Device & Quality
- device_platform, app_version, network_type.
- buffer_error_count, buffering_time_ms, sleep_timer_usage_count.

Velocity & Forecasting
- weekly_progress_velocity_ms, projected_finish_date for each in‑progress book.

Additional Recommendations
- weekly_active_days, longest_inactive_gap_days, rolling_7d_minutes, rolling_30d_minutes.
- retention_rates: d1/d7/d30 active return rates (based on any listening activity).
- time_to_resume_seconds (pause→resume latency), session_gap_distribution_minutes.
- playback_rate_distribution (histogram), seek_distance_distribution_ms.
- unique_devices_used, output_device_distribution (speaker/headphones/bluetooth/carplay/android-auto).
- chapter_completion_distribution, per-chapter relisten hotspots (if chapter data available).
- weekday_vs_weekend_minutes_ratio, holiday_vs_non_holiday_minutes (later, optional).
- local_timezone (stored per user) to compute local-day aggregates and streaks correctly.

## 9) Insights to Return (API)
- Summary: daily minutes (calendar heatmap), current streak, avg/median session length, most active hours, last 7/30‑day trend, books started/finished counts, top genres/authors/narrators.
- Per‑book: startedAt, finishedAt, total clock/content minutes, sessions_count, completionRate, lastListenedAt, velocity, projectedFinishDate, relistenRatio.
- Retention: d1/d7/d30 return rates, weekly_active_days, time_since_last_session.
- Habits: best_time_to_listen (hour bucket), weekday_vs_weekend distribution, average_pause_to_resume_latency.
- Devices & Quality: device/app usage breakdown, buffering issues trend, bluetooth_controls_usage.
- Pace & Forecasts: rolling_7d_velocity_ms, rolling_14d_velocity_ms, decayed_velocity_ms, eta_confidence, pace_classification (ahead/on‑track/behind).

## 10) Security & Privacy
- Enforce auth; per‑user data only. Minimize PII; store device/app only for diagnostics and insights.
- Retain raw progress_events 90–180 days; aggregates indefinitely. Support export/delete.
- Rate‑limit ingestion; validate payloads; audit access.

## 11) Acceptance Criteria
- All endpoints have feature tests (happy path, conflict, idempotency, batch) and unit tests for aggregation math.
- Concurrency tests simulate two devices racing; correctness verified by revision logic.
- Code formatted/linted; docs (README/blueprint/changelog) updated; OpenAPI added.

## 12) OpenAPI & Docs
- Provide OpenAPI spec for all endpoints/payloads.
- Document client integration (timers, batching, retries, revision handling).
