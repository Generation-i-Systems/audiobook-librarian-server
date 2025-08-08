<?php

namespace App\Console\Commands;

use App\Services\SafeLoggingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CheckLogsDirectory extends Command
{
    protected $signature = 'logs:check {--fix : Attempt to fix permissions}';
    protected $description = 'Check logs directory status and permissions';

    public function handle(): int
    {
        $this->info('Checking logs directory status...');

        $status = SafeLoggingService::getLogsDirectoryStatus();

        $this->table(
            ['Property', 'Value'],
            [
                ['Path', $status['path']],
                ['Exists', $status['exists'] ? 'Yes' : 'No'],
                ['Writable', $status['writable'] ? 'Yes' : 'No'],
                ['Permissions', $status['permissions'] ?? 'N/A'],
            ]
        );

        if (!$status['exists']) {
            $this->error('Logs directory does not exist!');

            if ($this->option('fix')) {
                $this->info('Attempting to create logs directory...');
                try {
                    File::makeDirectory($status['path'], 0755, true);
                    $this->info('Logs directory created successfully.');
                } catch (\Exception $e) {
                    $this->error('Failed to create logs directory: ' . $e->getMessage());
                    return 1;
                }
            }
        }

        if (!$status['writable']) {
            $this->error('Logs directory is not writable!');

            if ($this->option('fix')) {
                $this->info('Attempting to fix permissions...');
                try {
                    chmod($status['path'], 0755);
                    $this->info('Permissions updated.');
                } catch (\Exception $e) {
                    $this->error('Failed to update permissions: ' . $e->getMessage());
                    return 1;
                }
            } else {
                $this->warn('Run with --fix to attempt to fix permissions.');
            }
        }

        if ($status['exists'] && $status['writable']) {
            $this->info('Logs directory is properly configured.');

            // Test logging
            $this->info('Testing logging functionality...');
            SafeLoggingService::safeLog('info', 'Test log message from logs:check command');
            $this->info('Test log written successfully.');
        }

        return 0;
    }
}
