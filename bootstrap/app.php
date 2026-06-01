<?php

declare(strict_types=1);

// CRITICAL: Run database safety check BEFORE Laravel bootstrap
require_once __DIR__ . '/database-safety-check.php';

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function ($schedule): void {
        // Run database backup nightly at 2:00 AM
        $schedule->command('backup:database --verify')
            ->dailyAt('02:00')
            ->appendOutputTo(storage_path('logs/backup-cron.log'));

        // Run database backup weekly with extra verification on Sundays at 3:00 AM
        $schedule->command('backup:database --verify')
            ->weeklyOn(0, '03:00')
            ->appendOutputTo(storage_path('logs/backup-cron.log'));

        // Compress log files older than 1 day, daily at 1:00 AM
        $schedule->command('logs:compress')
            ->dailyAt('01:00')
            ->appendOutputTo(storage_path('logs/log-compression.log'));

        // Rotate logs at midnight
        $schedule->command('log:rotate')
            ->dailyAt('00:00')
            ->timezone(config('app.timezone'))
            ->onOneServer()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/log-rotation.log'));

        // Clean up old log files (keep last 14 days)
        $schedule->command('log:clear --keep-last=14')
            ->daily()
            ->appendOutputTo(storage_path('logs/log-cleanup.log'));

        // Fix storage permissions every hour to ensure proper access
        $schedule->command('storage:fix-permissions')
            ->hourly()
            ->appendOutputTo(storage_path('logs/permissions-fix.log'));

        // Validate book directories daily at 3:00 AM
        $schedule->command('books:validate-directories')
            ->dailyAt('03:00')
            ->appendOutputTo(storage_path('logs/directory-validation.log'));

        // Scrape AudiobookBay categories for favorite authors daily at 4:00 AM
        $schedule->command('abb:scrape-categories')
            ->dailyAt('04:00')
            ->appendOutputTo(storage_path('logs/abb-scraping.log'));

        // Send daily email notifications for new books by favorite authors at 8:00 AM
        $schedule->command('favorites:send-notifications')
            ->dailyAt('08:00')
            ->appendOutputTo(storage_path('logs/favorite-notifications.log'));

        // Scan audiobook library for directory issues every night at 5:00 AM
        $schedule->command('library:repair-scan --issue=missing_directory --issue=nested_audio --issue=duplicate_directory --issue=orphan_directory')
            ->dailyAt('05:00')
            ->appendOutputTo(storage_path('logs/library-repair.log'));

        // Process import queue daily at 2:30 AM (after backup, before other maintenance)
        $schedule->command('imports:process-queue --limit=50')
            ->dailyAt('02:30')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/import-queue.log'));

        // Cleanup old completed imports monthly (keep 30 days)
        $schedule->command('imports:process-queue --cleanup --cleanup-days=30')
            ->monthlyOn(1, '03:30')
            ->appendOutputTo(storage_path('logs/import-queue.log'));

        // Close orphaned listening sessions daily at 3:30 AM
        $schedule->command('sessions:close-orphaned')
            ->dailyAt('03:30')
            ->appendOutputTo(storage_path('logs/orphaned-sessions.log'));

        // Sync LibriVox catalog daily at 6:00 AM (delta after first full sync)
        $schedule->command('librivox:sync --language=English')
            ->dailyAt('06:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/librivox-sync.log'));
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(\App\Http\Middleware\ResolveLibraryProfileFromHost::class);

        $middleware->validateCsrfTokens(except: [
            'admin/adminer*',
        ]);

        $middleware->alias([
            'admin' => \App\Http\Middleware\CheckAdminRole::class,
            'standard' => \App\Http\Middleware\RequireLibraryRole::class,
            'library' => \App\Http\Middleware\RequireLibraryRole::class,
            'active' => \App\Http\Middleware\EnsureActiveUser::class,
            'api.auth' => \App\Http\Middleware\ApiAuth::class,
            'idempotency' => \App\Http\Middleware\CheckIdempotency::class,
            'device.identify' => \App\Http\Middleware\IdentifyDevice::class,
        ]);
    })
    ->withProviders([
        \App\Providers\BookParserServiceProvider::class,
        \App\Providers\GalleryServiceProvider::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        // Ensure API routes return JSON errors
        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                $statusCode = 500;
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    return response()->json([
                        'error' => true,
                        'message' => $e->getMessage(),
                        'errors' => $e->errors(),
                    ], 422);
                }

                if (method_exists($e, 'getStatusCode')) {
                    $statusCode = $e->getStatusCode();
                }

                return response()->json([
                    'error' => true,
                    'message' => $e->getMessage(),
                    'code' => $e->getCode() ?: $statusCode,
                ], $statusCode);
            }
        });

        // Prevent infinite loops when logging fails due to permission issues
        $exceptions->report(function (\Throwable $e) {
            $message = $e->getMessage();
            $file = $e->getFile();

            // Handle Monolog StreamHandler exceptions
            if (
                $e instanceof \UnexpectedValueException &&
                (str_contains($message, 'could not be opened in append mode') ||
                    str_contains($message, 'Permission denied') ||
                    str_contains($message, 'No such file or directory')) &&
                str_contains($message, 'logs')
            ) {
                return false;
            }

            // Handle general file operation exceptions in logs directory
            if (
                (str_contains($message, 'fopen') ||
                    str_contains($message, 'Permission denied') ||
                    str_contains($message, 'No such file or directory')) &&
                (str_contains($message, 'logs') || str_contains($file, 'logs'))
            ) {
                return false;
            }

            // Handle ErrorException for file operations
            if (
                $e instanceof \ErrorException &&
                (str_contains($message, 'Permission denied') ||
                    str_contains($message, 'No such file or directory')) &&
                (str_contains($message, 'logs') || str_contains($file, 'logs'))
            ) {
                return false;
            }

            return null;
        });
    })
    ->create();
