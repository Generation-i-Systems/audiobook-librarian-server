<?php

declare(strict_types=1);

// CRITICAL: Run database safety check BEFORE Laravel bootstrap
require_once __DIR__ . '/database-safety-check.php';

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withKernels([
        Illuminate\Contracts\Http\Kernel::class => App\Http\Kernel::class,
        Illuminate\Contracts\Console\Kernel::class => App\Console\Kernel::class,
    ])
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
            ->appendOutputTo(storage_path('logs/backup-cron.log'))
        ;

        // Run database backup weekly with extra verification on Sundays at 3:00 AM
        $schedule->command('backup:database --verify')
            ->weeklyOn(0, '03:00') // Sunday at 3:00 AM
            ->appendOutputTo(storage_path('logs/backup-cron.log'))
        ;

        // Compress log files older than 1 day, daily at 1:00 AM
        $schedule->command('logs:compress')
            ->dailyAt('01:00')
            ->appendOutputTo(storage_path('logs/log-compression.log'))
        ;

        // Rotate logs at midnight
        $schedule->command('log:rotate')
            ->dailyAt('00:00')
            ->timezone(config('app.timezone'))
            ->onOneServer()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/log-rotation.log'))
        ;

        // Clean up old log files (keep last 14 days)
        $schedule->command('log:clear --keep-last=14')
            ->daily()
            ->appendOutputTo(storage_path('logs/log-cleanup.log'))
        ;

        // Fix storage permissions every hour to ensure proper access
        //$schedule->command('storage:fix-permissions')
        //         ->hourly()
        //         ->appendOutputTo(storage_path('logs/permissions-fix.log'));

        // Validate book directories daily at 3:00 AM
        $schedule->command('books:validate-directories')
            ->dailyAt('03:00')
            ->appendOutputTo(storage_path('logs/directory-validation.log'))
        ;

        // Scrape AudiobookBay categories for favorite authors daily at 4:00 AM
        $schedule->command('abb:scrape-categories')
            ->dailyAt('04:00')
            ->appendOutputTo(storage_path('logs/abb-scraping.log'))
        ;

        // Send daily email notifications for new books by favorite authors at 8:00 AM
        $schedule->command('favorites:send-notifications')
            ->dailyAt('08:00')
            ->appendOutputTo(storage_path('logs/favorite-notifications.log'))
        ;

        // Scan audiobook library for directory issues every night at 5:00 AM
        $schedule->command('library:repair-scan --issue=missing_directory --issue=duplicate_directory --issue=orphan_directory --issue=nested_audio')
            ->dailyAt('05:00')
            ->appendOutputTo(storage_path('logs/library-repair.log'))
        ;
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'admin/adminer*',
        ]);

        $middleware->api([
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Session\Middleware\StartSession::class,
        ]);

        $middleware->alias([
            'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
            'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
            'can' => \Illuminate\Auth\Middleware\Authorize::class,
            'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
            'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
            'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            'admin' => \App\Http\Middleware\CheckAdminRole::class,
            'standard' => \App\Http\Middleware\RequireLibraryRole::class,
            'library' => \App\Http\Middleware\RequireLibraryRole::class,
            'active' => \App\Http\Middleware\EnsureActiveUser::class,
            // Firebase auth middleware removed - application now uses MySQL-only authentication
            'api.auth' => \App\Http\Middleware\ApiAuth::class,
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
                return response()->json([
                    'error' => true,
                    'message' => $e->getMessage(),
                    'code' => $e->getCode() ?: 500,
                ], method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500);
            }
        });

        // Prevent infinite loops when logging fails due to permission issues
        $exceptions->report(function (\Throwable $e) {
            // Check for logging-related exceptions that could cause infinite loops
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
                return false; // Don't report this exception to prevent infinite loop
            }

            // Handle general file operation exceptions in logs directory
            if (
                (str_contains($message, 'fopen') ||
                    str_contains($message, 'Permission denied') ||
                    str_contains($message, 'No such file or directory')) &&
                (str_contains($message, 'logs') || str_contains($file, 'logs'))
            ) {
                return false; // Don't report this exception to prevent infinite loop
            }

            // Handle ErrorException for file operations
            if (
                $e instanceof \ErrorException &&
                (str_contains($message, 'Permission denied') ||
                    str_contains($message, 'No such file or directory')) &&
                (str_contains($message, 'logs') || str_contains($file, 'logs'))
            ) {
                return false; // Don't report this exception to prevent infinite loop
            }

            return null; // Report all other exceptions normally
        });

        // Override default logging behavior to prevent cascading failures
        $exceptions->reportable(function (\Throwable $e) {
            // For critical logging failures, try to report to stderr instead
            $message = $e->getMessage();

            if (
                (str_contains($message, 'logs') &&
                    str_contains($message, 'Permission denied')) ||
                (str_contains($message, 'could not be opened in append mode'))
            ) {
                // Try to write to stderr as a last resort
                try {
                    error_log('Laravel logging failure: ' . $message);
                } catch (\Throwable $ignored) {
                    // If even stderr fails, give up silently
                }

                return false;
            }
        });
    })->create();
