<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        // CRITICAL: Run database safety check BEFORE any test (pre-bootstrap)
        $this->ensureDatabaseSafetyPreBootstrap();

        parent::setUp();

        // CRITICAL: Verify runtime DB connection is safe (post-bootstrap)
        $this->ensureDatabaseSafetyRuntime();

        // ISO-test: Isolate book storage for parallel tests
        $booksRoot = config('filesystems.disks.books.root');
        $parallelToken = $_SERVER['LARAVEL_PARALLEL_TESTING_TOKEN'] ?? $_ENV['LARAVEL_PARALLEL_TESTING_TOKEN'] ?? $_SERVER['TEST_TOKEN'] ?? $_ENV['TEST_TOKEN'] ?? null;
        if ($booksRoot === '/tmp/ab-librarian-test-books' && $parallelToken) {
            $booksRoot = "/tmp/ab-librarian-test-books-{$parallelToken}";
            config([
                'filesystems.disks.books.root' => $booksRoot,
                'app.book_root' => $booksRoot,
                'app.library_repair_sync_path' => "{$booksRoot}/sync",
            ]);
            // Purge disk to ensure the change is picked up
            \Illuminate\Support\Facades\Storage::forgetDisk('books');
        }

        $connection = DB::connection();
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $compiledPath = sys_get_temp_dir() . '/librarian_framework_views';
        if (!is_dir($compiledPath)) {
            mkdir($compiledPath, 0777, true);
        }
        config(['view.compiled' => $compiledPath]);

        // Clean directory for this specific process
        // Support both the default path and the isolated parallel paths
        if ($booksRoot === '/tmp/ab-librarian-test-books' || (str_starts_with($booksRoot, '/tmp/ab-librarian-test-books-') && $parallelToken)) {
            $this->cleanTestDirectory($booksRoot);
        }
    }

    /**
     * Clean up the testing environment before the next test.
     */
    protected function tearDown(): void
    {
        // Clean up books disk if it's using the testing path
        // Removed cleanup from tearDown as it's redundant with setUp and can cause race conditions
        // during fast parallel execution.

        // Force garbage collection to clean up PendingCommand objects
        // BEFORE parent::tearDown() destroys the application
        gc_collect_cycles();

        parent::tearDown();

        // Force another garbage collection AFTER teardown
        gc_collect_cycles();
    }

    private function cleanTestDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectoryRecursive($path) : unlink($path);
        }
    }

    private function deleteDirectoryRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectoryRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * CRITICAL: Ensure database safety before running any test
     *
     * This method runs before EVERY test to ensure tests are using SQLite,
     * not MySQL. This prevents database wipes even when running tests by name.
     */
    private function ensureDatabaseSafetyPreBootstrap(): void
    {
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

    private function ensureDatabaseSafetyRuntime(): void
    {
        $dbConnection = config('database.default');
        if ($dbConnection !== 'sqlite') {
            throw new \RuntimeException(
                "CRITICAL SAFETY FAILURE: runtime database.default is not sqlite (got '{$dbConnection}'). " .
                'Tests must never use MySQL. Database wipe prevented.'
            );
        }

        $driverName = DB::connection()->getDriverName();
        if ($driverName !== 'sqlite') {
            throw new \RuntimeException(
                "CRITICAL SAFETY FAILURE: runtime DB driver is not sqlite (got '{$driverName}'). " .
                'Tests must never use MySQL. Database wipe prevented.'
            );
        }
    }
}
