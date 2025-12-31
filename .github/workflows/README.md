# GitHub Actions Workflows

This directory contains automated CI/CD workflows for the project.

## Workflows

### tests.yml - Full Test Suite
**Trigger**: Push and Pull Requests to `main` and `develop` branches

**What it does**:
- Runs the complete test suite on PHP 8.2 and 8.3
- Uses SQLite in-memory database (fast, isolated)
- Executes tests in parallel for speed
- Runs code style checks (phpcbf)
- Runs static analysis (PHPStan)
- Generates code coverage report (70% minimum)

**Test Environment**:
- PHP: 8.2, 8.3
- Laravel: 12.x
- Database: SQLite (in-memory)
- Extensions: mbstring, xml, ctype, json, sqlite3, pdo_sqlite, fileinfo, gd

**Jobs**:
1. **tests** - Matrix testing on PHP 8.2 and 8.3
   - Installs dependencies with Composer caching
   - Runs migrations
   - Executes full test suite with parallel execution
   - Runs code quality checks

2. **coverage** - Code coverage analysis (runs after tests pass)
   - Requires tests job to pass
   - Uses Xdebug for coverage
   - Enforces 70% minimum coverage threshold

### import-tests.yml - Import System Regression Tests
**Trigger**: Push and Pull Requests affecting import system files

**What it does**:
- Focused regression testing for the book import system
- Runs specific import-related test suites
- Uses MySQL service for testing
- Generates coverage reports for import code

**Test Suites**:
- MetadataExtractionTest
- AuthorNormalizationTest
- EnrichmentMergeTest

**When it runs**:
Only when changes are made to:
- `app/Services/BookImportService.php`
- `app/Services/BookEnrichmentService.php`
- `app/Services/MetadataProcessingService.php`
- `app/Services/GoogleBooksApiService.php`
- `app/Services/AIBookProcessor.php`
- `app/Services/AudioFileAnalyzer.php`
- `app/Console/Commands/ImportBooksFromDownloads.php`
- `app/Console/Commands/PrepareForReprocessing.php`
- Import-related test files

## Local Testing

To run tests locally the same way GitHub Actions does:

```bash
# Full test suite (like tests.yml)
php artisan config:clear
php artisan test --parallel

# With coverage (like coverage job)
php artisan test --coverage --min=70

# Import regression tests (like import-tests.yml)
php artisan test --filter=MetadataExtractionTest
php artisan test --filter=AuthorNormalizationTest
php artisan test --filter=EnrichmentMergeTest

# Code style check
./vendor/bin/phpcbf --standard=PSR12 app/ tests/

# Static analysis
./vendor/bin/phpstan analyse --memory-limit=2G
```

## Viewing Results

### GitHub UI
1. Go to the **Actions** tab in the repository
2. Click on a workflow run to see details
3. View logs, test results, and coverage reports

### Pull Request Checks
- Workflow status badges appear on PRs
- Tests must pass before merging
- Coverage requirements must be met

## Workflow Configuration

### Environment Variables
The workflows use these environment variables (set in the workflow file):
- `APP_ENV=testing` - Prevents browser launches, uses test config
- `DB_CONNECTION=sqlite` - Fast in-memory database
- `DB_DATABASE=:memory:` - No file persistence needed
- `CACHE_STORE=array` - Array-based caching
- `SESSION_DRIVER=array` - Array-based sessions
- `QUEUE_CONNECTION=sync` - Synchronous queue processing

### Caching
Composer dependencies are cached using GitHub's cache action:
- **Cache key**: Based on `composer.lock` hash
- **Restore keys**: Falls back to most recent cache
- **Benefits**: Faster builds, reduced API calls

### Parallel Testing
The main test suite uses Laravel's parallel testing feature:
- Automatically splits tests across multiple processes
- Significantly faster execution time
- Each process gets its own isolated database

## Troubleshooting

### Tests Pass Locally But Fail in CI
1. **Check PHP version**: Workflow uses 8.2/8.3, ensure compatibility
2. **Check database**: CI uses SQLite, not MySQL
3. **Check environment**: `APP_ENV=testing` may behave differently
4. **Check file paths**: CI runs in `/home/runner/work/`

### Coverage Job Fails
1. **Check minimum threshold**: Currently set to 70%
2. **Add more tests**: Focus on untested code paths
3. **Check Xdebug**: Coverage job requires Xdebug extension

### Workflow Doesn't Trigger
1. **Check branch**: Must be `main` or `develop`
2. **Check paths**: import-tests.yml only triggers on specific files
3. **Check syntax**: YAML syntax errors prevent workflow execution

### Browser Opens During Tests (Fixed)
- `ShowBookInfo` command now detects `APP_ENV=testing`
- Skips browser launch in test environment
- Tests verify skipping behavior instead of actually launching

## Best Practices

### Before Pushing
```bash
# Always run tests locally first
php artisan test

# Fix code style issues
./vendor/bin/phpcbf --standard=PSR12 app/ tests/

# Check for type errors
./vendor/bin/phpstan analyse
```

### Writing Tests
- All tests should pass with SQLite in-memory database
- Don't rely on MySQL-specific features
- Use `RefreshDatabase` trait for database tests
- Avoid external dependencies (mock APIs, services)
- Tests should work in parallel (no shared state)

### Updating Workflows
1. Test changes locally if possible
2. Use `continue-on-error: true` for non-critical steps
3. Update this README when adding new workflows
4. Document any new environment variables or requirements
