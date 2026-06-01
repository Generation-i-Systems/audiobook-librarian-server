## [Unreleased]

### Security

- Fixed path traversal vulnerability in `ImageProxyController` (`/image-proxy` and `/cover/{path}` routes): user-supplied paths are now confined to the book storage root via `realpath()` before serving files
- Fixed path traversal vulnerability in `SkinAssetController` (`/skin-asset/{id}/{path}` route): asset paths are now confined within the resolved skin directory via `realpath()`
- Fixed path traversal vulnerability in `DocsController` (`/docs/{path}` route): documentation paths are now confined to the `docs/` directory via `realpath()`
- Added `throttle:10,1` rate limiting to all authentication endpoints (`/login`, `/register`, `/forgot-password`, `/auth/otp/*`, and OAuth social login routes)

### Changed

- Added host-based library profile routing so one running server can serve multiple isolated library variants (`main`, `librivox`) from the same codebase and `.env`, with request-time DB/storage switching by incoming host
- Import CLI layout redesigned to fit within actual terminal height
- Genre management now stores an emoji plus a local icon path for each genre, shows both on the admin manage page, and returns them from genre API responses
- Genre-related API queries now ignore soft-deleted genres consistently in author filters, listening goals, and listening statistics
    - Replaced outer box with a simple title row; eliminated progress bar gaps
    - Menu fixed at 14 rows with dynamic scroll (all 9 edit-field options visible)
    - Activity Log gets all remaining space and grows with terminal height (6 visible lines at 40 rows)
    - Added `computeLayout()` centralized layout engine
    - Eliminated blank lines below submenus

- Apple Sign-In now performs user lookup by `apple_id` via `DocumentStoreServiceInterface` (no direct DB access from controllers)

### Fixed

- Badge evaluation now only grants badges for reachable conditions and real user activity
    - Canonical badge definitions now align with actual tracked data (goals, playlists, library, recommendations, seasonal activity, and series exploration)
    - Legacy impossible badge definitions are deactivated during canonical seeding, and action badges remain available
    - Speed badges now require meaningful listening time at each playback speed instead of any brief speed change
    - Library and goal badges now use `user_book_status`, playlists, listening goals, and cross-device listening data correctly
- Reading timeline and dashboard stats now aggregate correctly across a user's devices and return usable listening-minute summaries
    - Added day/week/month listening-minute totals to overview and dashboard responses
    - Timeline queries now support detailed day/week/month drill-downs with per-book listening totals
    - Playlist listening goals now count listening from all of the user's devices
    - Timeline queries now support arbitrary date ranges with aggregation by day, week, month, or year
    - Timeline queries now support weekend-only, weekday-only, or specific weekday filtering for client reporting needs

- **CRITICAL**: Fixed auto-processing bug where books were imported without user confirmation
    - reviewAndApprove method was auto-approving books when no enrichment data existed
    - Restructured to always run the review loop regardless of enrichment data presence
    - Ensures user can review and approve ALL imports, set cover images, and verify metadata
    - Fixed default choice to prefer 'Edit' when enrichment data is missing

- OpenAPI spec updated to document Apple/Facebook login responses (401/403/500)
- Badge API `/badges/unnotified` now returns complete badge fields required by clients (e.g. `key`, `name`, `description`, `icon`, `category`, `tier`, `points`, `is_repeatable`)
- Listening statistics endpoints now aggregate correctly across devices for authenticated users by querying `user_id` (and `reportSession` stores `user_id` and `device_id` correctly)
- SQLite test runs no longer fail on MySQL-specific `ALTER TABLE ... MODIFY ...` in listening events migration
- **CRITICAL**: Import now verifies files exist in destination BEFORE deleting source to prevent data loss
    - Fixed moveSpecificFiles to verify destination before deleting cross-filesystem source files
    - Moved assertDirectoryHasAudioFiles check before cleanupSourceDirectory for regular moves
    - Prevents source deletion if move/copy operations fail silently
    - Added exception handling to stop multi-book imports on ANY error (not just merge requests)
    - Enhanced logging for multi-book processing to track each book's import status
- Series names and numbers are now properly removed from book titles in all import paths
    - Added removeSeriesFromTitle call after extractSeriesNumberFromTitle and after enrichment
    - Added patterns for "Series [##] Title", "Series (##) Title", and "Series Title" (space-only) formats
    - Ensures title cleaning happens regardless of metadata source (AI, tags, enrichment)
- Genre is now auto-detected from existing books in the same series by the same author
- Fixed multi-book file grouping to correctly split each file into its own book
    - Replaced fuzzy stripos matching with precise regex patterns using word boundaries
    - Prevents multiple files from being incorrectly grouped into a single book entry
- Restoring a deleted book now restores/updates the existing (soft-deleted) database record and queues restored paths for permission fixing
- Bookmark delete-by-id now permanently deletes the bookmark record (instead of soft-deleting)
- Import UI test mock updated to include `getAllLogs()`
- OpenAPI spec updated to document device management and sync endpoints required by route coverage tests

### Added

- Added `--collection`, `--genre`, and `--pattern` options to `book:import` CLI command
    - `--collection`: Specify a collection name to put all imported items into
    - `--genre`: Specify a default genre for all imported items
    - `--pattern`: Specify an alternate default storage pattern (e.g., `[genre]/VA/[series]/[title] ([author])`)
- Implemented custom directory pattern engine in `BookImportService`
    - Supports placeholders: `[genre]`, `[author]`, `[series]`, `[title]`, `[series_number]`, `[year]`, `[narrator]`
    - Automatically handles path cleanup and consistency with existing directory logic
- Enhanced `bin/import-bk` wrapper script to properly handle options with separate values
- Reorganized test suite by category for better organization and targeted testing
    - tests/Api/ - API tests (156 tests)
    - tests/Web/ - Web/Admin UI tests (111 tests)
    - tests/Cli/ - CLI/command tests (283 tests)
    - tests/Import/ - Import system tests (190 tests)
    - tests/Core/ - Core/service tests (224 tests)
- Added composer test scripts for each category: test:api, test:web, test:cli, test:import, test:core
- GitHub Actions workflows organized by test category for efficient CI/CD
    - api-tests.yml - Runs only API tests when API code changes (~30s)
    - web-tests.yml - Runs only web/admin tests when UI code changes (~25s)
    - cli-tests.yml - Runs only CLI tests when commands change (~60s)
    - import-tests.yml - Runs only import tests when import system changes (~75s)
    - core-tests.yml - Runs only core tests when services/models change (~45s)
    - full-test-suite.yml - Complete suite for main branch, manual runs, and daily schedule
- Path-based workflow triggers minimize unnecessary test runs (run 100-300 tests vs all 1100+)
- Bootstrap-level database safety check that aborts any PHPUnit/artisan test run unless SQLite in-memory configuration is detected, preventing accidental production wipes
- MockDocumentStoreService wiring and helpers for feature/unit tests so web auth, queue controllers, and import flows can run entirely in-memory without touching MySQL
- Book edit page: needs_review_reasons now editable via checkboxes to keep or remove specific review flags
- Book deletion now moves files to trash instead of permanent deletion
- Library Repair enhancements:
    - Added `NUMBERED_SUFFIX_DIRECTORY` detection plus per-issue rescans/imports and stale-issue cleanup
    - Library Repair UI now defaults to pending issues, includes a “Show resolved” toggle, inline AudiobookBay links generated at render time, and replaces “Mark resolved” with targeted Rescan/Import actions
    - MySqlService + controller/view now emit only minimal issue metadata and rebuild presentation details dynamically
- BookDeletionService for unified deletion operations across web and CLI
- Admin trash management page at /admin/trash with restore and permanent delete functionality
- Trash auto-cleanup by age feature with configurable days threshold
- Environment variables: DELETED_BOOKS_PATH and TRASH_AUTO_CLEANUP_DAYS
- Delete button on book edit page with confirmation modal and option to delete files
- Added `/admin/books/search` support for `source=audiobookbay` and `source=hardcover`
- Added standalone external integration test suite for metadata lookup (real network calls; not part of default test run)
- Added import destination audio-file sanity check to prevent successful imports when the destination directory contains no audio files
- Import-from-File: remember selected genre during multi-book imports and reuse it for subsequent books in the same session
- Added `--no-multi-book` flag to `books:import-downloads` command to disable multi-book directory detection for the entire run
- Added `^` suffix support for book directories to disable multi-book detection for specific directories (e.g., "Series Books 1-3^" won't be treated as multi-book)
- Title parsing now automatically strips trailing colons from parsed directory names (manual edits preserve colons)
- Introduced **Library Repair** system:
    - `library:repair-scan` command detects missing/orphan/duplicate/nested directories (JSON output, per-issue filters, optional auto-fixes)
    - Nightly scheduler entry runs the scan and logs to `storage/logs/library-repair.log`
    - `/admin/library-repair` dashboard (linked in admin navbar) surfaces issues with filtering, pagination, AudiobookBay links, and resolve actions
    - Library Repair issues automatically flag books for Needs Review; edit form now lets staff keep/clear reasons individually
    - Comprehensive unit tests for `LibraryRepairService` + command ensure regressions are caught
- User edit prompts now support single space (' ') input to clear a field instead of using the default value
- Added `DocumentStoreServiceInterface::findBookByDirectoryPath()` for consistent directory-based lookups.

### Changed

- Book edit form: needs_review section now interactive with checkboxes (check to keep reasons, uncheck to remove)
- BookController::destroy() now uses trash system instead of direct deletion
- Book delete confirmation shows directory path and file count with option to preserve or delete files
- `books:info --delete` command now uses trash system instead of permanent deletion

### Fixed

- Fixed directory conflict resolution to avoid creating suffixed directories (`_01`, `_02`) when target directory only contains metadata files (librarian.json, cover images)
- Both web-based directory edits and command-line imports now reuse existing directories when they only contain metadata
- Added metadata detection to distinguish between actual content conflicts and overwritable metadata files
- BookDirectoryMoveService and BookImportService now consistently handle directory conflicts with the same logic
- CLI import UI now clears cached cover art between books so stale images are never shown when the next book lacks a cover
- Updated API authentication documentation to match the current bearer token implementation (email or username login; opaque token; logout revokes token)
- Fixed `ShowBookInfo` command opening browser during test runs
    - Added environment detection to skip browser launch when APP_ENV=testing
    - Tests now verify skipping behavior instead of actually opening browser
- Fixed CI failures in Web/Admin and Import test workflows
    - Replaced PHPUnit doc-comment metadata usage with PHP 8 attributes to avoid PHPUnit warnings
    - Added `APP_KEY` to `.env.example` so `php artisan key:generate` works in CI
    - Improved Import test PSR-4 autoloading to prevent Composer from skipping test classes
- LoginFeatureTest, QueueControllerTest, BookDeletionServiceTest, and MoveBookDirectoryCriticalTest now mock DocumentStore interactions correctly and cover rollback scenarios without DB facade hacks
- Authors API pagination tests generate unique author names to avoid SQLite unique-constraint flakes
- DatabaseSafetyCheckTest updated to camelCase + enforced to run first, ensuring safety assertions gate every test invocation
- Fixed TUI import preview Directory to always show the destination `directory_path` under `app.book_root` and made it editable during review
- Fixed TUI prompt area reserving too much vertical space by sizing it dynamically to the current prompt content
- Fixed `books:info` command not finding books when `BOOK_STORAGE_PATH` is a symlink
    - Now resolves symlinks using `realpath()` for consistent path handling
    - Fixes "Could not read image dimensions" errors when displaying cover images
    - Books can now be found regardless of whether paths use symlinks or real paths
- Fixed admin book edit `directoryPath` updates to move/merge files when the destination directory already exists
- Fixed admin book edit `directoryPath` updates not moving files when the old path is a directory (used file-only existence checks)
- Fixed `book-mv`/`books:move` aborting when the destination directory already exists by treating the destination as a parent directory
- Added `books:move --require-book` to abort instead of falling back when no matching books are found
- Added `books:move --verify` and `book-mv --verify` to interactively review planned path/database changes before applying
- Added `book-mv --book-only` and `book-mv --non-book` to force database-aware or filesystem-only behavior
- Fixed import-bk creating duplicate book directories with a `_01` suffix when the destination directory already exists from an earlier step (e.g., cover image creation)
- Fixed admin book edit not persisting series updates when the form submits malformed `series` payloads; also fixed MySqlService `findOrCreateMany()` undefined-method errors by adding missing `findOrCreate*` helpers
- Fixed inconsistent cover image storage by persisting local `coverImage` values as filename-only (relative to `directoryPath`) while remaining backward compatible with legacy values that include the directory path
- Fixed `books:move` command error "Property [name] does not exist on this collection instance"
    - The `series` relationship is `belongsToMany` (returns Collection), not a single model
    - Now correctly uses `->first()` to get the first series before accessing `->name`
- Fixed API returning null series entries (e.g., `{"name":null,"series_number":null}`)
    - `formatSeriesData()` now filters out entries with null or empty series names
    - API responses now return empty `[]` instead of arrays with null values
    - Ensures compliance with OpenAPI spec for series field

### Changed

- Refactored `Admin\BookController` by moving filesystem-related AJAX endpoints into `Admin\BookFilesystemController` and `BookFilesystemService`.

### Added

- Added API health check endpoints for uptime monitoring (no authentication required)
    - `GET /api/v1/health/ping` - Basic ping endpoint for uptime checks
    - `GET /api/v1/health` - Detailed health check with database, format, and spec validation
    - `GET /api/v1/health/validate` - Full OpenAPI spec compliance validation
    - Validates series format, array fields, and book structure
    - Returns 200/healthy or 503/unhealthy status with detailed check results

- Added comprehensive API spec validation test suite (`ApiSpecValidationTest.php`)
    - Run independently with: `php artisan test --filter=ApiSpecValidationTest`
    - Run all spec tests: `php artisan test --group=api-spec`
    - Tests for series field (no null entries), author/narrator/genre arrays
    - Tests for pagination meta structure compliance

### Fixed

- Fixed multi-book series detection to use actual book titles instead of chapter titles
    - Now extracts SERIES and PART metadata fields from M4B files
    - Prefers 'album' metadata field over 'title' field for book titles (avoids "Chapter 1" issues)
    - Filters out chapter/part/track patterns from title field (e.g., "^Chapter \d+")
    - Uses SERIES metadata field for series name when available
    - Falls back to directory name for series when metadata missing
    - Extracts series number from PART metadata field before filename parsing
    - Properly handles "(Unabridged)" and "(Abridged)" suffixes in album names
    - Fixes issue where multi-book archives showed random series like "3 #4"
- Fixed admin books index view error when coverImage is stored as array
    - Added array handling to extract path from coverImage field
    - Handles coverImage as string, array with 'path' key, or indexed array
    - Prevents "Missing required parameter" error in cover.proxy route
    - Added comprehensive test coverage for all coverImage formats
- Fixed genre detection for OpenAudible imports
    - Now reads OpenAudible's books.json file when present
    - Automatically detects books.json in scan directories (up to 5 levels deep)
    - Injects genre and other metadata from books.json into import process
    - Prioritizes OpenAudible genre data over AI-detected genres (when confidence < 90%)
    - Properly handles hierarchical genre formats (e.g., "Science Fiction & Fantasy:Science Fiction")
    - Books receive dual genres: mapped primary + OpenAudible category as secondary
    - Exception: "Science Fiction & Fantasy:Science Fiction/Fantasy" only get single genre
    - Added comprehensive OpenAudible category mappings:
        - Biographies & Memoirs → History
        - Business & Careers → Non Fiction
        - Children's Audiobooks → Kids
        - Computers & Technology → Computer
        - Mystery, Thriller & Suspense → Action
        - Teen & Young Adult → General Fiction
        - And 15+ more category mappings
    - Also imports series, narrator, publisher, release date, and description from books.json
    - Improves accuracy for OpenAudible book imports
- Fixed directory paths storing absolute paths instead of relative paths
    - ImportBooksFromDownloads now strips book root from custom directory paths
    - ImportBooksFromDownloads now handles absolute paths in duplicate detection (4 locations)
    - ImportBooksFromDownloads.handleManualReview() now passes metadata by reference to preserve edits
    - ImportBooksFromDownloads.editMetadataFields() always sets custom_directory_path to preserve edited titles
    - BookImportService now uses makePathRelative() helper for consistent path conversion
    - BookImportService.generateTargetDirectory() now honors custom paths and converts absolute to relative
    - Handles edge cases with trailing slashes and different path formats
    - Prevents doubling of root directory in file operations
    - Custom directory paths and edited titles set during import are now properly preserved
    - Added `books:fix-absolute-paths` command to fix existing books
    - Successfully fixed 990 books with absolute paths (921 + 69 MySQL-only records)
- Fixed cover image import to download images instead of storing URLs
    - BookImportService no longer falls back to storing URL when download fails
    - Cover images are now properly downloaded to book directories during import
    - Added `books:fix-url-covers` command to fix existing books with URL covers
    - Command scans all books, downloads images from external URLs, updates database
    - Uses curl for reliable HTTPS downloads with proper headers
    - Automatically detects source (Audible, Google Books) from URL
    - Skips internal API URLs (books.saturn.generation-i.com)
    - Supports --dry-run and --limit options
    - Comprehensive test coverage for cover download functionality
    - Successfully processing 1700+ books with URL covers

### Fixed

- Fixed admin book list to show books with missing directories
    - Added `includeAllBooks` parameter to MySqlService.listBooks() and MongoService.listBooks()
    - Admin panel now passes `includeAllBooks=true` to show all books
    - Admin panel now passes `include_needs_review=true` to show books flagged for review
    - Books with missing directories are marked with red background and "⚠️ Missing Files" badge
    - API calls still filter out books with missing directories and needs_review (for normal users)
    - Allows admins to find and fix broken book records
    - Fixed issue where books with `needs_review=true` were hidden from admin panel

### Added

- Added `books:fix-invalid-genres` command to fix books with invalid primary genres
    - Maps invalid genres to valid primary genres using GenreMappingService
    - Flags garbage genres (Copyright, locations, etc.) for manual review
    - Updates book genre relationships in database
    - Optionally moves book directories to correct genre folders with `--move-files`
    - Supports `--dry-run` to preview changes
    - Automatically cleans up empty invalid genres after fixing
    - Valid genres: Science Fiction, Fantasy, LitRPG, Romance, History, Historical Fiction, Non Fiction, Religion, Church, Kids, Action, Classic, General Fiction, Computer, Western, Horror, Mystery, Other, Science
    - Found 41 invalid genres affecting hundreds of books
- Added `books:update-genres-from-json` command to update genres from JSON metadata
    - Loads OpenAudible books.json files (1,746 books from multiple sources)
    - Matches books by ASIN to OpenAudible data
    - Falls back to librarian.json for non-OpenAudible books
    - Updates genres based on actual genre data in JSON files
    - Supports `--only-invalid` to target books with invalid genres
    - Supports `--dry-run` to preview changes
    - Maps genres through GenreMappingService for consistency
    - Successfully updated 1,107 books with missing or incorrect genres
- Added genre validation to BookImportService to prevent future invalid genres
    - All genres are now validated and mapped before creation
    - Invalid genres automatically map to valid ones:
        - "Fiction" → "General Fiction"
        - "Biography & Autobiography" → "History"
        - "Science Fiction & Fantasy" → "Science Fiction"
    - First genre is marked as primary, subsequent genres as secondary
    - Comprehensive test coverage ensures no invalid genres can be created
    - Prevents creation of invalid genre directories during import
- Added automatic cover image detection for individual audio files
    - When importing individual audio files (not directories), searches for cover images
    - Priority 1: Image with same basename as audio file (e.g., "Book.m4b" → "Book.jpg")
    - Priority 2: Common cover names (cover.jpg, folder.jpg, albumart.jpg, front.jpg)
    - Supports jpg, jpeg, png, webp, and gif formats
    - BookImportService now handles local file paths in cover_url field
    - Automatically copies cover images from source directory to book directory
    - Files are saved as "cover_local.{ext}" in the book directory
- Added `--skip-ai` option to import command
    - Skips all AI processing for faster imports when metadata is already complete
    - Uses only file metadata (ID3/M4B tags) and OpenAudible data
    - Useful for re-importing books with correct metadata already embedded
    - Works with existing --auto, --dry-run, and other import options
    - Sets confidence to 50% since no AI validation is performed
- Added `books:fix-titles-year-narrator` command to clean up book titles
    - Removes year prefix from titles (e.g., "2005 - The Colorado Kid" → "The Colorado Kid")
    - Removes year suffix in parentheses (e.g., "Book Title (2008)" → "Book Title")
    - Extracts and sets release_date from year (validates 1700 to current year)
    - Skips books with invalid years (future years or before 1700) - leaves title unchanged
    - Extracts narrator information from title parentheses
    - Supports multiple narrator patterns: "read by", "narrated by", "performed by"
    - Handles complex patterns like "(Nonfiction - read by Name)"
    - Handles multiple narrators separated by "and", commas, or "&"
    - Merges with existing narrator data without duplicates
    - Options: `--dry-run`, `--limit=N`
    - Comprehensive test coverage (13 tests)
- Enhanced OpenAudible import to automatically include PDF files
    - Automatically detects and imports PDF files with the same name as audio files
    - Works with both copy and move operations
    - Searches for PDFs in the same directory as the audio file
    - Applies proper file permissions (0664) to imported PDFs
    - Logs PDF import operations for tracking
- Added `books:fix-james-axler-deathlands` command to fix James Axler Deathlands series books
    - Standardizes all books to series 'Deathlands (GraphicAudio)'
    - Extracts series number from directory path or title
    - Handles double-number pattern (e.g., "03 008" extracts "8")
    - Ignores failed import numbers (01, 02, 03)
    - Removes number prefix from titles
    - Sets genre to 'Science Fiction' for all books
    - Merges duplicate books with same series number AND same normalized title
    - Books with same number but different titles are NOT merged (e.g., sub-series)
    - Moves files from duplicate directories
    - Fixes directory paths to standard format with 3-digit zero padding
    - Uses correct book storage path from config
    - Options: `--dry-run`, `--no-backup`
    - Comprehensive test coverage (12 tests)
- Added `books:resolve-duplicate-paths` command to find and resolve duplicate directory paths
    - Scans all books for duplicate `directory_path` values
    - Displays detailed side-by-side comparison with authors, series, narrators
    - Calculates completeness score (% of fields populated)
    - Interactive resolution options:
        - Keep Book #1 or #2 - Choose which book to keep, delete the other
        - Merge manually - Field-by-field merge, choose best value for each field
        - Ignore - Skip this duplicate for now
        - Quit (q) - Exit command safely
    - Options: `--dry-run`, `--auto`, `--limit=N`, `-v` for verbose
    - Field-by-field merge with readable formatting
    - Auto-skips fields when values are identical
    - Shows relationship IDs for reference
    - Syncs authors, series, narrators, genres via pivot tables
    - Safe deletion with logging and confirmation
- Enhanced `books:update` and `books:info` commands to download cover images from URLs
    - Automatically downloads and saves cover images when URL is provided
    - Follows HTTP redirects (up to 10 hops) for Google Images, Goodreads, etc.
    - Validates downloaded content is actually an image
    - Saves as `cover.{ext}` in book directory
    - Supports jpg, jpeg, png, gif, webp formats
    - Stores relative path in database instead of external URL
    - Works with both commands: `books:update --cover=URL` and `books:info -c URL`
    - Example: `books:info /path/to/book -c https://example.com/image.jpg`
- Implemented cover image priority system for imports
    - Priority 1: Existing files in directory (cover.jpg, folder.jpg, \*.jpg)
    - Priority 2: Embedded cover from M4B file
    - Priority 3: Cover from enrichment sources (Audible, Google Books)
    - Added `findExistingCoverImage()` method to scan for cover files
    - Existing and M4B covers are preserved during enrichment merge
    - Clear logging shows which cover source was used

### Fixed

- Fixed books:move command failing when moving book into existing series directory
    - Now checks if destination is an existing directory
    - Treats existing directories as "move into" targets
    - Works even when shell script removes trailing `/`
    - Example: `mv Book Series/` works correctly
- Fixed books:move command path security check with symlinks
    - bookRoot is resolved to real path for consistency
    - Now resolves user-provided paths to real paths before comparison
    - Works with both symlink paths and real paths
    - Example: `/media/audiobooks` (symlink) → `/media/lyra_data1/audiobooks/books` (real)
- Fixed books:move command with `..` in relative paths
    - Now resolves `..` and `.` before security check
    - Example: `../Author/Series/Book` works correctly
    - Security still enforced on final resolved path
- Fixed directory path display in import confirmation prompts
    - Manual path confirmation now shows full path including book title
    - Before: "Fantasy/Author/Series"
    - After: "Fantasy/Author/Series/05 Book Title"
    - Updated editIndividualFields() and editDirectoryPathOnly() methods
    - Path shown now matches actual filesystem location
- Fixed single audio file imports failing with "Source directory does not exist"
    - Added file handling to copyDirectoryContents() and moveDirectoryContents()
    - Methods now detect and handle single files correctly
    - Files moved from actual location to target directory
    - Fixes import of single M4B/M4A/MP3 files from download folder
- Fixed directory_path in database not matching actual filesystem location
    - Changed `BookImportService` to store full path including title directory
    - Database now stores: "Genre/Author/Series/02 Book Title"
    - Previously stored: "Genre/Author/Series" (missing title directory)
    - This fixes move operations failing with path mismatches
    - Added `books:fix-directory-paths` command to repair existing books
- Fixed `BackgroundProcessingService` to properly handle completed processes
    - Changed `maintainConcurrentTasks()` to call `wait()` on `InvokedProcess` before accessing result methods
    - Resolves "Call to undefined method Illuminate\Process\InvokedProcess::exitCode()" error
    - Added comprehensive unit tests for process completion handling
- Fixed directory path generation to include series numbers in book titles
    - `BookImportService::generateTargetDirectory()` now includes series number from pivot table
    - Series numbers are zero-padded (e.g., "01", "09") and prefixed to book titles
    - Display in import command now shows correct path: "Genre/Author/Series/01 Book Title"
    - Added comprehensive unit tests for series number formatting
- Fixed missing `analyzeDirectory()` method in `AudioFileAnalyzer`
    - Added method to extract metadata from audio file tags using getID3
    - Properly handles M4B/M4A files using QuickTime metadata atoms (not ID3 tags)
    - Falls back to ID3 tags for MP3 and other formats
    - Extracts title, author, series, genre, year, publisher, narrator
    - Calculates total duration from all audio files in directory
    - Returns null if no metadata found or directory has no audio files
    - Added comprehensive unit tests for audio analysis functionality
- Enhanced `AIBookProcessor::extractFileTags()` to extract embedded cover images
    - Now extracts picture data from M4B files (`$fileInfo['comments']['picture']`)
    - Extracts picture data from MP3 files (`$fileInfo['id3v2']['APIC']`)
    - Prioritizes front cover (picturetypeid == 3) for MP3 files
    - Returns picture as array with 'data', 'mime', and 'type' fields
    - Enables multi-book series to use embedded covers from individual M4B files
- Fixed "Attempt to read property 'role' on null" error on profile page
    - Moved profile routes inside auth middleware group to require authentication
    - Added `@auth` directive in profile view to handle unauthenticated access gracefully
    - Shows login prompt for unauthenticated users instead of throwing error
    - Removed duplicate profile route definitions
- Fixed admin users page showing no users
    - Removed invalid `roles` relationship from `MySqlService::getAllUsers()`
    - User model uses `role` field, not a `roles` relationship
    - Simplified UserController to remove unnecessary role normalization logic
    - Users now display correctly on `/admin/users` page
- Fixed import-downloads silently failing when database entry exists but files are missing
    - Removed calls to non-existent `promptForDuplicateAction()` method
    - Added proper handling for existing books with missing files
    - Now offers to restore files from new download to existing database entry
    - User can choose to: restore files, skip import, or continue anyway
    - Prevents silent failures and provides clear feedback

### Added

- Graphic Audio multi-part book handling in import-downloads command
    - Detects Graphic Audio books by directory name containing "Graphic Audio"
    - Identifies multi-part books by numbered filenames (01.m4b, 02.m4b, etc.)
    - Extracts actual author from description text ("by [Author Name]")
    - Removes "Graphic Audio" from author field
    - Sets publisher to "GraphicAudio"
    - Cleans up title by removing "X of Y" patterns
    - Moves all parts to single directory
    - Automatically cleans up source directory after successful import
- Multi-book series detection and splitting in import-downloads command
    - Automatically detects when a directory contains multiple books from a series
    - Checks for: multiple large files (>100MB, >3 hours each), different titles
    - Falls back to filename parsing when metadata is unavailable
    - Extracts series number from filename patterns (Book 1, Vol 1, 01-, etc.)
    - Extracts book titles from filenames (e.g., "Willful Child - 01 - Willful Child.m4b" → "Willful Child")
    - Splits multi-book directories into separate database entries during processing
    - Preserves series information and relationships
    - Each book gets proper series number and title from metadata
    - Extracts embedded cover images from individual M4B files (takes priority over external sources)
    - Extracts additional metadata (narrator, year, publisher) from M4B file tags
    - Uses existing AIBookProcessor::extractFileTags() method for metadata extraction
    - Includes extensive debug output to troubleshoot detection issues
    - Added comprehensive unit tests covering all detection and splitting scenarios
- Extended `cover:check` command to validate and fix book cover images
    - Options:
        - `--attempt-audible` to fetch missing covers from Audible using `AudibleService`
        - `--dry-run` to preview changes without modifying files or database
        - `--limit[=N]` to process only the first N books (0 means no limit)
    - Behavior:
        - Tries to find a valid local cover image in the book's directory first
        - If none found and `--attempt-audible` is provided, searches Audible using book title and author
        - Downloads cover via `ExternalCoverService` and saves it to the book directory on the `books` disk
        - Updates `cover_image` and clears `needs_review` flags on success; appends review reason when unresolved
    - Tests: feature tests added for dry-run, limit, and Audible fetch paths

### Changed

- Authors and Series endpoints now exclude `needs_review` books by default
    - `/api/v1/authors` and `/api/v1/series` filter out books flagged as `needs_review` unless explicitly included
    - Counts (e.g., `book_count`) now respect the `needs_review` filter to avoid inflated totals
    - Sorting by `book_count` uses the filtered count to ensure correct order
- `/api/v1/me` endpoint now explicitly returns only the authenticated user's `name` and `email` via `UserApiController@me`
    - Route wired to controller method; no longer aliases full user object
    - Feature and unit tests added to validate response shape and authentication behavior
- Badge icon fields standardized across schema, models, seeders, and API
    - Store a single-character emoji in `icon`
    - Store SVG URI in `image_url` (e.g., `/images/badges/{key}.svg`)
    - Deprecated fields `emoji_icon` and `icon_url` migrated and removed
    - API responses now include both `icon` and `image_url` for badges
- Authentication documentation updated to reflect Laravel Sanctum Bearer tokens instead of Firebase JWT
    - Updated root `README.md`
    - Updated `docs/api/README.md` and `docs/api/authentication.md`
- User role names aligned across docs and middleware: `standard`, `admin`, `superadmin`
- Archived MongoService for application runtime; it is now migration-only
    - `DocumentStoreServiceProvider` always binds `MySqlService` at runtime (even if `DOCUMENT_STORE_DRIVER=mongodb`)
    - Added deprecation docblock to `App\\Services\\MongoService`
- Archived FirestoreService (moved to app/Services/Legacy) as it's no longer used
- Updated service providers to remove FirestoreService registration
- Added database safety check in TestCase to prevent tests from wiping production MySQL database
- Created PersistentDatabaseTestCase for tests that need persistent data

### Added

- AI-assisted suggestions for reconciling book directories
    - Extended Artisan command `books:list-missing-directories` with `--ai-suggest` and `--ai-output`
    - Compares DB-referenced missing directories to on-disk unreferenced audio directories
    - Produces suggested mappings with confidence and reason via the configured AI provider
    - Suggestions appear under `ai_suggestions` (JSON) or as a `# AI suggestions` section (TXT)
- Public helper `AIBookProcessor::complete()` to allow generic prompt completion using the configured model/provider
- Feature test `ListMissingBookDirectoriesAiTest` validating AI suggestion integration with a mocked processor

### Added

- Support for `includeNeedsReview` (also `include_needs_review`) query flag on Authors and Series endpoints
    - When set to a truthy value, responses include books and entities otherwise excluded due to `needs_review`
    - Added feature tests to verify default exclusion and override behavior for both endpoints
- Artisan command: `php artisan books:list-missing-directories` to list book `directory_path` values that don't exist on disk
    - Options: `--disk=books` to select storage disk, `--format=txt|json` (default txt), `--output=path` to write to a file
    - Produces a stable, unique, sorted list; JSON output uses unescaped slashes for readability
    - Feature tests added covering text output and JSON output
- Artisan command: `php artisan badges:generate-icons` to generate per-badge SVG placeholders
    - Writes files to `public/images/badges/{key}.svg`
    - Use `--force` to overwrite existing files
- Artisan command `books:mark-invalid-directories` to mark books with missing/invalid `directory_path` as `needs_review` and append reason `missing_directory`. Supports `--disk`, `--limit`, and `--dry-run`.
- Reading Progress & Statistics requirements documentation
    - Added `docs/requirements/reading-progress-and-stats.md`
    - Linked from root `README.md` and `docs/README.md`
    - Expanded recommended metrics to collect and insights to return
    - Sets API and schema requirements for cross-device progress sync and statistics
- Fix Series Starting With Number command (`php artisan series:fix-number-prefix`) for correcting series names
    - Identifies series entries whose names start with a number
    - Reparses directory paths to determine the correct series name
    - Supports interactive mode for user confirmation when needed
    - Supports dry-run mode for safe testing
    - Handles renaming series or moving books to existing series with correct name
- API Service Client command (`php artisan api:client`) for making authenticated API calls
    - Support for both full URLs and relative URIs
- Fix Remote Images command (`php artisan books:fix-remote-images`) for downloading remote cover images
    - Downloads remote cover images to book directories and updates database URLs
    - Supports dry-run mode and limiting number of books to process
    - User impersonation with fallback to first admin user
    - Support for all HTTP methods (GET, POST, PUT, PATCH, DELETE)
    - JSON data support for POST/PUT/PATCH requests
    - Colored JSON output with syntax highlighting
    - Comprehensive error handling and validation
    - Full test coverage with 11 test cases
- Enhanced database backup and restore scripts
    - Backup script now includes field names (`--complete-insert`) for schema compatibility
    - Backup script uses single-row inserts (`--extended-insert=FALSE`) for better readability
    - Restore script automatically skips confirmation if both `users` and `books` tables are empty
    - Improved error handling for missing tables during restore checks
    - Full test coverage with 14 test cases for script validation
- MongoDB to MySQL migration for series data with pivot table support
    - Added `series_number` field to `book_series` pivot table
    - Updated `Book` and `Series` models to use `BelongsToMany` relationship
    - Enhanced `MigrateMongoToMysql` command to handle series relationships
- MongoDB Atlas Search autocomplete integration for series (fuzzy, prefix, $search aggregation)
- New API endpoint: `/api/v1/series/autocomplete` via `BookApiController@autocompleteSeries`
- Service method: `MongoService::autocompleteSeries`
- Interface update: `DocumentStoreServiceInterface::autocompleteSeries`
- Tests: `BookApiAutocompleteSeriesTest` (feature), interface mocks updated
- Import feature now skips directories without files matching allowed extensions
    - Updated ImportFileController to filter directories during listing
    - Added comprehensive tests to verify directory filtering behavior
    - Improved import experience by showing only relevant directories
- Unified external cover image integration
    - Added `ExternalCoverService` for handling Audible and Google Books cover images
    - Integrated external cover images into the "Select Cover Image" UI block
    - Auto-select external cover image radio buttons after autofill
    - Implemented error handling for external cover image downloads
    - Added validation to prevent form submission with invalid external cover images
    - Ensured a cover image radio button is always selected if any image is available

### Fixed

- Book updates now persist narrator, series, and published year reliably
    - `BookController@update` normalizes plural and snake_case input keys pre-validation
    - `MongoService@updateBook` normalizes payload: plural→singular, snake_case→camelCase, trims/filters arrays, maps legacy series `name`→`seriesName`
    - Added feature tests covering narrator array trimming, series normalization, and persistence
- Fixed API 'recent' books query to correctly return books ordered by creation date
    - Updated MySqlService to handle 'recent' keyword properly in date_added filter
    - Ensured consistent ordering by created_at in descending order
    - Verified alignment between API results and direct database queries
- Fixed cover image URLs in API responses to use the request's hostname and protocol
    - Updated BookApiController to use request's scheme and host for cover URLs
    - Enhanced ApiServiceClient command to preserve hostname information in internal requests
    - Added proper server parameter handling for request forwarding
    - Ensured cover URLs match the original request's domain (e.g., example.com vs localhost)
- Updated API to return series data as an array of objects with name and series number
    - Modified BookApiController to format series data consistently
    - Added formatSeriesData method to handle both direct and relationship-based series data
    - Improved handling of many-to-many series relationships with pivot data
- Fixed 401 Unauthorized error for series autocomplete in admin book form
    - Added admin-accessible endpoint `/admin/series-autocomplete` in BookController
    - Updated JS to use the correct endpoint for series autocomplete
    - Ensured proper normalization of series data using 'seriesName' field
    - Added comprehensive tests for the admin series autocomplete endpoint
- Fixed import file browser JavaScript initialization issues
- Added proper error handling for AJAX requests in import file browser
- Improved DOM element selection for import file browser containers
- Enhanced logging for import file browser initialization and AJAX calls
- Fixed missing routes for import file browser API endpoints
- Improved route definitions for import file browser to ensure proper access
- Added consistent URL variable usage in JavaScript for better maintainability
- Fixed Audible cover download in book update functionality
    - Fixed tests to properly verify cover image storage
    - Added test fixture image for cover testing
    - Ensured proper file extension handling for downloaded covers
- Fixed Audible cover download in book edit form to properly save cover images to book directory
- Added UI feedback during book form submission (spinner and disabled button)
- Improved error handling for Audible cover image downloads with proper logging
- Fixed issue with select button remaining disabled when selecting directories with audio files
- Improved directory listing with direct event attachment to list items
- Enhanced ImportFileController with better logging and error handling
- Improved user-facing error messages for permission issues in ImportFileController
- Added detailed error reporting with specific HTTP status codes for different error types
- Implemented comprehensive permission checking for file and directory operations
- Added informative error details for common file system issues (not found, permission denied, etc.)
- Added robust error handling for getID3 library availability and failures
- Improved parameter validation in ImportFileController extract endpoint
- Enhanced exception handling with detailed stack trace logging
- Added graceful fallback for audio metadata extraction when getID3 fails
- Implemented better error categorization (permission, disk space, library issues)
- Added URL-based navigation to preserve browse location between page loads
- Added support for query string parameters to select initial root and path
- Implemented directory metadata extraction for improved import experience
- Added support for selecting directories containing audio files
- Enhanced metadata extraction from audio files with support for multiple tag formats (ID3v1, ID3v2, QuickTime)
- Improved parsing of title, author, series, narrator, and other metadata from audio files
- Added cover image extraction from audio file picture/APIC tags
- Enhanced series detection from artist parentheses (e.g., "Author Name (Series Name)")
- Improved series number extraction from album prefixes (e.g., "00.1 The Mad Lancers")
- Added robust tag normalization for consistent metadata extraction across different file formats

### Changed

- The `series` field for books is now always stored and processed as an array of objects with `seriesName` and `number` keys.
- All backend, frontend, API, and tests updated to use the canonical format.
- Legacy formats (string, key-value, separate objects) are no longer accepted or produced.
- Documentation and tests updated to reflect this normalization.

### Migration

- If you have existing data in legacy formats, you must migrate it to the new format:
    ```json
    "series": [
      { "seriesName": "Buryoku", "number": "9" }
    ]
    ```

### Added

- Implemented complete import-from-file/audio feature for book management
    - Added backend processing in BookController with new processImport method
    - Enhanced frontend JavaScript for import file browser integration
    - Added metadata extraction and normalization from audio files
    - Implemented cover image import from URLs
    - Added comprehensive unit tests for import functionality
    - Created detailed documentation for the import-from-file feature
- Created unified search endpoint for all book APIs (Audible, Google Books) with source parameter
- Integrated Audible audiobook metadata autofill support alongside Google Books
- Added new backend API endpoint in BookController for Audible searches
- Extended frontend autofill modal to support multiple sources (Google Books, Audible)
- Added support for Audible-specific fields like narrators and Audible ID
- Added narrator autocomplete functionality for book form
- Added comprehensive tests for narrator autocomplete functionality
- Enhanced Audible search response to include missing fields (authors, coverImageUrl, category)
- Updated frontend JavaScript to use the unified search endpoint for book autofill
- Removed unused book source options from autofill modal
- Added tests for Audible search response format and author filtering functionality

### Fixed

- Fixed book autofill author query to properly include author in search requests
    - Modified AudibleService to include author in search options instead of combining with title
    - Enhanced Google Books search to properly format author and title parameters with quotes
    - Google Books search now post-filters results by author for accuracy
    - Added detailed debug logging for search parameters in BookController
    - Added comprehensive unit tests to verify author parameter handling
- Fixed missing title field in Audible search results
    - Added title extraction in the AudibleService transform method
- Changed: narrators are now returned as 'narrators' (not 'narratorList') in AudibleService responses for consistency
- Fixed: coverImageUrl is now always returned as 'coverImageUrl' (not 'audibleCoverImageUrl') in AudibleService responses
- Enhanced MongoDB BSONArray normalization in MongoService to recursively convert all MongoDB BSON types to PHP arrays
- Fixed MongoDB BSONArray conversion issues in all MongoService methods that return book data
- Properly handled nested BSON objects in MongoDB query results to prevent type conversion errors
- Updated MongoDB\BSON\Regex usage with fully qualified namespace to fix lint errors
- Removed orphaned `$container` logic from autofill modal JS, fixed ReferenceError
- Ensured all AJAX autofill logic is inside the correct event handler
- Added Jest test for autofill modal UI logic (`tests/js/autofillModal.test.js`)
- All JS now syntax checked, linted, and tested
- Fixed JavaScript structure in form.blade.php to properly handle narrator rows for Audible autofill
- Fixed authentication in Audible feature tests by using DocumentstoreUser instead of User model
- Added proper initialization of narrator input fields in autofill modal
- Ensured consistent handling of narrator data between Google Books and Audible sources
- Fixed Mockery mock setup in PHPUnit feature tests to use proper class string references
- Fixed middleware reference in tests to use CheckAdminRole instead of AdminMiddleware
- Fixed Blade template lint errors by using @push('scripts') instead of @section('scripts')
- Added IDE type hint support for MongoDB\BSON\Regex class to fix lint warnings
- Added PHPDoc annotations to improve static analysis compatibility
- Restructured Blade template script injection using @push directive to fix decorator and expression lint errors
- Fixed Audible API mock expectations in tests to match actual controller implementation
- Fixed Audible search to properly handle author filtering by implementing a two-step search approach
- Kept Audible ID field as 'id' instead of 'audibleId' for consistency
- Used 'audibleAuthors' field for author values in Audible response
- Used 'genre' field for category values in Audible response
- Renamed 'coverImageUrl' to 'audibleCoverImageUrl' for Audible responses
- Converted Audible publisher field to array format to match Google Books response structure
- Ensured all Audible response keys are in camelCase for consistent frontend integration
- Fixed audio metadata extraction tests by properly overriding extractFileMetadata method in anonymous subclasses
- Improved test reliability by avoiding real getID3 analysis calls in unit tests
- Enhanced test coverage for series extraction from artist parentheses and album prefixes
- Added robust tests for cover image extraction from audio file picture tags
