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

    /**
     * The pre-bootstrap check must remove cached config (it can force MySQL) but must leave the
     * shared package manifest alone: parallel workers boot at the same moment, and one that finds
     * packages.php/services.php deleted loads no package providers (laravel/ui → Auth::routes()).
     */
    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\Group('database-safety')]
    public function testPreBootstrapCheckRemovesCachedConfigButKeepsPackageManifest(): void
    {
        $root = sys_get_temp_dir() . '/db-safety-check-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($root . '/bootstrap/cache', 0775, true);
        copy(__DIR__ . '/../../bootstrap/database-safety-check.php', $root . '/bootstrap/database-safety-check.php');
        file_put_contents($root . '/.env.testing', "DB_CONNECTION=sqlite\nDB_DATABASE=:memory:\nDB_HOST=\n");

        $removed = ['config.php', 'routes-v7.php', 'routes.php', 'events.php'];
        $kept = ['packages.php', 'services.php'];
        foreach (array_merge($removed, $kept) as $file) {
            file_put_contents($root . '/bootstrap/cache/' . $file, '<?php return [];');
        }

        // The check only acts when argv[0] looks like a test runner.
        $runner = $root . '/phpunit-runner.php';
        file_put_contents($runner, '<?php require ' . var_export($root . '/bootstrap/database-safety-check.php', true) . ';');
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1', $output, $exitCode);

        $present = array_values(array_filter(
            array_merge($removed, $kept),
            fn (string $file): bool => is_file($root . '/bootstrap/cache/' . $file)
        ));

        foreach (glob($root . '/bootstrap/cache/*') ?: [] as $file) {
            unlink($file);
        }
        foreach ([$runner, $root . '/bootstrap/database-safety-check.php', $root . '/.env.testing'] as $file) {
            unlink($file);
        }
        rmdir($root . '/bootstrap/cache');
        rmdir($root . '/bootstrap');
        rmdir($root);

        $this->assertSame(['exit' => 0, 'present' => $kept], ['exit' => $exitCode, 'present' => $present], implode("\n", $output));
    }
}
