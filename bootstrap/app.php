<?php

declare(strict_types=1);

// CRITICAL: Run database safety check BEFORE Laravel bootstrap
require_once __DIR__ . '/database-safety-check.php';

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// Register CDN service
App::singleton(\App\Services\SimpleAssetCDNService::class);
        
        // Prevent infinite loops when logging fails
        $exceptions->reportable(function (\Throwable $e) {
            // Check for logging-related exceptions that could cause infinite loops
            $message = $e->getMessage();
            $file = $e->getFile();
            
            // Handle Monolog StreamHandler exceptions
            if (
                (str_contains($message, 'fopen') ||
                (str_contains($message, 'Permission denied') ||
                (str_contains($message, 'logs') || str_contains($file, 'logs'))
            ) {
                return false; // Don't report this exception to prevent infinite loop
            }
        });
                (str_contains($message, 'fopen') ||
                (str_contains($message, 'Permission denied') ||
                (str_contains($message, 'could not be opened in append mode')) ||
                (str_contains($message, 'logs'))
            ) {
                return false; // Don't report this exception to prevent infinite loop
            }
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
