<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * CRITICAL SAFETY TEST
 *
 * This test MUST run first to ensure tests are running on SQLite, not MySQL.
 * If this test fails, it means tests are configured to use production MySQL
 * and could wipe the database.
 *
 * This test should be placed at the top of any test suite or configured
 * to run first in PHPUnit.
 */
class DatabaseSafetyCheckTest extends BaseTestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\Group('critical')]
    #[\PHPUnit\Framework\Attributes\Group('database-safety')]
    public function testEnsuresTestsUseSqliteNotMysql()
    {
        // Check .env.testing file exists and has correct settings
        $envTestingPath = __DIR__ . '/../../.env.testing';
        $this->assertFileExists(
            $envTestingPath,
            "CRITICAL SAFETY FAILURE: .env.testing file not found! " .
            "This file is required to ensure tests use SQLite."
        );

        $envContent = file_get_contents($envTestingPath);

        // Check for SQLite configuration
        $this->assertStringContainsString(
            'DB_CONNECTION=sqlite',
            $envContent,
            "CRITICAL SAFETY FAILURE: .env.testing does not contain DB_CONNECTION=sqlite! " .
            "Tests might be using MySQL instead."
        );

        $this->assertStringContainsString(
            'DB_DATABASE=:memory:',
            $envContent,
            "CRITICAL SAFETY FAILURE: .env.testing does not contain DB_DATABASE=:memory:! " .
            "Tests might be using persistent database instead of in-memory SQLite."
        );

        // Check for MySQL connection disabling
        $this->assertStringContainsString(
            'DB_HOST=',
            $envContent,
            "CRITICAL SAFETY FAILURE: .env.testing does not disable MySQL host! " .
            "MySQL connection must be disabled in tests."
        );

        // Check DocumentStore driver
        $this->assertStringContainsString(
            'DOCUMENT_STORE_DRIVER=sqlite',
            $envContent,
            "CRITICAL SAFETY FAILURE: .env.testing does not contain DOCUMENT_STORE_DRIVER=sqlite! " .
            "DocumentStore should use SQLite in tests."
        );

        // Check phpunit.xml configuration
        $phpunitXmlPath = __DIR__ . '/../../phpunit.xml';
        $this->assertFileExists(
            $phpunitXmlPath,
            "CRITICAL SAFETY FAILURE: phpunit.xml not found! " .
            "This file is required to configure test environment."
        );

        $phpunitContent = file_get_contents($phpunitXmlPath);

        // Check for SQLite configuration in phpunit.xml
        $this->assertStringContainsString(
            'DB_CONNECTION',
            $phpunitContent,
            "CRITICAL SAFETY FAILURE: phpunit.xml does not contain DB_CONNECTION setting!"
        );

        $this->assertStringContainsString(
            'sqlite',
            $phpunitContent,
            "CRITICAL SAFETY FAILURE: phpunit.xml does not configure SQLite!"
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\Group('critical')]
    #[\PHPUnit\Framework\Attributes\Group('database-safety')]
    public function testVerifiesTestEnvironmentIsolation()
    {
        // Check that .env.testing has array drivers
        $envTestingPath = __DIR__ . '/../../.env.testing';
        $envContent = file_get_contents($envTestingPath);

        $this->assertStringContainsString(
            'CACHE_STORE=array',
            $envContent,
            "Cache driver should be 'array' in tests for isolation."
        );

        $this->assertStringContainsString(
            'SESSION_DRIVER=array',
            $envContent,
            "Session driver should be 'array' in tests for isolation."
        );
    }
}
