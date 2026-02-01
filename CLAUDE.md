## TOP PRIORITY SAFETY RULES

- **CRITICAL**: Assume the database is *production* and contains live data. NEVER execute commands that clear or destructively delete data.
- **FORBIDDEN COMMANDS**: Never execute `php artisan migrate:fresh`, `php artisan migrate:reset`, `DB::raw('DROP TABLE...')`, `TRUNCATE`, or `rm -rf` on any user-facing file system paths.
- **MIGRATIONS**: All migrations MUST be non-destructive (`Schema::table` or `Schema::create`). If a migration involves a destructive operation (like dropping a column), it MUST require explicit user confirmation, and prefer safe alternatives like soft deletes or renaming.
- **DATA LOSS RESPONSE**: If data loss occurs, immediately pause all work and instruct the user on restoration. Do not proceed until data integrity is confirmed by the user.

---

### Code Preferences

- Always detect and follow existing project patterns and conventions
- Check for existing scripts in package.json, build.gradle.kts, Makefile, etc.
- Minimize comments unless explicitly requested
- Follow existing import organization and naming conventions

### Platform-Native Development Philosophy

- **"Let the platform do what it does best"** - Leverage platform strengths rather than creating complex abstractions
- **Avoid over-engineering**: Question whether custom abstractions add value vs. using platform solutions
- **Prefer**: Essential logging + coordination over complex resource management layers

## Important Workflow Guidelines

## Code Commit Guidelines

- IMPORTANT: Work should be committed in logical, reasonably sized chunks for human and AI review.
- IMPORTANT: Commits should always leave the code in a working state.
- IMPORTANT: Never add Claude or Gemini attributions or as a co-editor or mention them in commit messages.

## Verification Guidelines

- IMPORTANT: Always verify/validate the changes through compilation, tests, etc. or ask the human to do the verification/validation if unable to do so.

## Mobile Development Notes

- When you install to a device or emulator, go ahead and launch the app

## Efficiency Guidelines

- **Build Tools**: When running fire-and-forget tasks (builds, tests, installs), use quiet/minimal output flags to minimize tokens
    - **Gradle**: Use `--quiet` and `--console=plain`
- Reduced ktlint output from ~400+ to ~10 tokens by setting debug.set(false) and verbose.set(false) in the Gradle configuration and adding --quiet to the gradle command, while adding a simple success message for acknowledgment

## General Guidance

- Use full paths instead of changing directories. Instead of `cd` into a dir, the `ls`, just `ls /full/path`

## Subagent Investigation Guidelines

- When investigating other code bases/repos, use subagents and report back relevant details
- Proactively add details that may be helpful for context
- Reports should include a very brief set of instructions to find the most relevant parts of the code
- Provide context to help the parent agent guide subsequent subagent investigations

## Tool Usage Guidelines

- Make sure to read files fully before doing updates on them
- Do batch updates when possible to reduce tool calls

# Claude Code Guidelines, adopted from guidelines by Sabrina Ramonov

# https://www.sabrina.dev/p/ultimate-ai-coding-guide-claude-code

## Implementation Best Practices

### 0 — Purpose

These rules ensure maintainability, safety, and developer velocity.
**MUST** rules are enforced by CI; **SHOULD** rules are strongly recommended.

---

### 1 — Before Coding

- **BP-1 (MUST)** Ask the user clarifying questions.
- **BP-2 (SHOULD)** Draft and confirm an approach for complex work.
- **BP-3 (SHOULD)** If ≥ 2 approaches exist, list clear pros and cons.
- **BP-4 (MUST)** Use git to track changes.
- **BP-5 (MUST)** Keep the README updated.
- **BP-6 (MUST)** Keep the Blueprint updated.
- **BP-7 (MUST)** Keep the documentation updated.
- **BP-8 (MUST)** Keep the changelog updated.
- **BP-9 (MUST)** Store prompts in a file called prompts.md.

---

### 2 — While Coding

- **C-1 (MUST)** Follow TDD: scaffold stub -> write failing test -> implement.
- **C-2 (MUST)** Name functions with existing domain vocabulary for consistency.
- **C-3 (SHOULD NOT)** Introduce classes when small testable functions suffice.
- **C-4 (SHOULD)** Prefer simple, composable, testable functions.
- **C-5 (MUST)** Follow Kotlin conventions for imports and nullability.
- **C-6 (SHOULD NOT)** Add comments except for critical caveats; rely on self‑explanatory code.
- **C-7 (SHOULD)** Use data classes for simple data containers; prefer sealed classes for state modeling.
- **C-8 (SHOULD NOT)** Extract a new function unless it will be reused elsewhere, is the only way to unit-test otherwise untestable logic, or drastically improves readability of an opaque block.
- **C-9 (MUST)** Remove any trailing whitespace on lines.
- **C-10 (MUST)** Ensure that all code is formatted to the same style.
- **C-10 (MUST)** keep the code simple and readable.
- **C-11 (MUST)** Use null safety features of Kotlin.
- **C-12 (SHOULD)** Avoid any linting warnings.
- **C-13 (SHOULD)** Use null safety features of Laravel.
- **C-14 (SHOULD)** Keep a controller thin and simple in laravel.
- **C-15 (SHOULD)** Use eloquent for database access from laravel.
- **C-16 (MUST)** avoid any changes to address a special data case address the issue in a general way.
- **C-17 (MUST)** use as little code in blade templates a possible. prefer code being in controllers or services or separate javascript files.
- **C-18 (MUST)** use a database abstraction layer for database access from laravel.
- **C-19 (MUST)** Laravel projects use Laravel 11+ (no Console Kernel.php, use bootstrap/app.php for scheduling).
- **C-20 (SHOULD)** Classes with over 1000 lines should be split into smaller, focused pieces to improve maintainability and testability.
- **C-21 (MUST)** update openapi.json after any api change

---

### 3 — Testing

- **T-1 (MUST)** For a simple function, colocate unit tests in `*Test.kt` in test directory matching source package structure.
- **T-2 (MUST)** Unit tests for a function should be grouped under `class FunctionNameTest`.
- **T-3 (SHOULD)** Unit-test complex algorithms thoroughly.
- **T-4 (SHOULD)** Unit-test edge cases.
- **T-7 (MUST)** Use Laravel's testing features.
- **T-8 (MUST)** Use phpunit for testing
- **T-9 (MUST)** Name test methods using camelCase.
- **T-10 (SHOULD)** Test the entire structure in one assertion if possible

---

### 4 - Database

- **D-1 (CRITICAL)** Assume the database is *production* with live data. Never execute destructive commands.
- **D-2 (MUST NOT)** Execute `migrate:fresh`, `migrate:reset`, `DROP TABLE`, `TRUNCATE`, or similar destructive operations.
- **D-3 (MUST)** All migrations must be non-destructive (`Schema::table` or `Schema::create`). Prefer soft deletes or renaming over dropping columns/tables.
- **D-4 (MUST)** Use Eloquent for database access.
- **D-5 (MUST)** Use snake_case for database fields.
- **D-6 (MUST)** Use camelCase for database models.
- **D-7 (MUST)** Use PascalCase for database entities.
- **D-8 (MUST)** Always backup database before any modifications.
- **D-9 (MUST)** Implement comprehensive database operation logging.
- **D-10 (MUST)** Add confirmation prompts for any destructive database operations.
- **D-11 (MUST)** If data loss occurs, pause all work and instruct user on restoration before proceeding.

---

### 4 — Code Organization

---

### 6 — Tooling Gates

- **G-1 (MUST)** run syntax check for laravel on all changed files.
- **G-2 (MUST)** `php artisan test` passes for all modules for laravel.
- **G-3 (MUST)** `php artisan build` passes for any changed modules for laravel.
- **G-4 (MUST)** run `phpcbf` for laravel on all changed files.

---

### 7 — Git

- **GH-1 (MUST NOT)** Refer to Claude or Anthropic in commit messages.
- **GH-2 (MUST)** Use git to track changes.
- **GH-3 (MUST)** Use conventional commit format: `type(scope): description`.
- **GH-4 (MUST)** Use `git commit --amend` to fix the last commit.
- **GH-5 (MUST)** Use `git rebase` to rebase the last commit.
- **GH-6 (MUST)** if a commit fails causing you to edit code, make sure the new changes are added before retrying the commit. Using `git status` to ensure the expected changes are added.
- **GH-7 (SHOULD)** If a commit can reasonably be split into multiple commits, do so.
- **GH-8 (MUST NOT)** Use `--no-verify` flag with git commands unless the user gives express approval after you explain why the hook failure cannot be avoided through fixing the underlying issue.

---

## Writing Functions Best Practices

When evaluating whether a function you implemented is good or not, use this checklist:

1. Can you read the function and HONESTLY easily follow what it's doing? If yes, then stop here.
2. Does the function have very high cyclomatic complexity? (number of independent paths, or, in a lot of cases, number of nesting if if-else as a proxy). If it does, then it's probably sketchy.
3. Are there any common data structures and algorithms that would make this function much easier to follow and more robust? Parsers, trees, stacks / queues, etc.
4. Are there any unused parameters in the function?
5. Are there any unnecessary type casts that can be moved to function arguments?
6. Is the function easily testable?
7. Does it have any hidden untested dependencies or any values that can be factored out into the arguments instead? Only care about non-trivial dependencies that can actually change or affect the function.
8. Brainstorm 3 better function names and see if the current name is the best, consistent with rest of codebase.

IMPORTANT: you SHOULD NOT refactor out a separate function unless there is a compelling need, such as:

- the refactored function is used in more than one place
- the refactored function is easily unit testable while the original function is not AND you can't test it any other way
- the original function is extremely hard to follow and you resort to putting comments everywhere just to explain it

## Writing Tests Best Practices

When evaluating whether a test you've implemented is good or not, use this checklist:

1. SHOULD parameterize inputs; never embed unexplained literals such as 42 or "foo" directly in the test.
2. SHOULD NOT add a test unless it can fail for a real defect. Trivial asserts (e.g., assertThat(2).isEqualTo(2)) are forbidden.
3. SHOULD ensure the test description states exactly what the final expect verifies. If the wording and assert don’t align, rename or rewrite.
4. SHOULD compare results to independent, pre-computed expectations or to properties of the domain, never to the function’s output re-used as the oracle.
5. SHOULD follow the same lint, type-safety, and style rules as prod code (ktlint, strict nullability).
6. SHOULD express invariants or axioms (e.g., commutativity, idempotence, round-trip) rather than single hard-coded cases whenever practical.
7. Use descriptive test method names that state what is being verified.
8. ALWAYS use strong assertions over weaker ones e.g. `assertThat(x).isEqualTo(1)` instead of `assertThat(x).isAtLeast(1)`.
9. SHOULD test edge cases, realistic input, unexpected input, and value boundaries.
