## TOP PRIORITY SAFETY RULES

- **CRITICAL**: Assume the database is *production* and contains live data. NEVER execute commands that clear or destructively delete data.
- **FORBIDDEN COMMANDS**: Never execute `php artisan migrate:fresh`, `php artisan migrate:reset`, `DB::raw('DROP TABLE...')`, `TRUNCATE`, or `rm -rf` on any user-facing file system paths.
- **MIGRATIONS**: All migrations MUST be non-destructive (`Schema::table` or `Schema::create`). If a migration involves a destructive operation (like dropping a column), it MUST require explicit user confirmation, and prefer safe alternatives like soft deletes or renaming.
- **DATA LOSS RESPONSE**: If data loss occurs, immediately pause all work and instruct the user on restoration. Do not proceed until data integrity is confirmed by the user.

---

## Platform-Native Development Philosophy

- **"Let the platform do what it does best"** - Leverage platform strengths rather than creating complex abstractions
- **Avoid over-engineering**: Question whether custom abstractions add value vs. using platform solutions
- **Prefer**: Essential logging + coordination over complex resource management layers

## Mobile Development Notes

- When you install to a device or emulator, go ahead and launch the app

## Efficiency Guidelines

- **Build Tools**: When running fire-and-forget tasks (builds, tests, installs), use quiet/minimal output flags
    - **Gradle**: Use `--quiet` and `--console=plain`

## Implementation Best Practices

### Before Coding (Project-Specific Additions)

- **MUST** Follow TDD: scaffold stub -> write failing test -> implement
- **MUST** Keep the Blueprint updated
- **MUST** Keep the changelog updated
- **MUST** Store prompts in a file called prompts.md

### While Coding

- **MUST** Follow Kotlin conventions for imports and nullability
- **SHOULD** Use data classes for simple data containers; prefer sealed classes for state modeling
- **SHOULD NOT** Extract a new function unless it will be reused elsewhere, is the only way to unit-test otherwise untestable logic, or drastically improves readability of an opaque block
- **MUST** Use null safety features of Kotlin
- **SHOULD** Use null safety features of Laravel
- **SHOULD** Keep controllers thin and simple in Laravel
- **SHOULD** Use Eloquent for database access from Laravel
- **MUST** Use as little code in Blade templates as possible — prefer controllers, services, or separate JS files
- **MUST** Use a database abstraction layer for database access from Laravel
- **MUST** Laravel projects use Laravel 11+ (no Console Kernel.php, use bootstrap/app.php for scheduling)
- **SHOULD** Split classes over 1000 lines into smaller, focused pieces
- **MUST** Update openapi.json after any API change

### Testing

- **MUST** For Kotlin: colocate unit tests in `*Test.kt` in test directory matching source package structure
- **MUST** Unit tests for a function should be grouped under `class FunctionNameTest`
- **SHOULD** Unit-test complex algorithms thoroughly
- **SHOULD** Unit-test edge cases
- **MUST** Use Laravel's testing features
- **MUST** Use PHPUnit for testing
- **MUST** Name test methods using camelCase
- **SHOULD** Test the entire structure in one assertion if possible

### Database

- **CRITICAL** Assume the database is *production* with live data. Never execute destructive commands.
- **MUST NOT** Execute `migrate:fresh`, `migrate:reset`, `DROP TABLE`, `TRUNCATE`, or similar destructive operations
- **MUST** All migrations must be non-destructive. Prefer soft deletes or renaming over dropping columns/tables
- **MUST** Use Eloquent for database access
- **MUST** Use snake_case for database fields
- **MUST** Use camelCase for database models
- **MUST** Use PascalCase for database entities
- **MUST** Always backup database before any modifications
- **MUST** Implement comprehensive database operation logging
- **MUST** Add confirmation prompts for any destructive database operations
- **MUST** If data loss occurs, pause all work and instruct user on restoration before proceeding

### Tooling Gates

- **MUST** Run syntax check (`php -l`) for Laravel on all changed files
- **MUST** `php artisan test` passes for all modules
- **MUST** Run `phpcbf` on all changed PHP files
