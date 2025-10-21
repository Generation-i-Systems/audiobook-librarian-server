# Import System Testing

## Overview

The import system has comprehensive regression tests to prevent bugs from being reintroduced. These tests run automatically on every commit that touches import-related code.

---

## Test Suites

### 1. Metadata Extraction Tests
**File**: `tests/Unit/Services/MetadataExtractionTest.php`  
**Tests**: 10 tests covering file tag extraction

**What it tests**:
- Author extraction from `artist` tag
- Narrator extraction from `composer` tag
- Year extraction from `date` tag
- Genre extraction from `genre` tag
- Publisher extraction from `copyright` tag
- Title parsing with series/number
- Description extraction
- File tags override AI results

### 2. Author Normalization Tests
**File**: `tests/Unit/Services/AuthorNormalizationTest.php`  
**Tests**: 8 tests covering author validation

**What it tests**:
- Extract author from "Graphic Audio [Alex Archer]"
- Reject "Graphic Audio" as author
- Reject "GraphicAudio" as author
- Reject any name containing "Graphic" AND "Audio"
- Reject "Full Cast" as author
- Preserve normal author names
- Normalize initials (J K Rowling → J.K. Rowling)

### 3. Enrichment Merge Tests
**File**: `tests/Unit/Import/EnrichmentMergeTest.php`  
**Tests**: 5 tests covering enrichment behavior

**What it tests**:
- Enrichment doesn't override existing author
- Enrichment doesn't override existing year
- Enrichment doesn't override existing genre
- Enrichment doesn't override existing publisher
- Enrichment fills in missing fields only

---

## Running Tests

### Quick Commands

```bash
# Run all import regression tests
composer test:import

# Run with coverage
composer test:import-coverage

# Run individual test suites
php artisan test --filter=MetadataExtractionTest
php artisan test --filter=AuthorNormalizationTest
php artisan test --filter=EnrichmentMergeTest

# Run all tests
composer test
```

---

## Automated Testing

### Pre-Commit Hook

**Location**: `.git/hooks/pre-commit`

**Triggers when**:
- Any import system file is modified
- Attempting to commit changes

**What it does**:
1. Detects changes to import files
2. Runs all 3 regression test suites
3. Runs code style check (Pint)
4. Blocks commit if any test fails
5. Shows clear error messages

**Files watched**:
- `app/Services/BookImportService.php`
- `app/Services/BookEnrichmentService.php`
- `app/Services/MetadataProcessingService.php`
- `app/Services/GoogleBooksApiService.php`
- `app/Services/AIBookProcessor.php`
- `app/Services/AudioFileAnalyzer.php`
- `app/Console/Commands/ImportBooksFromDownloads.php`
- `app/Console/Commands/PrepareForReprocessing.php`

**Bypass** (not recommended):
```bash
git commit --no-verify
```

---

### GitHub Actions CI/CD

**Location**: `.github/workflows/import-tests.yml`

**Triggers on**:
- Push to `main` or `develop` branches
- Pull requests to `main` or `develop`
- Only when import files are modified

**What it does**:
1. Sets up PHP 8.2 with required extensions
2. Sets up MySQL 8.0 test database
3. Installs dependencies
4. Runs migrations
5. Runs all 3 regression test suites
6. Runs full test suite (if regression tests pass)
7. Generates coverage reports
8. Uploads coverage to Codecov

**Status badge**:
```markdown
![Import Tests](https://github.com/your-org/your-repo/workflows/Import%20System%20Tests/badge.svg)
```

---

## Test Coverage Requirements

**Minimum coverage**: 80%

**Current coverage**:
- Metadata Extraction: ~95%
- Author Normalization: ~100%
- Enrichment Merge: ~90%

---

## Adding New Tests

### When to add tests:

1. **New import feature** - Add tests before implementing
2. **Bug fix** - Add test that reproduces bug, then fix
3. **Edge case discovered** - Add test to prevent regression

### Test template:

```php
/** @test */
public function it_does_something_specific()
{
    // Arrange
    $input = 'test data';
    
    // Act
    $result = $this->service->method($input);
    
    // Assert
    $this->assertEquals('expected', $result);
}
```

### Test naming convention:

- Use `it_` prefix
- Describe behavior, not implementation
- Be specific: `it_extracts_author_from_artist_tag`
- Not generic: `it_works`

---

## Debugging Failed Tests

### Local failures:

```bash
# Run with verbose output
php artisan test --filter=TestName -v

# Run single test method
php artisan test --filter=TestName::test_method_name

# Show full error trace
php artisan test --filter=TestName --stop-on-failure
```

### CI failures:

1. Check GitHub Actions logs
2. Look for specific test failure
3. Reproduce locally:
   ```bash
   composer test:import
   ```
4. Fix issue
5. Verify fix:
   ```bash
   composer test:import
   ```
6. Commit and push

---

## Common Issues

### Issue: Tests pass locally but fail in CI

**Cause**: Environment differences (PHP version, extensions, database)

**Solution**:
- Check CI PHP version matches local
- Ensure all extensions installed
- Check database configuration

### Issue: Pre-commit hook not running

**Cause**: Hook not executable

**Solution**:
```bash
chmod +x .git/hooks/pre-commit
```

### Issue: Tests are slow

**Cause**: Database operations, external API calls

**Solution**:
- Use in-memory SQLite for tests
- Mock external services
- Use test doubles

---

## Best Practices

### 1. Test First
Write tests before implementing features (TDD)

### 2. Keep Tests Fast
- Mock external services
- Use factories for test data
- Avoid unnecessary database operations

### 3. Test One Thing
Each test should verify one specific behavior

### 4. Clear Assertions
Use descriptive assertion messages:
```php
$this->assertEquals(
    'Shannon Mayer',
    $result['author'],
    'Author should be extracted from artist tag'
);
```

### 5. Clean Up
Tests should not leave artifacts:
- Reset database state
- Clean up test files
- Clear caches

---

## Continuous Improvement

### Review test coverage regularly:
```bash
composer test:import-coverage
```

### Update tests when requirements change:
- New metadata fields
- Changed validation rules
- New enrichment sources

### Monitor CI build times:
- Optimize slow tests
- Parallelize when possible
- Cache dependencies

---

## Resources

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Laravel Testing](https://laravel.com/docs/testing)
- [GitHub Actions](https://docs.github.com/en/actions)
- [Codecov](https://docs.codecov.com/)

---

## Support

If tests are failing and you're stuck:

1. Check test output for specific error
2. Review recent changes to import code
3. Check `docs/IMPORT-FIXES-SUMMARY.md` for context
4. Ask for help with specific test failure

**Remember**: Tests are there to help you, not block you. If a test is failing, there's likely a real issue that needs fixing!
