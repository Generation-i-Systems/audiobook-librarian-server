<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Supported locales
|--------------------------------------------------------------------------
|
| Code => native name. A locale is only offered, accepted from a user, or
| matched from Accept-Language once it is listed here AND has a translation
| file at lang/<code>.json (UI strings keyed by their English text). Add a
| language by creating that file and listing it below.
|
*/

return [
    'default' => env('APP_LOCALE', 'en'),

    'supported' => [
        'en' => 'English',
    ],
];
