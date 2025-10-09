<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MoveBookDirectory extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'books:move-directory 
                            {source : Source directory path (relative to book root or absolute)}
                            {destination : Destination directory path (relative to book root or absolute)}
                            {--dry-run : Show what would be done without making changes}
                            {--no-db : Only move files, do not update database}';

    /**
     * The console command description.
     */
    protected $description = 'Move a book directory and update all database references';

    private string $bookRoot;
    private DocumentStoreServiceInterface $documentStore;

    public function __construct(DocumentStoreServiceInterface $documentStore)
    {
        parent::__construct();
        $this->documentStore = $documentStore;
        $this->bookRoot = rtrim(env('BOOK_STORAGE_PATH'), '/');
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $source = $this->argument('source');
        $destination = $this->argument('destination');
        $dryRun = $this->option('dry-run');
        $noDb = $this->option('no-db');

        // Normalize paths
        $sourcePath = $this->normalizePath($source);
        $destPath = $this->normalizePath($destination);

        // Fast validation: check if source is in book root
        if (!$this->isInBookRoot($sourcePath)) {
            $this->error("Source path is not in book root: {$sourcePath}");
            return 1;
        }

        // Fast validation: check if source exists
        if (!is_dir($sourcePath)) {
            $this->error("Source directory does not exist: {$sourcePath}");
            return 1;
        }

        // Fast validation: check if destination already exists
        if (file_exists($destPath)) {
            $this->error("Destination already exists: {$destPath}");
            return 1;
        }

        // Get relative paths for database operations
        $sourceRelative = $this->getRelativePath($sourcePath);
        $destRelative = $this->getRelativePath($destPath);

        // Fast check: find affected books
        $affectedBooks = $this->findAffectedBooks($sourceRelative);
        
        if (empty($affectedBooks)) {
            $this->warn("No books found with path: {$sourceRelative}");
            $this->info("This appears to be a non-book directory. Proceeding with file move only.");
        } else {
            $this->info("Found " . count($affectedBooks) . " book(s) to update");
        }

        if ($dryRun) {
            $this->info("\n=== DRY RUN MODE ===");
            $this->info("Would move: {$sourcePath}");
            $this->info("        to: {$destPath}");
            
            if (!empty($affectedBooks) && !$noDb) {
                $this->info("\nWould update " . count($affectedBooks) . " book record(s):");
                foreach ($affectedBooks as $book) {
                    $oldPath = $book['directoryPath'] ?? $book['directory_path'] ?? 'N/A';
                    $newPath = $this->calculateNewPath($oldPath, $sourceRelative, $destRelative);
                    $this->line("  - {$oldPath} -> {$newPath}");
                }
            }
            
            return 0;
        }

        // Perform the move
        $this->info("Moving directory...");
        
        // Create parent directory if needed
        $destParent = dirname($destPath);
        if (!is_dir($destParent)) {
            if (!mkdir($destParent, 0755, true)) {
                $this->error("Failed to create parent directory: {$destParent}");
                return 1;
            }
        }

        // Move the directory
        if (!rename($sourcePath, $destPath)) {
            $this->error("Failed to move directory");
            return 1;
        }

        $this->info("✓ Directory moved successfully");

        // Update database records
        if (!$noDb && !empty($affectedBooks)) {
            $this->info("Updating database records...");
            $updated = $this->updateBookRecords($affectedBooks, $sourceRelative, $destRelative);
            $this->info("✓ Updated {$updated} book record(s)");
        }

        $this->info("\n✓ Move completed successfully!");
        return 0;
    }

    /**
     * Normalize path to absolute path
     */
    private function normalizePath(string $path): string
    {
        // If already absolute, return as-is
        if (str_starts_with($path, '/')) {
            return rtrim($path, '/');
        }

        // If relative, make it relative to book root
        return rtrim($this->bookRoot . '/' . ltrim($path, '/'), '/');
    }

    /**
     * Check if path is within book root
     */
    private function isInBookRoot(string $path): bool
    {
        $realPath = realpath($path) ?: $path;
        $realBookRoot = realpath($this->bookRoot);
        
        return str_starts_with($realPath, $realBookRoot);
    }

    /**
     * Get path relative to book root
     */
    private function getRelativePath(string $absolutePath): string
    {
        return trim(str_replace($this->bookRoot, '', $absolutePath), '/');
    }

    /**
     * Find all books affected by this move (fast query)
     */
    private function findAffectedBooks(string $relativePath): array
    {
        try {
            // Use raw query for speed - find books where directoryPath starts with the source path
            $books = DB::table('books')
                ->where('directory_path', 'like', $relativePath . '%')
                ->select('id', 'directory_path', 'title')
                ->get()
                ->toArray();

            return array_map(function ($book) {
                return [
                    '_id' => $book->id,
                    'directoryPath' => $book->directory_path,
                    'title' => $book->title,
                ];
            }, $books);
        } catch (\Exception $e) {
            Log::error('Error finding affected books: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Calculate new path for a book
     */
    private function calculateNewPath(string $oldPath, string $sourceRelative, string $destRelative): string
    {
        // If exact match, replace entirely
        if ($oldPath === $sourceRelative) {
            return $destRelative;
        }

        // If starts with source path, replace the prefix
        if (str_starts_with($oldPath, $sourceRelative . '/')) {
            return $destRelative . substr($oldPath, strlen($sourceRelative));
        }

        return $oldPath;
    }

    /**
     * Update book records with new paths
     */
    private function updateBookRecords(array $books, string $sourceRelative, string $destRelative): int
    {
        $updated = 0;

        foreach ($books as $book) {
            try {
                $oldPath = $book['directoryPath'];
                $newPath = $this->calculateNewPath($oldPath, $sourceRelative, $destRelative);

                if ($oldPath !== $newPath) {
                    // Update using raw query for speed
                    DB::table('books')
                        ->where('id', $book['_id'])
                        ->update([
                            'directory_path' => $newPath,
                            'updated_at' => now(),
                        ]);

                    $updated++;
                    $this->line("  ✓ Updated: {$book['title']}");
                }
            } catch (\Exception $e) {
                $this->error("  ✗ Failed to update book {$book['_id']}: " . $e->getMessage());
            }
        }

        return $updated;
    }
}
