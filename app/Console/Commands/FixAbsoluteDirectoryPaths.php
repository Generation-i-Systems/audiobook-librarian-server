<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Console\Command;

class FixAbsoluteDirectoryPaths extends Command
{
    protected $signature = 'books:fix-absolute-paths
                            {--dry-run : Show what would be changed without making changes}';

    protected $description = 'Fix books with absolute directory paths to use relative paths';

    private DocumentStoreServiceInterface $documentStore;

    public function __construct(DocumentStoreServiceInterface $documentStore)
    {
        parent::__construct();
        $this->documentStore = $documentStore;
    }

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        $this->info('Scanning books for absolute directory paths...');
        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $bookRoot = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
        $bookRootPattern = $bookRoot . '/';

        $page = 1;
        $perPage = 100;
        $totalProcessed = 0;
        $totalFixed = 0;

        while (true) {
            $result = $this->documentStore->listBooks($page, $perPage, [], false);
            $books = $result['data'];

            if (empty($books)) {
                break;
            }

            foreach ($books as $book) {
                $totalProcessed++;
                $bookId = $book['id'] ?? $book['documentId'] ?? null;

                if (!$bookId) {
                    continue;
                }

                // Get full book details
                $fullBook = $this->documentStore->getBook($bookId);
                $directoryPath = $fullBook['directoryPath'] ?? $fullBook['directory_path'] ?? null;

                if (!$directoryPath) {
                    continue;
                }

                // Check if it's an absolute path
                if (!str_starts_with($directoryPath, '/')) {
                    continue;
                }

                // Convert to relative path
                $relativePath = $directoryPath;

                // Remove book root if present
                if (str_starts_with($directoryPath, $bookRootPattern)) {
                    $relativePath = substr($directoryPath, strlen($bookRootPattern));
                } elseif (str_starts_with($directoryPath, $bookRoot)) {
                    $relativePath = substr($directoryPath, strlen($bookRoot) + 1);
                }

                // Skip if it's still absolute (different root)
                if (str_starts_with($relativePath, '/')) {
                    $this->warn("Book ID {$bookId}: Cannot convert path (different root): {$directoryPath}");
                    continue;
                }

                $totalFixed++;

                $this->line('');
                $this->info("Book ID: {$bookId}");
                $this->line("  Title: " . ($book['title'] ?? 'Unknown'));
                $this->line("  Old path: {$directoryPath}");
                $this->line("  New path: {$relativePath}");

                if (!$isDryRun) {
                    $this->documentStore->updateBook($bookId, ['directory_path' => $relativePath]);
                    $this->comment("  ✓ Updated");
                }
            }

            $page++;
        }

        $this->newLine();
        $this->info("Processed {$totalProcessed} books");
        $this->info("Found {$totalFixed} books with absolute paths");

        if (!$isDryRun) {
            $this->info("Successfully fixed: {$totalFixed}");
        } else {
            $this->warn('DRY RUN MODE - No changes were made');
            $this->info('Run without --dry-run to apply changes');
        }

        return 0;
    }
}
