<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\GenreMappingService;
use App\Services\OpenAudibleParser;
use App\Services\UnifiedBookImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportOpenAudible extends Command
{
    protected $signature = 'books:import-openaudible
                            {--source=/media/audiobooks/OpenAudible : OpenAudible directory}
                            {--dry-run : Show what would be imported without making changes}
                            {--include-old : Also import books from books_old directory}
                            {--force : Reimport books that already exist}
                            {--auto-replace : Automatically replace audio files for duplicates}
                            {--auto-merge : Automatically merge files for duplicates}
                            {--auto-skip : Automatically skip duplicates}
                            {--limit= : Limit number of books to import}';

    protected $description = 'Import books from OpenAudible with full metadata';

    private string $bookRoot;
    private UnifiedBookImporter $importer;
    private OpenAudibleParser $parser;
    private GenreMappingService $genreMapper;
    private array $stats = [
        'total' => 0,
        'imported' => 0,
        'skipped' => 0,
        'errors' => 0,
        'updated' => 0,
    ];

    public function __construct(
        UnifiedBookImporter $importer,
        OpenAudibleParser $parser,
        GenreMappingService $genreMapper
    ) {
        parent::__construct();
        $this->importer = $importer;
        $this->parser = $parser;
        $this->genreMapper = $genreMapper;
        $this->bookRoot = '';
    }

    private function resolveBookRoot(): string
    {
        $configBookRoot = config('app.book_root');
        $diskRoot = config('filesystems.disks.books.root');
        $envRoot = env('BOOK_STORAGE_PATH') ?: (getenv('BOOK_STORAGE_PATH') ?: null);

        $candidates = array_values(array_filter([
            is_string($configBookRoot) ? trim($configBookRoot) : '',
            is_string($diskRoot) ? trim($diskRoot) : '',
            is_string($envRoot) ? trim($envRoot) : '',
        ]));

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_dir($candidate)) {
                return rtrim((string) (realpath($candidate) ?: $candidate), '/');
            }
        }

        $fallback = $candidates[0] ?? '';

        return rtrim((string) (realpath($fallback) ?: $fallback), '/');
    }

    public function handle(): int
    {
        $this->bookRoot = $this->resolveBookRoot();

        $source = rtrim($this->option('source'), '/');
        $dryRun = $this->option('dry-run');
        $includeOld = $this->option('include-old');
        $force = $this->option('force');
        $limit = $this->option('limit');

        // CRITICAL SAFETY: Validate source directory
        if (!is_dir($source)) {
            $this->error("Source directory does not exist: {$source}");
            return 1;
        }

        // CRITICAL SAFETY: Validate books.json exists
        $booksJsonPath = $source . '/books.json';
        if (!file_exists($booksJsonPath)) {
            $this->error("books.json not found: {$booksJsonPath}");
            return 1;
        }

        // CRITICAL SAFETY: Validate book root
        if (!is_dir($this->bookRoot)) {
            $this->error("Book root does not exist: {$this->bookRoot}");
            return 1;
        }

        if (!is_writable($this->bookRoot)) {
            $this->error("Book root is not writable: {$this->bookRoot}");
            return 1;
        }

        // CRITICAL SAFETY: Validate database connection
        try {
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            $this->error("Database connection failed: " . $e->getMessage());
            return 1;
        }

        $this->info("Loading OpenAudible metadata...");

        // Load books.json using parser
        try {
            $booksData = $this->parser->loadBooksJson($source);
            $this->stats['total'] = count($booksData);
            $this->info("Found {$this->stats['total']} books in metadata");
        } catch (\Exception $e) {
            $this->error("Failed to load books.json: " . $e->getMessage());
            return 1;
        }

        // Apply limit if specified
        if ($limit) {
            $booksData = array_slice($booksData, 0, (int) $limit);
            $this->info("Limited to {$limit} books");
        }

        if ($dryRun) {
            $this->warn("=== DRY RUN MODE ===");
        }

        // Process each book
        $this->info("\nProcessing books...");
        $progressBar = $this->output->createProgressBar(count($booksData));
        $progressBar->start();

        foreach ($booksData as $bookData) {
            try {
                $this->processBook($bookData, $source, $dryRun, $force, $includeOld);
            } catch (\Exception $e) {
                $this->stats['errors']++;
                Log::error('OpenAudible import error', [
                    'book' => $bookData['title'] ?? 'Unknown',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                if (!$dryRun) {
                    $this->newLine();
                    $this->error("Error importing '{$bookData['title']}': " . $e->getMessage());
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Display summary
        $this->displaySummary($dryRun);

        return 0;
    }

    private function processBook(array $rawBookData, string $source, bool $dryRun, bool $force, bool $includeOld): void
    {
        // Normalize book data using parser
        $bookData = $this->parser->normalizeBookData($rawBookData);

        // Check if should skip
        if ($this->parser->shouldSkipBook($bookData)) {
            $this->stats['skipped']++;
            return;
        }

        // Find audio file using parser
        $audioFile = $this->parser->findAudioFile($bookData, $source, $includeOld);

        if (!$audioFile || !file_exists($audioFile)) {
            $this->stats['skipped']++;
            return;
        }

        // Handle duplicate detection for interactive prompts
        $duplicateAction = null;
        if (!$dryRun && !$force) {
            $existingBook = null;
            if (!empty($bookData['asin'])) {
                $existingBook = Book::where('asin', $bookData['asin'])->first();
            }
            if (!$existingBook && !empty($bookData['title'])) {
                $existingBook = Book::where('title', $bookData['title'])->first();
            }

            if ($existingBook) {
                $duplicateAction = $this->handleDuplicateBook($existingBook, $bookData, $dryRun, $force);

                if ($duplicateAction === 'skip') {
                    $this->stats['skipped']++;
                    return;
                }
            }
        }

        // Use unified importer
        $result = $this->importer->importBook($bookData, [
            'source_path' => dirname($audioFile),
            'dry_run' => $dryRun,
            'force' => $force,
            'duplicate_action' => $duplicateAction,
        ]);

        // Update stats based on result
        switch ($result['status']) {
            case 'imported':
            case 'would_import':
                $this->stats['imported']++;
                break;
            case 'updated':
            case 'would_update':
                $this->stats['updated']++;
                break;
            case 'skipped':
                $this->stats['skipped']++;
                break;
            case 'error':
                $this->stats['errors']++;
                break;
        }
    }

    private function handleDuplicateBook(Book $existingBook, array $bookData, bool $dryRun, bool $force): string
    {
        // Check for auto-action flags
        if ($this->option('auto-replace')) {
            return 'replace';
        }

        if ($this->option('auto-merge')) {
            return 'merge';
        }

        if ($this->option('auto-skip')) {
            return 'skip';
        }

        // If --force flag, default to replace
        if ($force) {
            return 'replace';
        }

        // If dry-run, just report
        if ($dryRun) {
            return 'replace'; // Simulate replace in dry-run
        }

        // Interactive prompt
        $this->newLine();
        $this->warn("Duplicate book found:");
        $this->line("  Title: {$bookData['title']}");
        $this->line("  Existing: {$existingBook->directory_path}");
        $this->line("  ASIN: " . ($bookData['asin'] ?? 'N/A'));
        $this->newLine();

        $choice = $this->choice(
            'How would you like to handle this duplicate?',
            [
                'replace' => 'Replace - Delete old audio files, replace with new OpenAudible files',
                'merge' => 'Merge - Keep old audio files, add new non-audio files (images, PDFs)',
                'skip' => 'Skip - Leave existing book unchanged',
                'manual' => 'Manual - Stop and let me fix it manually',
            ],
            'replace'
        );

        if ($choice === 'manual') {
            $this->error("Import paused. Please resolve manually and restart.");
            exit(1);
        }

        return $choice;
    }

    private function displaySummary(bool $dryRun): void
    {
        $this->info("=== Import Summary ===");
        $this->info("Total books in metadata: {$this->stats['total']}");
        $this->info("Imported: {$this->stats['imported']}");
        $this->info("Updated: {$this->stats['updated']}");
        $this->info("Skipped: {$this->stats['skipped']}");
        $this->info("Errors: {$this->stats['errors']}");

        if ($dryRun) {
            $this->warn("\nThis was a dry run. No changes were made.");
        }
    }
}
