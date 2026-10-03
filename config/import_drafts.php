<?php

declare(strict_types=1);

/*
 * imports.v1 draft workflow used by the Kotlin desktop and terminal importers.
 * Disabled by default so a deploy changes nothing until an operator opts in.
 */
return [
    'enabled' => (bool) env('IMPORT_DRAFTS_ENABLED', false),

    'contract_version' => 'imports.v1',

    'observation_schema_versions' => [1],

    // Transfer modes offered to clients. shared_stage stays off until staging targets exist.
    'transfer_modes' => ['upload'],

    'draft_ttl_days' => (int) env('IMPORT_DRAFT_TTL_DAYS', 7),

    'idempotency_ttl_hours' => 24,

    'max_files_per_draft' => (int) env('IMPORT_DRAFT_MAX_FILES', 2000),

    'max_artifact_bytes' => 15 * 1024 * 1024,

    'max_inline_text_bytes' => 256 * 1024,

    'max_media_observation_bytes' => 256 * 1024,

    'max_upload_chunk_bytes' => 16 * 1024 * 1024,

    // Private staging area for resumable uploads (never a client-supplied path).
    // Bytes live at {staging_root}/{draft public id}/{file row id}.part.
    'staging_root' => env('IMPORT_DRAFT_STAGING_ROOT', storage_path('app/import-staging')),

    // Staged bytes of cancelled, failed or expired drafts are removed this long after
    // the draft last changed (imports:purge-staging, scheduled hourly).
    'staging_retention_hours' => (int) env('IMPORT_DRAFT_STAGING_RETENTION_HOURS', 24),

    // A transfer_progress event is recorded each time a file crosses this percentage step.
    'progress_event_percent_step' => 5,

    'accepted_audio_extensions' => [
        'mp3', 'm4a', 'm4b', 'flac', 'ogg', 'oga', 'wav', 'aac', 'wma', 'm4p', 'mp4', 'opus',
    ],

    'page_size' => 50,

    'event_page_size' => 100,

    // Queue for InterpretImportDraftJob; null uses the default queue.
    'interpretation_queue' => env('IMPORT_DRAFT_INTERPRETATION_QUEUE'),

    // Uses BookImportService::processWithAI with client-observed tags and paths.
    // Explicitly enabled on installations with a configured AI provider.
    'ai_enabled' => (bool) env('IMPORT_DRAFTS_AI_ENABLED', false),

    // Optional external metadata lookup (Audible/Google Books/Hardcover) during
    // interpretation. Off by default; failures never block a draft.
    'enrichment' => [
        'enabled' => (bool) env('IMPORT_DRAFTS_ENRICHMENT_ENABLED', false),
        'sources' => ['audible', 'google_books', 'hardcover'],
    ],

    // The server may ask the client for a short audio sample when tags, NFO and online lookups leave the
    // title or author unproven. Opt-in; needs ai_enabled, a client that advertises `audio_snippet`, and the
    // additive `evidence_requests` column (php artisan migrate).
    'audio_evidence' => [
        'enabled' => (bool) env('IMPORT_DRAFTS_AUDIO_EVIDENCE_ENABLED', false),
        // A request nobody answers in this time is closed and interpretation finishes without audio.
        'request_ttl_seconds' => (int) env('IMPORT_DRAFTS_AUDIO_EVIDENCE_TTL', 300),
        'snippet_seconds' => 20,
        'max_bytes' => 2 * 1024 * 1024,
        'media_types' => ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav', 'audio/webm'],
        // A title/author is "proven" by a source at or above this confidence that is neither the folder name
        // nor the AI's own guess.
        'proven_confidence' => 0.75,
    ],

    // POST /imports/discoveries: names and sizes only, bounded so one request stays small.
    'discovery' => [
        'max_entries' => (int) env('IMPORT_DISCOVERY_MAX_ENTRIES', 20000),
        'max_selections' => 200,
        'max_tags' => 600,
    ],

    'max_duplicate_candidates' => 5,

    // File operations a plan may choose, per transfer mode; the first is the default.
    // Upload clients send copy (originals stay on the user's computer). in_place is
    // only for server-approved shared staging targets.
    'file_operations' => [
        'upload' => ['copy', 'move'],
        'shared_stage' => ['move', 'copy', 'in_place'],
    ],
];
