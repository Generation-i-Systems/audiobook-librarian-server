<?php

declare(strict_types=1);

namespace App\Console\Commands\LibriVox;

use App\Services\LibriVoxImportService;
use Illuminate\Console\Command;

class ImportLibriVoxCommand extends Command
{
    protected $signature = 'librivox:import
        {--limit=50 : Books per page (max 50)}
        {--offset=0 : Starting offset}
        {--pages=1 : Number of pages to import}
        {--since= : Only import books added since this Unix timestamp}
        {--id= : Import a single book by LibriVox ID}';

    protected $description = 'Import audiobook metadata from the LibriVox API into the local database';

    public function handle(LibriVoxImportService $importService): int
    {
        if ($id = $this->option('id')) {
            return $this->importSingle((string) $id, $importService);
        }

        return $this->importPages($importService);
    }

    private function importSingle(string $id, LibriVoxImportService $importService): int
    {
        $this->info("Importing LibriVox book {$id}...");

        $apiBook = app(\App\Services\LibriVoxApiService::class)->getById($id);
        if ($apiBook === null) {
            $this->error("Book {$id} not found on LibriVox.");
            return self::FAILURE;
        }

        $book = $importService->importBook($apiBook);
        $this->info("Imported: [{$book->id}] {$book->title}");

        return self::SUCCESS;
    }

    private function importPages(LibriVoxImportService $importService): int
    {
        $limit = max(1, min(50, (int) $this->option('limit')));
        $offset = (int) $this->option('offset');
        $pages = max(1, (int) $this->option('pages'));
        $since = $this->option('since') !== null ? (int) $this->option('since') : null;

        $totalImported = $totalFailed = 0;

        for ($page = 0; $page < $pages; $page++) {
            $currentOffset = $offset + ($page * $limit);
            $this->info("Fetching page " . ($page + 1) . " (offset {$currentOffset})...");

            $result = $importService->importPage($limit, $currentOffset, $since);

            $totalImported += $result['imported'];
            $totalFailed += $result['failed'];

            $this->line("  Imported: {$result['imported']}, Failed: {$result['failed']}");

            if ($result['imported'] === 0 && $result['failed'] === 0) {
                $this->info('No more books found.');
                break;
            }
        }

        $this->info("Done. Total imported: {$totalImported}, Failed: {$totalFailed}");

        return $totalFailed > 0 && $totalImported === 0 ? self::FAILURE : self::SUCCESS;
    }
}
