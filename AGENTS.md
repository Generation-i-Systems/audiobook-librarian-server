# Agent Guidelines for Librarian Repository

This repository is a Laravel 11+ audiobook management application with MySQL database, PHP backend, and jQuery/Vite frontend.

## TOP PRIORITY SAFETY RULES

- **CRITICAL**: Assume the database is _production_ and contains live data. NEVER execute commands that clear or destructively delete data, such as `php artisan migrate:fresh`, `php artisan migrate:reset`, `DB::raw('DROP TABLE...')`, or `rm -rf` on any user-facing file system paths.
- **MIGRATIONS**: All migrations MUST be non-destructive (`Schema::table` or `Schema::create`). If a migration involves a destructive operation (like dropping a column), it MUST be protected by a manual review flag or an explicit user confirmation, and preferably a safe, non-destructive alternative like soft deletes or renaming should be used.
- **RESTORE**: If data loss occurs, immediately pause all work and instruct the user on restoration. Do not proceed until data integrity is confirmed by the user.

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

## Implementation Best Practices

### Before Coding

- **MUST** Ask user clarifying questions for complex work
- **MUST** Draft and confirm approach for complex work
- **SHOULD** List pros/cons for multiple approaches
- **MUST** Use git to track changes
- **MUST** Keep README updated
- **MUST** Keep Blueprint updated
- **MUST** Keep documentation updated
- **MUST** document all API changes in `docs/openapi.json` and provide human-readable documentation explaining the feature, usage logic, and purpose.
- **MUST** Keep changelog updated
- **MUST** Store prompts in prompts.md

### While Coding

- **MUST** Follow TDD: scaffold stub → write failing test → implement
- **MUST** Name functions with existing domain vocabulary
- **SHOULD NOT** Introduce classes when small functions suffice
- **SHOULD** Prefer simple, composable, testable functions
- **SHOULD NOT** Add comments except for critical caveats
- **SHOULD NOT** Extract new functions unless reused, untestable, or major readability improvement
- **MUST** Remove trailing whitespace
- **MUST** Ensure all code is formatted consistently
- **MUST** Keep code simple and readable
- **MUST** Avoid special case handling - address issues generally
- **SHOULD** Split classes >1000 lines into smaller pieces

### Platform Philosophy

- **"Let the platform do what it does best"** - Leverage platform strengths over custom abstractions
- **Avoid over-engineering**: Question custom abstraction value vs platform solutions
- **Prefer**: Essential logging + coordination over complex resource management layers
- **Always detect and follow existing project patterns and conventions**
- **Check for existing scripts** in package.json, build.gradle.kts, Makefile, etc.

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
- **MUST use database abstraction layer for database access**
- **MUST use as little code in Blade templates as possible** - prefer controllers/services/separate JS files

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

#### Database Conventions

- **CRITICAL**: Assume database is _production_ with live data - never execute destructive commands
- **FORBIDDEN COMMANDS**: Never execute `php artisan migrate:fresh`, `php artisan migrate:reset`, `DB::raw('DROP TABLE...')`, `TRUNCATE`, or `rm -rf` on user-facing paths
- **MIGRATIONS**: All migrations MUST be non-destructive (`Schema::table` or `Schema::create`). Destructive operations require explicit user confirmation and prefer safe alternatives (soft deletes, renaming)
- **MUST** Use Eloquent for database access
- **MUST** Use snake_case for database fields
- **MUST** Use camelCase for model attributes accessed via toArray()
- **MUST** Use PascalCase for classes
- **MUST** Always backup database before modifications
- **MUST** Implement comprehensive database operation logging
- **MUST** Add confirmation prompts for destructive operations
- **RESTORE**: If data loss occurs, pause all work and instruct user on restoration before proceeding

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

#### JavaScript Error Handling

- Use `console.error()` with descriptive prefixes: `[module-name] message`
- Check for required dependencies: `if (!$) { console.error("requires jQuery"); return; }`
- Validate data types before operations: `typeof text !== "string"`

#### Laravel Error Handling

- Use Laravel's exception handling mechanisms
- Log errors with `Log::error()` including context
- Use try-catch for external API calls
- Never expose sensitive data in exceptions

## Testing Strategy

### Test Requirements

- **MUST** write tests for each change
- **MUST** ensure all functions have tests
- **MUST** ensure all tests pass
- **MUST** run syntax check on all code
- **MUST** run code formatter on all code

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

- **MUST** parameterize inputs - don't hardcode literals
- **SHOULD** test edge cases and boundaries
- **MUST** use strong assertions (assertEquals over assertGreaterThanOrEqual)
- **SHOULD** test entire structures in single assertion when practical
- **MUST** mock external dependencies (API calls, file system)
- **MUST** use descriptive test method names in camelCase
- **SHOULD** express invariants/axioms over single hard-coded cases
- **SHOULD NOT** add trivial tests that can't fail for real defects

## Safety Checks

### Git Operations

- **Before reverting ANY commit for ANY reason, MUST create a git tag and prompt for confirmation**
- Always verify revert targets with `git log --oneline` before executing
- Use descriptive tag messages: `git tag pre-revert-[YYYYMMDD]-[feature]`
- **MUST** use git to track changes
- **MUST** use conventional commit format: `type(scope): description`
- **MUST NEVER add AI attribution in commit messages**
- **SHOULD** split large commits into smaller, focused pieces when reasonable
- **MUST** ensure all changes are added before retrying failed commits

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
- **MUST** always verify/validate changes through compilation, tests, etc.
- **NEVER** consider a task complete when any syntax errors exist. Run `php -l` on modified files to verify.

### Tool Usage Guidelines

- **MUST** read files fully before updating them
- **SHOULD** do batch updates when possible to reduce tool calls
- **MUST** use full paths instead of changing directories
- **SHOULD** use quiet/minimal output flags for fire-and-forget tasks

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
- **MUST** prompt the user to commit and push if more than 10 files have been locally modified at the end of a response.

## Function Quality Guidelines

When evaluating function quality:

1. Can you read the function and easily follow what it's doing?
2. Does the function have high cyclomatic complexity (too many nested paths)?
3. Are there common data structures/algorithms that would simplify it?
4. Are there unused parameters or unnecessary type casts?
5. Is the function easily testable?
6. Are there hidden untested dependencies?
7. Can you brainstorm 3 better function names?

**Only extract separate functions when**:

- The function is used in multiple places
- The extracted function is testable while the original is not
- The original function is extremely hard to follow without comments
