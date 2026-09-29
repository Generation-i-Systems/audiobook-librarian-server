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

    'accepted_audio_extensions' => [
        'mp3', 'm4a', 'm4b', 'flac', 'ogg', 'oga', 'wav', 'aac', 'wma', 'm4p', 'mp4', 'opus',
    ],

    'page_size' => 50,
];
