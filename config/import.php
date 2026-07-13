<?php

$configuredRoots = array_values(array_filter(array_map(
    static fn (string $path): string => trim($path),
    explode(',', (string) env('IMPORT_ROOTS', '')),
)));

$importConfig = [
    // Root directories allowed for import browsing (absolute paths)
    'roots' => $configuredRoots,
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
