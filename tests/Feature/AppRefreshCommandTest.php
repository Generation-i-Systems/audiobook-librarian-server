<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AppRefreshCommandTest extends TestCase
{
    /** @var array<string, string> */
    private array $bootstrapCacheBackup = [];

    private string $isolatedViewCachePath;

    protected function setUp(): void
    {
        // Back up shared bootstrap cache files BEFORE parent::setUp() so we
        // capture the clean state. app:refresh runs compiled:clear which deletes
        // services.php and packages.php — shared between parallel workers.
        $this->snapshotBootstrapCache();

        parent::setUp();

        // The same sharing problem applies to compiled Blade views:
        // app:refresh's clearCaches() unconditionally runs optimize:clear,
        // which deletes every file in config('view.compiled') — normally
        // storage/framework/views, shared by every parallel worker.
        $this->isolatedViewCachePath = storage_path('framework/testing/app-refresh-test-views-' . getmypid());
        File::ensureDirectoryExists($this->isolatedViewCachePath);
        config(['view.compiled' => $this->isolatedViewCachePath]);

        $this->app->forgetInstance('blade.compiler');
        $this->app->forgetInstance('view.engine.resolver');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->isolatedViewCachePath);

        // Atomically restore the shared cache files before destroying the app so
        // other workers never see an absent or partially-written file.
        $this->restoreBootstrapCache();

        parent::tearDown();
    }

    private function snapshotBootstrapCache(): void
    {
        $dir = dirname(__DIR__, 2) . '/bootstrap/cache';

        foreach (['packages.php', 'services.php'] as $file) {
            $path = "{$dir}/{$file}";

            if (is_file($path)) {
                $this->bootstrapCacheBackup[$file] = file_get_contents($path);
            }
        }
    }

    private function restoreBootstrapCache(): void
    {
        $dir = dirname(__DIR__, 2) . '/bootstrap/cache';

        foreach ($this->bootstrapCacheBackup as $file => $content) {
            $path = "{$dir}/{$file}";
            $tmp = "{$path}.tmp." . getmypid();
            file_put_contents($tmp, $content);
            rename($tmp, $path);
        }
    }

    public function test_it_runs_with_all_steps_skipped(): void
    {
        $exit = Artisan::call('app:refresh', [
            '--no-migrate' => true,
            '--no-build' => true,
            '--no-queue-restart' => true,
            '--no-composer-install' => true,
            '--no-autoload' => true,
            '--no-opcache' => true,
        ]);

        $this->assertSame(0, $exit);
    }

    public function test_no_build_flag_short_circuits_build_step(): void
    {
        $exit = Artisan::call('app:refresh', [
            '--no-migrate' => true,
            '--no-build' => true,
            '--force-build' => true,
            '--no-queue-restart' => true,
            '--no-composer-install' => true,
            '--no-autoload' => true,
            '--no-opcache' => true,
        ]);

        $this->assertSame(0, $exit);
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('app:refresh', Artisan::all());
    }

    public function test_no_fpm_reload_flag_short_circuits_reload_step(): void
    {
        $exit = Artisan::call('app:refresh', [
            '--no-migrate' => true,
            '--no-build' => true,
            '--no-queue-restart' => true,
            '--no-composer-install' => true,
            '--no-autoload' => true,
            '--no-opcache' => true,
            '--no-fpm-reload' => true,
        ]);

        $this->assertSame(0, $exit);
    }

    public function test_refresh_repairs_storage_directory_permissions_after_clearing_caches(): void
    {
        $path = storage_path('framework/testing/app-refresh-permissions-test-' . getmypid());

        File::ensureDirectoryExists($path, 0755);
        chmod($path, 0755);

        try {
            $exit = Artisan::call('app:refresh', [
                '--no-migrate' => true,
                '--no-build' => true,
                '--no-queue-restart' => true,
                '--no-composer-install' => true,
                '--no-autoload' => true,
                '--no-opcache' => true,
                '--no-fpm-reload' => true,
                '--writable-group' => $this->currentGroupName(),
            ]);

            clearstatcache(true, $path);

            $this->assertSame(0, $exit);
            $this->assertDirectoryExists($path);
            $this->assertSame(0020, fileperms($path) & 0020);
            $this->assertSame(02000, fileperms($path) & 02000);
        } finally {
            File::deleteDirectory($path);
        }
    }

    private function currentGroupName(): string
    {
        if (function_exists('posix_getgrgid') && function_exists('posix_getgid')) {
            $group = posix_getgrgid(posix_getgid());

            if (is_array($group)) {
                return $group['name'];
            }
        }

        return '';
    }
}
