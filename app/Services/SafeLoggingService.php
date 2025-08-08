<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class SafeLoggingService
{
    protected static bool $logsDirectoryChecked = false;
    protected static bool $logsDirectoryWritable = false;

    /**
     * Safely log a message, handling permission errors gracefully
     */
    public static function safeLog($level, $message, array $context = [], $channel = null): void
    {
        // Check if logs directory is writable (do this only once per request)
        if (!self::$logsDirectoryChecked) {
            self::checkLogsDirectory();
        }

        // If logs directory is not writable, fail silently
        if (!self::$logsDirectoryWritable) {
            return;
        }

        try {
            if ($channel) {
                Log::channel($channel)->$level($message, $context);
            } else {
                Log::$level($message, $context);
            }
        } catch (\Exception $e) {
            // If logging fails, fail silently to prevent infinite loops
            // In production, you might want to store critical errors in memory
            // or send them to an external service
        }
    }

    /**
     * Check if logs directory exists and is writable
     */
    protected static function checkLogsDirectory(): void
    {
        self::$logsDirectoryChecked = true;

        $logsPath = storage_path('logs');

        try {
            // Create logs directory if it doesn't exist
            if (!File::exists($logsPath)) {
                File::makeDirectory($logsPath, 0755, true);
            }

            // Check if directory is writable
            self::$logsDirectoryWritable = is_writable($logsPath);
        } catch (\Exception $e) {
            self::$logsDirectoryWritable = false;
        }
    }

    /**
     * Get the status of the logs directory
     */
    public static function getLogsDirectoryStatus(): array
    {
        if (!self::$logsDirectoryChecked) {
            self::checkLogsDirectory();
        }

        $logsPath = storage_path('logs');

        return [
            'exists' => File::exists($logsPath),
            'writable' => self::$logsDirectoryWritable,
            'path' => $logsPath,
            'permissions' => File::exists($logsPath) ? substr(sprintf('%o', fileperms($logsPath)), -4) : null,
        ];
    }
}
