<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // All test configuration is handled by phpunit.xml
        // Database connection is set to 'testing' via phpunit.xml
        // Individual tests should use RefreshDatabase trait as needed
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
