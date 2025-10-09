<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use App\Services\BookDirectoryParser;
use App\Services\MetadataProcessingService;
use App\Traits\BookImportTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ImportBook extends Command
{
    use BookImportTrait;

    protected $signature = 'books:import
                            {paths* : Book directories or files to import}
                            {--dry-run : Show what would be imported without making changes}
                            {--force : Reimport books that already exist}';

    protected $description = 'Import books from filesystem locations into the library';

    private string $bookRoot;
    private DocumentStoreServiceInterface $documentStore;
    private BookDirectoryParser $parser;
    private MetadataProcessingService $metadataProcessor;
    private array $stats = [
        'total' => 0,
        'imported' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => 0,
    ];

    public function __construct(
        DocumentStoreServiceInterface $documentStore,
        BookDirectoryParser $parser,
        MetadataProcessingService $metadataProcessor
    ) {
        parent::__construct();
        $this->documentStore = $documentStore;
        $this->parser = $parser;
        $this->metadataProcessor = $metadataProcessor;
        $this->bookRoot = rtrim(env('BOOK_STORAGE_PATH'), '/');
    }

    public function handle(): int
    {
        $paths = $this->argument('paths');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        // If no paths provided, check current directory
        if (empty($paths)) {
            $currentDir = getcwd();
            if ($this->hasAudioFiles($currentDir)) {
                $paths = [$currentDir];
                $this->info("No paths provided, using current directory: {$currentDir}");
            } else {
                $this->showHelp();
                return 1;
            }
        }

        // Validate paths
        $validPaths = [];
        foreach ($paths as $path) {
            $realPath = realpath($path);
            if (!$realPath || !file_exists($realPath)) {
                $this->error("Path does not exist: {$path}");
                continue;
            }
            $validPaths[] = $realPath;
        }

        if (empty($validPaths)) {
            $this->error('No valid paths to import');
            return 1;
        }

        $this->info('Starting import...');
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }
        $this->newLine();

        // Process each path
        foreach ($validPaths as $path) {
            $this->processPath($path, $dryRun, $force);
        }

        // Display summary
        $this->displaySummary($dryRun);

        return 0;
    }

    private function hasAudioFiles(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $audioFiles = glob($dir . '/*.{m4b,m4a,mp3}', GLOB_BRACE);
        return !empty($audioFiles);
    }

    private function showHelp(): void
    {
        $this->newLine();
        $this->line('<fg=cyan>Import Book - Quick Import Tool</>');
        $this->newLine();
        $this->line('Import audiobooks from any filesystem location into your library.');
        $this->newLine();
        $this->line('<fg=yellow>Usage:</>');
        $this->line('  php artisan books:import [paths...]');
        $this->line('  import-bk [paths...]');
        $this->newLine();
        $this->line('<fg=yellow>Examples:</>');
        $this->line('  # Import current directory (if it has audio files)');
        $this->line('  import-bk');
        $this->newLine();
        $this->line('  # Import specific book directory');
        $this->line('  import-bk /path/to/book/directory');
        $this->newLine();
        $this->line('  # Import multiple books');
        $this->line('  import-bk /path/book1 /path/book2 /path/book3');
        $this->newLine();
        $this->line('  # Import single audio file');
        $this->line('  import-bk /path/to/audiobook.m4b');
        $this->newLine();
        $this->line('<fg=yellow>Options:</>');
        $this->line('  --dry-run    Show what would be imported without making changes');
        $this->line('  --force      Reimport books that already exist');
        $this->newLine();
        $this->line('<fg=yellow>Behavior:</>');
        $this->line('  • Books already in library structure are updated in database only');
        $this->line('  • Books outside library are moved/copied into proper location');
        $this->line('  • Metadata is read from .abs files or parsed from directory structure');
        $this->line('  • Existing books are skipped unless --force is used');
        $this->newLine();
    }

    private function processPath(string $path, bool $dryRun, bool $force): void
    {
        $this->stats['total']++;

        if (is_file($path)) {
            $this->processFile($path, $dryRun, $force);
        } elseif (is_dir($path)) {
            $this->processDirectory($path, $dryRun, $force);
        }
    }

    private function processFile(string $filePath, bool $dryRun, bool $force): void
    {
        // Check if it's an audio file
        if (!preg_match('/\.(m4b|m4a|mp3)$/i', $filePath)) {
            $this->warn("Skipping non-audio file: {$filePath}");
            $this->stats['skipped']++;
            return;
        }

        $directory = dirname($filePath);
        $this->processDirectory($directory, $dryRun, $force);
    }

    private function processDirectory(string $dirPath, bool $dryRun, bool $force): void
    {
        try {
            $this->line("Processing: {$dirPath}");

            // Check if directory is already in library structure
            $isInLibrary = str_starts_with($dirPath, $this->bookRoot);

            // Parse the directory
            $bookData = $this->parser->parseDirectory($dirPath);

            if (empty($bookData)) {
                $this->warn("  No valid book data found");
                $this->stats['skipped']++;
                return;
            }

            // Check if book already exists
            $existingBook = $this->findExistingBook($bookData);

            if ($existingBook && !$force) {
                $this->line("  <fg=yellow>Book already exists:</> {$existingBook['title']}");
                $this->stats['skipped']++;
                return;
            }

            if ($dryRun) {
                if ($existingBook) {
                    $this->line("  <fg=cyan>Would update:</> {$bookData['title']}");
                    $this->stats['updated']++;
                } else {
                    $this->line("  <fg=green>Would import:</> {$bookData['title']}");
                    $this->stats['imported']++;
                }
                return;
            }

            // Import or update the book
            if ($isInLibrary) {
                // Already in library, just update database
                // Find and add cover image if not already in bookData
                if (empty($bookData['cover_image'])) {
                    [$coverImage, $coverCandidates] = $this->findCoverImageCandidate($bookData['directory_path']);
                    if ($coverImage) {
                        $bookData['cover_image'] = $coverImage;
                    }
                }

                $this->updateBookInDatabase($bookData, $existingBook);
                $this->line("  <fg=green>✓</> Updated in database");
                $this->stats['updated']++;
            } else {
                // Move/copy to library and import
                $this->importBookToLibrary($bookData, $dirPath, $existingBook);
                $this->line("  <fg=green>✓</> Imported to library");
                if ($existingBook) {
                    $this->stats['updated']++;
                } else {
                    $this->stats['imported']++;
                }
            }
        } catch (\Exception $e) {
            $this->error("  Error: " . $e->getMessage());
            $this->stats['errors']++;
        }
    }

    private function findExistingBook(array $bookData): ?array
    {
        // Try to find by directory path
        if (!empty($bookData['directory_path'])) {
            $book = $this->documentStore->findBookByDirectoryPath($bookData['directory_path']);
            if ($book) {
                return $book;
            }
        }

        // Try to find by title and author
        if (!empty($bookData['title'])) {
            $books = $this->documentStore->listBooks(1, 100, ['title' => $bookData['title']]);
            if (!empty($books['data'])) {
                foreach ($books['data'] as $book) {
                    if ($book['title'] === $bookData['title']) {
                        return $book;
                    }
                }
            }
        }

        return null;
    }

    private function updateBookInDatabase(array $bookData, ?array $existingBook): void
    {
        if ($existingBook) {
            $this->documentStore->updateBook($existingBook['_id'], $bookData);
        } else {
            $this->documentStore->createBook($bookData);
        }
    }

    private function importBookToLibrary(array $bookData, string $sourcePath, ?array $existingBook): void
    {
        // Determine destination path
        $destPath = $this->bookRoot . '/' . $bookData['directory_path'];

        // Create destination directory
        if (!File::exists($destPath)) {
            File::makeDirectory($destPath, 0755, true);
        }

        // Copy/move files
        $files = File::files($sourcePath);
        $coverImage = null;

        foreach ($files as $file) {
            $filename = $file->getFilename();
            $destFile = $destPath . '/' . $filename;

            // Copy file
            File::copy($file->getPathname(), $destFile);

            // Check if this is a cover image
            $ext = strtolower($file->getExtension());
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                if (stripos($filename, 'cover') !== false) {
                    $coverImage = $filename;
                } elseif (!$coverImage) {
                    // Use first image found if no cover-named image
                    $coverImage = $filename;
                }
            }
        }

        // Add cover image to book data if found
        if ($coverImage) {
            $bookData['cover_image'] = $coverImage;
        }

        // Update database
        $this->updateBookInDatabase($bookData, $existingBook);
    }

    private function displaySummary(bool $dryRun): void
    {
        $this->newLine();
        $this->line('═══════════════════════════════════════════════════════════════');
        $this->line('  Import Summary' . ($dryRun ? ' (DRY RUN)' : ''));
        $this->line('═══════════════════════════════════════════════════════════════');
        $this->line("  Total processed:  {$this->stats['total']}");
        $this->line("  <fg=green>Imported:</>        {$this->stats['imported']}");
        $this->line("  <fg=cyan>Updated:</>         {$this->stats['updated']}");
        $this->line("  <fg=yellow>Skipped:</>         {$this->stats['skipped']}");
        $this->line("  <fg=red>Errors:</>          {$this->stats['errors']}");
        $this->line('═══════════════════════════════════════════════════════════════');
        $this->newLine();
    }
}
