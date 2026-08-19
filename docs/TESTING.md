# Running Tests

Run the full test suite with PHPUnit:

```bash
# Run all tests
composer test

# Run specific test file
./vendor/bin/phpunit tests/Feature/BookDirectoryParserTest.php

# Run with code coverage (requires Xdebug)
XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-html coverage-report

# Run with detailed output
./vendor/bin/phpunit --testdox
```

Suite-scoped shortcuts: `composer test:api`, `composer test:web`, `composer test:cli`,
`composer test:import`, `composer test:core`.

## Testing Safety

- Tests use SQLite in-memory database by default to prevent data loss
- Safety checks prevent tests from accidentally using the production MySQL database
- `PersistentDatabaseTestCase` is available for tests that need persistent data

## External Metadata Lookup Integration Tests

These tests hit real external services (network required) and are excluded from the default PHPUnit suites.

Run them explicitly:

```bash
composer test:external-metadata
```

Notes:

- AudiobookBay tests do not require credentials.
- Hardcover tests require `HARDCOVER_API_TOKEN` to be configured (otherwise they will be skipped).
