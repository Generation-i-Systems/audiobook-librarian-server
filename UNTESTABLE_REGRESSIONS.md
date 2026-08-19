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
| **Anthropic Claude API** | `app/Services/AI/Providers/ClaudeProvider.php` | Prompt format, schema-mode output, token cost calculations. `describeImage()`/`callAPIWithImage()` (used by `EmbedBookJob` to caption book covers for the recommendation embedding pipeline) sends a vision-content-block request whose exact accepted shape (base64 image + text block ordering, media_type values) can only be confirmed against the real Anthropic API. |
| **OpenAI Whisper (transcription)** | `app/Services/AI/Providers/OpenAIProvider.php` `transcribe()` | Requires real audio; mock cannot verify transcription accuracy |
| **OpenAI Chat / GPT** | `app/Services/AI/Providers/OpenAIProvider.php` | Structured JSON schema responses. `describeImage()`/`callAPIWithImage()` sends a Chat Completions vision request (`image_url` with a `data:` URI) whose real-API acceptance can't be proven by mocks. |
| **Google Gemini** | `app/Services/AI/Providers/GeminiProvider.php` | Any model-response format changes. `describeImage()`/`callAPIWithImage()` sends `inline_data` with a real cover image's bytes; only a mocked HTTP response is exercised by tests, not the real Gemini vision endpoint's actual acceptance/limits (e.g. max image size). |
| **AIBookProcessor prompt (import metadata extraction)** | `app/Services/AIBookProcessor.php` `buildPrompt()`/`extractMetadataFromTranscription()` (separate, older Gemini/Claude/OpenAI caller from the `AI/Providers/*` classes above) | Whether the model actually returns a `tags` field, and whether it reliably infers the "spicy" tag only when warranted (vs. over/under-tagging), can only be judged against real AI responses on real book directories — unit tests only cover `normalizeMetadata()`'s parsing of an already-returned `tags` array, not the model's judgment in producing one. |
| **Google Books API** | `app/Services/GoogleBooksApiService.php` | Search queries, duration-matching tolerance (±15%), result ranking |
| **Hardcover API (GraphQL)** | `app/Services/HardcoverService.php` | Token expiry email flow; GraphQL schema changes |
| **Audible API** | `app/Services/AudibleService.php`, `AudibleApiService.php` | Search filtering, cover image download, rate-limiting headers |
| **Audnex API** | `app/Services/AudnexApiService.php`, used by `AudibleService::enrichWithAudnex()` | Response schema changes (audnex is an unofficial third-party wrapper around Audible); outage-vs-no-data caching split (only confirmed outcomes are cached — see `getBookByAsin()`); `AUDNEX_ENABLED` kill switch |
| **LibriVox API** | `app/Services/LibriVoxApiService.php` | 24-hour cache TTL logic; chapter metadata structure |
| **AudioBook Bay scraper** | `app/Services/AudiobookBayApiService.php`, `AudiobookBayCategoryScraperService.php` | XPath selectors break if site HTML changes; login/session |
| **Google Custom Image Search** | `app/Services/GoogleImageSearchService.php` | API key scopes, result quality filtering |
| **External cover image fetch** | `app/Services/ExternalCoverService.php` | Binary download, timeout handling, file caching |
| **Embedding provider APIs (OpenAI/Gemini/Voyage/Mistral text embeddings)** | `app/Services/Embeddings/EmbeddingPipeline.php::resolveEmbeddingProvider()`, `NeuronAI\RAG\Embeddings\*` (vendor package) | These call the real embedding endpoint per provider; response shape (vector length matching the configured `dimensions`, rate limits) can only be confirmed live. `EmbedBookJob` treats an embedding-call failure as an uncaught exception (job retry), which is also unverified against real API error responses. `App\Services\Search\SemanticBookSearchService::rankedBookIds()` (used by the opt-in `semantic=true` param on `GET /books`, `GET /books/search`, and the web book-search page's "Smart search" toggle) calls the same `embedText()` + `resolveVectorStore()->similaritySearch()` pair at query time; automated tests mock `EmbeddingPipeline` (same pattern as `AbstractSimilarityStrategy`'s tests), so real provider latency/rate limits and actual nearest-neighbor result quality for a raw query embedding (as opposed to a whole-book embedding) are unverified by the suite. Manually verify: run a real `semantic=true` search against a populated vector store and confirm result ordering looks reasonable. |
| **AI ranking fallback for discovery shelves** | `app/Services/Recommendations/Strategies/AbstractSimilarityStrategy.php::rankWithAi()` | Used by `SimilarToRecentBooksStrategy`/`NewForYouStrategy` when the vector store has no usable data yet: sends a candidate-book catalog to whichever provider is configured via `completeStructured()` and expects a `{"book_ids": [...]}` JSON response. Tests substitute a fake `AIProviderInterface` via the `aiProvider()` extension point (never make a real call), so the real provider's actual willingness/ability to follow this schema instruction is unverified — a bad response is designed to degrade to plain SQL recency order rather than error, but that degradation path itself has only been proven with a fake failure response, not a real malformed one. |

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
- **Embedded chapter extraction for `librarian.json`** — `ChapterDetectionService` shells out to
  `ffprobe -show_chapters`; fixtures can verify normalization, but only real audiobook files can
  prove chapter availability across container formats and encoders.
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
- **`CacheBookFileChunkHashes`** (`books:cache-file-chunk-hashes`) — walks real book
  directories and reads full file bytes to pre-generate manifest chunk hashes; fake-disk tests
  cannot prove production mount permissions, I/O contention, or hash throughput on real files.
- **`GenerateLibraryJson`, `ListMissingBookDirectories`** — check actual `directory_path` on
  disk.
- **ZIP file upload and validation** — `SkinController::store()` / `ThemeController` — real
  ZIP bytes required.
- **`ApiHealthController::checkStorageVolumes()`** — reads real filesystem state via `is_dir()`,
  `is_readable()`, `disk_free_space()`, `disk_total_space()`. Tests use `/tmp` as a stand-in;
  they cannot verify that the actual production mounts (e.g. `/media/audiobooks/books`) are
  mounted, readable, or have sufficient free space.
- **`AppRefreshCommand`** (`app:refresh`) — recursively updates filesystem permissions of all storage/cache directories on disk, runs database migrations, restarts queue workers, resets SAPI/CLI OPcaches, and reloads SAPI `php-fpm` via systemd (`systemctl reload`). Automated tests run in an isolated sandbox where real system privileges, group ownerships, SAPI process state, and systemctl commands are stubbed/skipped, so tests cannot verify SAPI reload or permissions updates in production.

---

## 5. Interactive Terminal UI

The import command uses raw TTY operations that cannot be driven by PHPUnit.

- **`ImportUIService`** (`app/Services/ImportUIService.php`) — `readLineWithPrompt()`,
  cursor positioning, ANSI control codes, STDIN reading.
- **`ImportBooksFromDownloads` command** — the entire interactive review loop, field editing,
  confidence prompts, cover selection menu.
- Any change to callback signatures in `processAudiobook()` (25+ callbacks) may silently
  break the interactive flow.
- **`ReviewProgressionFantasyDuplicates` command** (`app/Console/Commands/ReviewProgressionFantasyDuplicates.php`)
  — interactive menu driven by `$this->ask()`, and shells out to the external `mplayer` binary
  via `passthru()` to play audio for manual A/B comparison. Neither the TTY menu loop nor the
  mplayer playback can be exercised by PHPUnit; only the pure duplicate-pair-finding logic
  (`findDuplicatePairs()`/`findBookOccupyingSlot()`) is realistically testable in isolation.
- **`BookImportService::playAudioFiles()`** (`app/Services/BookImportService.php`) — shells out
  to `mpv` (preferred) or `mplayer` via `passthru()` from the main import duplicate-conflict
  screens (`selectDuplicateAction()`'s `p`/`e` options), used to A/B-compare source vs. existing
  audio for narrator/quality judgment. `resolveAudioPlayerBinary()`'s `command -v` check and the
  empty-file-list branch are unit tested; actual playback, terminal takeover, and `q`-to-quit
  behavior cannot be — verify manually with both mpv and mplayer installed and neither installed.
- **`ImportUIService::resize()`** (`app/Services/ImportUIService.php`) and the `SIGWINCH` handler
  in `ImportBooksFromDownloads::setupSignalHandlers()` — redraws the import TUI at a new size
  when the terminal window is resized mid-import. `pcntl_signal(SIGWINCH, ...)` cannot be
  triggered from PHPUnit (no real TTY/signal delivery in the test runner); only the deterministic
  plain-mode width/height update is unit tested. Verify manually by resizing a real terminal
  while `book:import` is running.
- **Genre typeahead selector** — `ImportUIService::selectFilteredWithArrowKeys()` (raw-TTY
  arrow-key + type-to-filter loop, used in `--ui=ncurses` mode) and
  `HybridUIService::selectFiltered()` / `App\Services\ScrollableSearchPrompt` (Laravel Prompts
  `SearchPrompt`-based, used in the default `--ui=hybrid` mode) cannot be driven by PHPUnit since
  `terminalSupportsArrowInput()` is forced false under `app()->environment('testing')`. Only the
  filtering/sorting algorithm (`App\Support\TypeaheadFilter`) and the non-arrow fallback loop
  (`ImportUIService::selectFilteredFallback()`) are unit tested. Verify manually in a real
  terminal: typing should narrow the Genre list (earliest match first), Backspace should widen it
  again, and Enter/arrow-key navigation should still pick the highlighted genre. Escape should
  back out of just that selection (empty-string return, not the `'q'` quit sentinel — the caller
  falls back to the field's current/default value) without exiting the whole import, in both
  `--ui=ncurses` and the default `--ui=hybrid` mode — verify manually since raw ESC-key handling
  and `Laravel\Prompts\SearchPrompt`'s internal loop can't be driven by PHPUnit either.
- **Text field editing (Title, Directory Path, etc.)** — `ImportUIService::readLineWithEditableDefault()`
  (raw-TTY line editor, `--ui=ncurses`) and `HybridUIService::ask()` / `App\Services\ScrollableTextPrompt`
  (Laravel Prompts `TextPrompt`-based, default `--ui=hybrid` mode) have the same
  PHPUnit-can't-drive-a-raw-TTY limitation as the genre selector above. Verify manually: typing
  should edit the prefilled default text, Enter should submit whatever's currently in the field,
  and Escape should discard any edits and leave the field's original value untouched (not blank
  it out, and not quit the import).

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
- **Related books modal** (`resources/js/admin/books/related-books-modal.js`) — Bootstrap modal
  open/close, AJAX-loaded book list rendering; only the backend `relatedBooksAjax` JSON endpoint
  is covered by Feature tests.
- **Save button spinner** (`resources/js/admin/books/save-button-spinner.js`) — depends on script
  load order relative to `directory-conflict.js` (registers its `submit` handler after it, so
  `e.isDefaultPrevented()` correctly reflects an in-progress async directory-conflict check); a
  reordering of the `@vite([...])` list in `form_support_modals.blade.php` or `vite.config.js`
  could silently break this without any test catching it.
- **Admin login QR modal** (`resources/js/admin/users/login-qr.js`) — Bootstrap modal
  open, AJAX fetch of a fresh login OTP, and `qrcode` canvas rendering (reusing
  `renderAppConnectQr()` from `resources/js/app-connect-qr.js`); only the backend
  `generateLoginQr` JSON response is covered by feature tests, not that a phone camera can
  actually scan the rendered canvas.
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
  actual behavior (clearing PHP's real stat cache) is **not** covered by an automated test: an
  earlier unit test tried to reproduce it by growing a real temp file after an initial
  `filesize()` read and asserting the stale-vs-fresh precondition, but whether PHP's stat cache
  actually goes stale for a given path depends on php.ini/OS-level caching behavior that differs
  between environments — the precondition held on the dev machine but failed on CI (fresh read
  both times), making the test flaky in a way that couldn't be fixed without mocking `filesize()`/
  `clearstatcache()` at the PHP-namespace-function level. The test was deleted rather than kept
  as a false safety net. A regression here (e.g. someone replacing `freshFileSize()` with a bare
  `filesize()` call) must be caught by code review, not tests.
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
  The web inline-code-entry path (`EmailOtpController::verifyCodeWeb()`) and the admin
  "Send login email" / "Show QR code" actions (`AdminUserController::sendOtp()`,
  `generateLoginQr()`) share this same real-arrival-timing risk — tests can only verify the
  DB record and mail-fake dispatch, not that a real inbox receives it in time.
- **Password reset emails** (`PasswordResetController`).
- **Web-based account deletion** (`ProfileController::destroy()`) — reuses the same
  `AccountDeletionScheduledMail` as the app's API-based deletion flow (`AuthController::
  deleteAccount()`), but is reached via the existing OTP web-login session
  (`EmailOtpController::verifyCodeWeb()`) rather than a bearer token, so it also inherits that
  OTP flow's real-inbox-arrival-timing risk. This is the app-store-required "delete your
  account without the app" path (Amazon/Google Play) — tests cover the scheduling/mail-fake
  dispatch and DB state, but the full "receive OTP email, log in, click delete" journey has
  only been exercised end-to-end for the *existing* login flow it builds on, not this
  specific delete-after-login path, and only manually against a real inbox/SMTP setup can
  confirm the whole chain works for a real user with no app installed.
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
- **`EmbedBookJob`** (`app/Jobs/EmbedBookJob.php`) — reads a real cover image file, calls a real AI vision API for a cover caption and a real embedding-provider API, then writes to the recommendation vector store. Unit tests cover the branching logic (skip-when-unavailable, skip-when-unchanged, force) with mocked `EmbeddingPipeline`/`EmbeddingsProviderInterface`, but real end-to-end behavior — actual vision/embedding API responses, and the vector store write itself under real queue-worker conditions — can't be observed by PHPUnit. This job is dispatched onto a dedicated `embeddings` queue that **must** be run with a single worker (see the `FileVectorStore` note below); nothing in the test suite can verify that the deployed queue configuration actually honors that.
- **`FileVectorStore`'s concurrent-write safety** (`vendor/neuron-core/neuron-ai/src/RAG/VectorStore/FileVectorStore.php`, used by `EmbeddingPipeline::resolveVectorStore()`) — `addDocuments()` appends to its `.store` file via `file_put_contents(..., FILE_APPEND)` with no `flock`/`LOCK_EX`. Unit tests exercise it against a real temp-directory file (not mocked), so single-writer correctness is proven, but interleaved writes from two concurrent `EmbedBookJob` queue workers — the actual risk during a bulk `books:backfill-embeddings` run or a burst of book edits — cannot be simulated by PHPUnit's single-process test runner. Verify manually: run `queue:work --queue=embeddings` with more than one process against a real backfill and confirm the `.store` file isn't corrupted (every line still parses as JSON).
- **`RecomputeRecommendationsJob`** (`app/Jobs/RecomputeRecommendationsJob.php`) — runs `RecommendationEngine::fromConfig()->recompute()` for one user, which in turn (via `SimilarToRecentBooksStrategy`/`NewForYouStrategy`) may do a real `FileVectorStore::similaritySearch()` scan and/or a real AI ranking call. Dispatched onto a dedicated `recommendations` queue on every `BookStatusUpdated` event (via `RefreshRecommendationsListener`) and by the daily `books:refresh-recommendations` sweep; unit tests cover the job/listener/command wiring and `RecommendationEngine`'s orchestration logic with mocked strategies, but real multi-user queue throughput (e.g. a `books:refresh-recommendations` run across a large user base, each doing an O(n) vector scan) has not been — and can't be — load-tested by PHPUnit.

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
- **Magic-link deep links carrying `apiUrl`** (`AppConnectLinks::magicPlayerDeepLink()`,
  `magicLibraryDeepLink()`, `androidMagicIntentLink()`, used by
  `EmailOtpController::magicLanding()`) — this repo can only verify the URL string these
  helpers produce (see `EmailOtpControllerTest::test_magic_landing_page_includes_api_url_in_deep_links_for_self_hosted_server`).
  Whether the mobile client actually reads the `apiUrl` query param from the deep link and
  targets that self-hosted server (rather than a default/other server) can only be verified
  on a real device against the separate client app repo.
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
