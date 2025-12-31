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
$isTestRunner = (
    defined('PHPUNIT_COMPOSER_INSTALL') ||
    strpos($_SERVER['argv'][0] ?? '', 'phpunit') !== false ||
    strpos($_SERVER['argv'][0] ?? '', 'artisan') !== false &&
    (isset($_SERVER['argv'][1]) && $_SERVER['argv'][1] === 'test')
);

if ($isTestRunner) {
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

    // Force environment to testing
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';
    $_SERVER['APP_ENV'] = 'testing';
}
