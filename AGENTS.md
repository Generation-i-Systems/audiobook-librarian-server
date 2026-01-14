# Agent Guidelines for Librarian Repository

This repository is a Laravel 11+ audiobook management application with MySQL database, PHP backend, and jQuery/Vite frontend.

## Essential Commands

### PHP/Laravel Commands

```bash
# Run all tests
composer test

# Run specific test suites
composer test:api
composer test:web
composer test:cli
composer test:import
composer test:core

# Run single test (exact method name)
php artisan test --filter "testMethodName"

# Run tests for specific file
php artisan test tests/Path/To/TestFile.php

# Run tests with pattern matching
php artisan test --filter "AudibleTest"
php artisan test --filter ".*narrator.*"

# Code formatting
vendor/bin/pint app/  # Laravel Pint (opinionated formatter)
phpcbf app/  # PHP CodeSniffer fixer
phpcs app/  # PHP CodeSniffer check

# Static analysis
vendor/bin/phpstan analyse app/ --level=5

# Syntax check (quick validation)
php -l path/to/file.php
```

### JavaScript/Node Commands

```bash
# Build production assets
npm run build

# Development mode with hot reload
npm run dev

# Run Jest tests
npm test

# Run Jest in watch mode
npm run test:watch

# Generate coverage
npm run test:coverage
```

## Code Style Guidelines

### PHP

#### File Structure

- All PHP files must start with `declare(strict_types=1);`
- Use PSR-4 autoloading: `App\` for `app/`, `Tests\` for `tests/`
- Namespace declarations must match directory structure
- Ensure all code is PSR-12 compliant from beginning
- Set line length limit of 120 characters
- **MUST verify with `php -l` after updates** - no syntax errors
- **MUST check for missing imports after updates**
- **MUST check for missing dependencies after updates**
- **MUST always run code formatter after changes** (vendor/bin/pint or phpcbf)

#### Naming Conventions

- **Classes**: PascalCase (e.g., `BookController`, `MySqlService`)
- **Methods**: camelCase (e.g., `getBook()`, `updateBook()`)
- **Variables**: camelCase (e.g., `$userId`, `$bookData`)
- **Database fields**: snake_case (e.g., `user_id`, `created_at`)
- **Eloquent relationships**: plural (e.g., `authors()`, `narrators()`)

#### Laravel-Specific Rules

- Use Eloquent for all database access (no raw SQL queries)
- Keep controllers thin - move business logic to Services
- Minimize Blade template code - prefer controllers/services/JS
- Use Laravel 11+ patterns (no `app/Console/Kernel.php`, use `bootstrap/app.php`)
- Prefer dependency injection over facades where possible
- **MUST update openapi.json after any API change**
- **MUST NOT access database from controllers** - must use DocumentStoreServiceInterface
- User authentication handled by DocumentstoreUser NOT models/User
- Use Laravel Pint with config from `.pint.json` in project root if present

#### Type Safety

- Always use strict types in method signatures
- Use `declare(strict_types=1);` at file top
- Use PHPStan level 5 analysis (baseline allowed in `phpstan-baseline.neon`)
- Use null safety: `?string` for nullable, never omit nullability

#### Testing

- Use PHPUnit (not Pest)
- Test method names: camelCase, e.g., `testGetBookReturnsData()`
- Use Laravel's testing features (`actingAs()`, `actingAsUser()`, factory(), etc.)
- Place tests in matching directory structure under `tests/`
- Group tests by suite: `tests/Unit`, `tests/Feature`, `tests/Api`, `tests/Web`, `tests/Cli`, `tests/Import`, `tests/Core`

#### Code Organization

- Classes > 1000 lines: split into smaller, focused pieces
- Use traits for reusable behavior (e.g., `HandlesLibraryJson`)
- Implement interfaces for services (e.g., `DocumentStoreServiceInterface`)
- Use service providers for dependency injection
- Always use braces on if statements (no single-line if without braces)
- String concatenation using `.` should have spaces on both sides
- Using `!` for not should not have a space after it
- Use parentheses for instantiating new classes: `new ClassName()`
- `@param` in docblocks should use one space before and after type

#### Error Handling

- Use Laravel's exception handling mechanisms
- Log errors with `Log::error()` including context
- Use try-catch for external API calls
- Never expose sensitive data in exceptions

### JavaScript

#### File Structure

- Use strict mode: `(function (window, $) { "use strict"; ... })(window, window.jQuery);`
- IIFE pattern to avoid polluting global scope
- Module namespace: `window.BookForm = window.BookForm || {}`

#### Naming Conventions

- **Functions**: camelCase (e.g., `addNarratorRow()`, `initializeTemplates()`)
- **Variables**: camelCase (e.g., `$container`, `narratorArray`)
- **Constants**: UPPER_SNAKE_CASE (e.g., `window.GOOGLE_BOOKS_MATCH_LIMIT`)
- **CSS classes**: kebab-case (e.g., `.narrator-row`, `.add-narrator-row`)

#### jQuery Patterns

- Use `$` prefix for jQuery objects (e.g., `$container`, `$modal`)
- Use `$(document).ready()` for initialization
- Use event delegation for dynamic elements: `$(document).on('click', '.btn', fn)`
- Prefer `.prop()` over `.attr()` for properties
- Check element existence before operations: `if ($element.length) { ... }`

#### Error Handling

- Use `console.error()` with descriptive prefixes: `[module-name] message`
- Check for required dependencies: `if (!$) { console.error("requires jQuery"); return; }`
- Validate data types before operations: `typeof text !== "string"`

## Testing Strategy

### Before Coding

1. Confirm approach with user for complex work
2. Follow TDD: scaffold stub → write failing test → implement
3. Use existing patterns - don't reinvent abstractions

### Running Tests

Always run relevant tests after changes:

```bash
# Quick single test check
php artisan test --filter "specificTestName"

# Run all related tests
php artisan test tests/Path/To/Module/
```

### Test Quality

- Parameterize inputs - don't hardcode literals
- Test edge cases and boundaries
- Use strong assertions (assertEquals over assertGreaterThanOrEqual)
- Test entire structures in single assertion when practical
- Mock external dependencies (API calls, file system)

## Platform Philosophy

- "Let the platform do what it does best"
- Prefer platform solutions over custom abstractions
- Essential logging + coordination over complex resource management layers
- Avoid over-engineering
- Minimize comments - prefer self-explanatory code
- Keep code simple and readable

## Safety Checks

### Git Operations

- **Before reverting ANY commit for ANY reason, MUST create a git tag and prompt for confirmation**
- Always verify revert targets with `git log --oneline` before executing
- Use descriptive tag messages: `git tag pre-revert-[YYYYMMDD]-[feature]`

### Pre-commit Hooks

- **Syntax Check**: All PHP (`php -l`) and JS (`node --check`) files are verified for syntax before commit.
- **Formatting**: Laravel Pint runs on staged PHP files.
- **Static Analysis**: PHPStan runs on the `app/` directory.
- **Testing**: Relevant tests (Import, Web, Api, Cli) or a smoke test are executed automatically.

### Verification & Reporting

- **MUST double-check all changes stated in message responses** to ensure they actually exist in the current state
- Use `git diff`, `git status`, or file reading to verify changes before reporting
- Never claim changes are complete unless explicitly confirmed they are in place
- If uncertain about state, run verification commands before responding

## Commit Guidelines

- Work in logical, reviewable chunks
- **MUST leave code in working state**
- Use conventional commit format: `type(scope): description`
- **MUST NEVER add AI attribution in commit messages**
- Commit often and keep commits small
- Write clear, concise commit messages
- Group related changes together
- Avoid committing broken code
- Keep commits focused on a single purpose
- Make sure each commit is buildable and functional
- Split large commits when reasonable
