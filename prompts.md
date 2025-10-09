2025-10-08: Audiobook Web Player Implementation Plan
- Created comprehensive implementation plan for web-based audiobook player
- Phase 1: Core Infrastructure
  - Database schema: audiobook_progress, audiobook_bookmarks, audiobook_queue tables
  - AudioStreamController with HTTP Range support for seeking in large files
  - AudioProgressController API for saving/loading playback progress
  - Routes for streaming and progress tracking
- Phase 2: Player UI Component
  - Fixed bottom player bar with gradient background
  - Book info display (cover, title, author, series)
  - Main controls: play/pause, skip backward/forward (30s)
  - Seekable progress bar with draggable handle
  - Time display (current / total)
  - Volume control with slider
  - Playback speed selector (0.5x - 3.0x)
  - Sleep timer with countdown
  - Chapters panel (collapsible)
  - Bookmarks panel (collapsible)
- Phase 3: Player JavaScript Class
  - Full AudiobookPlayer class with all functionality
  - HTML5 Audio API integration
  - Progress auto-save every 10 seconds
  - Keyboard shortcuts (Space, arrows, brackets, M for mute)
  - State persistence via localStorage
  - Cross-device progress sync
  - Sleep timer with fade-out
  - Error handling and user feedback
- Phase 4: Advanced Features (Future)
  - Chapter extraction from M4B metadata
  - Bookmark system with notes
  - Queue/playlist management
  - Real-time cross-device sync via WebSocket
  - PWA/Offline support with service workers
- Testing plan and implementation order included
- Complete code examples for all components
- Ready for development

2025-10-08: Book Edit Form UI Refinement and Series Management System
- Reorganized book edit form layout for better UX
  - Row 1: Title (50%) | Series (50%)
  - Row 2: Authors (50%) | Narrators (50%)  
  - Row 3: Genres (half-width)
  - Combined first two cards into single "Basic Information" card
  - Moved Additional Information (Description + Release Date) to last position
  - Card order: Basic Info → Directory & Files → Additional Info
- Made all cards collapsible with dynamic summaries
  - Summary shows when collapsed: "Title (Series #) by Authors narrated by Narrators [Genres]"
  - Additional Info summary: "Released: YYYY | Description preview..."
  - Directory summary: Shows directory path
- Cover image management moved to modal popup
  - Cover preview shown at top right next to "Edit Book" heading
  - Click cover to open modal with all cover selection options
  - Removed large cover section from main form for cleaner layout
- Implemented "Resync Fields from Path" functionality
  - Uses existing BookDirectoryParser::processDirPath() method
  - Server-side parsing via ParsePathController
  - Populates: genre, author, title, series name, series number
  - Toast notifications instead of popup alerts
  - Centered toast at top of screen, auto-dismisses after 2 seconds
- Created comprehensive Series Management system
  - New page: "Manage Series" in admin navigation
  - Automatic detection of potential merges (books split across subdirectories)
  - Example: "History/Stephen Fry/The Ode Less Travelled/fry 1" + "fry 2" + "fry 3"
  - Merge multiple book entries into one with checkbox selection
  - Optional directory flattening (moves audio files to primary directory)
  - Rename series across all books
  - Accordion UI for browsing all series with book counts
  - Routes: admin.series.manage, admin.series.merge, admin.series.rename
- UI/UX improvements
  - Reduced collapsed card margins for compact layout
  - 30px margin below "Edit Book" heading
  - 10px margin below header section
  - Genre field properly identified as select dropdown
  - All styling follows existing Bootstrap/card patterns

2025-06-15: Fixed fatal artisan failure ('migration.creator' binding) by restoring default Laravel providers in config/app.php and correcting Kernel.php PSR-4 compliance. See changelog for details.

2025-06-20: MongoDB Atlas Search Autocomplete Integration
- User requested fuzzy autocomplete for book series using Atlas Search on seriesName field.
- Implemented autocompleteSeries in MongoService, controller, interface.
- New endpoint: /api/v1/series/autocomplete
- Tests, docs, and changelog updated.

2025-08-07: Fix Series Names Starting with a Number
- Created command to identify and fix series entries whose names start with a number.
- Command reparses directory_path to determine the correct series name.
- Supports interactive mode for user confirmation when needed.
- Supports dry-run mode for safe testing.
- Handles renaming series or moving books to existing series with correct name.
- Comprehensive test coverage with mocking approach.

2025-08-08: Reading Progress & Statistics Requirements
- Created `docs/requirements/reading-progress-and-stats.md` capturing requirements for cross-device progress sync, stats ingestion, conflict resolution, batch/offline handling, and endpoints.
- Linked from root `README.md`, `docs/README.md`, and `PROJECT-BLUEPRINT.md`; added CHANGELOG entry.
- Expanded recommended metrics and insights to include engagement, content preferences, device/quality, velocity/forecasting, retention, and habits.

2025-08-09: Archive MongoService (runtime) and persist book updates
- DocumentStoreServiceProvider now always binds MySqlService at runtime; MongoService marked @deprecated for migration-only use
- BookController@update and MongoService@updateBook normalized inputs to persist narrator, series, and publishedYear
- Added/updated feature tests for narrator trimming and series normalization/persistence

2025-08-13: Badge Seeder, Date Normalization, and PSR-12 fixes
- Implemented `Database/Seeders/CanonicalBadgeSeeder` with 13 categories × 6 badges = 78 canonical badges (idempotent by key).
- Added `tests/Feature/BadgeSeederTest.php` verifying all 78 canonical keys exist and structure fields (category/tier/is_active/is_repeatable/sort_order).
- Added PHP `App/Support/DateNormalizer` with unit tests `tests/Unit/Support/DateNormalizerTest.php` to normalize `release_date` inputs to `Y-m-d`.
- Added JS `resources/js/utils/dateNormalizer.js` (CommonJS) with Jest tests `tests/Javascript/utils/dateNormalizer.test.js` to normalize dates consistently on the client.
- Fixed PSR-12 line-length in `routes/api.php` by wrapping long chained route definitions.
- Syntax checks run; PHPUnit and Jest tests for new code pass.

2025-08-13: More TODOs
more todos
   ☐ implement a script to verify directory_paths are correct and update the ones that are not to a status where it is hidden from the api but shows up on a needs_review page that also needs to be created_at
   update all badges in the table to have both an emoji icon and an image_url for the icon (SVG URI)
   ☐ Add tests for badge system functionality
   ☐ Fix release date handling across the application
   ☐ Ensure release_date field is properly handled in all forms and controllers
   ☐ Update JavaScript to properly handle date field conversions
   ☐ Test release date functionality end-to-end
   ☐ Create badge display components and UI elements

2025-10-04: Fixed BackgroundProcessingService InvokedProcess Error
- User reported error: "Call to undefined method Illuminate\Process\InvokedProcess::exitCode()" in BackgroundProcessingService at line 125
- Root cause: In Laravel 12, Process::start() returns InvokedProcess which doesn't have exitCode() method directly
- Solution: Call wait() on InvokedProcess to get ProcessResult, then access exitCode(), output(), and errorOutput()
- Updated maintainConcurrentTasks() method to properly handle process completion
- Added comprehensive unit tests with Mockery mocks for InvokedProcess and ProcessResult
- All tests pass, code formatted with Pint, syntax checked

2025-10-04: Fixed Directory Path Generation to Include Series Numbers
- User reported: Series number not being factored into directory path (e.g., "Willful Child #1" showing as "Science Fiction/Steven Erikson/Willful Child/Willful Child" instead of including "01" prefix)
- Root cause: generateTargetDirectory() was manually appending title without series number, and metadata didn't include series_number
- Solution:
  - Updated BookImportService::generateTargetDirectory() to extract series_number from book's pivot table
  - Added series_number to metadata array passed to generateDirectoryPath()
  - Format series number with zero-padding (01, 02, etc.) and prefix to title
  - Updated ImportBooksFromDownloads::displayEnrichedMetadata() to show correct path with series number
- Result: Books in series now have paths like "Genre/Author/Series/01 Book Title"
- Added 5 comprehensive unit tests to verify series number formatting
- All tests pass, code formatted with Pint

2025-10-04: Fixed Missing analyzeDirectory() Method in AudioFileAnalyzer
- User reported error: "Call to undefined method App\Services\AudioFileAnalyzer::analyzeDirectory()" at MetadataProcessingService.php:89
- Context: When AI confidence is low (75%), system tries audio analysis fallback but method didn't exist
- Root cause: AudioFileAnalyzer had getDirectoryAudioDuration() but not analyzeDirectory() for metadata extraction
- Solution:
  - Added analyzeDirectory() method to extract metadata from audio file ID3 tags
  - Uses getID3 library to read tags from first audio file in directory
  - Extracts: title, author (artist), series (album), genre, year, publisher, narrator
  - Calculates total duration using existing getDirectoryAudioDuration()
  - Returns null if no audio files or no metadata found
  - Sets confidence to 75% for audio-extracted metadata
- Added 6 comprehensive unit tests covering edge cases
- All tests pass, code formatted with Pint

2025-10-04: Fixed Profile Page "Attempt to read property 'role' on null" Error
- User reported error: "Attempt to read property 'role' on null" at resources/views/profile/index.blade.php:15
- Context: Profile page accessible at https://books.thelin.org/profile
- Root cause: Profile routes were not protected by auth middleware, allowing unauthenticated access
- View was calling Auth::user()->role without checking if user is authenticated
- Solution:
  - Moved all profile routes (index, update, changePassword, requestAdminPermissions) inside auth middleware group
  - Wrapped profile content in @auth directive in view
  - Added @else block showing login prompt for unauthenticated users
  - Removed duplicate profile route definitions that were outside auth middleware
- Result: Profile page now requires authentication and shows friendly login prompt if not authenticated
- Code formatted with Pint

2025-10-04: Fixed Admin Users Page Showing No Users
- User reported: https://books.thelin.org/admin/users shows no users
- Root cause: MySqlService::getAllUsers() was trying to eager load non-existent 'roles' relationship
- User model has a 'role' field (string), not a 'roles' relationship
- The invalid relationship was causing the query to fail silently
- Solution:
  - Removed ->with(['roles']) from getAllUsers() method in MySqlService
  - Changed to simple User::all()->toArray()
  - Simplified UserController::index() to remove unnecessary role normalization logic
  - Role normalization was trying to handle non-existent roles array from service
- Result: Admin users page now displays all users correctly
- Code formatted with Pint

2025-10-04: Fixed Import-Downloads Silently Failing for Existing Books with Missing Files
- User reported: import-downloads silently failing when database entry exists but files were deleted
- Goal: Use existing database entry and restore files from new download
- Root cause: Code was calling non-existent promptForDuplicateAction() method, causing silent failure
- When existing directory not found, it would fail without proper error handling
- Solution:
  - Replaced promptForDuplicateAction() calls with proper inline handling
  - When existing book found but files missing, now offers options:
    1. Restore files from new download (uses existing database entry)
    2. Skip import (leave database as-is)
  - When storage path/directory path missing, offers:
    1. Skip import
    2. Continue anyway (with warning)
  - Uses BookImportService::moveFilesToLibrary() to restore files to existing book
  - Properly tracks restored books in processedBooks array
- Result: Books with missing files can now be restored from new downloads
- Code formatted with Pint

2025-10-04: Added Multi-Book Series Detection and Splitting
- User requested: Handle directories with multiple large audiobook files that are separate books in a series
- Example: "/media/download/Steven Erikson - Willful Child" with multiple m4b files, each >3 hours
- Requirements:
  - Check file length (>3 hours each)
  - Check metadata for different titles
  - Extract series number from filename
  - Split into multiple folders and DB entries
- Solution:
  - Added detectMultiBookSeries() method to identify multi-book directories
  - Checks for: multiple files >100MB, duration >3 hours each, different titles in metadata
  - Added splitMultiBookSeries() to create separate book entries
  - Added extractSeriesNumber() to parse series numbers from filenames (Book 1, Vol 1, 01-, etc.)
  - Added extractFileMetadata() to get title, author, album, genre, year from ID3 tags
  - Integrated into processAudiobook() to check each directory individually during processing
  - Each book gets: proper series name, series number, individual title, single file
- User feedback: Check directories one at a time during processing, not all at once during scanning
- Updated: Moved detection from scanForAudiobooks() to processAudiobook()
- Issue found: "/media/download/Steven Erikson - Willful Child" not being detected
- Debug output showed: 3 large files (>3 hours each) but metadata titles all "N/A"
- Root cause: Files don't have title metadata in ID3 tags, only filenames
- Solution update:
  - Added fallback to filename parsing when metadata is unavailable
  - Extracts clean titles from filenames (removes series prefixes like "01 - ")
  - Pattern: "Willful Child - 01 - Willful Child.m4b" → "Willful Child"
  - Updated splitMultiBookSeries() to extract titles from filenames
  - Added extensive debug output showing file sizes, durations, titles
- User feedback: M4B files don't use ID3 tags, they use M4B/QuickTime metadata
- Fixed extractFileMetadata() to properly handle M4B files:
  - Check fileInfo['quicktime']['comments'] first for M4B/M4A files
  - Fall back to fileInfo['comments'] for MP3 and other formats
  - Handle creation_date field for year in QuickTime metadata
- Also updated AudioFileAnalyzer::analyzeDirectory() with same fix
- Issue: Infinite loop detected - same directory being split repeatedly
- Root cause: Split books had same 'path' as parent, so re-detection kept triggering
- Solution: Added 'is_split_book' flag to split books, skip detection if flag is set
- Issue: Filename parsing not extracting correct titles
- Example: "Willful Child - 01 - Willful Child.m4b" was keeping full name instead of just "Willful Child"
- Root cause: Regex pattern only removed number prefix, not series name prefix
- Solution: Changed to extract last part after last " - " separator
- Pattern: /.*\s+-\s+(.+)$/ extracts "Title" from "Series - 01 - Title"
- Updated both detectMultiBookSeries() and splitMultiBookSeries()
- User request: Ensure series number is preserved and cover images from individual files are used
- Created comprehensive unit tests in tests/Unit/Commands/ImportBooksMultiBookSeriesTest.php:
  - detectMultiBookSeriesIdentifiesMultipleLargeFiles()
  - extractSeriesNumberFromVariousFilenamePatterns()
  - splitMultiBookSeriesCreatesIndividualBookEntries()
  - splitMultiBookSeriesExtractsAuthorFromDirectoryName()
  - extractFileMetadataHandlesBothQuicktimeAndId3Tags()
  - splitBooksHaveCorrectMetadataStructure()
- Tests verify:
  - Series numbers are correctly extracted (1, 2, 3, etc.)
  - Metadata structure includes series_number field
  - Author is extracted from directory name
  - Original metadata (genre, year) is preserved
  - Each book has single file reference
  - is_split_book flag is set
- All 6 tests pass with 30 assertions
- Issue: Series number still getting lost after AI processing
- Root cause: AI processing was overwriting the pre-set series_number from split books
- Solution: Added metadata preservation logic in processAudiobook():
  - Check if audiobook['metadata'] exists (from split books)
  - Preserve series_number, series, and title from split book metadata
  - Only run extractSeriesNumberFromTitle() if series_number not already set
  - Skip detectMultiBookPattern() for split books (already processed)
  - Added debug output showing when pre-set series number is used
- User request: Cover images from individual M4B files should take priority, use existing methods
- Solution: Use AIBookProcessor::extractFileTags() instead of custom extraction
- Extracts from split book M4B files:
  - Embedded cover image (picture data) - saved as cover.jpg
  - Narrator metadata
  - Year metadata  
  - Publisher metadata
- Cover from M4B takes priority over external sources (Audible, etc.)
- Removed custom extractCoverImage() method from AudioFileAnalyzer
- Added getAIProcessor() helper method
- Issue: M4B cover still being overwritten by external enrichment
- Root cause: performExternalDataEnrichment() uses array_merge which overwrites cover_url
- Solution: Preserve M4B cover before merge, restore after
  - Check if cover_source === 'Embedded in M4B'
  - Save cover_url before array_merge
  - Restore cover_url and cover_source after merge
  - Added debug output: "Preserving M4B cover (priority over external sources)"
- Issue: Still using Amazon cover - debug shows "✗ No embedded cover image found in M4B file"
- Root cause: AIBookProcessor::extractFileTags() wasn't extracting picture data
- The method only extracted from $fileInfo['tags'], not $fileInfo['comments']['picture']
- Solution: Enhanced extractFileTags() to extract embedded cover images
  - Added extraction from $fileInfo['comments']['picture'][0] for M4B files
  - Added extraction from $fileInfo['id3v2']['APIC'] for MP3 files
  - Prioritizes front cover (picturetypeid == 3) for MP3
  - Returns picture as array: ['data' => binary, 'mime' => type, 'type' => 'front_cover']
- Now extractFileTags() returns complete metadata including embedded artwork
- Issue: Files not being moved after import - all 3 M4B files stay in download directory
- Root cause: moveFilesToLibrary() moves entire directory contents, not individual files
- For split books, we need to move only the single M4B file + cover for each book
- Solution: Added moveSplitBookFiles() method for split books
  - Generates target directory using metadata (includes series number in path)
  - Moves/copies only the single M4B file for this book
  - Copies the extracted cover.jpg
  - Updates book.directory_path to target location
  - Provides debug output showing file operations
- Split books now bypass normal moveFilesToLibrary() and use custom logic
- Result: Multi-book series directories are automatically split into individual books during processing
- Works with both metadata-based and filename-based detection
- Properly reads M4B metadata atoms and ID3 tags
- Correctly extracts book titles from filenames
- Series numbers are preserved through AI processing
- Pre-set metadata takes priority over AI-extracted metadata
- Embedded cover images extracted from individual M4B files
- Additional metadata (narrator, year, publisher) extracted from M4B tags
- No infinite loops - split books are processed once
- Code formatted with Pint

## Graphic Audio Multi-Part Book Handling (2025-10-04)
- User request: Handle Graphic Audio books with special rules
- Graphic Audio is a publisher, not an author
- Author can be extracted from description: "by [Author Name]"
- Books come in multiple parts (e.g., "3 of 5")
- Filenames end in part numbers: 01.m4b, 02.m4b, 03.m4b
- Solution: Added detectGraphicAudioMultiPart() method
  - Detects by "Graphic Audio" in directory name
  - Finds numbered parts using regex: /[_\s](\d{2})$/
  - Returns array with all parts sorted by number
- Added processGraphicAudioMultiPart() method
  - Extracts metadata from first part
  - Extracts author from description using regex: /\bby\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)/i
  - Removes "Graphic Audio" from author array
  - Sets publisher = "GraphicAudio"
  - Cleans title by removing "X of Y" pattern
  - Creates single audiobook with all parts in files array
- Added moveGraphicAudioFiles() method
  - Moves all parts to single directory
  - Uses copy+delete for cross-filesystem support
  - Cleans up source directory after move
- All parts go into one book record with one directory
- Source directory cleanup with ls -lh and confirmation prompt
