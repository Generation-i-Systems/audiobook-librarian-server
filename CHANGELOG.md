## [Unreleased]
### Added
- MongoDB Atlas Search autocomplete integration for series (fuzzy, prefix, $search aggregation)
- New API endpoint: `/api/v1/series/autocomplete` via `BookApiController@autocompleteSeries`
- Service method: `MongoService::autocompleteSeries`
- Interface update: `DocumentStoreServiceInterface::autocompleteSeries`
- Tests: `BookApiAutocompleteSeriesTest` (feature), interface mocks updated


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
