<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
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
        
        // Force SQLite for tests by default
        if (!defined('ALLOW_MYSQL_IN_TESTS') || !ALLOW_MYSQL_IN_TESTS) {
            config(['database.default' => 'sqlite']);
            config(['database.connections.sqlite.database' => ':memory:']);
        }
    }
}
