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

**Status**: Provider tests passing (37/76), service/controller tests updated and ready to verify

### AIAssistantServiceTest Fixes

**Problem**: Tests assumed `books` table has `author`/`genre` columns, but they're many-to-many relationships.

**Solution Applied**: Tests now use proper Eloquent models and factories
```php
// Create helper method for test data
$book = Book::factory()->create(['title' => 'The Way of Kings']);
$author = Author::factory()->create(['name' => 'Brandon Sanderson']);
$genre = Genre::factory()->create(['name' => 'Fantasy']);
$book->authors()->attach($author);
$book->genres()->attach($genre);
```

**Status**: Tests updated, needs verification run
**Estimated Effort**: 1 hour to verify and fix any remaining issues
**Priority**: High - blocks AI feature testing

### AIAssistantControllerTest Fixes

**Problem**: Same schema issues in feature tests

**Solution**: Apply same relationship-based approach

**Status**: Tests created, may need updates
**Estimated Effort**: 1-2 hours
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
**Estimated Effort**: 4-6 hours
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
**Estimated Effort**: 3-4 hours
**Priority**: High

### 1.3 ImportService (0% coverage)

**File**: `app/Services/ImportService.php`
**Why Important**: Data import workflows, potential for data corruption

**Test Strategy**:
- Test each import format separately
- Test validation and error handling
- Test rollback on failure

**Estimated Tests**: 12-18
**Estimated Effort**: 4-5 hours
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
**Estimated Effort**: 6-8 hours
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
**Estimated Effort**: 4-6 hours per controller
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
**Estimated Effort**: 3-4 hours
**Priority**: Medium

### 3.2 Background Jobs (mostly 0% coverage)

**Files**: Various job files in `app/Jobs/`

**Test Strategy**:
- Test job dispatching
- Test job execution with mocked dependencies
- Test error handling and retries

**Estimated Tests**: 5-8 per job
**Estimated Effort**: 2-3 hours per job
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
**Estimated Effort**: 2-3 hours
**Priority**: Medium

### 4.2 Scopes and Query Builders

**Test Strategy**:
- Test complex scopes
- Test custom query methods
- Test soft deletes behavior

**Estimated Tests**: 8-12
**Estimated Effort**: 2-3 hours
**Priority**: Low-Medium

---

## Priority 5: Infrastructure (Low Value)

Test only critical infrastructure code.

### 5.1 Middleware (mostly 0% coverage)

**Strategy**: Test authorization logic, skip trivial middleware

**Estimated Effort**: 1-2 hours for critical middleware

### 5.2 Service Providers (mostly 0% coverage)

**Strategy**: Test only custom registration logic, skip standard Laravel providers

**Estimated Effort**: 1 hour

### 5.3 Helpers (0% coverage)

**Strategy**: Test complex helper functions, skip simple wrappers

**Estimated Effort**: 1-2 hours

---

## Implementation Roadmap

### Phase 1: Foundation (Week 1)
- ✅ Fix AI Assistant tests (1-2 hours)
- Add LibraryRepairService tests (4-6 hours)
- Add BookFileService tests (3-4 hours)
- **Target**: Cover critical services
- **Total Effort**: 8-12 hours

### Phase 2: API Coverage (Week 2)
- Add BookController comprehensive tests (6-8 hours)
- Add admin controller tests (4-6 hours each)
- **Target**: Cover critical HTTP endpoints
- **Total Effort**: 14-20 hours

### Phase 3: Background Processing (Week 3)
- Add command tests (3-4 hours)
- Add job tests (2-3 hours each)
- **Target**: Cover async operations
- **Total Effort**: 9-13 hours

### Phase 4: Data Layer (Week 4)
- Add model relationship tests (2-3 hours)
- Add scope tests (2-3 hours)
- **Target**: Cover data integrity
- **Total Effort**: 4-6 hours

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
- Mockery for mocking

### Recommended Additions

#### Mutation Testing
- **Tool**: [Infection PHP](https://infection.github.io/)
- **Purpose**: Detects tests that don't actually verify behavior
- **Benefit**: Improves test quality, not just coverage

#### Parallel Testing
- **Tool**: [Paratest](https://github.com/paratestphp/paratest)
- **Purpose**: Speed up test execution
- **Benefit**: Run tests in parallel

#### Continuous Coverage
- Track coverage over time (don't mandate thresholds)
- Watch for decreasing coverage trends
- Alert on significant drops

---

## Getting Started

### Quick Wins (Do These First)
1. ✅ Create AI Provider tests (Done - 37 passing)
2. Verify AI Service tests (1 hour)
3. Add LibraryRepairService tests (4-6 hours)
4. Add BookController critical path tests (2-3 hours)
5. Add ImportService validation tests (2-3 hours)

**Total Time**: ~10-15 hours
**Impact**: Cover most critical business logic

### Long Term Strategy
- Gradually add tests for new features
- Add tests when fixing bugs (regression tests)
- Refactor tests as code evolves
- Focus on valuable tests, not coverage numbers
- Review and update this plan quarterly

---

## Files Requiring Immediate Attention

### Critical (P0) - Test ASAP
1. `app/Services/LibraryRepairService.php` - File integrity operations
2. `app/Services/BookFileService.php` - File management
3. `app/Services/ImportService.php` - Data import workflows

### High Priority (P1) - Test Soon
1. `app/Http/Controllers/Api/BookController.php` - API endpoints
2. `app/Http/Controllers/Admin/*Controller.php` - Admin operations
3. `app/Console/Commands/*` - CLI commands

### Medium Priority (P2) - Test Eventually
1. `app/Jobs/*` - Background jobs
2. `app/Models/Book.php` - Relationships and scopes
3. Complex middleware and policies

### Low Priority (P3) - Test If Time Permits
1. Simple helpers
2. Standard service providers
3. Trivial middleware

---

## Conclusion

This plan provides a practical, prioritized approach to improving test coverage. The focus is on **risk reduction and value**, not arbitrary coverage percentages.

**Start with high-priority items** that protect critical business logic, then expand coverage gradually over time.

**Remember**: The goal is not 100% coverage. The goal is confidence that your code works correctly and will continue to work as you make changes.

---

## Progress Tracking

### Completed
- ✅ AI Assistant Provider tests (37 tests, 100% coverage)
- ✅ AI Assistant Service tests (13 tests, created and updated)
- ✅ AI Assistant Controller tests (21 tests, created)

### In Progress
- ⏳ Verifying AI Service and Controller tests

### Next Up
- LibraryRepairService tests
- BookFileService tests
- BookController comprehensive tests

### Future
- Command tests
- Job tests
- Model relationship tests
