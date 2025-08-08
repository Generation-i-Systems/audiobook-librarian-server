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

        // Close all log handlers to release file handles
        $logger = app('log');
        foreach ($logger->getHandlers() as $handler) {
            if (method_exists($handler, 'close')) {
                $handler->close();
            }
        }

        // Reopen log handlers to create new log file
        $logger->info('Log file rotated at ' . now());

        $this->info('Logs have been rotated successfully.');
        return 0;
    }
}
