<?php

/**
 * CRITICAL DATABASE SAFETY CHECK
 *
 * This bootstrap file runs BEFORE Laravel application bootstrap
 * to ensure tests are using SQLite, not MySQL.
 *
 * This prevents database wipes even when running tests by name.
 */

// ALWAYS run safety check when tests are being executed
$argv0 = (string) ($_SERVER['argv'][0] ?? '');
$argv1 = (string) ($_SERVER['argv'][1] ?? '');
$isTestRunner = (
    defined('PHPUNIT_COMPOSER_INSTALL') ||
    str_contains($argv0, 'phpunit') ||
    (str_contains($argv0, 'artisan') && $argv1 === 'test')
);

if ($isTestRunner) {
    // Force environment variables BEFORE Laravel boots.
    // Cached config can override env, so we also remove cached config below.
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';
    $_SERVER['APP_ENV'] = 'testing';

    putenv('DB_CONNECTION=sqlite');
    $_ENV['DB_CONNECTION'] = 'sqlite';
    $_SERVER['DB_CONNECTION'] = 'sqlite';

    putenv('DB_DATABASE=:memory:');
    $_ENV['DB_DATABASE'] = ':memory:';
    $_SERVER['DB_DATABASE'] = ':memory:';

    putenv('DB_HOST=');
    $_ENV['DB_HOST'] = '';
    $_SERVER['DB_HOST'] = '';

    putenv('DB_PORT=');
    $_ENV['DB_PORT'] = '';
    $_SERVER['DB_PORT'] = '';

    putenv('DB_USERNAME=');
    $_ENV['DB_USERNAME'] = '';
    $_SERVER['DB_USERNAME'] = '';

    putenv('DB_PASSWORD=');
    $_ENV['DB_PASSWORD'] = '';
    $_SERVER['DB_PASSWORD'] = '';

    putenv('DOCUMENT_STORE_DRIVER=sqlite');
    $_ENV['DOCUMENT_STORE_DRIVER'] = 'sqlite';
    $_SERVER['DOCUMENT_STORE_DRIVER'] = 'sqlite';

    putenv('CACHE_STORE=array');
    $_ENV['CACHE_STORE'] = 'array';
    $_SERVER['CACHE_STORE'] = 'array';

    putenv('SESSION_DRIVER=array');
    $_ENV['SESSION_DRIVER'] = 'array';
    $_SERVER['SESSION_DRIVER'] = 'array';

    putenv('QUEUE_CONNECTION=sync');
    $_ENV['QUEUE_CONNECTION'] = 'sync';
    $_SERVER['QUEUE_CONNECTION'] = 'sync';

    // Ensure no cached config can force MySQL during tests.
    $basePath = dirname(__DIR__);
    $cacheFiles = [
        $basePath . '/bootstrap/cache/config.php',
        $basePath . '/bootstrap/cache/routes-v7.php',
        $basePath . '/bootstrap/cache/routes.php',
        $basePath . '/bootstrap/cache/packages.php',
        $basePath . '/bootstrap/cache/services.php',
        $basePath . '/bootstrap/cache/events.php',
    ];
    foreach ($cacheFiles as $cacheFile) {
        if (!file_exists($cacheFile)) {
            continue;
        }

        if (!@unlink($cacheFile)) {
            throw new \RuntimeException(
                'CRITICAL SAFETY FAILURE: Unable to delete cached bootstrap file: ' . $cacheFile . '. ' .
                'Cached config can force MySQL during tests. Aborting.'
            );
        }
    }

    // Check .env.testing file exists and has correct settings
    $envTestingPath = __DIR__ . '/../.env.testing';
    if (!file_exists($envTestingPath)) {
        throw new \RuntimeException(
            "CRITICAL SAFETY FAILURE: .env.testing file not found! " .
            "Tests cannot proceed without proper SQLite configuration."
        );
    }

    $envContent = file_get_contents($envTestingPath);

    // Check for SQLite configuration
    if (strpos($envContent, 'DB_CONNECTION=sqlite') === false) {
        throw new \RuntimeException(
            "CRITICAL SAFETY FAILURE: .env.testing does not contain DB_CONNECTION=sqlite! " .
            "Tests might be using MySQL instead. Database wipe prevented."
        );
    }

    if (strpos($envContent, 'DB_DATABASE=:memory:') === false) {
        throw new \RuntimeException(
            "CRITICAL SAFETY FAILURE: .env.testing does not contain DB_DATABASE=:memory:! " .
            "Tests might be using persistent database instead of in-memory SQLite. Database wipe prevented."
        );
    }

    // Check for MySQL connection disabling
    if (strpos($envContent, 'DB_HOST=') === false) {
        throw new \RuntimeException(
            "CRITICAL SAFETY FAILURE: .env.testing does not disable MySQL host! " .
            "MySQL connection must be disabled in tests. Database wipe prevented."
        );
    }

    // Check DocumentStore driver
    if (strpos($envContent, 'DOCUMENT_STORE_DRIVER=sqlite') === false) {
        throw new \RuntimeException(
            "CRITICAL SAFETY FAILURE: .env.testing does not contain DOCUMENT_STORE_DRIVER=sqlite! " .
            "DocumentStore should use SQLite in tests. Database wipe prevented."
        );
    }
}
