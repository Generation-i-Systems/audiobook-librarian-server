<?php

namespace App\Console\Commands;

use App\Services\ImportQueueService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessImportQueue extends Command
{
    protected $signature = 'imports:process-queue
                            {--limit=0 : Max imports to process (0 = no limit)}
                            {--dry-run : Preview without processing}
                            {--cleanup : Clean up old completed imports}
                            {--cleanup-days=30 : Days to keep completed imports}
                            {--stats : Show queue statistics only}';

    protected $description = 'Process queued imports from sync directory';

    protected ImportQueueService $queueService;

    public function __construct(ImportQueueService $queueService)
    {
        parent::__construct();
        $this->queueService = $queueService;
    }

    public function handle(): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        if ($this->option('cleanup')) {
            return $this->cleanupOldImports();
        }

        return $this->processQueue();
    }

    protected function showStats(): int
    {
        $stats = $this->queueService->getQueueStats();

        $this->info('Import Queue Statistics');
        $this->line('------------------------');
        $this->table(
            ['Status', 'Count'],
            [
                ['Pending', $stats['pending']],
                ['Completed', $stats['completed']],
                ['Failed', $stats['failed']],
            ]
        );

        return Command::SUCCESS;
    }

    protected function cleanupOldImports(): int
    {
        $days = (int) $this->option('cleanup-days');

        $this->info("Cleaning up imports older than {$days} days...");

        $count = $this->queueService->cleanupOldCompleted($days);

        if ($count > 0) {
            $this->info("Deleted {$count} old completed import(s)");
        } else {
            $this->line("No old imports to clean up");
        }

        return Command::SUCCESS;
    }

    protected function processQueue(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        if ($dryRun) {
            $this->warn('Running in dry-run mode - no changes will be made');
        }

        $pending = $this->queueService->getPendingImports();

        if (empty($pending)) {
            $this->info('No pending imports in queue');
            return Command::SUCCESS;
        }

        $total = count($pending);
        $this->info("Found {$total} pending import(s)");

        if ($limit > 0 && $total > $limit) {
            $pending = array_slice($pending, 0, $limit);
            $this->line("Processing limited to {$limit} imports");
        }

        $processed = 0;
        $succeeded = 0;
        $failed = 0;

        foreach ($pending as $import) {
            $processed++;
            $importId = $import['id'];
            $title = $import['metadata']['title'] ?? 'Unknown';

            $this->line("[{$processed}/{$total}] Processing: {$title}");

            $validationErrors = $this->queueService->validateImport($import);
            if (!empty($validationErrors)) {
                $this->error("  Validation failed:");
                foreach ($validationErrors as $error) {
                    $this->line("    - {$error}");
                }
                $failed++;
                continue;
            }

            if ($dryRun) {
                $this->info("  [Dry run] Would import: {$title}");
                $succeeded++;
                continue;
            }

            $result = $this->queueService->processImport($importId, false);

            if ($result['success']) {
                $bookId = $result['book_id'] ?? 'N/A';
                $this->info("  Imported successfully (Book ID: {$bookId})");
                $succeeded++;

                Log::info('ImportQueueService: Import succeeded', [
                    'import_id' => $importId,
                    'book_id' => $bookId,
                    'title' => $title,
                ]);
            } else {
                $error = $result['error'] ?? 'Unknown error';
                $this->error("  Import failed: {$error}");
                $failed++;

                Log::error('ImportQueueService: Import failed', [
                    'import_id' => $importId,
                    'title' => $title,
                    'error' => $error,
                ]);
            }
        }

        $this->newLine();
        $this->info('Processing complete');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total processed', $processed],
                ['Succeeded', $succeeded],
                ['Failed', $failed],
            ]
        );

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
