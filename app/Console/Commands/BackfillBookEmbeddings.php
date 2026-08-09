<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\EmbedBookJob;
use App\Models\Book;
use Illuminate\Console\Command;

class BackfillBookEmbeddings extends Command
{
    protected $signature = 'books:backfill-embeddings {--force : Re-embed every book, even ones with an up-to-date embedding}';

    protected $description = 'Queue EmbedBookJob for every book missing a recommendation-engine embedding';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $query = Book::query();
        if (!$force) {
            $query->whereDoesntHave('embedding');
        }

        $count = 0;
        $query->select('id')->chunkById(200, function ($books) use ($force, &$count): void {
            foreach ($books as $book) {
                EmbedBookJob::dispatch($book->id, $force);
                $count++;
            }
        });

        $this->info("Queued {$count} book(s) for embedding.");

        return self::SUCCESS;
    }
}
