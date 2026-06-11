ALWAYS verify with php -l that .php files do not have syntax errors after updates
ALWAYS check that there aren't missing imports after updates
ALWAYS check that there aren't missing dependencies after updates
NEVER do a fresh migration

## Test Failure Policy — CRITICAL

ALL commits are blocked by any test failure. Pre-commit hooks enforce this; never bypass them.

Pre-existing test failures are a HIGHER priority, not lower. A failure that predates current work means a broken commit was already merged — a prior process violation. Fix it immediately, before any other work continues.

"It was already failing" is never an acceptable reason to leave a test broken. Discovering a pre-existing failure makes it your responsibility to fix, regardless of cause.

Never describe a failure as "pre-existing" and move on. That framing is itself a process failure. Stop, diagnose, and fix it.
