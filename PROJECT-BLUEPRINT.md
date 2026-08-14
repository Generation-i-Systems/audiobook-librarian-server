# Audiobook Librarian Project Blueprint

## Overview

Audiobook Librarian is a Laravel-based web app for managing audiobooks, supporting admin CRUD, user management, Google Books autofill, and Firestore-backed autocomplete for authors and series. The backend also provides a REST API that supports integration with an Android app client. Recent hardening ensures all automated tests run exclusively against SQLite in-memory via a bootstrap database safety check; any misconfiguration aborts immediately to prevent production MySQL wipes.

The API now supports host-based library profile resolution in a single runtime: incoming host name selects an active library profile (for example `main` vs `librivox`) and switches database connection plus book storage roots at request time while preserving the same API routes and response contract.

## Reading Progress & Statistics Requirements

See `docs/requirements/reading-progress-and-stats.md`.

## 1. Tech Stack

- **Backend:** Laravel (PHP) — provides both web and REST API endpoints
- **Frontend:** Blade, Bootstrap 5, jQuery, jQuery UI (Autocomplete)
- **Styles:** Application Sass uses the module system and configures Bootstrap's theme variables at load time. Bootstrap 5 still ships legacy Sass internals, which Vite treats as quiet third-party dependencies while continuing to report application-level deprecations.
- **Database:** MySQL with Redis caching and queue management
- **Other:** Google Books API integration
- **API Clients:** Android app (in development/production)

## 2. Core Features

- Book CRUD (admin)
- Book form: multiple authors/series (autocomplete), Google Books autofill, genre selection, file uploads
- Import asks the AI for freeform content tags per book (including a `spicy` tag when there is clear evidence of explicit sexual content), shows them on the review summary, allows editing during interactive review, and stores confirmed tags as system-scope book tags. Author/narrator name lists are split on `&`, `/`, and "and" as well as commas, both from the AI response and from ID3 tags.
- Multi-book split imports give each split-out book its own AI lookup and its own file-tag/filename parsing for author/narrator, rather than inheriting a single whole-folder AI guess that may have been sampled from a different book's files.
- Audible enrichment search retries a ladder of query variants (drop series number, drop series name, add author) when the exact-title search finds nothing.
- Library Repair detects path-genre mismatches (the first storage directory is absent from the book's genre assignments) and title-directory mismatches, records repair issues, adds dedicated Needs Review tags, and can filter repair issues by any Needs Review tag. The nightly scan reevaluates every repair type and resolves an issue after it is corrected outside the repair page.
- `book:import --repair-title-mismatch-date=2025-08-06` reopens the affected import batch through the normal AI, audio-tag, directory, enrichment, and interactive confirmation flow while preserving each existing record ID and its physical audio directory. It requires an expected-count guard before any record is reviewed.
- Completing or safely skipping a title-mismatch repair clears only that repair reason, resolves its Library Repair issue, and reports the remaining batch count. Import genre lists preserve every valid selection, use `Other` only as the no-specific-genre fallback, and are editable as a complete comma-separated list.
- The title-mismatch repair uses the normal import progress UI, initialized with the selected repair batch and advanced after every reviewed record.
- The `bin/import-bk` wrapper recognizes repair options with separate values, so repair mode reaches `book:import` rather than falling through to normal path scanning.
- Repair expected counts are an upper-bound safety guard: a resumed batch may contain fewer records because earlier confirmed repairs remove their target marker, while empty or larger batches are rejected.
- For repair proposals with no tag or AI author, the existing library directory's author segment is used as reviewable fallback context before external enrichment.
- Repair AI context supplies the full library path, and prompt sanitization retains author/series/title context while stripping unrelated leading filesystem segments.
- Repair files move only after the user confirms a directory different from the original; unchanged paths are in-place metadata updates, and move failure restores the old database path for retry.
- Cover updates in import review aggregate enrichment, embedded-tag, and all directory-image sources for a user-selected preview; an explicit selection may replace the current cover.
- Import confirmation treats a source directory already located at the selected library path as metadata-only, avoiding a false destination-file conflict without weakening protection against distinct source/destination collisions.
- Book form metadata search/autofill supports Audible, Google Books, AudiobookBay, and Hardcover.
  Metadata autofill must preserve any existing selected genre, including genres inferred from the directory path, and may only apply provider genres that match configured genre options when no genre is selected.
- Authors/series autocomplete via jQuery UI, server-side filtering
- Google Books API integration for autofill
- Admin/user management
- Account deletion with email verification, immediate access revocation, a 30-day cancellation period, and scheduled permanent erasure
- Mobile clients connect to self-hosted servers only through publicly trusted HTTPS endpoints; Docker HTTP listeners remain loopback-only behind a TLS reverse proxy. Storage, import, backup, and Librivox paths are environment-configurable and default to portable application-storage locations.
- Production installs require the Laravel scheduler plus a queue worker. Docker runs both under supervisor; native installs must configure `schedule:work` or a one-minute `schedule:run` cron and a managed `queue:work` service. `docs/INSTALLATION.md` documents required and optional scheduled jobs, including low-load chunk-hash precomputation.
- The login page includes a locally bundled QR code generator for the mobile server-connection link, avoiding a runtime dependency on a third-party CDN.
- The Skin Store is a separate `www.ablibrarian.com` service, not a capability of a selected self-hosted library backend. New clients browse, preview, and download free skins from www directly; this server keeps its skin/theme proxy only for legacy-client compatibility and never owns Store payments or entitlements.
- Download manifests can optionally include per-chunk SHA-256 hashes for local files; those hashes are cached in `book_file_chunk_hashes` after first generation and can be precomputed with `books:cache-file-chunk-hashes` during low system load. The precompute command reports book id, author, title, and elapsed processing time for each processed book. Normal bounded runs use a DB-only missing-cache filter; `--refresh` opts into filesystem size/mtime checks for stale or partially missing hashes. Explicit book selection accepts repeated `--book` values and inclusive ranges such as `--book=100-150`.
- `librarian.json` includes chapter metadata when available. Chapters are persisted in the `chapters` table with start offsets so JSON can be regenerated from the database; JSON-only chapters are imported into the database when discovered. Embedded audio chapter detection is an explicit `books:detect-chapters` command so UI-triggered JSON regeneration does not run slow `ffprobe` work. Explicit book selection accepts repeated `--book` values and inclusive ranges such as `--book=100-150`.

## 3. Data Structures

### Book Data Model

### Series Field (Canonical Format)

- The `series` field is always an array of objects, each with `seriesName` (string) and `number` (string or int):
    ```json
    "series": [
      { "seriesName": "Buryoku", "number": "9" }
    ]
    ```
- All code (backend, frontend, API) expects and produces this format only.
- Persisted `book_series.series_number` values remove ordinal zero-padding on every application save path (`"003"` becomes `"3"`), while valid values below one (such as `"0.5"`) and non-numeric text remain unchanged.
- Legacy formats (string, key-value, separate objects) are not supported.
- Migration: update any old data to this format.

### Book Document (Firestore)

```json
{
  "title": "The Way of Kings",
  "authors": ["Brandon Sanderson", "Co-Author Name"],
  "series": [
    { "seriesName": "Stormlight Archive", "number": 1 },
    { "seriesName": "Cosmere", "number": 15 }
  ],
    "Cosmere": 15
  },
  "description": "Epic fantasy novel...",
  "coverImage": "covers/way-of-kings.jpg",
  "directoryPath": "audiobooks/way-of-kings",
  "genre": ["Fantasy", "Epic"],
  "createdAt": "2025-05-20T14:00:00Z",
  "updatedAt": "2025-05-20T14:00:00Z",
  // ...other metadata fields
}
```

- **Authors:** Array of strings, each an author name.
- **Series:** Map of series name to series number (int or null).
- **Genre:** Array of strings.
- **Cover image:** Path or URL to image.
- **Directory path:** Path to audiobook files.
- **Timestamps:** ISO8601 strings.
- **Other:** Additional metadata as needed (e.g., Google Books ID, language, tags, etc.)

## 4. Key Backend Components

- `Admin\BookController`: CRUD, autocomplete endpoints, REST API endpoints
- `Admin\BookFilesystemController`: admin filesystem AJAX endpoints (rename/move directory path helpers, directory browser helpers)
- `BookFilesystemService`: filesystem operations for book directories (rename/list/browse) with document store updates
- `FirestoreService`: list/search authors/series
- `LibraryRepairService`: nightly scanner that detects missing/orphan/duplicate/nested/numbered-suffix directories, auto-fixes safe cases, and records issues with minimal metadata
- `BookImportService`: preserves detected series numbering from enrichment and directory names, including validated trailing-number names such as `Magic Eater 5`
- `LibraryRepairScanCommand` (`library:repair-scan`): CLI entry point for manual or scheduled scans (JSON mode, selective issue filters, optional auto-fixes)
- `AppRefreshCommand` (`app:refresh`): Clear caches, run migrations, restart queue workers, reset OPcache, reload PHP-FPM, and build frontend assets when changes are detected.
- `EmailOtpController`: passwordless email OTP/magic-link login — request/verify (API and web-session), magic-link landing page with `apiUrl`-aware deep links (`AppConnectLinks::magicPlayerDeepLink`/`magicLibraryDeepLink`/`androidMagicIntentLink`) so self-hosted installs route back to the issuing server; gated end-to-end by `MailConfiguration::isMailConfigured()`. `Admin\UserController`/`Api\AdminUserController` expose "send login email" and "generate login QR" for existing users from the admin UI.
- `Admin\LibraryRepairController` + `/admin/library-repair`: paginated UI for reviewing Library Repair Issues, defaulting to pending issues with a “Show resolved” toggle, inline book edit shortcuts, AudiobookBay search links, per-issue rescans, and missing-directory import helpers
- `App\Services\Embeddings\EmbeddingPipeline` + `EmbedBookJob`: embeds each book's metadata (+ AI-generated cover caption) into a local vector store (`neuron-ai`, `file` driver); `book_embeddings` table tracks staleness, `books:backfill-embeddings` catches up existing books.
- `App\Services\Recommendations\RecommendationEngine` + `RecommendationStrategyInterface` (`app/Services/Recommendations/Strategies/*`): Netflix-style "discovery shelves" for Browse. Precomputed per-user into `recommendation_shelves`/`recommendation_shelf_books`, served by `Api\DiscoveryController` (`GET /discovery/shelves`, `GET /discovery/shelves/{shelfKey}/books`); recomputed via `RecomputeRecommendationsJob` on `BookStatusUpdated` and by the daily `books:refresh-recommendations` command. `Api\DiscoveryController::surprise()` (`GET /discovery/surprise`) is a separate, live-computed single-book pick, not a cached shelf.
- `Api\BookSeriesGenreController::booksByGenre()`/`seriesByGenre()` (`GET /genres/{genre}/books`, `GET /genres/{genre}/series`): sortable genre detail sub-lists alongside the existing `authorsByGenre()` (now also `sort=random`-capable). `Api\BookAuthorController` now populates `image_url` (an author's book cover) and `sample_book_titles` instead of always-null placeholders.
- `App\Services\UserTagFilterService` + `UserTagFilter` model: per-user require/ban tag content filter, self-service via `Api\UserTagFilterController` (`/users/me/tag-filters`) and admin-locked via `Api\AdminUserTagFilterController` (`/admin/users/{id}/tag-filters`). Applied inside `MySqlService::listBooks()` unconditionally, so every listing/search/discovery surface respects it automatically.
- **Web Routes:**
    - `/admin/books` (CRUD)
- `/admin/library-repair` (issue triage dashboard)
    - `/admin/books/create`, `/admin/books/{book}/edit`
    - `/admin/books/autocomplete/authors` (AJAX autocomplete)
    - `/admin/books/autocomplete/series` (AJAX autocomplete)
    - `/admin/books/import-from-title` (Google Books autofill)
- **REST API Routes:**
    - `GET /api/books` — List books
    - `GET /api/books/{id}` — Get book detail
    - `POST /api/books` — Create book
    - `PUT /api/books/{id}` — Update book
    - `DELETE /api/books/{id}` — Delete book
    - `POST /api/v1/auth/google` — Google Sign-In
    - `POST /api/v1/auth/facebook` — Facebook Sign-In
    - `POST /api/v1/auth/apple` — Apple Sign-In
    - `GET /api/authors` — List authors
    - `GET /api/series` — List series
    - (additional endpoints for Android app support)

## 5. Frontend Integration

- **Blade:** Book form uses `.author-autocomplete`/`.series-autocomplete`, `window.BOOK_FORM_ROUTES`
- **JS:** `public/js/admin/books/form.js` handles dynamic rows, autocomplete, Google Books autofill

### External Integration Tests

- Standalone external integration tests for metadata lookup live under `tests/External/MetadataLookup`
- Run via `composer test:external-metadata`

## 6. Design Decisions

- Server-side filtering for autocomplete
- Firestore as source of truth
- All dynamic logic in external JS
- Modern UX with jQuery UI

## 7. Refactors & Cleanups

- All JS moved to external files
- jQuery/jQuery UI loaded globally
- Removed redundant controllers
- Linting/code style improvements ongoing
- DocumentStore integration in tests now uses a dedicated MockDocumentStoreService so feature/unit tests confidently run without real MySQL

## 8. Known Issues & TODOs

- Some PHPCS lint errors remain
- Add more robust AJAX error handling
- More modularization/testing possible

## 9. How to Extend

- **Add autocomplete:** endpoint in BookController/FirestoreService, route, field, JS
- **Add book metadata:** update Firestore, form, validation, display

## 10. Contributors & Structure

- **Controllers:** `app/Http/Controllers/Admin/BookController.php`
- **Firebase Cloud Functions:** All Firebase backend automation, API endpoints, and event-driven logic are located in the `/functions` directory. Reference this for any Firebase-related backend code or extensions.

- **Services:** `app/Services/FirestoreService.php`
- **Views:** `resources/views/admin/books/form.blade.php`, `layouts/app.blade.php`
- **JS:** `public/js/admin/books/form.js`

---

## Appendix: Screenshots

### Create Book Form

![Create Book Form](public/screenshots/Screenshot-Book%20Form.png)

### Book List View

![Book List View](public/screenshots/Screenshot-Book%20List.png)

### Import Author View

![Import Author View](public/screenshots/Screenshot-Import%20Author%20View.png)

### Login Screen

![Login Screen](public/screenshots/Screenshot-Login.png)

---

This blueprint summarizes the architecture, features, and design up to this point. Use for onboarding, planning, or future extension.
