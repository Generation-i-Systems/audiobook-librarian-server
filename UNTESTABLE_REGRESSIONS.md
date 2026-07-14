# Untestable Regressions

These are known areas where automated tests **cannot** detect regressions because they require
real external services, real audio/image files, a real browser, or real filesystem state.

**Before making any change, cross-reference it against this list.**
- If your change **touches** an area below → think extra hard about the implications and call
  out the untestable risk explicitly to the user.
- If your change **introduces a new untestable area** → add it here in the same PR/commit.

---

## 1. External API Integrations

Changes to request structure, headers, parsing, or error handling for any of these services
cannot be verified by the test suite — only by live calls.

| Service | Files | What breaks silently |
|---------|-------|----------------------|
| **Anthropic Claude API** | `app/Services/AI/Providers/ClaudeProvider.php` | Prompt format, schema-mode output, token cost calculations |
| **OpenAI Whisper (transcription)** | `app/Services/AI/Providers/OpenAIProvider.php` `transcribe()` | Requires real audio; mock cannot verify transcription accuracy |
| **OpenAI Chat / GPT** | `app/Services/AI/Providers/OpenAIProvider.php` | Structured JSON schema responses |
| **Google Gemini** | `app/Services/AI/Providers/GeminiProvider.php` | Any model-response format changes |
| **Google Books API** | `app/Services/GoogleBooksApiService.php` | Search queries, duration-matching tolerance (±15%), result ranking |
| **Hardcover API (GraphQL)** | `app/Services/HardcoverService.php` | Token expiry email flow; GraphQL schema changes |
| **Audible API** | `app/Services/AudibleService.php`, `AudibleApiService.php` | Search filtering, cover image download, rate-limiting headers |
| **LibriVox API** | `app/Services/LibriVoxApiService.php` | 24-hour cache TTL logic; chapter metadata structure |
| **AudioBook Bay scraper** | `app/Services/AudiobookBayApiService.php`, `AudiobookBayCategoryScraperService.php` | XPath selectors break if site HTML changes; login/session |
| **Google Custom Image Search** | `app/Services/GoogleImageSearchService.php` | API key scopes, result quality filtering |
| **External cover image fetch** | `app/Services/ExternalCoverService.php` | Binary download, timeout handling, file caching |

---

## 2. OAuth / Social Authentication

Token verification depends on the current public keys of external providers; keys rotate
without notice and are never present in the test environment.

- **Google OAuth** — `AuthController::googleLogin()` — `Google_Client::verifyIdToken()`
- **Facebook Login** — `AuthController::facebookLogin()` — profile data fetch
- **Apple Sign-In** — `AuthController::appleLogin()` — JWT + rotating Apple signing keys
- **Discord OAuth** — `AuthController::discordLogin()` — token exchange

---

## 3. Real Audio File Processing

No test fixture can substitute for a real binary audio file when the feature reads codec
metadata or shell-invokes `ffprobe`.

- **`AudioFileAnalyzer`** (`app/Services/AudioFileAnalyzer.php`) — `validateAudioFile()`,
  duration extraction via `ffprobe` + fallback to getID3. Affects all formats: mp3, m4a, m4b,
  m4p, mp4, aac, ogg, oga, wav, flac, wma.
- **Duration matching in Google Books** — `GoogleBooksApiService::searchAndMerge()` —
  the ±15% tolerance check uses the actual audio duration from disk.
- **Embedded ID3 tag / cover art extraction** — import pipeline reads artist, title, and
  embedded cover images directly from audio file headers.
- **OpenAI Whisper transcription** — real audio bytes required; mock responses cannot
  validate accuracy or API contract.

---

## 4. Filesystem-Dependent Features

These features require real directory trees and file contents on disk.

- **`BookFilesystemService`** — `renameItem()`, `listFiles()`, `browseDirectories()` — real
  `rename()` and `scandir()` calls; moving book files to trash.
- **`BookDirectoryParser`** — Symfony Finder scanning real directories for audio files.
- **`BookImportService::lookupOpenAudibleMetadata()`** — expects `books.json` in the real
  OpenAudible directory layout.
- **`CoverImageAnalysisService::isTextOnWhiteCover()`** — reads real image pixels via
  Intervention\Image; cannot determine cover quality from a mock.
- **`ValidateAudioFilesCommand`, `ValidateBookDirectoriesCommand`** — walk real filesystem.
- **`GenerateLibraryJson`, `ListMissingBookDirectories`** — check actual `directory_path` on
  disk.
- **ZIP file upload and validation** — `SkinController::store()` / `ThemeController` — real
  ZIP bytes required.
- **`ApiHealthController::checkStorageVolumes()`** — reads real filesystem state via `is_dir()`,
  `is_readable()`, `disk_free_space()`, `disk_total_space()`. Tests use `/tmp` as a stand-in;
  they cannot verify that the actual production mounts (e.g. `/media/audiobooks/books`) are
  mounted, readable, or have sufficient free space.

---

## 5. Interactive Terminal UI

The import command uses raw TTY operations that cannot be driven by PHPUnit.

- **`ImportUIService`** (`app/Services/ImportUIService.php`) — `readLineWithPrompt()`,
  cursor positioning, ANSI control codes, STDIN reading.
- **`ImportBooksFromDownloads` command** — the entire interactive review loop, field editing,
  confidence prompts, cover selection menu.
- Any change to callback signatures in `processAudiobook()` (25+ callbacks) may silently
  break the interactive flow.

---

## 6. Browser / JavaScript UI

These features require a live browser with DOM and event-loop; Jest tests cover logic but not
rendering or real user interactions.

- **Cover image selection UI** (`resources/js/admin/books/form-cover.js`) —
  `ensureCoverImageSelected()`, radio button handlers, `syncCornerPreview()`.
- **Import file browser** (`resources/js/admin/books/import_file.js`) — AJAX directory tree,
  file selection, preview rendering.
- **Directory browser widget** (`resources/js/admin/books/directory-browser.js`) — real-time
  list updates.
- **Book form autocomplete** (`resources/js/admin/books/form-autocomplete.js`) — author /
  narrator / series autocomplete dropdowns.
- **Book form initialization** (`resources/js/admin/books/init-book-form.js`) — jQuery DOM
  wiring on page load.
- **Inline cover preview during import** — the cover candidate list with inline `<img>` tags
  rendered in the terminal; verifying display requires a human.
- **Skin designer rendering parity** (`public/js/skin-designer.js` `SkinRenderer.renderElement()`)
  — must stay in sync with the Android client's renderer (`ElementRenderers.kt`, separate repo
  `audiobook-librarian-client`) and `tools/preview_skin.py`, both outside this repo and untouched
  by this repo's test suite. Any new element property (e.g. `foregroundImage`) can silently render
  correctly here but differently — or not at all — on the other two, with no automated check
  across repos. Verify new rendering properties manually in all three places.

---

## 7. File Download / Streaming

Real bytes streamed to a client cannot be verified by unit or feature tests.

- **`BookDownloadController::download()`** — 8 MB chunked file streaming. Also reads every file's
  size via `freshFileSize()` (`clearstatcache()` then `filesize()`) instead of a bare
  `Storage::size()`/`filesize()` call. Root-caused a real incident: a client's manifest reported
  a book's `.m4b` at 368,773,186 bytes while the file on disk was a stable, complete 603,039,422
  bytes (unchanged mtime, hours old) — not a live write race, but PHP's per-process stat cache
  serving a size some earlier read in that worker had cached, indefinitely, until the worker
  recycled. The client trusted that manifest size as authoritative, downloaded exactly that many
  bytes, and ended up with a file missing its trailing MP4 `moov` atom. `freshFileSize()`'s
  actual behavior (clearing PHP's real stat cache) is unit-tested directly by growing a real temp
  file after an initial `filesize()` read; the fix itself needed no live concurrency to reproduce
  or verify, only two real filesystem reads in one PHP process.
- **`BookDownloadController::downloadFile()`** — same `freshFileSize()` fix, for the
  `Content-Length`/Range-header size used by the actual byte-streaming endpoint.
- **`BookDownloadController::downloadUrl()`** — same `freshFileSize()` fix, for the per-file
  `size` field in its signed-URL response (unused by the current Android client, which calls
  `download()` for its manifest, but shares the same bug class).
- **`BookDownloadController::queueDownload()`** — multi-file ZIP creation from real book files.
- **`BookDownloadController::remoteDownload()`** — proxy to LibriVox / archive.org CDN.
- **`librivoxManifest()`** — builds download manifest from live CDN URLs (ia800.archive.org).
- **`BookCoverController::cover()`** — serves cover images from disk or proxies remote URLs.
- **`SkinController::download()`, `ThemeController::download()`** — ZIP file streaming.

---

## 8. Email Delivery

Mail is caught by the test fake, but actual SMTP delivery, formatting in real clients,
and OTP arrival timing cannot be verified.

- **OTP email flow** (`EmailOtpController`) — real OTP must arrive in inbox before it expires.
- **Password reset emails** (`PasswordResetController`).
- **Hardcover token expiry notification** (`HardcoverTokenExpiring` Mailable).
- **Daily favourite book notifications** (`SendDailyFavoriteNotifications` scheduled command).
- **New user registration notification**.

---

## 9. Background Queue Jobs

Jobs dispatched to the queue run outside the HTTP request and cannot be observed by
feature tests without running a real queue worker.

- **`ProcessQueuedImportJob`** — reads real files, calls AI, updates DB.
- **`ImportBookFromDirectoryJob`** — requires the target directory to exist.
- **`CreateImportJobsForDirectory`** — real filesystem scan.

---

## 10. Time-Dependent Logic

- **Hardcover API token expiration** — timer-based check + email; requires real time passage
  or a mocked clock wired through the service.
- **LibriVox 24-hour API cache** — cache invalidation is invisible to the test suite.
- **`SendDailyFavoriteNotifications`** — scheduled via cron; never runs in test context.

---

## 11. Device / Client Specifics

- **`DeviceController`** — device fingerprinting via request headers varies by real
  Android/iOS hardware; cannot be reliably simulated.
- **Audio playback progress sync** — position tracking is driven by the mobile client;
  server-side tests only verify storage, not end-to-end accuracy.
- **Mobile app QR/server-connect redirector** — QR scanning, custom URL scheme dispatch,
  app-store fallback behavior, and returning after first install depend on real Android/iOS
  devices, installed app variants, browser behavior, and store availability.
- **Push token registration (`DeviceController::updatePushToken`)** — this endpoint only
  stores the FCM/ADM token; there is no send-side integration (no Firebase Admin SDK / ADM
  HTTP client wired up anywhere yet). Whether a stored token is actually valid, whether it
  belongs to the store variant it claims (`fcm` vs `adm`), and whether a push notification
  built from it would actually reach a device can only be verified against a real Google
  Play / Amazon Appstore install, not this test suite.

---

## 12. Docker / Container Deployment

The `Dockerfile`, `docker-compose*.yml`, and `docker/entrypoint.sh` orchestrate image build,
first-boot bootstrap (APP_KEY generation, SQLite file creation or MySQL/PostgreSQL
readiness wait, `migrate --force`, `storage:link`, config/route/view caching), and
supervisor-managed nginx + php-fpm + queue worker + scheduler processes inside one
container. None of this is exercised by PHPUnit/Jest — a passing test suite says nothing
about whether the image actually builds, boots, serves traffic, or persists data correctly.

- **`docker/entrypoint.sh`** — first-boot logic (SQLite creation, external DB wait loop,
  migrations, caching) only runs when a container starts; a broken condition here silently
  produces a container that never becomes healthy, or worse, runs with a stale/empty schema.
- **`docker-compose.mysql.yml` / `docker-compose.pgsql.yml` overlays** — switching database
  backends via compose overlay is untested; a typo in the overlay's `environment:` block
  fails silently until the app tries to query the database.
- **Volume/bind-mount book storage** (`HOST_BOOK_STORAGE_PATH`, `HOST_DELETED_BOOKS_PATH`)
  — see section 4; inside a container, a wrong host path or permission mismatch on the
  bind mount is invisible until `checkStorageVolumes()` or an import job hits it.
- **Supervisor process supervision** (`docker/supervisor/supervisord.conf`) — if php-fpm,
  nginx, the queue worker, or the scheduler crashes inside the container, only supervisor's
  restart behavior recovers it; no automated check verifies all four stay up.

---

## Adding New Items

When you introduce a feature that belongs in any category above:
1. Add a row or bullet to the relevant section with the service/file name and **what breaks
   silently**.
2. Include it in the same commit as the feature code.
3. Call out the untestable risk in the PR description.
