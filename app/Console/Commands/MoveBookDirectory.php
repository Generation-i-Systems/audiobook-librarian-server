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
    protected $signature = 'books:move 
                            {sources* : Source path(s) to move}
                            {--dry-run : Show what would be done without making changes}
                            {--no-db : Only move files, do not update database}
                            {--mv-options=* : Options to pass to mv command}';

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
        $sources = $this->argument('sources');
        $dryRun = $this->option('dry-run');
        $noDb = $this->option('no-db');

        // Last argument is destination
        $destination = array_pop($sources);
        
        if (empty($sources)) {
            $this->error("No source files specified");
            return 1;
        }

        // Normalize destination path
        $destPath = $this->normalizePath($destination);
        
        // Auto-create parent directories (mkdmv behavior)
        $destDir = $destPath;
        if (count($sources) > 1 || str_ends_with($destination, '/')) {
            // Multiple sources or trailing slash means dest is a directory
            $destDir = $destPath;
        } else {
            // Single source, dest might be a file
            $destDir = dirname($destPath);
        }
        
        if (!$dryRun && !is_dir($destDir)) {
            if (!mkdir($destDir, 0755, true)) {
                $this->error("Failed to create destination directory: {$destDir}");
                return 1;
            }
        }

        $allAffectedBooks = [];
        $bookSources = [];
        
        // Process each source
        foreach ($sources as $source) {
            $sourcePath = $this->normalizePath($source);
            
            // Check if this source is in book root
            if ($this->isInBookRoot($sourcePath)) {
                $sourceRelative = $this->getRelativePath($sourcePath);
                $affectedBooks = $this->findAffectedBooks($sourceRelative);
                
                if (!empty($affectedBooks)) {
                    $bookSources[] = [
                        'path' => $sourcePath,
                        'relative' => $sourceRelative,
                        'books' => $affectedBooks,
                    ];
                    $allAffectedBooks = array_merge($allAffectedBooks, $affectedBooks);
                }
            }
        }

        // If no book sources found, this is not a book move
        if (empty($bookSources)) {
            return 2; // Signal to fall back to regular mv
        }

        $this->info("Found " . count($allAffectedBooks) . " book(s) to update across " . count($bookSources) . " source(s)");

        if ($dryRun) {
            $this->info("\n=== DRY RUN MODE ===");
            foreach ($bookSources as $bookSource) {
                $this->info("Would move: {$bookSource['path']}");
                $this->info("        to: {$destPath}");
                
                if (!$noDb) {
                    $this->info("  Would update " . count($bookSource['books']) . " book(s)");
                }
            }
            return 0;
        }

        // Perform moves and database updates
        $totalUpdated = 0;
        
        foreach ($bookSources as $bookSource) {
            $sourcePath = $bookSource['path'];
            $sourceRelative = $bookSource['relative'];
            
            // Calculate final destination for this source
            $finalDest = $destPath;
            if (count($sources) > 1 || str_ends_with($destination, '/')) {
                $finalDest = $destPath . '/' . basename($sourcePath);
            }
            
            // Move the directory/file
            if (!rename($sourcePath, $finalDest)) {
                $this->error("Failed to move: {$sourcePath}");
                continue;
            }
            
            $this->info("✓ Moved: " . basename($sourcePath));

            // Update database records
            if (!$noDb && !empty($bookSource['books'])) {
                $destRelative = $this->getRelativePath($finalDest);
                $updated = $this->updateBookRecords(
                    $bookSource['books'], 
                    $sourceRelative, 
                    $destRelative
                );
                $totalUpdated += $updated;
            }
        }

        if (!$noDb && $totalUpdated > 0) {
            $this->info("✓ Updated {$totalUpdated} book record(s)");
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
