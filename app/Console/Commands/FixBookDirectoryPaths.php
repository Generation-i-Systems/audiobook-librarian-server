<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Console\Command;

class FixBookDirectoryPaths extends Command
{
    protected $signature = 'books:fix-directory-paths
                            {--dry-run : Show what would be fixed without making changes}
                            {--limit= : Limit number of books to process}
                            {--debug : Show debug information}';

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
        $debug = $this->option('debug');

        $this->info('Checking books for directory path mismatches...');
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $bookRoot = rtrim(config('filesystems.disks.books.root') ?? config('app.book_root'), '/');
        $realBookRoot = realpath($bookRoot) ?: $bookRoot;

        $this->line("Book root: {$realBookRoot}");
        $this->newLine();

        // Get all books (use a high page size to get all books)
        $perPage = $limit ? (int)$limit : 10000;
        $result = $this->documentStore->listBooks(1, $perPage, [], true);
        $books = $result['data'] ?? [];

        if ($debug && !empty($books)) {
            $this->line("Sample book structure:");
            $this->line(json_encode(array_slice($books[0], 0, 10), JSON_PRETTY_PRINT));
            $this->newLine();
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

            // If no directory_path, try to extract from coverImage
            if (!$currentPath && !empty($book['coverImage'])) {
                // Extract directory from cover image path
                // e.g., "History/Stephen Fry/The Ode Less Travelled/fry 3/cover_audible_B0055PO24A.jpg"
                // Should give us: "History/Stephen Fry/The Ode Less Travelled/fry 3"
                $coverPath = $book['coverImage'];
                $pathParts = explode('/', $coverPath);

                // Remove the filename (last part)
                array_pop($pathParts);

                if (!empty($pathParts)) {
                    $currentPath = implode('/', $pathParts);

                    if ($debug) {
                        $progressBar->clear();
                        $this->line("<fg=blue>Extracted path from coverImage:</>");
                        $this->line("  Book: " . ($book['title'] ?? 'Unknown'));
                        $this->line("  Cover: {$coverPath}");
                        $this->line("  Path: {$currentPath}");
                        $progressBar->display();
                    }
                }
            }

            if (!$currentPath) {
                if ($debug) {
                    $progressBar->clear();
                    $this->error("Book missing directory_path and coverImage: " . ($book['title'] ?? 'Unknown'));
                    $this->line("Book keys: " . implode(', ', array_keys($book)));
                    $progressBar->display();
                }
                $stats['errors']++;
                continue;
            }

            // Check if files exist at current path
            $currentFullPath = "{$realBookRoot}/{$currentPath}";

            // If book doesn't have directory_path field but path exists, add it
            $needsDirectoryPathField = !isset($book['directory_path']);

            if (is_dir($currentFullPath)) {
                // Path exists on filesystem
                if ($needsDirectoryPathField) {
                    // Need to add directory_path field to database
                    $progressBar->clear();
                    $this->line("\n<fg=cyan>Adding directory_path field:</>");
                    $this->line("  Book: " . ($book['title'] ?? 'Unknown'));
                    $this->line("  Path: {$currentPath}");

                    if (!$dryRun) {
                        try {
                            $bookId = $book['id'] ?? $book['_id'] ?? null;
                            if ($bookId) {
                                $this->documentStore->updateBook($bookId, [
                                    'directory_path' => $currentPath,
                                ]);
                                $this->line("  <fg=green>✓ Added</>");
                                $stats['fixed']++;
                            } else {
                                $this->error("  No book ID found");
                                $stats['errors']++;
                            }
                        } catch (\Exception $e) {
                            $this->error("  Error updating: " . $e->getMessage());
                            $stats['errors']++;
                        }
                    } else {
                        $this->line("  <fg=blue>Would add</>");
                        $stats['fixed']++;
                    }
                    $progressBar->display();
                } else {
                    // Path is correct and field exists
                    $stats['already_correct']++;
                }
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
                        $bookId = $book['id'] ?? $book['_id'] ?? null;
                        if ($bookId) {
                            $this->documentStore->updateBook($bookId, [
                                'directory_path' => $expectedPath,
                            ]);
                            $this->line("  <fg=green>✓ Fixed</>");
                        } else {
                            $this->error("  No book ID found");
                            $stats['errors']++;
                            continue;
                        }
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
