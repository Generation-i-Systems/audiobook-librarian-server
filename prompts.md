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
