<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default File Extensions
    |--------------------------------------------------------------------------
    |
    | These file extensions will be considered as book files when scanning
    | directories. Files with these extensions will be processed.
    |
    */
    'extensions' => [
        'mp3',
        'm4b',
        'm4a',
        'mp4',
        'ogg',
        'flac',
        'aac',
        'wav',
    ],

    /*
    |--------------------------------------------------------------------------
    | Minimum File Size
    |--------------------------------------------------------------------------
    |
    | Files smaller than this size (in bytes) will be ignored.
    | This helps filter out small or corrupted files.
    |
    */
    'min_file_size' => 1024 * 100, // 100KB

    /*
    |--------------------------------------------------------------------------
    | Maximum Directory Depth
    |--------------------------------------------------------------------------
    |
    | Maximum depth to scan when searching for book files.
    | Set to 0 for unlimited depth.
    |
    */
    'max_depth' => 10,

    /*
    |--------------------------------------------------------------------------
    | Excluded Directories
    |--------------------------------------------------------------------------
    |
    | Directories that match these patterns will be excluded from scanning.
    | Supports regex patterns.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Metadata Storage
    |--------------------------------------------------------------------------
    |
    | Configuration for storing book metadata. Only local JSON storage is supported.
    |
    */
    'local_metadata_filename' => 'librarian.json',

    /*
    |--------------------------------------------------------------------------
    | Excluded Directories
    |--------------------------------------------------------------------------
    |
    | Directories that match these patterns will be excluded from scanning.
    | Supports regex patterns.
    |
    */
    'exclude_dirs' => [
        '\\.', // Hidden directories
        '@eaDir', // Synology thumbnail directory
        'System Volume Information',
        '\\$RECYCLE\\.BIN',
        '\\..*', // All hidden files and directories
        '^sync$', // Sync directory (used for imports, not actual book storage)
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Output Format
    |--------------------------------------------------------------------------
    |
    | Default output format when saving results.
    | Supported: 'json', 'csv', 'sql'
    |
    */
    'default_output_format' => 'json',

    /*
    |--------------------------------------------------------------------------
    | Default Output Path
    |--------------------------------------------------------------------------
    |
    | Default path where the output file will be saved.
    |
    */
    'default_output_path' => storage_path('app/books_metadata.json'),

    /*
    |--------------------------------------------------------------------------
    | Database Table Name
    |--------------------------------------------------------------------------
    |
    | Table name to use when generating SQL output.
    |
    */
    'database_table' => 'books',

    /*
    |--------------------------------------------------------------------------
    | Metadata Fields
    |--------------------------------------------------------------------------
    |
    | List of metadata fields to extract and their default values.
    |
    */
    'metadata_fields' => [
        'title' => null,
        'author' => 'Unknown Author',
        'series' => null,
        'series_number' => null,
        'narrator' => null,
        'edition' => null,
        'year' => null,
        'genre' => null,
        'path' => null,
        'filename' => null,
        'file_extension' => null,
        'file_size' => 0,
        'file_modified' => 0,
        'full_path' => null,
    ],
];
