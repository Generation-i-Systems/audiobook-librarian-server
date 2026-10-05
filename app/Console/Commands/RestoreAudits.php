<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AuditArchiveService;
use Illuminate\Console\Command;

class RestoreAudits extends Command
{
    protected $signature = 'audits:restore {file : Path to an audits_*.ndjson.gz archive file}';

    protected $description = 'Load an audit archive file back into the audits table (existing ids are skipped)';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (!is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        try {
            $inserted = (new AuditArchiveService((string) config('audits.archive_path')))->restore($file);
        } catch (\Throwable $e) {
            $this->error('Restore failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("✓ Restored {$inserted} audit row(s) from " . basename($file));

        return self::SUCCESS;
    }
}
