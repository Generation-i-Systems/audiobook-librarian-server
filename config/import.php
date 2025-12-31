<?php

$importConfig = [
    // Root directories allowed for import browsing (absolute paths)
    'roots' => [
        '/media/downloads',
        '/media/downloads/audiobooks',
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

if (!empty(env('IMPORT_ROOT_1'))) {
    $importConfig['roots'][] = env('IMPORT_ROOT_1');
}

return $importConfig;
