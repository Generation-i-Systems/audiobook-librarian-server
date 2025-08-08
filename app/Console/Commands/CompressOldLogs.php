<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CompressOldLogs extends Command
{
    protected $signature = 'logs:compress {--days=1 : Compress logs older than this many days} {--dry-run : Show what would be compressed without actually compressing}';

    protected $description = 'Compress log files that are older than the specified number of days';

    public function handle()
    {
        $daysOld = (int) $this->option('days');
        $dryRun = $this->option('dry-run');

        $this->info("Searching for log files older than {$daysOld} day(s)...");

        $logPath = storage_path('logs');
        $cutoffTime = Carbon::now()->subDays($daysOld)->timestamp;

        $logFiles = glob($logPath . '/*.log');
        $compressedCount = 0;
        $skippedCount = 0;

        foreach ($logFiles as $logFile) {
            $fileName = basename($logFile);
            $fileTime = filemtime($logFile);

            // Skip if file is newer than cutoff
            if ($fileTime >= $cutoffTime) {
                $this->line("Skipping {$fileName} (too recent)");
                $skippedCount++;
                continue;
            }

            // Skip if already compressed
            if (file_exists($logFile . '.gz')) {
                $this->line("Skipping {$fileName} (already compressed)");
                $skippedCount++;
                continue;
            }

            // Skip current log file (typically laravel.log)
            if ($fileName === 'laravel.log') {
                $this->line("Skipping {$fileName} (current log file)");
                $skippedCount++;
                continue;
            }

            $fileSize = $this->formatBytes(filesize($logFile));
            $fileAge = Carbon::createFromTimestamp($fileTime)->diffForHumans();

            if ($dryRun) {
                $this->line("Would compress: {$fileName} ({$fileSize}, {$fileAge})");
                $compressedCount++;
            } else {
                $this->line("Compressing: {$fileName} ({$fileSize}, {$fileAge})");

                if ($this->compressFile($logFile)) {
                    $compressedSize = $this->formatBytes(filesize($logFile . '.gz'));
                    $this->info("✓ Compressed {$fileName} -> {$fileName}.gz ({$compressedSize})");
                    $compressedCount++;

                    Log::info('Log file compressed', [
                        'original_file' => $fileName,
                        'compressed_file' => $fileName . '.gz',
                        'original_size' => $fileSize,
                        'compressed_size' => $compressedSize,
                        'file_age' => $fileAge
                    ]);
                } else {
                    $this->error("✗ Failed to compress {$fileName}");
                    Log::error('Log compression failed', [
                        'file' => $fileName
                    ]);
                }
            }
        }

        if ($dryRun) {
            $this->info("Dry run complete. Would compress {$compressedCount} file(s), skip {$skippedCount} file(s)");
        } else {
            $this->info("Compression complete. Compressed {$compressedCount} file(s), skipped {$skippedCount} file(s)");
        }

        return Command::SUCCESS;
    }

    private function compressFile(string $filePath): bool
    {
        $command = sprintf('gzip %s', escapeshellarg($filePath));
        $output = [];
        $returnCode = 0;

        exec($command, $output, $returnCode);

        return $returnCode === 0;
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
