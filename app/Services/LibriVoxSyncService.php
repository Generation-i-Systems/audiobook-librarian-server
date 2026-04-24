<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LibriVoxSyncLog;

class LibriVoxSyncService
{
    public function trigger(string $language, bool $full): void
    {
        $cmd = 'php artisan librivox:sync --language=' . escapeshellarg($language) . ($full ? ' --full' : '');
        exec($cmd . ' > ' . escapeshellarg(storage_path('logs/librivox-sync.log')) . ' 2>&1 &');
    }

    /**
     * @return array{found: bool, message: string}
     */
    public function cancel(string $language): array
    {
        $log = LibriVoxSyncLog::where('language', $language)
            ->whereIn('status', ['running'])
            ->latest('started_at')
            ->first();

        if (!$log) {
            return ['found' => false, 'message' => 'No running sync found.'];
        }

        $log->update(['status' => 'cancelling']);

        if ($log->pid && function_exists('posix_kill')) {
            posix_kill($log->pid, SIGTERM);
        }

        return ['found' => true, 'message' => 'Cancellation requested — sync will stop after the current page.'];
    }
}
