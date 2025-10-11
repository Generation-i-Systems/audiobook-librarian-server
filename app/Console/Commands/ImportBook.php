<?php

namespace App\Console\Commands;

use App\Services\BookDirectoryParser;
use App\Services\UnifiedBookImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ImportBook extends Command
{
    protected $signature = 'books:import
                            {paths* : Book directories or files to import}
                            {--dry-run : Show what would be imported without making changes}
                            {--force : Reimport books that already exist}';

    protected $description = 'Import books from filesystem locations into the library';

    private string $bookRoot;
    private UnifiedBookImporter $importer;
    private BookDirectoryParser $parser;
    private array $stats = [
        'total' => 0,
        'imported' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => 0,
    ];

    public function __construct(
        UnifiedBookImporter $importer,
        BookDirectoryParser $parser
    ) {
        parent::__construct();
        $this->importer = $importer;
        $this->parser = $parser;
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

        // When importing a single audio file, treat the file itself as a single-book directory
        // Create a temporary directory with just this file, then import it
        $directory = dirname($filePath);
        $filename = basename($filePath);

        $this->line("Processing file: {$filename}");

        // Create a unique temporary directory for this single file
        $tempDir = sys_get_temp_dir() . '/librarian-import-' . uniqid();
        try {
            mkdir($tempDir, 0755, true);

            // Create a symlink to the file in the temp directory
            $tempFilePath = $tempDir . '/' . $filename;
            symlink($filePath, $tempFilePath);

            // Also symlink the metadata file if it exists
            $metadataFile = $directory . '/metadata.abs';
            if (file_exists($metadataFile)) {
                symlink($metadataFile, $tempDir . '/metadata.abs');
            }

            // Process this temporary directory as a book directory
            $this->processDirectory($tempDir, $dryRun, $force);

        } finally {
            // Clean up temporary directory
            if (is_dir($tempDir)) {
                array_map('unlink', glob("$tempDir/*"));
                rmdir($tempDir);
            }
        }
    }

    private function processDirectory(string $dirPath, bool $dryRun, bool $force): void
    {
        try {
            $this->line("Processing: {$dirPath}");

            // Parse the directory
            $bookData = $this->parser->parseDirectory($dirPath);

            if (empty($bookData)) {
                $this->warn("  No valid book data found");
                $this->stats['skipped']++;
                return;
            }

            // Use unified importer
            $result = $this->importer->importBook($bookData, [
                'source_path' => $dirPath,
                'dry_run' => $dryRun,
                'force' => $force,
            ]);

            // Handle result and update stats
            switch ($result['status']) {
                case 'imported':
                    $this->line("  <fg=green>✓</> Imported: {$bookData['title']}");
                    $this->stats['imported']++;
                    break;
                case 'updated':
                    $this->line("  <fg=cyan>✓</> Updated: {$bookData['title']}");
                    $this->stats['updated']++;
                    break;
                case 'skipped':
                    $reason = $result['reason'] ?? 'unknown';
                    $this->line("  <fg=yellow>Skipped:</> {$bookData['title']} ({$reason})");
                    $this->stats['skipped']++;
                    break;
                case 'would_import':
                    $this->line("  <fg=green>Would import:</> {$bookData['title']}");
                    $this->stats['imported']++;
                    break;
                case 'would_update':
                    $this->line("  <fg=cyan>Would update:</> {$bookData['title']}");
                    $this->stats['updated']++;
                    break;
                case 'error':
                    $this->error("  Error: " . ($result['error'] ?? 'Unknown error'));
                    $this->stats['errors']++;
                    break;
                default:
                    $this->stats['errors']++;
                    break;
            }
        } catch (\Exception $e) {
            $this->error("  Error: " . $e->getMessage());
            $this->stats['errors']++;
        }
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
