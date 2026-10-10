<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AppRefreshCommandTest extends TestCase
{
    private const BOOTSTRAP_CACHE_ENV = [
        'APP_PACKAGES_CACHE' => 'packages.php',
        'APP_SERVICES_CACHE' => 'services.php',
        'APP_CONFIG_CACHE' => 'config.php',
        'APP_ROUTES_CACHE' => 'routes-v7.php',
        'APP_EVENTS_CACHE' => 'events.php',
    ];

    private string $isolatedBootstrapCachePath;

    private string $isolatedViewCachePath;

    protected function setUp(): void
    {
        // app:refresh runs optimize:clear, which deletes bootstrap/cache/packages.php
        // and services.php. Those are shared by every parallel worker: a worker that
        // boots while they are missing loads no package providers (e.g. laravel/ui,
        // so Auth::routes() throws). Point this test's app at its own cache files
        // before it is created so the shared ones are never touched.
        $this->isolatedBootstrapCachePath = dirname(__DIR__, 2)
            . '/storage/framework/testing/app-refresh-test-bootstrap-' . getmypid();
        if (!is_dir($this->isolatedBootstrapCachePath)) {
            mkdir($this->isolatedBootstrapCachePath, 0775, true);
        }
        foreach (self::BOOTSTRAP_CACHE_ENV as $key => $file) {
            $this->setEnvironmentValue($key, $this->isolatedBootstrapCachePath . '/' . $file);
        }

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

        parent::tearDown();

        foreach (array_keys(self::BOOTSTRAP_CACHE_ENV) as $key) {
            $this->setEnvironmentValue($key, null);
        }
        foreach (glob($this->isolatedBootstrapCachePath . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->isolatedBootstrapCachePath);
    }

    private function setEnvironmentValue(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);

            return;
        }

        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
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
