<?php

namespace App\Console\Commands;

use App\Models\PendingDownload;
use Illuminate\Console\Command;

class CleanupExpiredPendingDownloads extends Command
{
    protected $signature = 'pending-downloads:cleanup';

    protected $description = 'Mark stale, never-matched pending-download records as expired';

    public function handle(): int
    {
        $count = PendingDownload::expired()->count();
        PendingDownload::expired()->update(['status' => 'expired']);

        $this->info("Marked {$count} pending-download record(s) as expired.");

        return Command::SUCCESS;
    }
}
