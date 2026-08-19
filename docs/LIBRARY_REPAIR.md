# Library Repair & Needs Review Workflow

- Nightly `library:repair-scan` command inspects audiobook directories for:
    - Missing directories referenced in MySQL
    - Duplicate `directory_path` assignments
    - Orphaned folders containing audio but no book
    - Nested audio folders that can be auto-promoted into `directory_path`
    - Numbered-suffix directories (e.g., `_01`, `_02`) that exist without a matching base directory
- Issues record only the minimum metadata necessary (book IDs, directory paths). Derived details—like AudiobookBay links—are generated in the controller/view layer at render time.
- Admin UI lives at **`/admin/library-repair`** (linked in the admin navbar). It defaults to showing pending issues with a "Show resolved" checkbox, includes inline book edit shortcuts, and exposes per-issue **Rescan** plus **Import Missing Directory** actions (the latter replaces the old generic "Mark resolved" button for missing directories).
- Missing-directory issues display dynamic AudiobookBay search links that are built client-side from normalized author/title data.
- Issues detected on books automatically flag them for review; staff can clear or retain reasons from the book edit form.
- Use `php artisan library:repair-scan --help` for all options (issue filters, JSON output, disabling auto-fixes). Running the scan also clears stale issues where books have been removed.
- Database setup: run `php artisan migrate` to create the `library_repair_issues` table (book_id, issue_type, status, directory_path, metadata JSON, auto_resolved flag, resolved_at, resolution_notes). The table is required for both the CLI command and the admin UI.
- For production and self-hosted installs, configure the scheduler and queue worker described in
  [Cross-platform installation](INSTALLATION.md#required-background-processes). That section
  also documents the scheduled Library Repair scan and other required or optional maintenance jobs.
