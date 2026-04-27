<?php

declare(strict_types=1);

namespace App\Console\Commands\LibriVox;

use App\Models\LibriVox\Chapter;
use App\Services\LibriVoxImportService;
use Illuminate\Console\Command;

class FetchChapterSizesCommand extends Command
{
    protected $signature = 'librivox:fetch-sizes
        {--book= : Backfill only a specific book by local DB ID}';

    protected $description = 'Backfill size_bytes for LibriVox chapters that still have size 0 via HTTP HEAD requests';

    public function handle(LibriVoxImportService $importService): int
    {
        $bookId = $this->option('book') !== null ? (int) $this->option('book') : null;

        $total = Chapter::whereNotNull('listen_url')
            ->where('size_bytes', 0)
            ->when($bookId !== null, fn ($q) => $q->where('book_id', $bookId))
            ->count();

        if ($total === 0) {
            $this->info('No chapters with missing sizes found.');
            return self::SUCCESS;
        }

        $this->info($bookId
            ? "Fetching chapter sizes for book {$bookId} ({$total} chapters)..."
            : "Fetching chapter sizes for {$total} zero-size chapters...");

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | updated: %message% | elapsed: %elapsed%');
        $bar->setMessage('0');
        $bar->start();

        $updated = $importService->backfillChapterSizes(
            $bookId,
            function (int $processed, int $updatedSoFar) use ($bar): void {
                $bar->setMessage((string) $updatedSoFar);
                $bar->setProgress($processed);
            },
        );

        $bar->finish();
        $this->newLine();
        $this->info("Done. Updated {$updated} of {$total} chapter(s).");

        return self::SUCCESS;
    }
}
