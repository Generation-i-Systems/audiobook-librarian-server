<?php

return [
    // Root directories allowed for import browsing (absolute paths)
    'roots' => [
        // Default import root
        env('IMPORT_ROOT_1', storage_path('import')),
        // User-specified roots
        '/media/download',
        '/media/download/audiobooks',
        '/media/audiobooks/unsorted/*',
        '/media/audiobooks/OpenAudible/books',
    ],
    // Allowed file extensions for import
    'allowed_extensions' => [
        'mp3',
        'm4b',
        'm4a',
        'aac',
        'flac',
        'ogg',
        'wav',
        'zip',
        'rar',
        '7z',
        'pdf',
        'epub',
        'txt',
    ],

];
