## UNTESTABLE REGRESSION LIST — MANDATORY CONSULTATION

`UNTESTABLE_REGRESSIONS.md` catalogues every area of this codebase where automated tests
cannot catch regressions (external APIs, real audio/image files, browser UI, TTY interactions,
streaming, email delivery, etc.).

Rules — enforce before every change:

1. Before implementing any change, read `UNTESTABLE_REGRESSIONS.md` and check whether the
   change touches any listed area.
2. If it does, explicitly warn the user about the specific untestable risk(s) before
   proceeding, and think extra hard about the implications.
3. If the change introduces a new untestable area (new external API, new file-dependent
   feature, new JS interaction, etc.), add it to `UNTESTABLE_REGRESSIONS.md` in the same
   commit.
4. Never mark a task complete without stating which untestable regressions (if any) were
   in scope and how you mitigated or noted them.

---

ALWAYS verify with php -l that .php files do not have syntax errors after updates
ALWAYS check that there aren't missing imports after updates
ALWAYS check that there aren't missing dependencies after updates
NEVER do a fresh migration

## Test Failure Policy — CRITICAL

ALL commits are blocked by any test failure. Pre-commit hooks enforce this; never bypass them.

Pre-existing test failures are a HIGHER priority, not lower. A failure that predates current work means a broken commit was already merged — a prior process violation. Fix it immediately, before any other work continues.

"It was already failing" is never an acceptable reason to leave a test broken. Discovering a pre-existing failure makes it your responsibility to fix, regardless of cause.

Never describe a failure as "pre-existing" and move on. That framing is itself a process failure. Stop, diagnose, and fix it.
