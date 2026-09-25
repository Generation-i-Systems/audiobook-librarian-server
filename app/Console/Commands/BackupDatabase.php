<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DatabaseBackupWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use RuntimeException;

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
    protected $description = 'Create a backup of the configured SQL database';

    /**
     * Minimum time between automatic (non-manual) backups.
     */
    public const AUTO_BACKUP_MIN_INTERVAL_HOURS = 4.0;

    /**
     * Hours elapsed since the most recent backup, or null if none exists.
     */
    public static function hoursSinceLastBackup(?string $backupDir = null): ?float
    {
        $backupDir = $backupDir ?? (string) config('app.database_backup_path');
        if (!is_dir($backupDir)) {
            return null;
        }

        $files = array_merge(glob($backupDir . '/backup_*.sql.gz') ?: [], glob($backupDir . '/backup_*.sqlite') ?: []);
        if ($files === []) {
            return null;
        }

        $latestMtime = max(array_map('filemtime', $files));

        return (time() - $latestMtime) / 3600;
    }

    /**
     * Execute the console command.
     */
    public function handle(DatabaseBackupWriter $writer): int
    {
        if (app()->environment('testing')) {
            $this->warn('Skipping database backup in testing environment.');
            Log::info('Backup skipped in testing environment');
            return self::SUCCESS;
        }

        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $databaseName = (string) $connection->getDatabaseName();
        $backupDir = (string) config('app.database_backup_path');
        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
            $this->error('Cannot create database backup directory.');
            return self::FAILURE;
        }

        $identifier = preg_replace('/[^a-zA-Z0-9_-]+/', '_', basename($databaseName));
        $suffix = (string) ($this->option('suffix') ?? '');
        $safeSuffix = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $suffix);
        $name = 'backup_' . $identifier . ($safeSuffix !== '' ? '_' . $safeSuffix : '')
            . '_' . now()->format('Ymd_His');
        $extension = $driver === 'sqlite' ? '.sqlite' : '.sql.gz';
        $backupFile = $backupDir . '/' . $name . $extension;
        $workingFile = $backupFile . '.part';
        $dumpFile = $driver === 'sqlite' ? $workingFile : $workingFile . '.sql';

        try {
            $writer->write($connection, $dumpFile);
            if ($driver !== 'sqlite') {
                $input = fopen($dumpFile, 'rb');
                $output = gzopen($workingFile, 'wb9');
                if ($input === false || $output === false) {
                    if ($input !== false) {
                        fclose($input);
                    }
                    if ($output !== false) {
                        gzclose($output);
                    }
                    throw new RuntimeException('Cannot compress the database backup.');
                }
                try {
                    while (!feof($input)) {
                        $chunk = fread($input, 1048576);
                        if ($chunk === false) {
                            throw new RuntimeException('Cannot compress the database backup.');
                        }
                        while ($chunk !== '') {
                            $written = gzwrite($output, $chunk);
                            if ($written === false || $written === 0) {
                                throw new RuntimeException('Cannot compress the database backup.');
                            }
                            $chunk = substr($chunk, $written);
                        }
                    }
                } finally {
                    fclose($input);
                    gzclose($output);
                }
                unlink($dumpFile);
            }

            if ($this->option('verify') && !$this->verifyBackup($workingFile, $driver)) {
                throw new RuntimeException('Database backup integrity verification failed.');
            }
            if (!rename($workingFile, $backupFile)) {
                throw new RuntimeException('Cannot publish the completed database backup.');
            }

            $fileSize = $this->formatBytes((int) filesize($backupFile));
            $this->info('Backup created: ' . basename($backupFile) . ' (' . $fileSize . ')');
            Log::info('Database backup created', [
                'file' => basename($backupFile),
                'size' => $fileSize,
                'connection' => $connection->getName(),
                'driver' => $driver,
            ]);
            $this->cleanupOldBackups($backupDir);
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            @unlink($dumpFile);
            @unlink($workingFile);
            $this->error('Database backup failed: ' . $exception->getMessage());
            Log::error('Database backup failed', [
                'connection' => $connection->getName(),
                'driver' => $driver,
                'error' => $exception->getMessage(),
            ]);
            return self::FAILURE;
        }
    }

    private function verifyBackup(string $backupFile, string $driver): bool
    {
        if ($driver === 'sqlite') {
            $snapshot = new PDO('sqlite:' . $backupFile);
            return $snapshot->query('PRAGMA quick_check')->fetchColumn() === 'ok';
        }

        $stream = gzopen($backupFile, 'rb');
        if ($stream === false) {
            return false;
        }
        $hasContent = false;
        try {
            while (!gzeof($stream)) {
                $chunk = gzread($stream, 1048576);
                if ($chunk === false) {
                    return false;
                }
                $hasContent = $hasContent || $chunk !== '';
            }
        } finally {
            gzclose($stream);
        }
        return $hasContent;
    }

    /**
     * Clean up backups older than 30 days
     */
    private function cleanupOldBackups(string $backupDir): void
    {
        $this->line("Cleaning up old backups...");

        $files = array_merge(glob($backupDir . '/backup_*.sql.gz') ?: [], glob($backupDir . '/backup_*.sqlite') ?: []);
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
