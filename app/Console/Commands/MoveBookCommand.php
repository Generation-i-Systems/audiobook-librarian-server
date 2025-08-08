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

        // Check if this is a multi-book operation
        $booksToMove = $this->findBooksToMove($sourcePath);

        if (count($booksToMove) > 1) {
            return $this->handleMultiBookMove($booksToMove, $sourcePath, $destinationPath, $dryRun, $force);
        }

        // Single book operation (existing logic)
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

        // If destination is an existing directory, append the source basename
        if (File::exists($destination) && File::isDirectory($destination)) {
            $sourcePath = $this->argument('source');
            $sourceBasename = basename(realpath($sourcePath) ?: $sourcePath);
            $destination = rtrim($destination, '/') . '/' . $sourceBasename;
        }

        return $destination;
    }

    protected function findBooksToMove(string $sourcePath): array
    {
        $bookStoragePath = config('filesystems.disks.books.root') ?? env('BOOK_STORAGE_PATH');
        if (!$bookStoragePath) {
            return [];
        }

        $relativePath = $this->convertToRelativePath($sourcePath);

        // Find all books whose directory_path starts with the relative source path
        $books = Book::where('directory_path', 'LIKE', $relativePath . '%')->get();

        // Also find by absolute path match in case of mixed data
        $absoluteBooks = Book::where('directory_path', 'LIKE', $sourcePath . '%')->get();

        // Merge and deduplicate
        $allBooks = $books->merge($absoluteBooks)->unique('id');

        return $allBooks->all();
    }

    protected function handleMultiBookMove(array $books, string $sourcePath, string $destinationPath, bool $dryRun, bool $force): int
    {
        $count = count($books);
        $this->info("Found {$count} books to move:");

        $sourceRelative = $this->convertToRelativePath($sourcePath);
        $destinationRelative = $this->convertToRelativePath(
            $this->resolveDestinationPath($destinationPath)
        );

        // Show what will be moved
        foreach ($books as $book) {
            $currentPath = $book->directory_path;
            // Calculate new path by replacing the source prefix with destination
            $newPath = $destinationRelative . '/' . ltrim(substr($currentPath, strlen($sourceRelative)), '/');
            $newPath = trim($newPath, '/');

            $this->line("  • {$book->title} (ID: {$book->id})");
            $this->line("    From: {$currentPath}");
            $this->line("    To:   {$newPath}");
        }

        if ($dryRun) {
            $this->warn("DRY RUN - No changes will be made");
            return 0;
        }

        if (!$force && !$this->confirm("Move all {$count} books?")) {
            $this->info('Operation cancelled.');
            return 0;
        }

        $errors = 0;
        $bookStoragePath = config('filesystems.disks.books.root') ?? env('BOOK_STORAGE_PATH');

        foreach ($books as $book) {
            if (!$book) {
                $this->error("Book not found, skipping");
                $errors++;
                continue;
            }

            $currentPath = $book->directory_path;
            $currentAbsolutePath = $bookStoragePath . '/' . ltrim($currentPath, '/');

            // Calculate new relative path
            $newRelativePath = $destinationRelative . '/' . ltrim(substr($currentPath, strlen($sourceRelative)), '/');
            $newRelativePath = trim($newRelativePath, '/');
            $newAbsolutePath = $bookStoragePath . '/' . $newRelativePath;

            try {
                // Ensure destination directory exists
                $newParentDir = dirname($newAbsolutePath);
                if (!File::exists($newParentDir)) {
                    File::makeDirectory($newParentDir, 0755, true);
                }

                // Move the directory
                if (File::exists($currentAbsolutePath)) {
                    if (!File::move($currentAbsolutePath, $newAbsolutePath)) {
                        $this->error("Failed to move {$book->title} from {$currentAbsolutePath} to {$newAbsolutePath}");
                        $errors++;
                        continue;
                    }
                }

                // Update database record
                $book->directory_path = $newRelativePath;
                $book->save();

                $this->info("✅ Moved: {$book->title}");
            } catch (\Exception $e) {
                $this->error("Error moving {$book->title}: {$e->getMessage()}");
                $errors++;
            }
        }

        $successful = $count - $errors;
        $this->info("Completed: {$successful}/{$count} books moved successfully");

        return $errors > 0 ? 1 : 0;
    }

    protected function convertToRelativePath(string $absolutePath): string
    {
        $bookStoragePath = config('filesystems.disks.books.root') ?? env('BOOK_STORAGE_PATH');

        if (!$bookStoragePath) {
            return $absolutePath;
        }

        $realBookPath = realpath($bookStoragePath);
        $realAbsolutePath = realpath($absolutePath) ?: $absolutePath;

        if ($realBookPath && strpos($realAbsolutePath, $realBookPath) === 0) {
            // Remove the book storage path prefix and leading slash
            $relativePath = ltrim(substr($realAbsolutePath, strlen($realBookPath)), '/');
            return $relativePath;
        }

        return $absolutePath;
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

                // Update book record with relative path
                $book->directory_path = $this->convertToRelativePath($destinationPath);
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
                    $book->directory_path = $this->convertToRelativePath($destinationPath);
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
