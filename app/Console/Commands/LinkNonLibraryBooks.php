<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Console\Command;

class LinkNonLibraryBooks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'books:link-statistical-data';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Link statistical data with no book_id to matching books by title and author';

    /**
     * Execute the console command.
     */
    public function handle(DocumentStoreServiceInterface $documentStoreService): int
    {
        $this->info('Starting to link non-library statistical data...');

        $linkedCount = $documentStoreService->linkNonLibraryBooks();

        $this->info("Successfully linked {$linkedCount} records.");

        return 0;
    }
}
