<?php

declare(strict_types=1);

return [
    /*
    | Who sends book file bytes once the API has checked the request:
    |   php               PHP streams the file (default; works behind any web server)
    |   x-accel-redirect  nginx (or Caddy, see docs/FILE_DELIVERY.md) serves it from an internal location
    |   x-sendfile        Apache mod_xsendfile or lighttpd serves it by absolute path
    | The web server must be set up for the chosen header, or clients get empty files.
    */
    'mode' => env('FILE_DELIVERY', 'php'),

    /*
    | x-accel-redirect only: the internal location that maps to the books disk root
    | (BOOK_STORAGE_PATH), e.g. nginx `location /_protected_books/ { internal; alias ...; }`.
    */
    'accel_prefix' => env('FILE_DELIVERY_ACCEL_PREFIX', '/_protected_books/'),
];
