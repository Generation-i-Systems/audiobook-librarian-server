<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Audit archive
    |--------------------------------------------------------------------------
    |
    | `audits:archive` exports audit rows older than `archive_after_days` to
    | compressed NDJSON files in `archive_path`, verifies each file, and only
    | then deletes exactly those rows from the database. Point `archive_path`
    | at storage that is NOT part of the nightly database backup (an external
    | or offline mount) — the whole point is to keep these rows out of the dumps.
    | `audits:restore` loads an archive file back.
    |
    */
    'archive_path' => (string) env('AUDIT_ARCHIVE_PATH', storage_path('app/audit-archive')),
    'archive_after_days' => (int) env('AUDIT_ARCHIVE_AFTER_DAYS', 30),
    'archive_chunk_size' => (int) env('AUDIT_ARCHIVE_CHUNK_SIZE', 5000),

    // Opt-in: run `audits:archive --force` weekly from the scheduler.
    'archive_scheduled' => (bool) env('AUDIT_ARCHIVE_SCHEDULED', false),
];
