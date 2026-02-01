<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Book;
use App\Services\BookDeletionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class RemoveDuplicateBooksCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'books:remove-duplicates
                            {--dry-run : Run without making changes}
                            {--keep=newest : Which duplicate to keep (newest, oldest, most-complete)}
                            {--permanent : Permanently delete without using trash}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove duplicate books based on directory_path AND title';

    /**
     * The document store service.
     *
     * @var DocumentStoreServiceInterface
     */
    protected $documentStore;

    /**
     * The book deletion service.
     *
     * @var BookDeletionService
     */
    protected $deletionService;

    /**
     * Create a new command instance.
     *
     * @param DocumentStoreServiceInterface $documentStore
     * @param BookDeletionService $deletionService
     * @return void
     */
    public function __construct(DocumentStoreServiceInterface $documentStore, BookDeletionService $deletionService)
    {
        parent::__construct();
        $this->documentStore = $documentStore;
        $this->deletionService = $deletionService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $keepStrategy = $this->option('keep');
        $permanent = $this->option('permanent');

        if ($dryRun) {
            $this->info('Running in dry-run mode. No changes will be made.');
        }

        if ($permanent) {
            $this->warn('Using --permanent flag: Books will be permanently deleted without trash.');
        } else {
            $this->info('Books will be moved to trash (can be restored from /admin/trash).');
        }

        $this->info('Finding duplicate books based on directory_path AND title...');

        // Get all books with directory_path
        $books = Book::whereNotNull('directory_path')
            ->select('id', 'title', 'directory_path', 'created_at', 'updated_at', 'needs_review', 'audio_file_count')
            ->get();

        $this->info(sprintf('Found %d books with directory_path', $books->count()));

        // Group books by directory_path AND title
        $groupedBooks = $books->groupBy(function ($book) {
            return $book->directory_path . '|' . $book->title;
        });

        // Filter to only groups with more than one book
        $duplicateGroups = $groupedBooks->filter(function ($group) {
            return $group->count() > 1;
        });

        $this->info(sprintf('Found %d groups of duplicate books', $duplicateGroups->count()));

        $totalRemoved = 0;
        $errors = 0;

        // Process each group of duplicates
        foreach ($duplicateGroups as $key => $group) {
            list($directoryPath, $title) = explode('|', $key, 2);

            $this->info(sprintf('Processing duplicates for "%s" in directory "%s"', $title, $directoryPath));
            $this->info(sprintf('Found %d duplicate books', $group->count()));

            // Determine which book to keep based on the strategy
            $bookToKeep = $this->selectBookToKeep($group, $keepStrategy);

            // Remove all other books in the group
            foreach ($group as $book) {
                if ($book->id !== $bookToKeep->id) {
                    $this->info(sprintf('Removing duplicate book ID: %d', $book->id));

                    if (!$dryRun) {
                        try {
                            if ($permanent) {
                                // Permanently delete without trash
                                $result = $this->documentStore->deleteBook((string) $book->id);

                                if ($result) {
                                    $this->info(sprintf('Successfully removed book ID: %d (permanent)', $book->id));
                                    $totalRemoved++;
                                } else {
                                    $this->error(sprintf('Failed to remove book ID: %d', $book->id));
                                    $errors++;
                                }
                            } else {
                                // Move to trash
                                $result = $this->deletionService->moveToTrash((string) $book->id, true);

                                if ($result['success']) {
                                    $this->info(sprintf('Successfully moved book ID: %d to trash', $book->id));
                                    $totalRemoved++;
                                } else {
                                    $this->error(sprintf('Failed to move book ID: %d to trash - %s', $book->id, $result['error'] ?? 'Unknown error'));
                                    $errors++;
                                }
                            }
                        } catch (\Exception $e) {
                            $this->error(sprintf('Error removing book ID: %d - %s', $book->id, $e->getMessage()));
                            $errors++;
                        }
                    } else {
                        $action = $permanent ? 'permanently delete' : 'move to trash';
                        $this->info("[DRY RUN] Would {$action} book ID: " . $book->id);
                        $totalRemoved++;
                    }
                } else {
                    $this->info(sprintf('Keeping book ID: %d', $book->id));
                }
            }
        }

        $this->info("\nSummary:");
        $this->info(sprintf('Books removed: %d', $totalRemoved));
        $this->info(sprintf('Errors: %d', $errors));

        if ($dryRun) {
            $this->info('This was a dry run. No changes were made.');
        }

        return 0;
    }

    /**
     * Select which book to keep from a group of duplicates based on the strategy.
     *
     * @param Collection $books
     * @param string $strategy
     * @return Book
     */
    protected function selectBookToKeep(Collection $books, string $strategy): Book
    {
        switch ($strategy) {
            case 'oldest':
                return $books->sortBy('created_at')->first();

            case 'most-complete':
                // Select the book with the most non-null fields
                return $books->sortByDesc(function ($book) {
                    $nonNullCount = 0;
                    foreach ($book->getAttributes() as $value) {
                        if (!is_null($value) && $value !== '') {
                            $nonNullCount++;
                        }
                    }
                    return $nonNullCount;
                })->first();

            case 'newest':
            default:
                return $books->sortByDesc('created_at')->first();
        }
    }
}
