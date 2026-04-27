<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third-Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third-party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'cdn' => [
        'enabled' => env('CDN_ENABLED', false),
        'url' => env('CDN_URL', 'https://cdn.example.com'),
        'path' => env('CDN_PATH', 'assets'),
        'cache_ttl' => env('CDN_CACHE_TTL', 86400),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'allowed_client_ids' => array_values(array_filter([
            env('GOOGLE_CLIENT_ID'),
            env('GOOGLE_CLIENT_ID_ANDROID_LIBRARY'),
            env('GOOGLE_CLIENT_ID_ANDROID_PLAYER'),
            env('GOOGLE_CLIENT_ID_ANDROID_LIBRARY_DEV'),
            env('GOOGLE_CLIENT_ID_ANDROID_PLAYER_DEV'),
        ])),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/login/google/callback'),
        'api_key' => env('GOOGLE_API_KEY'),
        'search_engine_id' => env('GOOGLE_SEARCH_ENGINE_ID'),
    ],

    'googlebooks' => [
        'key' => env('GOOGLE_BOOKS_API_KEY'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI'),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'allowed_client_ids' => array_values(array_filter([
            env('APPLE_CLIENT_ID'),
            env('APPLE_CLIENT_ID_IOS'),
            env('APPLE_CLIENT_ID_ANDROID'),
        ])),
        'client_secret' => env('APPLE_CLIENT_SECRET'),
        'redirect' => env('APPLE_REDIRECT_URI'),
    ],

    'discord' => [
        'client_id' => env('DISCORD_CLIENT_ID'),
        'client_secret' => env('DISCORD_CLIENT_SECRET'),
        'redirect' => env('DISCORD_REDIRECT_URI'),
    ],

    'audiobook_bay' => [
        'base_url' => env('AUDIOBOOK_BAY_BASE_URL'),
        'username' => env('AUDIOBOOK_BAY_USERNAME'),
        'password' => env('AUDIOBOOK_BAY_PASSWORD'),
        'user_agent' => env('AUDIOBOOK_BAY_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ' .
            'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash-lite'),
        'timeout' => env('GEMINI_TIMEOUT', 30),
        'paid_tier' => env('GEMINI_PAID_TIER', false),
    ],

    'claude' => [
        'api_key' => env('CLAUDE_API_KEY'),
        'model' => env('CLAUDE_MODEL', 'claude-3-5-haiku'),
        'timeout' => env('CLAUDE_TIMEOUT', 30),
        'paid_tier' => true,
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'timeout' => env('OPENAI_TIMEOUT', 30),
        'paid_tier' => true,
        'organization' => env('OPENAI_ORGANIZATION'),
    ],

    'ai' => [
        'default_model' => env('AI_DEFAULT_MODEL', 'gemini-2.5-flash-lite'),
        'default_provider' => env('AI_DEFAULT_PROVIDER', 'gemini'),
    ],

];
