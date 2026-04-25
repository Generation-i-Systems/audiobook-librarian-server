<?php

declare(strict_types=1);

namespace App\Console\Commands\LibriVox;

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

        $this->info($bookId ? "Fetching chapter sizes for book {$bookId}..." : 'Fetching chapter sizes for all zero-size chapters...');

        $updated = $importService->backfillChapterSizes($bookId);

        $this->info("Updated {$updated} chapter(s).");

        return self::SUCCESS;
    }
}
