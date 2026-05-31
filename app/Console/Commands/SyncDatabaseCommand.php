<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DatabaseSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SyncDatabaseCommand extends Command
{
    protected $signature = 'db:sync
                            {--direction=prod_to_devel : Direction of sync (prod_to_devel or devel_to_prod)}
                            {--type=tables : Sync type (full or tables)}
                            {--tables= : Comma-separated list of tables to sync}
                            {--dry-run : Simulate without making changes}
                            {--force : Required for non-interactive destructive syncs}
                            {--non-interactive : Skip interactive prompts}';

    protected $description = 'Synchronize data between production and development databases';

    protected DatabaseSyncService $syncService;

    public function __construct(DatabaseSyncService $syncService)
    {
        parent::__construct();
        $this->syncService = $syncService;
    }

    public function handle()
    {
        $direction = $this->option('direction');
        $type = $this->option('type');
        $tablesList = $this->option('tables');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $interactive = !$this->option('non-interactive');
        $confirmed = false;

        if ($direction === 'prod_to_devel') {
            $sourceName = 'mysql_production';
            $targetName = 'mysql_devel';
        } else {
            $sourceName = 'mysql_devel';
            $targetName = 'mysql_production';
        }

        // Validate connections
        try {
            $source = DB::connection($sourceName);
            $target = DB::connection($targetName);
            $source->getPdo();
            $target->getPdo();
        } catch (\Exception $e) {
            $this->error("Connection failed: " . $e->getMessage());
            return 1;
        }

        $this->info("Source: {$sourceName} (" . $source->getDatabaseName() . ")");
        $this->info("Target: {$targetName} (" . $target->getDatabaseName() . ")");

        if ($dryRun) {
            $this->info("[DRY RUN] No changes will be applied.");
        }

        if ($interactive) {
            if (!$this->confirm("Are you sure you want to overwrite data in TARGET ({$targetName})? This cannot be undone.", $direction === 'prod_to_devel')) {
                $this->info("Operation cancelled.");
                return 0;
            }
            $confirmed = true;
        } elseif (!$dryRun && !$force) {
            $this->error('Refusing non-interactive destructive sync without --force.');

            return 1;
        } elseif (!$dryRun) {
            $confirmed = true;
        }

        $tables = [];
        if ($type === 'full') {
            // Get all tables from source schema
            // Simple generic way (MySQL specific lookup could be better)
            $tables = $source->getSchemaBuilder()->getTableListing();
            // Exclude migrations? maybe
        } else {
            if (empty($tablesList)) {
                $tablesList = $this->ask('Which tables do you want to sync? (comma-separated)');
            }
            $tables = explode(',', $tablesList);
        }

        $this->syncService->setConflictResolver(function ($type, $sourceData, $targetData) use ($interactive) {
            if (!$interactive) {
                return 'overwrite';
            }
            return $this->choice("Conflict in {$type}. Action?", ['overwrite', 'skip'], 0);
        });

        foreach ($tables as $table) {
            $table = trim($table);
            if (empty($table)) {
                continue;
            }

            $this->info("Syncing table: {$table}");

            if (!$dryRun) {
                try {
                    $count = $this->syncService->syncTable($table, $source, $target, $confirmed);
                    $this->info("  -> Synced {$count} rows.");
                } catch (\Exception $e) {
                    $this->error("  -> Failed: " . $e->getMessage());
                    if ($interactive && !$this->confirm("Continue with next table?")) {
                        return 1;
                    }
                }
            } else {
                $this->info("  -> [DRY RUN] Would truncate and copy rows.");
            }
        }

        $this->info("Sync completed.");
        return 0;
    }
}
