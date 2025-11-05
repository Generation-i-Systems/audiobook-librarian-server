<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\SkippedWithMessageException;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // SAFETY CHECK: Prevent tests from using the real MySQL database
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($connection === 'mysql' && $database !== ':memory:' && $database !== 'testing' && $database !== 'test') {
            throw new SkippedWithMessageException(
                "SAFETY ALERT: Test attempted to use the real MySQL database '{$database}'! \n" .
                "This test needs to be fixed to use a test database or mock the DocumentStoreServiceInterface. \n" .
                "The test has been skipped to prevent data loss."
            );
        }

        // Force the dedicated 'testing' SQLite connection for tests so that
        // RefreshDatabase runs consistently without cross-connection conflicts
        if (!defined('ALLOW_MYSQL_IN_TESTS') || !ALLOW_MYSQL_IN_TESTS) {
            config(['database.default' => 'testing']);
        }

        // Ensure migrations are run for tests that do not use RefreshDatabase
        // This prevents "no such table" errors when hitting Eloquent models/routes.
        try {
            Artisan::call('migrate', ['--force' => true]);
        } catch (\Throwable $e) {
            // If migration fails in certain isolated tests, do not block the entire suite here.
            // Individual tests that rely on DB should still use RefreshDatabase.
        }
    }

    /**
     * Clean up the testing environment before the next test.
     */
    protected function tearDown(): void
    {
        // Force garbage collection to clean up PendingCommand objects
        // BEFORE parent::tearDown() destroys the application
        gc_collect_cycles();

        parent::tearDown();

        // Force another garbage collection AFTER teardown
        gc_collect_cycles();
    }
}
