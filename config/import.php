<?php

return [
    // Root directories allowed for import browsing (absolute paths)
    'roots' => [
        env('IMPORT_ROOT_1', storage_path('import')),
        // Add more roots as needed, e.g. env('IMPORT_ROOT_2')
    ],
    // Allowed file extensions for import
    'allowed_extensions' => [
        'mp3', 'm4b', 'm4a', 'aac', 'flac', 'ogg', 'wav', 'zip', 'rar', '7z', 'pdf', 'epub', 'txt',
    ],

];
