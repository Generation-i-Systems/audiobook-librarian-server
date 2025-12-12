<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Hardcover API Configuration
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for the Hardcover API.
    |
    */

    'api_url' => env('HARDCOVER_API_URL', 'https://api.hardcover.app/v1/graphql'),
    'api_token' => env('HARDCOVER_API_TOKEN', env('HARDCOVER_API_KEY')), // Support both variable names
    'token_expires_at' => env('HARDCOVER_TOKEN_EXPIRES_AT'),
    'notification_email' => env('HARDCOVER_NOTIFICATION_EMAIL', env('MAIL_FROM_ADDRESS')),
];
