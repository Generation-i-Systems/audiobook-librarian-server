<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackupDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:database {--verify : Verify backup integrity after creation} {--suffix= : Add a suffix to distinguish backup source}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a backup of the MySQL database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting database backup...');

        // Safety: never execute external mysqldump during automated tests
        if (app()->environment('testing')) {
            $this->warn('Skipping database backup in testing environment.');
            Log::info('Backup skipped in testing environment');
            return Command::SUCCESS;
        }

        // Get database configuration
        $dbHost = config('database.connections.mysql.host');
        $dbPort = config('database.connections.mysql.port');
        $dbName = config('database.connections.mysql.database');
        $dbUser = config('database.connections.mysql.username');
        $dbPassword = config('database.connections.mysql.password');

        // Create backup directory
        $backupDir = '/var/lib/mysql/laravel_backup';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        // Generate backup filename
        $timestamp = now()->format('Ymd_His');
        $suffix = $this->option('suffix');
        $suffixPart = $suffix ? "_{$suffix}" : '';
        $backupFile = "{$backupDir}/backup_{$dbName}{$suffixPart}_{$timestamp}.sql";

        // Build mysqldump command
        $command = sprintf(
            'mysqldump -h%s -P%s -u%s -p%s --single-transaction --routines --triggers --events --add-drop-database --databases %s > %s',
            escapeshellarg($dbHost),
            escapeshellarg($dbPort),
            escapeshellarg($dbUser),
            escapeshellarg($dbPassword),
            escapeshellarg($dbName),
            escapeshellarg($backupFile)
        );

        // Execute backup
        $this->line("Creating backup: " . basename($backupFile));

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode === 0) {
            // Compress the backup
            $compressCommand = "gzip " . escapeshellarg($backupFile);
            exec($compressCommand, $output, $returnCode);

            if ($returnCode === 0) {
                $compressedFile = $backupFile . '.gz';
                $fileSize = $this->formatBytes(filesize($compressedFile));

                $this->info("✓ Backup created successfully: " . basename($compressedFile) . " ({$fileSize})");

                // Log the backup
                Log::info('Database backup created', [
                    'file' => basename($compressedFile),
                    'size' => $fileSize,
                    'database' => $dbName,
                    'suffix' => $suffix ?: 'none'
                ]);

                // Verify backup if requested
                if ($this->option('verify')) {
                    $this->verifyBackup($compressedFile);
                }

                // Cleanup old backups
                $this->cleanupOldBackups($backupDir);

                return Command::SUCCESS;
            } else {
                $this->error("✗ Failed to compress backup");
                return Command::FAILURE;
            }
        } else {
            $this->error("✗ Backup failed");
            Log::error('Database backup failed', [
                'database' => $dbName,
                'command' => $command,
                'output' => implode("\n", $output)
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * Verify backup integrity
     */
    private function verifyBackup(string $backupFile): void
    {
        $this->line("Verifying backup integrity...");

        $command = "gunzip -t " . escapeshellarg($backupFile);
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode === 0) {
            $this->info("✓ Backup integrity verified");
        } else {
            $this->error("✗ Backup integrity check failed");
            Log::error('Backup integrity check failed', [
                'file' => basename($backupFile)
            ]);
        }
    }

    /**
     * Clean up backups older than 30 days
     */
    private function cleanupOldBackups(string $backupDir): void
    {
        $this->line("Cleaning up old backups...");

        $files = glob($backupDir . '/backup_*.sql.gz');
        $thirtyDaysAgo = now()->subDays(30)->timestamp;
        $deletedCount = 0;

        foreach ($files as $file) {
            if (filemtime($file) < $thirtyDaysAgo) {
                unlink($file);
                $deletedCount++;
            }
        }

        if ($deletedCount > 0) {
            $this->info("✓ Deleted {$deletedCount} old backup(s)");
        } else {
            $this->line("No old backups to delete");
        }
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
