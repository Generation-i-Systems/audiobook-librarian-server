<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AuditArchiveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ArchiveAudits extends Command
{
    protected $signature = 'audits:archive
        {--days= : Archive audits older than this many days (default: config audits.archive_after_days)}
        {--dry-run : Only report how many rows would be archived}
        {--force : Do not ask for confirmation (required when non-interactive)}
        {--skip-backup-check : Do not require a database backup from the last 24 hours}';

    protected $description = 'Export audits older than N days to verified archive files, then delete them from the database';

    private const MAX_BACKUP_AGE_HOURS = 24.0;

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('audits.archive_after_days'));
        if ($days < 1) {
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }

        $service = new AuditArchiveService(
            (string) config('audits.archive_path'),
            (int) config('audits.archive_chunk_size')
        );
        $cutoff = now()->subDays($days);
        $pending = $service->pendingCount($cutoff);
        $this->info("{$pending} audit row(s) older than {$days} days (before {$cutoff->toDateTimeString()}).");

        if ($pending === 0 || $this->option('dry-run')) {
            return self::SUCCESS;
        }

        if (!$this->option('skip-backup-check')) {
            $age = BackupDatabase::hoursSinceLastBackup();
            if ($age === null || $age > self::MAX_BACKUP_AGE_HOURS) {
                $this->error('No database backup from the last 24 hours. Run backup:database first (or --skip-backup-check).');

                return self::FAILURE;
            }
        }

        $archivePath = (string) config('audits.archive_path');
        if (!$this->option('force')
            && !$this->confirm("Export these rows to {$archivePath} and DELETE them from the database?")) {
            $this->warn('Aborted, nothing changed.');

            return self::FAILURE;
        }

        try {
            $summary = $service->archive($cutoff, function (string $file, int $rows): void {
                $this->line("  archived {$rows} rows -> " . basename($file));
            });
        } catch (\Throwable $e) {
            Log::error('Audit archive failed', ['error' => $e->getMessage()]);
            $this->error('Audit archive failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        Log::info('Audit archive completed', ['rows' => $summary['rows'], 'files' => count($summary['files']), 'path' => $archivePath]);
        $this->info("✓ Archived and removed {$summary['rows']} rows in {$summary['batches']} file(s) at {$archivePath}");

        return self::SUCCESS;
    }
}
