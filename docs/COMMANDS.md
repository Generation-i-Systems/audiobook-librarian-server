# Console Commands & Jobs

This covers the Artisan commands a server admin is likely to run directly. The application ships
with many more one-off data-repair commands (`books:fix-*`, `books:repair-*`, etc.) used during
development; run `php artisan list` to see the full set, and `php artisan help <command>` for any
command's exact options.

## Importing books

### `php artisan book:import`

Import audiobooks from a downloads directory using AI processing and external metadata enrichment
(Audible, Google Books). Creates a database backup first unless `--no-backup` is passed.

```bash
# Free-tier AI processing, manual review of low-confidence matches
php artisan book:import --model=gemini-2.5-flash-lite

# Fully automated, no manual review
php artisan book:import --auto --min-confidence=80
```

Key options: `--directory=`, `--model=`, `--min-confidence=`, `--auto`, `--dry-run`, `--limit=`,
`--force`, `--copy-files`, `--collection=`, `--genre=`.

### `import-bk` / `bkmv`

See [Import Book Documentation](import-book.md) for the standalone `import-bk` CLI tool, and
`php artisan bkmv` (alias of `books:move`) for moving a single book directory and updating all
database references (`--dry-run`, `--force`, `--import`).

## AI metadata processing

### `php artisan books:process-ai`

Re-run AI metadata extraction/enrichment against existing books.

```bash
php artisan books:process-ai --model=gemini-2.5-flash-lite --limit=50
```

Key options: `--book=` (repeatable), `--limit=`, `--min-confidence=`, `--dry-run`, `--reprocess`,
`--model=`, `--paid-tier`. See [AI Processing Documentation](AI_PROCESSING_SETUP.md).

## Library maintenance

### `php artisan library:repair-scan`

Scans audiobook directories for missing/duplicate/orphaned directories and nested-audio issues,
recording results for the `/admin/library-repair` UI. Runs nightly via the scheduler; see
[Cross-platform installation](INSTALLATION.md#required-background-processes).

Options: `--issue=` (repeatable), `--no-attempt-fixes`, `--json`.

### `php artisan cover:check`

Validates each book's `cover_image` against files on disk and can fetch a missing cover from
Audible.

```bash
php artisan cover:check --attempt-audible --limit=50
```

Options: `--attempt-audible`, `--dry-run`, `--limit=`.

### `php artisan app:optimize-m4b-faststart`

Rewrites M4B/M4A files so the `moov` atom is at the start of the file, enabling progressive
playback in the web/mobile players. Options: `--book=`, `--dry-run`.

## Recommendations & embeddings

Only relevant if the recommendation engine is enabled; see
[Vector Database & Recommendation Pipeline](../README.md#vector-database--recommendation-pipeline).

- `php artisan books:backfill-embeddings [--force]` — queue embedding generation for books missing one.
- `php artisan books:refresh-recommendations` — queue recommendation-shelf recompute for every user.

## Accounts

- `php artisan app:create-admin-user [--no-backup]` — create an admin user if one doesn't already exist.
- `php artisan accounts:purge-scheduled-deletions` — permanently delete accounts whose cancellation period has expired (scheduled nightly).

## Backup & queues

- `php artisan backup:database [--verify] [--suffix=]` — MySQL backup; see [Backup System](BACKUP_SYSTEM.md).
- `php artisan queue:work --queue=embeddings,recommendations,default --tries=3` — standard queue worker.
- `php artisan horizon` — Redis-backed queue supervisor/dashboard.

See [Worker Management & Queue Monitoring](../README.md#worker-management--queue-monitoring) for
the full picture, including Horizon and Supervisor configuration.
