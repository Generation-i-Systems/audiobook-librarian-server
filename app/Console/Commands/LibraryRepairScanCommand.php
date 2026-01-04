<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LibraryRepairService;
use Illuminate\Console\Command;

class LibraryRepairScanCommand extends Command
{
    protected $signature = 'library:repair-scan
                            {--issue=* : Limit scan to specific issue types (missing_directory, orphan_directory, duplicate_directory, nested_audio)}
                            {--no-attempt-fixes : Disable automatic non-interactive fixes}
                            {--json : Output JSON summary instead of table}';

    protected $description = 'Scan the audiobook library for directory issues and record repair items.';

    public function __construct(private LibraryRepairService $repairService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $issueFilters = (array) $this->option('issue');
        $attemptFixes = ! (bool) $this->option('no-attempt-fixes');

        try {
            $summary = $this->repairService->scan($attemptFixes, $issueFilters);
        } catch (\Throwable $e) {
            $this->error('Library repair scan failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if ($this->option('json')) {
            $payload = [
                'attemptFixes' => $attemptFixes,
                'issues' => $summary,
            ];
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        if (empty($summary)) {
            $this->info('No issue types were selected. Nothing to scan.');

            return Command::SUCCESS;
        }

        $this->info('Library Repair Summary');
        $rows = [];

        foreach ($summary as $issue => $counts) {
            $rows[] = [
                'Issue' => $issue,
                'Created' => $counts['created'] ?? 0,
                'Resolved' => $counts['resolved'] ?? 0,
                'Auto-resolved' => $counts['autoResolved'] ?? 0,
            ];
        }

        $this->table(['Issue', 'Created', 'Resolved', 'Auto-resolved'], $rows);
        $this->info('Attempted fixes: ' . ($attemptFixes ? 'enabled' : 'disabled'));

        return Command::SUCCESS;
    }
}
