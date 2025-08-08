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
        
        try {
            // Get all log files that aren't from today
            $logFiles = glob($logPath . '/laravel-*.log*');
            
            foreach ($logFiles as $file) {
                // Skip today's log file
                if (str_contains($file, 'laravel-' . $today)) {
                    continue;
                }
                
                // If the file doesn't have a date in the name, rename it
                if (!preg_match('/laravel-\d{4}-\d{2}-\d{2}/', $file)) {
                    $newName = preg_replace('/(laravel)(\.log)?$/', '$1-' . $today . '$2', $file);
                    if ($newName !== $file) {
                        rename($file, $newName);
                    }
                }
            }
            
            // Clear the current log file
            file_put_contents($logPath . '/laravel.log', '');
            
            $this->info('Logs have been rotated successfully.');
            return 0;
            
        } catch (\Exception $e) {
            $this->error('Error rotating logs: ' . $e->getMessage());
            return 1;
        }
    }
}
