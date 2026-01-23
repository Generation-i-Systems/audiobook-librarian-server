# GitHub Actions Workflows

This directory contains automated CI/CD workflows organized by test category for efficient, targeted testing.

## Workflow Organization

The test suite is split into focused workflows that only run when relevant files change:

### 1. api-tests.yml - API Tests (156 tests)
**Triggers**: Changes to API controllers, routes, transformers

**Watches**:
- `app/Http/Controllers/Api/**`
- `routes/api.php`
- `tests/Feature/Api/**`
- `tests/Unit/Controllers/Api/**`
- `app/Transformers/**`
- `app/Http/Resources/**`

**Run locally**:
```bash
composer test:api
```

---

### 2. web-tests.yml - Web/Admin UI Tests (110 tests)
**Triggers**: Changes to admin controllers, views, web routes

**Watches**:
- `app/Http/Controllers/Admin/**`
- `resources/views/**`
- `routes/web.php`
- `public/js/**`, `public/css/**`
- `tests/Feature/Controllers/Admin/**`
- `tests/Feature/Admin/**`
- `tests/Unit/Controllers/Admin/**`

**Run locally**:
```bash
composer test:web
```

---

### 3. cli-tests.yml - CLI/Command Tests (283 tests)
**Triggers**: Changes to console commands

**Watches**:
- `app/Console/Commands/**`
- `tests/Feature/Commands/**`
- `tests/Unit/Commands/**`
- `tests/Unit/Console/Commands/**`

**Run locally**:
```bash
composer test:cli
```

---

### 4. import-tests.yml - Import System Tests (350 tests)
**Triggers**: Changes to import-related services and commands

**Watches**:
- `app/Services/BookImportService.php`
- `app/Services/BookEnrichmentService.php`
- `app/Services/AIBookProcessor.php`
- `app/Console/Commands/ImportBooksFromDownloads.php`
- All test files with "Import" in the name

**Run locally**:
```bash
composer test:import
```

---

### 5. core-tests.yml - Core/Service Tests (214 tests)
**Triggers**: Changes to services, models, traits

**Watches**:
- `app/Services/**`
- `app/Models/**`
- `app/Traits/**`
- `app/Events/**`, `app/Listeners/**`, `app/Observers/**`
- `tests/Unit/Services/**`
- `tests/Feature/Services/**`

**Run locally**:
```bash
composer test:core
```

---

### 6. full-test-suite.yml - Complete Test Suite (1126 tests)
**Triggers**:
- Manual dispatch (`workflow_dispatch`)
- Daily at 2 AM UTC (scheduled)
- Push to `main` or `develop` branches
- Pull requests to `main`, `develop`, `release/*`, or `hotfix/*` branches

**Features**:
- Runs ALL tests with parallel execution
- Includes code coverage (70% minimum)
- Runs code style check (phpcbf)
- Runs static analysis (PHPStan)

**Run locally**:
```bash
composer test
```

---

## Test Distribution Summary

| Category | Tests | Avg Runtime | Triggers When Changed |
|----------|-------|-------------|----------------------|
| API | 156 | ~30s | API endpoints, routes |
| Web/Admin | 110 | ~25s | Admin UI, views |
| CLI | 283 | ~60s | Console commands |
| Import | 350 | ~75s | Import system |
| Core | 214 | ~45s | Services, models |
| **Total** | **1126** | **~4min** | Full suite |

With parallel execution and targeted workflows, most changes only trigger 100-350 tests instead of all 1126.

---

## Local Testing Commands

### Quick Test Groups
```bash
# Run specific test group
composer test:api      # API tests only (156 tests)
composer test:web      # Web/Admin tests only (110 tests)
composer test:cli      # CLI tests only (283 tests)
composer test:import   # Import tests only (350 tests)
composer test:core     # Core/Service tests only (214 tests)

# Full suite
composer test          # All tests (1126 tests)
```

### Direct Artisan Commands
```bash
# Specific directories
php artisan test --parallel tests/Feature/Api
php artisan test --parallel tests/Unit/Services

# By filter
php artisan test --filter=Import
php artisan test --filter=BookController

# With coverage
php artisan test --coverage --min=70

# List all tests
php artisan test --list-tests
```

---

## Viewing Results

### GitHub UI
1. Go to the **Actions** tab
2. Click on a specific workflow (e.g., "API Tests")
3. View logs and test results

### Pull Request Checks
- Only relevant workflows run based on changed files
- All must pass before merging
- Failed tests show inline in PR

---

## Workflow Behavior Examples

### Example 1: Modify API Controller
```bash
# Changed: app/Http/Controllers/Api/BookController.php
# Triggers: api-tests.yml only
# Runs: 156 API tests (~30 seconds)
```

### Example 2: Modify Import Service
```bash
# Changed: app/Services/BookImportService.php
# Triggers: import-tests.yml AND core-tests.yml
# Runs: 350 + 214 = 564 tests (~2 minutes)
```

### Example 3: Modify View File
```bash
# Changed: resources/views/admin/books/index.blade.php
# Triggers: web-tests.yml only
# Runs: 110 web tests (~25 seconds)
```

### Example 4: Push to main
```bash
# Push to main branch
# Triggers: full-test-suite.yml
# Runs: All 1126 tests + coverage + style checks (~6 minutes)
```

---

## Environment Configuration

All workflows use consistent environment variables:

```bash
APP_ENV=testing          # Test mode (prevents browser launches)
DB_CONNECTION=sqlite     # Fast in-memory database
DB_DATABASE=:memory:     # No file persistence
CACHE_STORE=array        # Array-based caching
SESSION_DRIVER=array     # Array-based sessions
QUEUE_CONNECTION=sync    # Synchronous processing
```

---

## PHP Version Matrix

All workflows test on PHP 8.2 and 8.3:
- **Primary**: PHP 8.2 (with coverage on full suite)
- **Secondary**: PHP 8.3 (compatibility check)

---

## Caching Strategy

Composer dependencies are cached per workflow:
- **Cache key**: Based on `composer.lock` hash
- **Invalidation**: Automatic when dependencies change
- **Benefit**: ~2 minute speedup per workflow run

---

## Troubleshooting

### Workflow Didn't Trigger
- Check if changed files match the `paths:` filter
- Verify branch is `main` or `develop`
- Check workflow file syntax (YAML validation)

### Tests Pass Locally But Fail in CI
1. **PHP version**: Ensure you're on 8.2 or 8.3
2. **Database**: CI uses SQLite, not MySQL
3. **Environment**: Run with `APP_ENV=testing`
4. **Parallel execution**: Test with `--parallel` flag locally

### Too Many Workflows Running
This is expected! If you change:
- `app/Services/BookImportService.php`

Both workflows trigger:
- `import-tests.yml` (import-specific)
- `core-tests.yml` (all services)

This provides redundancy and ensures comprehensive testing.

### Reduce Workflow Runs
If too many workflows trigger, consider:
1. Making more focused commits (single concern)
2. Running tests locally first: `composer test:import`
3. Using draft PRs to prevent workflow runs

---

## Adding New Tests

When you add a new test file, ensure it's covered by a workflow:

- **API test** → `tests/Feature/Api/` or `tests/Unit/Controllers/Api/`
- **Web test** → `tests/Feature/Admin/` or `tests/Feature/Controllers/Admin/`
- **CLI test** → `tests/Feature/Commands/` or `tests/Unit/Commands/`
- **Import test** → Include "Import" in filename or path
- **Service test** → `tests/Unit/Services/` or `tests/Feature/Services/`

Tests outside these paths only run in `full-test-suite.yml`.

---

## Best Practices

### Before Pushing
```bash
# Run relevant test group locally
composer test:api     # if you changed API code
composer test:import  # if you changed import code

# Or run all tests
composer test
```

### For Large Changes
```bash
# Run full suite with coverage
composer test
php artisan test --coverage --min=70
```

### Writing CI-Friendly Tests
- Use SQLite-compatible queries (no MySQL-specific features)
- Don't rely on specific execution order
- Use `RefreshDatabase` trait for database tests
- Mock external services (APIs, file systems)
- Tests should work with `--parallel` flag

---

## Maintenance

### Updating Workflow Triggers
If you add new directories or patterns, update the relevant workflow's `paths:` section.

Example - Adding a new service directory:
```yaml
# .github/workflows/core-tests.yml
paths:
  - 'app/Services/**'
  - 'app/NewServiceDirectory/**'  # Add this
```

### Viewing Workflow Runs
- All runs: `https://github.com/YOUR_REPO/actions`
- Specific workflow: Click the workflow name in the sidebar
- Failed runs: Filter by "Failed" status

---

## Performance Metrics

Typical run times (with caching and parallel execution):

| Workflow | Cold Start | Cached | Parallel |
|----------|-----------|--------|----------|
| API Tests | ~3min | ~1min | ~30s |
| Web Tests | ~2.5min | ~50s | ~25s |
| CLI Tests | ~4min | ~2min | ~60s |
| Import Tests | ~5min | ~2.5min | ~75s |
| Core Tests | ~3.5min | ~1.5min | ~45s |
| Full Suite | ~10min | ~6min | ~4min |

Times include setup, dependency install, and test execution.

---

## Documentation

For more details on specific features:
- **Testing Guide**: See project root `TESTING.md`
- **Contributing**: See `CONTRIBUTING.md`
- **Changelog**: See `CHANGELOG.md` for recent changes
