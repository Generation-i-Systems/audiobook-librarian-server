<?php

declare(strict_types=1);

$defaultBookStoragePath = (string) env('BOOK_STORAGE_PATH', storage_path('app/books'));

return [
    'default_profile' => env('LIBRARY_PROFILE_DEFAULT', 'main'),

    'fallback_to_default_on_unknown_host' => (bool) env('LIBRARY_PROFILE_FALLBACK_TO_DEFAULT', true),

    'profiles' => [
        'main' => [
            'hosts' => array_values(array_filter(array_map(
                static fn (string $host): string => trim($host),
                explode(',', (string) env('LIBRARY_PROFILE_MAIN_HOSTS', 'localhost,127.0.0.1'))
            ))),
            'database_connection' => env('LIBRARY_PROFILE_MAIN_DB_CONNECTION', env('DB_CONNECTION', 'sqlite')),
            'book_storage_path' => env('LIBRARY_PROFILE_MAIN_BOOK_STORAGE_PATH', $defaultBookStoragePath),
            'source_mode' => env('LIBRARY_PROFILE_MAIN_SOURCE_MODE', 'local'),
        ],

        'librivox' => [
            'hosts' => array_values(array_filter(array_map(
                static fn (string $host): string => trim($host),
                explode(',', (string) env('LIBRARY_PROFILE_LIBRIVOX_HOSTS', ''))
            ))),
            'database_connection' => env('LIBRARY_PROFILE_LIBRIVOX_DB_CONNECTION'),
            'book_storage_path' => env('LIBRARY_PROFILE_LIBRIVOX_BOOK_STORAGE_PATH', storage_path('app/librivox')),
            'source_mode' => env('LIBRARY_PROFILE_LIBRIVOX_SOURCE_MODE', 'librivox'),
        ],

        // hybrid: local library + LibriVox catalog combined into one seamless view.
        // source_mode options: 'local' | 'librivox' | 'hybrid'
        'hybrid' => [
            'hosts' => array_values(array_filter(array_map(
                static fn (string $host): string => trim($host),
                explode(',', (string) env('LIBRARY_PROFILE_HYBRID_HOSTS', ''))
            ))),
            'database_connection' => env('LIBRARY_PROFILE_HYBRID_DB_CONNECTION', env('DB_CONNECTION', 'sqlite')),
            'book_storage_path' => env('LIBRARY_PROFILE_HYBRID_BOOK_STORAGE_PATH', $defaultBookStoragePath),
            'source_mode' => env('LIBRARY_PROFILE_HYBRID_SOURCE_MODE', 'hybrid'),
        ],
    ],
];
