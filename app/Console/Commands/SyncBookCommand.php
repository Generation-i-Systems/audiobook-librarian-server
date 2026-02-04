<?php

namespace App\Console\Commands;

use App\Services\DatabaseSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncBookCommand extends Command
{
    protected $signature = 'books:sync
                            {--book= : ID of the book to sync}
                            {--direction=prod_to_devel : Direction of sync (prod_to_devel or devel_to_prod)}
                            {--dry-run : Simulate without making changes}
                            {--non-interactive : Skip interactive prompts}';

    protected $description = 'Synchronize specific book and its relationships between environments';

    protected DatabaseSyncService $syncService;

    public function __construct(DatabaseSyncService $syncService)
    {
        parent::__construct();
        $this->syncService = $syncService;
    }

    public function handle()
    {
        $bookId = $this->option('book');
        $direction = $this->option('direction');
        $dryRun = $this->option('dry-run');
        $interactive = !$this->option('non-interactive');

        if ($direction === 'prod_to_devel') {
            $sourceName = 'mysql_production';
            $targetName = 'mysql_devel';
        } else {
            $sourceName = 'mysql_devel';
            $targetName = 'mysql_production';
        }

        try {
            $source = DB::connection($sourceName);
            $target = DB::connection($targetName);
            $source->getPdo();
            $target->getPdo();
        } catch (\Exception $e) {
            $this->error("Connection failed: " . $e->getMessage());
            return 1;
        }

        $this->info("Syncing Book from {$sourceName} to {$targetName}");

        if (!$bookId && $interactive) {
            $bookId = $this->ask('Enter the Book ID to sync:');
        }

        if (!$bookId) {
            $this->error("Book ID is required.");
            return 1;
        }

        if ($dryRun) {
            $this->info("[DRY RUN] Simulating sync for Book ID: {$bookId}");
            return 0;
        }

        $this->syncService->setConflictResolver(function ($type, $sourceData, $targetData) use ($interactive) {
            if (!$interactive) {
                return 'overwrite';
            }

            $this->warn("Conflict detected for {$type}!");
            // Show diff snippet?
            return $this->choice("Action?", ['overwrite', 'skip'], 0);
        });

        try {
            $success = $this->syncService->syncBook((int) $bookId, $source, $target);
            if ($success) {
                $this->info("Book ID {$bookId} synced successfully.");
                return 0;
            } else {
                $this->error("Failed to sync Book ID {$bookId}. Check logs.");
                return 1;
            }
        } catch (\Exception $e) {
            $this->error("Sync Error: " . $e->getMessage());
            return 1;
        }
    }
}
