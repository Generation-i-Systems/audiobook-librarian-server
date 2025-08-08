## [Unreleased]
### Changed
- Archived FirestoreService (moved to app/Services/Legacy) as it's no longer used
- Updated service providers to remove FirestoreService registration
- Added database safety check in TestCase to prevent tests from wiping production MySQL database
- Created PersistentDatabaseTestCase for tests that need persistent data

### Added
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
