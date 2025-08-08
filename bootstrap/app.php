<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function ($schedule) {
        // Run database backup nightly at 2:00 AM
        $schedule->command('backup:database --verify')
                 ->dailyAt('02:00')
                 ->appendOutputTo(storage_path('logs/backup-cron.log'));
                 
        // Run database backup weekly with extra verification on Sundays at 3:00 AM
        $schedule->command('backup:database --verify')
                 ->weeklyOn(0, '03:00') // Sunday at 3:00 AM
                 ->appendOutputTo(storage_path('logs/backup-cron.log'));
                 
        // Compress log files older than 1 day, daily at 1:00 AM
        $schedule->command('logs:compress')
                 ->dailyAt('01:00')
                 ->appendOutputTo(storage_path('logs/log-compression.log'));
                 
        // Fix storage permissions every hour to ensure proper access
        //$schedule->command('storage:fix-permissions')
        //         ->hourly()
        //         ->appendOutputTo(storage_path('logs/permissions-fix.log'));
    })
    ->withMiddleware(function (Middleware $middleware) {
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
            'standard' => \App\Http\Middleware\RequireStandardRole::class,
            'firebase.auth' => \App\Http\Middleware\FirebaseAuth::class,
            'api.auth' => \App\Http\Middleware\ApiAuth::class,
        ]);
    })
    ->withProviders([
        \App\Providers\BookParserServiceProvider::class,
    ])
    ->withExceptions(function (Exceptions $exceptions) {
        // Ensure API routes return JSON errors
        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return response()->json([
                    'error' => true,
                    'message' => $e->getMessage(),
                    'code' => $e->getCode() ?: 500
                ], method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500);
            }
        });

        // Prevent infinite loops when logging fails due to permission issues
        $exceptions->report(function (\Throwable $e) {
            // Check for logging-related exceptions that could cause infinite loops
            $message = $e->getMessage();
            $file = $e->getFile();
            
            // Handle Monolog StreamHandler exceptions
            if ($e instanceof \UnexpectedValueException && 
                (strpos($message, 'could not be opened in append mode') !== false ||
                 strpos($message, 'Permission denied') !== false ||
                 strpos($message, 'No such file or directory') !== false) &&
                strpos($message, 'logs') !== false) {
                return false; // Don't report this exception to prevent infinite loop
            }
            
            // Handle general file operation exceptions in logs directory
            if ((strpos($message, 'fopen') !== false || 
                 strpos($message, 'Permission denied') !== false ||
                 strpos($message, 'No such file or directory') !== false) && 
                (strpos($message, 'logs') !== false || strpos($file, 'logs') !== false)) {
                return false; // Don't report this exception to prevent infinite loop
            }
            
            // Handle ErrorException for file operations
            if ($e instanceof \ErrorException && 
                (strpos($message, 'Permission denied') !== false ||
                 strpos($message, 'No such file or directory') !== false) &&
                (strpos($message, 'logs') !== false || strpos($file, 'logs') !== false)) {
                return false; // Don't report this exception to prevent infinite loop
            }
            
            return null; // Report all other exceptions normally
        });
        
        // Override default logging behavior to prevent cascading failures
        $exceptions->reportable(function (\Throwable $e) {
            // For critical logging failures, try to report to stderr instead
            $message = $e->getMessage();
            if ((strpos($message, 'logs') !== false && 
                 strpos($message, 'Permission denied') !== false) ||
                (strpos($message, 'could not be opened in append mode') !== false)) {
                
                // Try to write to stderr as a last resort
                try {
                    error_log("Laravel logging failure: " . $message);
                } catch (\Throwable $ignored) {
                    // If even stderr fails, give up silently
                }
                return false;
            }
        });
    })->create();
