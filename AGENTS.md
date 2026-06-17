# Agent Guidelines for Librarian Repository

## UNTESTABLE REGRESSION LIST — MANDATORY CONSULTATION

**`UNTESTABLE_REGRESSIONS.md`** catalogues every area of this codebase where automated tests
cannot catch regressions (external APIs, real audio/image files, browser UI, TTY interactions,
streaming, email delivery, etc.).

**Rules — enforce before every change:**

1. **Before implementing any change**, read `UNTESTABLE_REGRESSIONS.md` and check whether the
   change touches any listed area.
2. **If it does**, explicitly warn the user about the specific untestable risk(s) before
   proceeding, and think extra hard about the implications.
3. **If the change introduces a new untestable area** (new external API, new file-dependent
   feature, new JS interaction, etc.), add it to `UNTESTABLE_REGRESSIONS.md` in the same
   commit.
4. **Never mark a task complete** without stating which untestable regressions (if any) were
   in scope and how you mitigated or noted them.

---


This repository is a Laravel 11+ audiobook management application with MySQL database, PHP backend, and jQuery/Vite frontend.

## TOP PRIORITY SAFETY RULES

- **MANDATORY WARNING**: The local database is live and contains real data. NEVER damage the database. Do not run any command or code path that could wipe, broadly reset, truncate, drop, or otherwise destructively alter live data.
- **CRITICAL**: Assume the database is _production_ and contains live data. NEVER execute commands that clear or destructively delete data, such as `php artisan migrate:fresh`, `php artisan migrate:reset`, `DB::raw('DROP TABLE...')`, or `rm -rf` on any user-facing file system paths.
- **LIMITED EXCEPTION**: Badge-related tables may be reset or reseeded when needed, but only if the work is strictly limited to badge tables and does not risk the rest of the database.
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
- **MUST update openapi.json after any API change** as it is the source of truth for the API
- **MUST NOT access database from controllers** - must use DocumentStoreServiceInterface
- User authentication handled by DocumentstoreUser NOT models/User
- Use Laravel Pint with config from `.pint.json` in project root if present
- **MUST use database abstraction layer for database access**
- **MUST use as little code in Blade templates as possible** - prefer controllers/services/separate JS files

#### Type Safety

- Always use strict types in method signatures
- Use `declare(strict_types=1);` at file top
- Use PHPStan level 5 analysis (baseline allowed in `phpstan-baseline.neon`)
- **MUST NOT** introduce any new PHPStan issues with any change
- **MUST NOT** update the PHPStan baseline without the express request of the USER
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

- **MANDATORY WARNING**: The local database is live. Never damage it. Protect all non-badge data from destructive changes at all times.
- **FORBIDDEN COMMANDS**: Never execute `php artisan migrate:fresh`, `php artisan migrate:reset`, `DB::raw('DROP TABLE...')`, `TRUNCATE`, or `rm -rf` on user-facing paths
- **ONLY ALLOWED RESET SCOPE**: If a reset/reseed is truly needed, it may apply only to badge tables, never the full database or unrelated user data
- **MIGRATIONS**: All migrations MUST be non-destructive. Destructive operations require explicit user confirmation and prefer safe alternatives (soft deletes, renaming)
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

### Pre-commit Hooks

- **Syntax Check**: All PHP (`php -l`) and JS (`node --check`) files are verified for syntax before commit
- **Formatting**: Laravel Pint runs on staged PHP files
- **Static Analysis**: PHPStan runs on the `app/` directory
- **Testing**: Relevant tests (Import, Web, Api, Cli) or a smoke test are executed automatically

### Test Failure Policy — CRITICAL

- **ALL commits are blocked by any test failure.** Pre-commit hooks enforce this; never attempt to bypass them.
- **Pre-existing test failures are a higher priority, not lower.** A failure that predates current work is evidence of a prior process violation — a broken commit was already merged. It must be fixed immediately, before any other work continues.
- **"It was already failing" is never an acceptable reason to leave a test broken.** Discovering a pre-existing failure means the fix is now your responsibility, regardless of what caused it.
- **Never describe a failure as "pre-existing" and move on.** That framing is itself a process failure. Stop, diagnose, and fix it.

### Verification & Reporting

- **MUST double-check all changes stated in message responses** to ensure they actually exist in the current state
- Use `git diff`, `git status`, or file reading to verify changes before reporting
- Never claim changes are complete unless explicitly confirmed they are in place
- If uncertain about state, run verification commands before responding
- **NEVER** consider a task complete when any syntax errors exist. Run `php -l` on modified files to verify

## Commit Guidelines (Project-Specific)

- **MUST** prompt the user to commit and push if more than 10 files have been locally modified at the end of a response
