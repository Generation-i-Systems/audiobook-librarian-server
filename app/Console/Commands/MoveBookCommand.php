<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\BookImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MoveBookCommand extends Command
{
    protected $signature = 'books:move 
                            {source : Source directory path}
                            {destination : Destination directory path}
                            {--dry-run : Show what would be moved without making changes}
                            {--force : Skip confirmation prompts}
                            {--import : Force import if no existing book found}';

    protected $description = 'Move a book directory in filesystem and update database record, or import if not found';

    protected BookImportService $importService;

    public function __construct(BookImportService $importService)
    {
        parent::__construct();
        $this->importService = $importService;
    }

    public function handle()
    {
        $sourcePath = $this->argument('source');
        $destinationPath = $this->argument('destination');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $forceImport = $this->option('import');

        // Resolve to absolute paths
        $sourcePath = realpath($sourcePath) ?: $sourcePath;
        $destinationPath = $this->resolveDestinationPath($destinationPath);

        // Validate source exists
        if (!File::exists($sourcePath)) {
            $this->error("Source path does not exist: {$sourcePath}");
            return 1;
        }

        if (!File::isDirectory($sourcePath)) {
            $this->error("Source path is not a directory: {$sourcePath}");
            return 1;
        }

        // Check if destination already exists
        if (File::exists($destinationPath)) {
            $this->error("Destination path already exists: {$destinationPath}");
            return 1;
        }

        // Show what will be done
        $this->info("Book Move Operation:");
        $this->line("Source:      {$sourcePath}");
        $this->line("Destination: {$destinationPath}");

        if ($dryRun) {
            $this->warn("DRY RUN - No changes will be made");
        }

        // Look for existing book with this directory path
        $book = $this->findBookByPath($sourcePath);

        if ($book) {
            $this->info("Found existing book: {$book->title} (ID: {$book->id})");
            
            if (!$force && !$dryRun) {
                if (!$this->confirm('Update this book and move its files?')) {
                    $this->info('Operation cancelled.');
                    return 0;
                }
            }

            return $this->moveExistingBook($book, $sourcePath, $destinationPath, $dryRun);
        } else {
            $this->warn("No existing book found for path: {$sourcePath}");
            
            if ($forceImport || (!$force && $this->confirm('Import this directory as a new book?'))) {
                return $this->importNewBook($sourcePath, $destinationPath, $dryRun);
            } else {
                // Just move the directory without importing
                return $this->moveDirectoryOnly($sourcePath, $destinationPath, $dryRun);
            }
        }
    }

    protected function resolveDestinationPath(string $destination): string
    {
        // If destination is relative, make it absolute
        if (!Str::startsWith($destination, '/')) {
            $destination = getcwd() . '/' . $destination;
        }
        
        return $destination;
    }

    protected function findBookByPath(string $path): ?Book
    {
        // Try exact match first
        $book = Book::where('directory_path', $path)->first();
        if ($book) {
            return $book;
        }

        // Try to find by basename match within book storage
        $basename = basename($path);
        $bookStoragePath = config('filesystems.disks.books.root') ?? env('BOOK_STORAGE_PATH');
        
        if ($bookStoragePath) {
            $books = Book::where('directory_path', 'like', "%{$basename}%")->get();
            
            foreach ($books as $book) {
                if (basename($book->directory_path) === $basename) {
                    return $book;
                }
            }
        }

        return null;
    }

    protected function moveExistingBook(Book $book, string $sourcePath, string $destinationPath, bool $dryRun): int
    {
        try {
            if (!$dryRun) {
                // Move the directory
                if (!File::move($sourcePath, $destinationPath)) {
                    $this->error("Failed to move directory from {$sourcePath} to {$destinationPath}");
                    return 1;
                }

                // Update book record
                $book->directory_path = $destinationPath;
                $book->save();
            }

            $this->info("✅ Successfully moved book and updated database:");
            $this->line("   Title: {$book->title}");
            $this->line("   Old path: {$sourcePath}");
            $this->line("   New path: {$destinationPath}");

            return 0;
        } catch (\Exception $e) {
            $this->error("Failed to move book: {$e->getMessage()}");
            return 1;
        }
    }

    protected function importNewBook(string $sourcePath, string $destinationPath, bool $dryRun): int
    {
        $this->info("Importing new book from: {$sourcePath}");

        if ($dryRun) {
            $this->line("Would import and move to: {$destinationPath}");
            return 0;
        }

        // Check if the destination is within the book storage path
        $bookStoragePath = config('filesystems.disks.books.root') ?? env('BOOK_STORAGE_PATH');
        $realBookPath = $bookStoragePath ? realpath($bookStoragePath) : null;
        $realDestPath = realpath(dirname($destinationPath));
        
        $isDestinationWithinBookStorage = $realBookPath && $realDestPath && 
            strpos($realDestPath, $realBookPath) === 0;

        if ($isDestinationWithinBookStorage) {
            // Move first, then import in place
            if (!File::move($sourcePath, $destinationPath)) {
                $this->error("Failed to move directory for import");
                return 1;
            }
            
            // Import in-place - files are already in the correct location
            $this->call('books:import-downloads', [
                'path' => [$destinationPath],
                '--force' => true,
                '--no-backup' => true,
            ]);
            
            $this->info("✅ Book imported in-place at destination");
        } else {
            // Import from source, then move to final destination
            $this->call('books:import-downloads', [
                'path' => [$sourcePath],
                '--force' => true,
                '--no-backup' => true,
            ]);

            // Find the imported book and move it to the final destination
            $book = $this->findBookByPath($sourcePath);
            if ($book && File::exists($book->directory_path)) {
                if (File::move($book->directory_path, $destinationPath)) {
                    $book->directory_path = $destinationPath;
                    $book->save();
                    $this->info("✅ Book imported and moved to final destination");
                } else {
                    $this->warn("Book imported but failed to move to final destination");
                }
            }
        }

        return 0;
    }

    protected function moveDirectoryOnly(string $sourcePath, string $destinationPath, bool $dryRun): int
    {
        $this->info("Moving directory without importing...");

        if ($dryRun) {
            $this->line("Would move directory to: {$destinationPath}");
            return 0;
        }

        try {
            if (!File::move($sourcePath, $destinationPath)) {
                $this->error("Failed to move directory");
                return 1;
            }

            $this->info("✅ Directory moved successfully");
            return 0;
        } catch (\Exception $e) {
            $this->error("Failed to move directory: {$e->getMessage()}");
            return 1;
        }
    }
}