<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class FixBookDirectoryPaths extends Command
{
    protected $signature = 'books:fix-directory-paths
                            {--dry-run : Show what would be fixed without making changes}
                            {--limit= : Limit number of books to process}';

    protected $description = 'Fix directory_path in database to match actual filesystem locations';

    private DocumentStoreServiceInterface $documentStore;

    public function __construct(DocumentStoreServiceInterface $documentStore)
    {
        parent::__construct();
        $this->documentStore = $documentStore;
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $limit = $this->option('limit');

        $this->info('Checking books for directory path mismatches...');
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $bookRoot = rtrim(env('BOOK_STORAGE_PATH'), '/');
        $realBookRoot = realpath($bookRoot) ?: $bookRoot;

        $this->line("Book root: {$realBookRoot}");
        $this->newLine();

        // Get all books
        $books = $this->documentStore->getAllBooks();
        
        if ($limit) {
            $books = array_slice($books, 0, (int)$limit);
        }

        $stats = [
            'total' => count($books),
            'fixed' => 0,
            'already_correct' => 0,
            'missing_files' => 0,
            'errors' => 0,
        ];

        $progressBar = $this->output->createProgressBar($stats['total']);
        $progressBar->start();

        foreach ($books as $book) {
            $progressBar->advance();

            $currentPath = $book['directory_path'] ?? null;
            if (!$currentPath) {
                $stats['errors']++;
                continue;
            }

            // Check if files exist at current path
            $currentFullPath = "{$realBookRoot}/{$currentPath}";
            
            if (is_dir($currentFullPath)) {
                // Path is correct
                $stats['already_correct']++;
                continue;
            }

            // Try to find the actual directory
            // The issue is usually missing the title directory at the end
            $title = $book['title'] ?? null;
            if (!$title) {
                $stats['errors']++;
                continue;
            }

            // Build expected path with title
            $seriesNumber = null;
            if (!empty($book['series']) && is_array($book['series'])) {
                $firstSeries = $book['series'][0] ?? null;
                if ($firstSeries && isset($firstSeries['series_number'])) {
                    $seriesNumber = $firstSeries['series_number'];
                }
            }

            $titleDir = $title;
            if ($seriesNumber) {
                $formattedNumber = str_pad($seriesNumber, 2, '0', STR_PAD_LEFT);
                $titleDir = $formattedNumber . ' ' . $title;
            }

            $expectedPath = $currentPath . '/' . $titleDir;
            $expectedFullPath = "{$realBookRoot}/{$expectedPath}";

            if (is_dir($expectedFullPath)) {
                // Found the correct path!
                $progressBar->clear();
                $this->line("\n<fg=yellow>Found mismatch:</>");
                $this->line("  Book: {$title}");
                $this->line("  Current DB path: {$currentPath}");
                $this->line("  Actual path:     {$expectedPath}");

                if (!$dryRun) {
                    try {
                        $this->documentStore->updateBook($book['_id'], [
                            'directory_path' => $expectedPath,
                        ]);
                        $this->line("  <fg=green>✓ Fixed</>");
                        $stats['fixed']++;
                    } catch (\Exception $e) {
                        $this->error("  Error updating: " . $e->getMessage());
                        $stats['errors']++;
                    }
                } else {
                    $this->line("  <fg=blue>Would fix</>");
                    $stats['fixed']++;
                }

                $progressBar->display();
            } else {
                // Can't find the files
                $stats['missing_files']++;
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        // Display summary
        $this->line('═══════════════════════════════════════════════════════════════');
        $this->line('  Summary' . ($dryRun ? ' (DRY RUN)' : ''));
        $this->line('═══════════════════════════════════════════════════════════════');
        $this->line("  Total books:        {$stats['total']}");
        $this->line("  <fg=green>Fixed:</>             {$stats['fixed']}");
        $this->line("  <fg=cyan>Already correct:</>   {$stats['already_correct']}");
        $this->line("  <fg=yellow>Missing files:</>     {$stats['missing_files']}");
        $this->line("  <fg=red>Errors:</>            {$stats['errors']}");
        $this->line('═══════════════════════════════════════════════════════════════');

        if ($dryRun && $stats['fixed'] > 0) {
            $this->newLine();
            $this->info('Run without --dry-run to apply these fixes');
        }

        return 0;
    }
}
