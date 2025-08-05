<?php

namespace Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\SkippedTestError;

/**
 * Base test case for tests that need a persistent database
 * This class does NOT use RefreshDatabase trait, allowing data to persist between tests
 */
abstract class PersistentDatabaseTestCase extends TestCase
{

    /**
     * Indicates whether the default database has been configured.
     *
     * @var bool
     */
    protected static $databaseConfigured = false;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Define a constant to allow MySQL in this test case
        if (!defined('ALLOW_MYSQL_IN_TESTS')) {
            define('ALLOW_MYSQL_IN_TESTS', true);
        }

        // Use a persistent test database instead of in-memory
        config(['database.default' => 'mysql']);
        
        // Make sure we're using a test database, not production
        $database = config('database.connections.mysql.database');
        if (!str_contains($database, 'test') && !str_contains($database, 'testing')) {
            config(['database.connections.mysql.database' => 'testing']);
        }
        
        // Only run migrations once for the entire test suite
        if (!static::$databaseConfigured) {
            // Refresh the database connection to use the test database
            DB::purge();
            DB::reconnect();
            
            // Uncomment to run migrations only once per test suite
            // Artisan::call('migrate:fresh');
            
            static::$databaseConfigured = true;
        }
    }
}
