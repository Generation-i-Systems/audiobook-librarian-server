<?php

declare(strict_types=1);

namespace App\Console\Commands;

use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\Process;

class AppRefreshCommand extends Command
{
    protected $signature = 'app:refresh
        {--no-migrate : Skip migrations}
        {--no-build : Skip the frontend build entirely}
        {--force-build : Force the frontend build even when nothing relevant changed}
        {--no-queue-restart : Skip signaling queue workers to restart}
        {--no-composer-install : Skip composer install (dependencies will not be updated)}
        {--no-autoload : Skip composer dump-autoload (only relevant when --no-composer-install is also set)}
        {--no-permissions : Skip repairing writable storage/cache directory permissions}
        {--writable-group= : Group that should own writable directories (default: APP_REFRESH_WRITABLE_GROUP or www-data when available)}
        {--production : Re-cache config/route/event/view for production after clearing}
        {--no-opcache : Skip OPcache reset}
        {--no-fpm-reload : Skip the php-fpm reload step}
        {--fpm-service= : Override the php-fpm systemd service name (auto-detected by default)}';

    protected $description = 'Clear caches, run migrations, restart queues, and rebuild frontend assets when needed. Safe to run after code changes or post-deploy.';

    public function handle(): int
    {
        $this->components->info('Starting application refresh');

        $this->clearCaches();

        if (! $this->option('no-permissions')) {
            $this->repairWritableDirectoryPermissions();
        }

        if (! $this->option('no-composer-install')) {
            $this->runComposerInstall();
        } elseif (! $this->option('no-autoload')) {
            $this->dumpAutoload();
        }

        if (! $this->option('no-migrate')) {
            $this->runMigrations();
        } else {
            $this->components->warn('Skipping migrations (--no-migrate)');
        }

        $this->buildFrontendIfNeeded();

        if ($this->shouldReCacheForProduction()) {
            $this->reCacheForProduction();
        }

        if (! $this->option('no-queue-restart')) {
            $this->restartQueueWorkers();
        }

        if (! $this->option('no-opcache')) {
            $this->resetOpcache();
        }

        if (! $this->option('no-fpm-reload')) {
            $this->reloadPhpFpm();
        }

        $this->components->info('Application refresh complete');

        return self::SUCCESS;
    }

    private function clearCaches(): void
    {
        $this->components->task('Clearing caches', function (): void {
            // optimize:clear runs config/route/view/event/compiled/cache clears in one shot.
            Artisan::call('optimize:clear', [], $this->getOutput());
        });
    }

    private function repairWritableDirectoryPermissions(): void
    {
        $this->components->task('Repairing writable directory permissions', function (): bool {
            $paths = $this->writablePaths();
            $group = $this->writableGroup();

            foreach ($paths as $path) {
                if (! is_dir($path) && ! @mkdir($path, 02775, true) && ! is_dir($path)) {
                    $this->components->warn("Unable to create writable directory: {$path}");

                    continue;
                }

                $this->repairPathPermissions($path, $group);
            }

            return true;
        });
    }

    /**
     * @return list<string>
     */
    private function writablePaths(): array
    {
        $paths = [
            storage_path('app'),
            storage_path('app/private'),
            storage_path('app/public'),
            storage_path('framework'),
            storage_path('framework/cache'),
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('framework/testing'),
            storage_path('framework/views'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ];

        return array_values(array_unique($paths));
    }

    private function writableGroup(): ?string
    {
        $option = $this->option('writable-group');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        $configured = getenv('APP_REFRESH_WRITABLE_GROUP');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return $this->groupExists('www-data') ? 'www-data' : null;
    }

    private function groupExists(string $group): bool
    {
        if (function_exists('posix_getgrnam')) {
            return posix_getgrnam($group) !== false;
        }

        return true;
    }

    private function repairPathPermissions(string $path, ?string $group): void
    {
        $this->repairSingleFilesystemEntry($path, $group);

        if (! is_readable($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
            RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo) {
                $this->repairSingleFilesystemEntry($entry->getPathname(), $group);
            }
        }
    }

    private function repairSingleFilesystemEntry(string $path, ?string $group): void
    {
        if ($group !== null) {
            @chgrp($path, $group);
        }

        if (is_dir($path)) {
            @chmod($path, 02777);

            return;
        }

        if (is_file($path)) {
            @chmod($path, (fileperms($path) & 0777) | 0660);
        }
    }

    private function runComposerInstall(): void
    {
        $args = ['composer', 'install', '--no-interaction', '--optimize-autoloader'];

        if (app()->isProduction() || $this->option('production')) {
            $args[] = '--no-dev';
        }

        $this->components->task('composer install', function () use ($args): bool {
            return $this->runProcess($args, timeout: 300);
        });
    }

    private function dumpAutoload(): void
    {
        $this->components->task('composer dump-autoload -o', function (): bool {
            return $this->runProcess(['composer', 'dump-autoload', '-o', '--no-interaction'], timeout: 120);
        });
    }

    private function runMigrations(): void
    {
        $this->components->task('Migrating database', function (): void {
            Artisan::call('migrate', ['--force' => true], $this->getOutput());
        });
    }

    private function buildFrontendIfNeeded(): void
    {
        if ($this->option('no-build')) {
            $this->components->warn('Skipping frontend build (--no-build)');

            return;
        }

        $force = (bool) $this->option('force-build');

        if (! $force && ! $this->frontendChangesDetected()) {
            $this->components->info('No frontend-relevant changes detected — skipping npm build');

            return;
        }

        $this->components->task($force ? 'Forcing npm run build' : 'Running npm run build (changes detected)', function (): bool {
            return $this->runProcess(['npm', 'run', 'build'], timeout: 600);
        });
    }

    private function frontendChangesDetected(): bool
    {
        $manifest = base_path('public/build/manifest.json');

        if (! is_file($manifest)) {
            return true;
        }

        $manifestMtime = (int) filemtime($manifest);

        $watchPaths = [
            base_path('resources'),
            base_path('vite.config.js'),
            base_path('vite.config.ts'),
            base_path('package.json'),
            base_path('package-lock.json'),
        ];

        foreach ($watchPaths as $path) {
            if ($this->pathHasNewerFile($path, $manifestMtime)) {
                return true;
            }
        }

        return false;
    }

    private function pathHasNewerFile(string $path, int $threshold): bool
    {
        if (! file_exists($path)) {
            return false;
        }

        if (is_file($path)) {
            return (int) filemtime($path) > $threshold;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }
            if ($file->getMTime() > $threshold) {
                return true;
            }
        }

        return false;
    }

    private function shouldReCacheForProduction(): bool
    {
        if ($this->option('production')) {
            return true;
        }

        return app()->isProduction();
    }

    private function reCacheForProduction(): void
    {
        $this->components->task('Caching config/route/event/view for production', function (): void {
            Artisan::call('config:cache', [], $this->getOutput());
            Artisan::call('route:cache', [], $this->getOutput());
            Artisan::call('event:cache', [], $this->getOutput());
            Artisan::call('view:cache', [], $this->getOutput());
        });
    }

    private function restartQueueWorkers(): void
    {
        $this->components->task('Signaling queue workers to restart', function (): void {
            Artisan::call('queue:restart', [], $this->getOutput());
        });
    }

    private function resetOpcache(): void
    {
        if (! function_exists('opcache_reset')) {
            return;
        }

        $this->components->task('Resetting OPcache (CLI process)', function (): bool {
            return @opcache_reset() === true;
        });
    }

    private function reloadPhpFpm(): void
    {
        if (! $this->isLinux()) {
            return;
        }

        $service = $this->resolveFpmService();

        if ($service === null) {
            if ($this->option('fpm-service') !== null) {
                $this->components->warn('php-fpm service not found; skipping reload. Use --fpm-service to override or --no-fpm-reload to silence.');
            }

            return;
        }

        // Order matters: try `sudo -n` first because it short-circuits cleanly
        // when no NOPASSWD rule exists (no prompt). Plain `systemctl` is tried
        // last because on systems where the user has direct authority it works
        // without sudo, but on others it can trigger an interactive polkit
        // prompt. `-n` plus the explicit fallback gives us "passwordless or
        // skip" behaviour, never an interactive prompt.
        $attempts = [
            ['sudo', '-n', 'systemctl', 'reload', $service],
            ['systemctl', '--no-ask-password', 'reload', $service],
        ];

        foreach ($attempts as $command) {
            $process = new Process($command, base_path());
            $process->setTimeout(30);
            // Detach stdin so nothing can read from the terminal even if a
            // helper tries to prompt.
            $process->setInput('');
            $process->run();

            if ($process->isSuccessful()) {
                $this->components->info("Reloaded php-fpm service: {$service}");

                return;
            }
        }

        $this->components->warn("Could not reload php-fpm ({$service}) automatically without prompting. Add a NOPASSWD sudoers rule for the deploy user (e.g. `<user> ALL=(root) NOPASSWD: /bin/systemctl reload {$service}`) or run `sudo systemctl reload {$service}` manually. Pass --no-fpm-reload to silence this message.");
    }

    private function resolveFpmService(): ?string
    {
        $override = $this->option('fpm-service');

        if (is_string($override) && $override !== '') {
            return $override;
        }

        if (! $this->systemctlAvailable()) {
            return null;
        }

        // Try the version-qualified name first (matches installed PHP), then generic.
        $candidates = [
            sprintf('php%s.%s-fpm', PHP_MAJOR_VERSION, PHP_MINOR_VERSION),
            'php-fpm',
        ];

        foreach ($candidates as $candidate) {
            $process = new Process(['systemctl', 'list-unit-files', "{$candidate}.service", '--no-legend']);
            $process->setTimeout(5);
            $process->run();

            if ($process->isSuccessful() && trim($process->getOutput()) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function systemctlAvailable(): bool
    {
        $process = new Process(['command', '-v', 'systemctl']);
        $process->setTimeout(3);
        $process->run();

        if ($process->isSuccessful()) {
            return true;
        }

        // Fallback: probe common path.
        return is_executable('/usr/bin/systemctl') || is_executable('/bin/systemctl');
    }

    private function isLinux(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }

    /**
     * @param  array<int, string>  $command
     */
    private function runProcess(array $command, int $timeout = 60): bool
    {
        $process = new Process($command, base_path());
        $process->setTimeout($timeout);

        $process->run(function (string $type, string $buffer): void {
            $this->getOutput()->write($buffer);
        });

        return $process->isSuccessful();
    }
}
