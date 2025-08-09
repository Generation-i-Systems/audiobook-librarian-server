<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RotateLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'log:rotate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rotate the application logs at midnight';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $logPath = storage_path('logs');
        $today = now()->format('Y-m-d');
        $currentLog = $logPath . '/laravel.log';

        try {
            // If the current log file exists and has content, rotate it
            if (file_exists($currentLog) && filesize($currentLog) > 0) {
                $newLog = $logPath . '/laravel-' . $today . '.log';

                // If a log file for today already exists, append a timestamp
                if (file_exists($newLog)) {
                    $newLog = $logPath . '/laravel-' . $today . '-' . time() . '.log';
                }

                // Rename the current log file
                rename($currentLog, $newLog);

                // Create a new empty log file
                touch($currentLog);
                chmod($currentLog, 0664);

                // Clean up old log files (keep last 14 days)
                $this->cleanupOldLogs($logPath);

                $this->info('Logs have been rotated successfully.');
                return 0;
            }

            $this->info('No log rotation needed - log file is empty or does not exist.');
            return 0;
        } catch (\Exception $e) {
            $this->error('Error rotating logs: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Clean up log files older than 14 days
     *
     * @param string $logPath
     * @return void
     */
    protected function cleanupOldLogs($logPath)
    {
        $files = glob($logPath . '/laravel-*.log*');
        $now = time();
        $daysToKeep = 14;

        foreach ($files as $file) {
            // Skip directories
            if (is_dir($file)) {
                continue;
            }

            // Get file modification time
            $fileTime = filemtime($file);

            // Delete files older than $daysToKeep days
            if (($now - $fileTime) >= ($daysToKeep * 24 * 60 * 60)) {
                @unlink($file);
            }
        }
    }
}
