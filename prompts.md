2025-06-15: Fixed fatal artisan failure ('migration.creator' binding) by restoring default Laravel providers in config/app.php and correcting Kernel.php PSR-4 compliance. See changelog for details.

2025-06-20: MongoDB Atlas Search Autocomplete Integration
- User requested fuzzy autocomplete for book series using Atlas Search on seriesName field.
- Implemented autocompleteSeries in MongoService, controller, interface.
- New endpoint: /api/v1/series/autocomplete
- Tests, docs, and changelog updated.
