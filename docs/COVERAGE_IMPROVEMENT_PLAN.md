# Coverage Improvement Plan

## Executive Summary

This document provides an actionable plan to improve test coverage across the codebase. The focus is on **risk reduction** and **value delivery**, not arbitrary coverage percentages. We prioritize areas where tests provide the most protection against bugs and regressions.

**Current State**:
- 205 of 275 files (74.5%) have 0% coverage
- AI Assistant providers: 100% coverage ✅
- Core business logic: Minimal coverage ⚠️
- Infrastructure: Minimal coverage ⚠️

**Guiding Principles**:
- ✅ Test business logic thoroughly
- ✅ Test code that changes frequently
- ✅ Test code that breaks often
- ❌ Don't mandate coverage percentages
- ❌ Don't test trivial getters/setters
- ❌ Don't test framework code

---

## Priority 0: Fix Broken AI Assistant Tests

**Status**: Tests created but not passing due to schema assumptions

### AIAssistantServiceTest Fixes

**Problem**: Tests assume `books` table has `author`/`genre` columns, but they're many-to-many relationships.

**Solution**:
```php
// Create helper method for test data
protected function createBookWithRelationships(array $attributes = []): Book
{
    $book = Book::factory()->create($attributes);

    if (isset($attributes['authors'])) {
        $authors = collect($attributes['authors'])->map(function($name) {
            return Author::firstOrCreate(['name' => $name]);
        });
        $book->authors()->attach($authors);
    }

    if (isset($attributes['genres'])) {
        $genres = collect($attributes['genres'])->map(function($name) {
            return Genre::firstOrCreate(['name' => $name]);
        });
        $book->genres()->attach($genres);
    }

    return $book;
}

// Update tests to use helper
public function testGenerateSearchResultsFindsBooks(): void
{
    $this->createBookWithRelationships([
        'title' => 'The Way of Kings',
        'authors' => ['Brandon Sanderson'],
        'genres' => ['Fantasy'],
    ]);

    // ... rest of test
}
```

**Estimated Effort**: 2-3 hours
**Priority**: High - blocks AI feature testing

### AIAssistantControllerTest Fixes

**Problem**: Same schema issues in feature tests

**Solution**: Use same approach as service tests, but with full HTTP testing stack.

**Estimated Effort**: 2-3 hours
**Priority**: High - blocks AI feature testing

---

## Priority 1: Core Business Logic (High Value)

These services contain critical business logic and should be tested thoroughly.

### 1.1 LibraryRepairService (0% coverage)

**File**: `app/Services/LibraryRepairService.php`
**Lines**: 283
**Why Important**: Manages file integrity, data consistency, critical repair operations

**Test Strategy**:
- Unit tests for each repair method
- Integration tests for full repair workflows
- Test edge cases: missing files, corrupt data, permission issues

**Example Test**:
```php
public function testRepairMissingBookFilesCreatesPlaceholders(): void
{
    $book = Book::factory()->create(['directory_path' => '/missing']);

    $result = $this->service->repairMissingFiles();

    $this->assertTrue($result['success']);
    $this->assertDirectoryExists($book->directory_path);
}
```

**Estimated Tests**: 15-20
**Priority**: Critical

### 1.2 BookFileService (0% coverage)

**File**: `app/Services/BookFileService.php`
**Lines**: ~200
**Why Important**: File management, audio processing, critical data operations

**Test Strategy**:
- Mock filesystem operations
- Test file validation logic
- Test error handling for corrupted files

**Estimated Tests**: 10-15
**Priority**: High

### 1.3 ImportService (0% coverage)

**File**: `app/Services/ImportService.php`
**Lines**: Unknown
**Why Important**: Data import workflows, potential for data corruption

**Test Strategy**:
- Test each import format separately
- Test validation and error handling
- Test rollback on failure

**Estimated Tests**: 12-18
**Priority**: High

---

## Priority 2: API & Controllers (Medium-High Value)

Controllers contain business logic and HTTP handling that should be tested.

### 2.1 BookController (15.79% coverage)

**File**: `app/Http/Controllers/Api/BookController.php`
**Lines**: 380
**Current Coverage**: 15.79% (60/380 lines)

**Uncovered Critical Paths**:
- Book creation and validation
- Bulk operations
- Complex filtering and search

**Test Strategy**:
- Feature tests for each endpoint
- Test authentication/authorization
- Test validation rules
- Test error responses

**Example Test**:
```php
public function testBulkUpdateValidatesInput(): void
{
    $response = $this->actingAs($this->admin)
        ->postJson('/api/books/bulk-update', [
            'book_ids' => [1, 2, 3],
            'changes' => ['invalid_field' => 'value'],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('changes');
}
```

**Estimated Tests**: 20-25
**Priority**: High

### 2.2 Admin Controllers (mostly 0% coverage)

**Files**:
- `AdminController.php` (0%)
- `LibraryController.php` (0%)
- `SettingsController.php` (0%)

**Test Strategy**:
- Feature tests for admin workflows
- Test authorization (admin-only access)
- Test form submissions and validation

**Estimated Tests**: 15-20 per controller
**Priority**: Medium-High

---

## Priority 3: Commands & Jobs (Medium Value)

Background processing and CLI commands need testing for reliability.

### 3.1 ImportBooksCommand (0% coverage)

**File**: `app/Console/Commands/ImportBooksCommand.php`
**Why Important**: Critical for bulk data operations

**Test Strategy**:
```php
public function testImportBooksProcessesDirectory(): void
{
    $this->artisan('books:import', ['path' => '/test/path'])
        ->expectsOutput('Processing directory: /test/path')
        ->assertExitCode(0);
}
```

**Estimated Tests**: 8-12
**Priority**: Medium

### 3.2 Background Jobs (mostly 0% coverage)

**Files**: Various job files in `app/Jobs/`

**Test Strategy**:
- Test job dispatching
- Test job execution with mocked dependencies
- Test error handling and retries

**Estimated Tests**: 5-8 per job
**Priority**: Medium

---

## Priority 4: Models & Relationships (Low-Medium Value)

Models contain relationship definitions and scopes worth testing.

### 4.1 Book Model Relationships

**Test Strategy**:
```php
public function testBookHasManyAuthors(): void
{
    $book = Book::factory()->create();
    $authors = Author::factory()->count(2)->create();
    $book->authors()->attach($authors);

    $this->assertCount(2, $book->authors);
}

public function testBookBelongsToSeries(): void
{
    $series = Series::factory()->create();
    $book = Book::factory()->create(['series_id' => $series->id]);

    $this->assertEquals($series->id, $book->series->id);
}
```

**Estimated Tests**: 10-15 for Book model
**Priority**: Medium

### 4.2 Scopes and Query Builders

**Test Strategy**:
- Test complex scopes
- Test custom query methods
- Test soft deletes behavior

**Estimated Tests**: 8-12
**Priority**: Low-Medium

---

## Priority 5: Infrastructure (Low Value)

Test only critical infrastructure code.

### 5.1 Middleware (mostly 0% coverage)

**Strategy**: Test authorization logic, skip trivial middleware

### 5.2 Service Providers (mostly 0% coverage)

**Strategy**: Test only custom registration logic, skip standard Laravel providers

### 5.3 Helpers (0% coverage)

**Strategy**: Test complex helper functions, skip simple wrappers

---

## Implementation Roadmap

### Phase 1: Foundation (Week 1)
- ✅ Fix AI Assistant tests
- ✅ Add LibraryRepairService tests
- ✅ Add BookFileService tests
- Target: Cover critical services

### Phase 2: API Coverage (Week 2)
- Add BookController comprehensive tests
- Add admin controller tests
- Target: Cover critical HTTP endpoints

### Phase 3: Background Processing (Week 3)
- Add command tests
- Add job tests
- Target: Cover async operations

### Phase 4: Data Layer (Week 4)
- Add model relationship tests
- Add scope tests
- Target: Cover data integrity

### Phase 5: Polish (Ongoing)
- Fill gaps identified by mutation testing
- Add tests for bugs discovered in production
- Refactor brittle tests

---

## Testing Best Practices

### Do's ✅
- **Test behavior, not implementation**: Focus on what code does, not how
- **Test edge cases**: Null values, empty arrays, boundary conditions
- **Use factories**: Create test data consistently
- **Mock external services**: No real API calls in tests
- **Test error paths**: Don't just test happy paths
- **Keep tests focused**: One assertion per test when possible
- **Use descriptive names**: `testCannotDeleteBookWithoutPermission()`

### Don'ts ❌
- **Don't test framework code**: Laravel is already tested
- **Don't test trivial code**: Simple getters/setters
- **Don't aim for 100% coverage**: Focus on valuable tests
- **Don't test private methods**: Test through public interface
- **Don't write brittle tests**: Tests shouldn't break on refactoring
- **Don't duplicate tests**: Each test should verify unique behavior

---

## Measuring Success

### Metrics That Matter
1. **Bug Detection**: Tests catch real bugs before production
2. **Refactoring Confidence**: Can refactor without breaking tests
3. **Documentation Value**: Tests explain how code works
4. **Deployment Safety**: Can deploy with confidence

### Metrics That Don't Matter
1. ❌ Line coverage percentage
2. ❌ Number of tests
3. ❌ Test execution time (within reason)

---

## Tools and Automation

### Current Setup
- PHPUnit with Xdebug for coverage
- Laravel testing utilities
- RefreshDatabase for clean test DB

### Recommended Additions
- **Mutation Testing**: [Infection PHP](https://infection.github.io/)
  - Detects tests that don't actually verify behavior
  - Improves test quality

- **Parallel Testing**: [Paratest](https://github.com/paratestphp/paratest)
  - Speed up test execution
  - Run tests in parallel

- **Continuous Coverage**: Track coverage over time
  - Don't mandate thresholds
  - Watch for decreasing coverage trends

---

## Getting Started

### Quick Wins (Do These First)
1. Fix AI Assistant tests (2-3 hours)
2. Add LibraryRepairService tests (3-4 hours)
3. Add BookController critical path tests (2-3 hours)
4. Add ImportService validation tests (2-3 hours)

**Total Time**: ~10-15 hours
**Impact**: Cover most critical business logic

### Long Term
- Gradually add tests for new features
- Add tests when fixing bugs (regression tests)
- Refactor tests as code evolves
- Focus on valuable tests, not coverage numbers

---

## Conclusion

This plan provides a practical, prioritized approach to improving test coverage. The focus is on **risk reduction and value**, not arbitrary coverage percentages. Start with high-priority items that protect critical business logic, then expand coverage gradually over time.

**Remember**: The goal is not 100% coverage. The goal is confidence that your code works correctly and will continue to work as you make changes.
