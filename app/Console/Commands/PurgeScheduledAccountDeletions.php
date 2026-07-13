<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AccountDeletionService;
use Illuminate\Console\Command;

class PurgeScheduledAccountDeletions extends Command
{
    protected $signature = 'accounts:purge-scheduled-deletions';

    protected $description = 'Permanently delete accounts whose cancellation period has expired';

    public function handle(AccountDeletionService $accountDeletionService): int
    {
        $count = $accountDeletionService->purgeDueAccounts();
        $this->info("Permanently deleted {$count} scheduled account(s).");

        return self::SUCCESS;
    }
}
