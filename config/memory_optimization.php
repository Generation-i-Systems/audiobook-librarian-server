<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Memory Optimization Settings
    |--------------------------------------------------------------------------
    |
    | These settings help prevent memory exhaustion in the book listing pages
    |
    */

    // Maximum items per page for different contexts
    'pagination_limits' => [
        'admin_books' => 10,    // Admin book list
        'public_books' => 12,   // Public book list
        'recent_books' => 5,    // Recent books widget
        'related_books' => 3,   // Related books on book detail page
    ],

    // Database query limits
    'relationship_limits' => [
        'authors_per_book' => 2,   // Max authors to load per book
        'genres_per_book' => 1,    // Max genres to load per book
        'series_per_book' => 1,    // Max series to load per book
    ],

    // Memory monitoring thresholds (in MB)
    'memory_thresholds' => [
        'warning' => 128,  // Log warning at 128MB
        'critical' => 256, // Log critical at 256MB
    ],

    // Enable aggressive memory optimization
    'aggressive_optimization' => env('MEMORY_AGGRESSIVE_MODE', true),
];
