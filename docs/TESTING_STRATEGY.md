# Testing Strategy & Coverage Improvement Plan

## Current Status (as of 2026-01-15)

### Coverage Statistics
- **Total PHP Files**: 275
- **Files with Tests**: ~70 (25.5%)
- **Files with 0% Coverage**: ~205 (74.5%)
- **Test Files**: 278
- **Test Methods**: ~611

### Coverage by Category

| Category | Total Files | Untested | % Untested |
|----------|-------------|----------|------------|
| Console Commands | 56 | 55 | 98% |
| Controllers | 69 | 45 | 65% |
| Services | 30 | 21 | 70% |
| Models | 29 | 14 | 48% |
| Providers | 10 | 10 | 100% |
| Middleware | 3 | 3 | 100% |
| Jobs | 2 | 2 | 100% |
| Mail | 3 | 3 | 100% |
| Traits | 7 | 7 | 100% |

## Priority Levels

### P0 - Critical (New Code, Core Business Logic)
**Must test immediately**

1. **New AI Assistant System** (0% coverage)
   - `AIAssistantService.php` - Core business logic
   - `AIProviderInterface.php` & `AIResponse.php` - Contracts
   - `BaseAIProvider.php` - Shared provider logic
   - `GeminiProvider.php` / `ClaudeProvider.php` / `OpenAIProvider.php`
   - `AIAssistantController.php`

2. **Core Services** (0% coverage)
   - `LibraryRepairService.php` - Data integrity
   - `BookImportService.php` - Critical import logic
   - `AIBookProcessor.php` - Metadata processing
   - `UnifiedBookImporter.php` - Main import orchestration

3. **Data Models with Business Logic** (0% coverage)
   - `Book.php` - Core domain model
   - `User.php` - Authentication & authorization
   - `UserBookStatus.php` - Reading progress

### P1 - High (API & Web Controllers)
**Test within 1-2 sprints**

1. **API Controllers** (18 files, 0% coverage)
   - Auth endpoints
   - User status tracking
   - Book operations
   - Progress tracking

2. **Admin Controllers** (25 files, 0% coverage)
   - Book management
   - Import operations
   - AI query interface
   - User management

3. **Web Controllers** (20 files, 0% coverage)
   - Book display
   - User profiles
   - Reading interface

### P2 - Medium (Commands & Jobs)
**Test within 2-3 sprints**

1. **Console Commands** (55 files, 0% coverage)
   - Import commands
   - Maintenance commands
   - Data repair commands

2. **Queue Jobs** (2 files, 0% coverage)
   - `ValidateBookDirectoriesJob.php`
   - `ImportBookFromDirectoryJob.php`

### P3 - Lower (Infrastructure)
**Test as time permits**

1. **Middleware** (3 files)
2. **Service Providers** (10 files)
3. **Mail Classes** (3 files)
4. **Traits** (7 files)

## Testing Patterns

### 1. Services
```php
// Example: AIAssistantServiceTest.php
class AIAssistantServiceTest extends TestCase
{
    public function testProcessRequestCreatesSession(): void
    {
        $service = new AIAssistantService('gemini');
        $result = $service->processRequest('Find all fantasy books', null, 1);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('session_id', $result);
        $this->assertDatabaseHas('ai_assistant_sessions', [
            'user_id' => 1,
            'status' => 'pending_approval',
        ]);
    }
}
```

### 2. Controllers
```php
// Example: AIAssistantControllerTest.php
class AIAssistantControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testIndexDisplaysRecentSessions(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->get('/admin/ai-assistant');

        $response->assertOk();
        $response->assertViewIs('admin.ai-assistant.index');
    }
}
```

### 3. API Endpoints
```php
class BookApiTest extends TestCase
{
    use RefreshDatabase;

    public function testListBooksRequiresAuthentication(): void
    {
        $response = $this->getJson('/api/books');

        $response->assertUnauthorized();
    }
}
```

### 4. Commands
```php
class ImportBooksCommandTest extends TestCase
{
    public function testImportBooksProcessesDirectory(): void
    {
        $this->artisan('books:import', ['path' => '/test/path'])
             ->expectsOutput('Import completed')
             ->assertExitCode(0);
    }
}
```

## Quick Wins (Easy to Test)

### 1. Pure Functions in Traits
- `NormalizesStrings.php`
- `GenreMapping.php`
- These have no dependencies, easy unit tests

### 2. Value Objects
- `AIResponse.php` ✅ (test created)
- Simple data containers

### 3. Interfaces & Contracts
- Document expected behavior
- Mock in other tests

## Testing Anti-Patterns to Avoid

❌ **Don't Test Framework Code**
```php
// BAD
public function testModelHasAttributes()
{
    $book = new Book();
    $this->assertTrue($book->fillable includes 'title');
}
```

❌ **Don't Test Private Methods Directly**
```php
// BAD - test through public interface
public function testPrivateHelperMethod() { ... }
```

❌ **Don't Create Brittle Tests**
```php
// BAD - too specific
$this->assertEquals('Exact error message text', $exception->getMessage());

// GOOD - test behavior
$this->assertInstanceOf(ValidationException::class, $exception);
```

## Coverage Goals

### Short Term (1 month)
- **Target**: 40% overall coverage
- Focus: P0 items (AI Assistant, Core Services)
- Result: All new code has tests

### Medium Term (3 months)
- **Target**: 60% overall coverage
- Focus: P1 items (Controllers, API)
- Result: All critical paths tested

### Long Term (6 months)
- **Target**: 75% overall coverage
- Focus: P2 items (Commands, Jobs)
- Result: Comprehensive test suite

## Tools & Commands

### Run Tests
```bash
# All tests
php artisan test

# With coverage
XDEBUG_MODE=coverage php artisan test --coverage

# Specific suite
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature

# Coverage report
XDEBUG_MODE=coverage php artisan test --coverage-html coverage-html
```

### Generate Test Skeleton
```bash
php artisan make:test Unit/Services/AI/AIAssistantServiceTest --unit
php artisan make:test Feature/Admin/AIAssistantControllerTest
```

## Immediate Action Items

1. ✅ Create `AIResponseTest.php` - DONE
2. ✅ Create `BaseAIProviderTest.php` - DONE
3. ⏳ Create `AIAssistantServiceTest.php` - NEXT
4. ⏳ Create `AIAssistantControllerTest.php` - NEXT
5. ⏳ Create tests for `GeminiProvider.php`
6. ⏳ Create tests for core services

## Review Schedule

- **Weekly**: Review coverage reports
- **Monthly**: Assess progress toward goals
- **Quarterly**: Update priorities based on risk

## Resources

- PHPUnit Docs: https://phpunit.de/documentation.html
- Laravel Testing: https://laravel.com/docs/testing
- Test-Driven Development: https://martinfowler.com/bliki/TestDrivenDevelopment.html
