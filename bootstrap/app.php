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
    ->withRouting([
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    ])
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
        $schedule->command('storage:fix-permissions')
            ->hourly()
            ->appendOutputTo(storage_path('logs/permissions-fix.log'))
        ;
        
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
        $schedule->command('library:repair-scan')
            ->dailyAt('05:00')
            ->appendOutputTo(storage_path('logs/library-repair.log'))
        ;
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'admin/editor*',
            'admin/manager*',
            'api/*',
            'test/*'
            'development/*'
            ]);
        $middleware->api([
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\Session\Middleware\AuthenticateSession::class,
        ]);
        $middleware->alias([
            'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
            'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
            'can' => \Illuminate\Auth\Middleware\Authorize::class,
            'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
            'throttle.api' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
            'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            'role' => \Illuminate\Routing\Middleware\SubstituteBindings::class,
            'standard' => \Illuminate\Routing\Middleware\TrimStrings::class,
            'library' => \App\Http\Middleware\RequireLibraryRole::class,
        ]);
        $middleware->api([
            \App\Http\Middleware\Api\Authenticate::class,
            \App\Http\Middleware\Api\EnsureEmailIsVerified::class,
        ]);
        $middleware->alias([
            'json.response' => \Laravel\Http\Middleware\PreventJsonResponse::class,
        ]);
        $middleware->web([
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\AuthenticateSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'admin/editor*',
            'admin/manager*',
            'api/*',
            'test/*',
            'development/*'
            ]);
        $middleware->alias([
            'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
            'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
            'can' => \Illuminate\Routing\Middleware\Authorize::class,
            'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
            'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            'role' => \Illuminate\Routing\Middleware\SubstituteBindings::class,
            'standard' => \Illuminate\Routing\Middleware\TrimStrings::class,
            'library' => \App\Http\Middleware\RequireLibraryRole::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'admin/editor*',
            'admin/manager*',
            'api/*',
            'test/*',
            'development/*',
            ]);
        $middleware->alias([
            'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
            'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
            'can' => \Illuminate\Routing\Middleware\Authorize::class,
            'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
            'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            'role' => \Illuminate\Routing\Middleware\SubstituteBindings::class,
            'standard' => \Illuminate\Routing\Middleware\TrimStrings::class,
            'library' => \App\Http\Middleware\RequireLibraryRole::class,
        ]);
    })
    ->create();