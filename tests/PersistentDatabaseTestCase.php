<?php

namespace Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

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

        // This project must NEVER run tests against MySQL.
        // Keep a persistent schema setup (no RefreshDatabase) while still using sqlite.
        if (!static::$databaseConfigured) {
            Artisan::call('migrate:fresh');

            DB::disconnect();
            DB::reconnect();
            static::$databaseConfigured = true;
        }
    }
}
