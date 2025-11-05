<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Series;
use App\Services\BookDirectoryParser;
use App\Traits\HandlesLibraryJson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MoveBookDirectory extends Command
{
    use HandlesLibraryJson;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'books:move
                            {sources* : Source path(s) to move OR regex pattern if using --regex}
                            {--dry-run : Show what would be done without making changes}
                            {--no-db : Only move files, do not update database}
                            {--no-parse : Do not parse and update metadata from new path}
                            {--regex= : Use regex rename (format: s/pattern/replacement/flags)}
                            {--mv-options=* : Options to pass to mv command}';

    /**
     * The console command description.
     */
    protected $description = 'Move a book directory and update all database references';

    private string $bookRoot;
    private DocumentStoreServiceInterface $documentStore;
    private BookDirectoryParser $directoryParser;

    public function __construct(DocumentStoreServiceInterface $documentStore, BookDirectoryParser $directoryParser)
    {
        parent::__construct();
        $this->documentStore = $documentStore;
        $this->directoryParser = $directoryParser;

        // Resolve book root to real path (handles symlinks and bind mounts)
        $bookStoragePath = env('BOOK_STORAGE_PATH');
        $realPath = realpath($bookStoragePath);

        // Use the real path if it exists, otherwise fall back to configured path
        $this->bookRoot = rtrim($realPath ?: $bookStoragePath, '/');
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $sources = $this->argument('sources');
        $dryRun = $this->option('dry-run');
        $noDb = $this->option('no-db');
        $verbose = $this->option('verbose');
        $regexPattern = $this->option('regex');

        if ($verbose) {
            $this->line("<fg=blue>[DEBUG]</> Received sources: " . json_encode($sources));
        }

        // Handle regex mode
        if ($regexPattern) {
            return $this->handleRegexRename($sources, $regexPattern, $dryRun, $noDb, $verbose);
        }

        // Last argument is destination
        $destination = array_pop($sources);

        if ($verbose) {
            $this->line("<fg=blue>[DEBUG]</> Destination: {$destination}");
            $this->line("<fg=blue>[DEBUG]</> Remaining sources: " . json_encode($sources));
        }

        if (empty($sources)) {
            $this->error("No source files specified");
            return 1;
        }

        // CRITICAL SAFETY: Validate book root is set and exists
        if (empty($this->bookRoot)) {
            $this->error("BOOK_STORAGE_PATH not configured");
            return 1;
        }

        if ($verbose) {
            $this->line("<fg=blue>[DEBUG]</> Book root: {$this->bookRoot}");
        }

        if (!is_dir($this->bookRoot)) {
            $this->error("Book root directory does not exist: {$this->bookRoot}");
            return 1;
        }

        // CRITICAL SAFETY: Validate database connection (unless --no-db)
        if (!$noDb) {
            try {
                DB::connection()->getPdo();
            } catch (\Exception $e) {
                $this->error("Database connection failed: " . $e->getMessage());
                $this->error("Use --no-db to skip database updates");
                return 1;
            }
        }

        // MINOR EDGE CASE: Validate book root is writable
        if (!is_writable($this->bookRoot)) {
            $this->error("Book root is not writable: {$this->bookRoot}");
            return 1;
        }

        // CRITICAL SAFETY: Prevent moving book root itself
        foreach ($sources as $source) {
            $absSource = realpath($source) ?: $source;
            $absBookRoot = realpath($this->bookRoot);

            if ($absSource === $absBookRoot) {
                $this->error("Cannot move the book root directory itself");
                return 1;
            }
        }

        // CRITICAL SAFETY: Validate ALL sources exist before ANY operations
        foreach ($sources as $source) {
            $sourcePath = $this->normalizePath($source);
            if (!file_exists($sourcePath)) {
                $this->error("Source does not exist: {$source}");
                return 1;
            }
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

            if ($verbose) {
                $this->line("<fg=blue>[DEBUG]</> Processing source: {$source}");
                $this->line("<fg=blue>[DEBUG]</> Normalized to: {$sourcePath}");
            }

            // Check if this source is in book root
            if ($this->isInBookRoot($sourcePath)) {
                if ($verbose) {
                    $this->line("<fg=blue>[DEBUG]</> Source is in book root");
                }

                $sourceRelative = $this->getRelativePath($sourcePath);

                if ($verbose) {
                    $this->line("<fg=blue>[DEBUG]</> Relative path: {$sourceRelative}");
                }

                $affectedBooks = $this->findAffectedBooks($sourceRelative);

                if ($verbose) {
                    $this->line("<fg=blue>[DEBUG]</> Found " . count($affectedBooks) . " affected books");
                }

                if (!empty($affectedBooks)) {
                    $bookSources[] = [
                        'path' => $sourcePath,
                        'relative' => $sourceRelative,
                        'books' => $affectedBooks,
                    ];
                    $allAffectedBooks = array_merge($allAffectedBooks, $affectedBooks);
                }
            } else {
                if ($verbose) {
                    $this->line("<fg=blue>[DEBUG]</> Source is NOT in book root");
                }
            }
        }

        // If no book sources found, this is not a book move
        if (empty($bookSources)) {
            if ($verbose) {
                $this->line("<fg=blue>[DEBUG]</> No book sources found, returning exit code 2");
            }
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

        // CRITICAL SAFETY: Validate destinations don't already exist
        foreach ($bookSources as $bookSource) {
            $sourcePath = $bookSource['path'];
            $finalDest = $destPath;

            // If multiple sources, trailing slash, OR destination is an existing directory,
            // treat as "move into directory" operation
            if (count($sources) > 1 || str_ends_with($destination, '/') || is_dir($destPath)) {
                $finalDest = $destPath . '/' . basename($sourcePath);
            }

            if ($verbose) {
                $this->line("<fg=blue>[DEBUG]</> Checking destination: {$finalDest}");
                $this->line("<fg=blue>[DEBUG]</> Source basename: " . basename($sourcePath));
                $this->line("<fg=blue>[DEBUG]</> Destination ends with /: " . (str_ends_with($destination, '/') ? 'yes' : 'no'));
                $this->line("<fg=blue>[DEBUG]</> Destination is directory: " . (is_dir($destPath) ? 'yes' : 'no'));
            }

            if (file_exists($finalDest)) {
                $this->error("Destination already exists: {$finalDest}");
                $this->error("Aborting to prevent data loss");
                return 1;
            }
        }

        // CRITICAL SAFETY: Use database transaction for atomicity
        DB::beginTransaction();

        try {
            $totalUpdated = 0;
            $movedPaths = []; // Track what we've moved for rollback

            foreach ($bookSources as $bookSource) {
                $sourcePath = $bookSource['path'];
                $sourceRelative = $bookSource['relative'];

                // Calculate final destination for this source
                $finalDest = $destPath;
                if (count($sources) > 1 || str_ends_with($destination, '/') || is_dir($destPath)) {
                    $finalDest = $destPath . '/' . basename($sourcePath);
                }

                // CRITICAL SAFETY: Verify source still exists (race condition check)
                if (!file_exists($sourcePath)) {
                    throw new \Exception("Source disappeared during operation: {$sourcePath}");
                }

                // CRITICAL SAFETY: Verify destination still doesn't exist
                if (file_exists($finalDest)) {
                    throw new \Exception("Destination appeared during operation: {$finalDest}");
                }

                // Move the directory/file
                if (!rename($sourcePath, $finalDest)) {
                    throw new \Exception("Failed to move: {$sourcePath}");
                }

                $movedPaths[] = ['from' => $sourcePath, 'to' => $finalDest];
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

            // CRITICAL SAFETY: Commit transaction only if everything succeeded
            DB::commit();

            if (!$noDb && $totalUpdated > 0) {
                $this->info("✓ Updated {$totalUpdated} book record(s)");
            }

            $this->info("\n✓ Move completed successfully!");
            return 0;
        } catch (\Exception $e) {
            // CRITICAL SAFETY: Rollback database changes
            DB::rollBack();

            $this->error("Error during move: " . $e->getMessage());
            $this->error("Rolling back filesystem changes...");

            // CRITICAL SAFETY: Attempt to rollback filesystem changes
            foreach (array_reverse($movedPaths) as $move) {
                if (file_exists($move['to']) && !file_exists($move['from'])) {
                    if (@rename($move['to'], $move['from'])) {
                        $this->info("✓ Rolled back: " . basename($move['from']));
                    } else {
                        $this->error("✗ Failed to rollback: " . basename($move['from']));
                        $this->error("  Manual intervention required!");
                    }
                }
            }

            return 1;
        }
    }

    /**
     * Normalize path to absolute path with security checks
     */
    private function normalizePath(string $path): string
    {
        // MINOR EDGE CASE: Handle empty paths
        if (empty(trim($path))) {
            throw new \Exception("Path cannot be empty");
        }

        // MINOR EDGE CASE: Trim whitespace
        $path = trim($path);

        // CRITICAL SAFETY: Remove null bytes (security)
        $path = str_replace("\0", '', $path);

        // MINOR EDGE CASE: Remove control characters
        $path = preg_replace('/[\x00-\x1F\x7F]/', '', $path);

        // MINOR EDGE CASE: Normalize backslashes to forward slashes
        $path = str_replace('\\', '/', $path);

        // MINOR EDGE CASE: Remove multiple consecutive slashes
        $path = preg_replace('#/+#', '/', $path);

        // MINOR EDGE CASE: Remove trailing dots (Windows compatibility)
        $path = rtrim($path, '.');

        // MINOR EDGE CASE: Reject paths that are just dots
        if ($path === '.' || $path === '..') {
            throw new \Exception("Invalid path: {$path}");
        }

        // CRITICAL SAFETY: Detect directory traversal attempts
        if (strpos($path, '..') !== false) {
            // Allow it but validate the result stays in book root
            $needsValidation = true;
        } else {
            $needsValidation = false;
        }

        // If already absolute, use as-is, otherwise make relative to book root
        if (str_starts_with($path, '/')) {
            $normalized = rtrim($path, '/');
        } else {
            $normalized = rtrim($this->bookRoot . '/' . ltrim($path, '/'), '/');
        }

        // Resolve .. and . in the path
        $parts = explode('/', $normalized);
        $resolved = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($resolved);
            } else {
                $resolved[] = $part;
            }
        }

        $normalized = '/' . implode('/', $resolved);

        // CRITICAL SAFETY: Ensure resolved path stays within book root
        // We need to resolve the normalized path to its real path for comparison
        // since bookRoot is already resolved to real path
        $parentDir = dirname($normalized);
        $basename = basename($normalized);

        // Get real path of parent directory (or use as-is if doesn't exist yet)
        $realParent = realpath($parentDir) ?: $parentDir;
        $realNormalized = rtrim($realParent . '/' . $basename, '/');

        if (!str_starts_with($realNormalized, $this->bookRoot)) {
            throw new \Exception("Path escapes book root: {$realNormalized} (from: {$path}, bookRoot: {$this->bookRoot})");
        }

        return $normalized;
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
        $noParse = $this->option('no-parse');

        foreach ($books as $book) {
            try {
                $oldPath = $book['directoryPath'];
                $newPath = $this->calculateNewPath($oldPath, $sourceRelative, $destRelative);

                if ($oldPath !== $newPath) {
                    // Get the Book model to update relationships
                    $bookModel = Book::with(['authors', 'narrators', 'genres', 'series', 'publisher'])
                        ->find($book['_id']);

                    if (!$bookModel) {
                        $this->error("  ✗ Book model not found for ID {$book['_id']}");
                        continue;
                    }

                    // Update directory path
                    $bookModel->directory_path = $newPath;

                    // Parse the new path and update metadata if enabled
                    if (!$noParse) {
                        try {
                            // Check if directory exists and has audio files before parsing
                            $fullPath = $this->bookRoot . '/' . $newPath;
                            if (!is_dir($fullPath)) {
                                throw new \Exception("Directory does not exist: {$fullPath}");
                            }

                            $parsedData = $this->directoryParser->parseDirectory($fullPath);

                            if (!empty($parsedData) && isset($parsedData[0])) {
                                $metadata = $parsedData[0];
                                $metadataUpdated = false;

                                // Update title if different
                                if (!empty($metadata['title']) && $metadata['title'] !== $bookModel->title) {
                                    $this->line("    • Title: {$bookModel->title} → {$metadata['title']}");
                                    $bookModel->title = $metadata['title'];
                                    $metadataUpdated = true;
                                }

                                // Update genre if different
                                if (!empty($metadata['genre'])) {
                                    $genres = is_array($metadata['genre']) ? $metadata['genre'] : [$metadata['genre']];
                                    $currentGenres = $bookModel->genres->pluck('name')->toArray();

                                    if ($genres !== $currentGenres) {
                                        $this->line("    • Genre: " . implode(', ', $currentGenres) . " → " . implode(', ', $genres));
                                        $bookModel->genres()->detach();
                                        $isPrimary = true;
                                        foreach ($genres as $genreName) {
                                            $genre = Genre::firstOrCreate(['name' => trim($genreName)]);
                                            $bookModel->genres()->attach($genre->id, ['is_primary' => $isPrimary]);
                                            $isPrimary = false;
                                        }
                                        $metadataUpdated = true;
                                    }
                                }

                                // Update authors if different
                                if (!empty($metadata['author'])) {
                                    $authors = is_array($metadata['author']) ? $metadata['author'] : [$metadata['author']];
                                    $currentAuthors = $bookModel->authors->pluck('name')->toArray();

                                    if ($authors !== $currentAuthors) {
                                        $this->line("    • Author: " . implode(', ', $currentAuthors) . " → " . implode(', ', $authors));
                                        $bookModel->authors()->detach();
                                        foreach ($authors as $authorName) {
                                            $author = Author::firstOrCreate(['name' => trim($authorName)]);
                                            $bookModel->authors()->attach($author->id);
                                        }
                                        $metadataUpdated = true;
                                    }
                                }

                                // Update series if different
                                if (!empty($metadata['series'])) {
                                    $currentSeries = $bookModel->series ? $bookModel->series->name : null;
                                    $newSeriesName = is_array($metadata['series']) ? array_key_first($metadata['series']) : $metadata['series'];
                                    $newSeriesNumber = is_array($metadata['series']) ? $metadata['series'][$newSeriesName] : ($metadata['seriesNumber'] ?? null);

                                    if ($newSeriesName !== $currentSeries) {
                                        $this->line("    • Series: " . ($currentSeries ?: '(none)') . " → {$newSeriesName}");
                                        $bookModel->series()->detach();
                                        $series = Series::firstOrCreate(['name' => trim($newSeriesName)]);
                                        $bookModel->series()->attach($series->id, [
                                            'series_number' => $newSeriesNumber
                                        ]);
                                        $metadataUpdated = true;
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            $this->warn("    ⚠ Could not parse new path metadata: " . $e->getMessage());
                            Log::debug("Failed to parse directory path", [
                                'path' => $fullPath ?? $newPath,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }

                    $bookModel->save();

                    // Update librarian.json with new metadata (only if directory exists)
                    if (is_dir($bookModel->directory_path)) {
                        try {
                            $this->updateLibraryJson($bookModel);
                        } catch (\Exception $e) {
                            $this->warn("    ⚠ Could not update librarian.json: " . $e->getMessage());
                            Log::debug("Failed to update librarian.json", [
                                'book_id' => $bookModel->id,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }

                    $updated++;
                    $this->line("  ✓ Updated: {$book['title']}");
                }
            } catch (\Exception $e) {
                $this->error("  ✗ Failed to update book {$book['_id']}: " . $e->getMessage());
                Log::error("Failed to update book record", [
                    'book_id' => $book['_id'],
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        return $updated;
    }

    /**
     * Handle regex-based renaming
     */
    private function handleRegexRename(array $sources, string $regexPattern, bool $dryRun, bool $noDb, bool $verbose): int
    {
        // Parse regex pattern (format: s/pattern/replacement/flags)
        $parsed = $this->parseRegexPattern($regexPattern);
        if (!$parsed) {
            $this->error("Invalid regex pattern. Use format: s/pattern/replacement/flags");
            $this->line("Example: s/Book/Novel/g or s/(\d+)/Book $1/");
            return 1;
        }

        [$pattern, $replacement, $flags] = $parsed;

        if ($verbose) {
            $this->line("<fg=blue>[DEBUG]</> Pattern: {$pattern}");
            $this->line("<fg=blue>[DEBUG]</> Replacement: {$replacement}");
            $this->line("<fg=blue>[DEBUG]</> Flags: {$flags}");
        }

        // CRITICAL SAFETY: Validate book root
        if (empty($this->bookRoot) || !is_dir($this->bookRoot)) {
            $this->error("Book root not configured or doesn't exist");
            return 1;
        }

        // CRITICAL SAFETY: Validate database connection (unless --no-db)
        if (!$noDb) {
            try {
                DB::connection()->getPdo();
            } catch (\Exception $e) {
                $this->error("Database connection failed: " . $e->getMessage());
                return 1;
            }
        }

        // Normalize and validate all source paths
        $normalizedSources = [];
        foreach ($sources as $source) {
            try {
                $sourcePath = $this->normalizePath($source);
                if (!file_exists($sourcePath)) {
                    $this->error("Source does not exist: {$source}");
                    return 1;
                }
                if (!$this->isInBookRoot($sourcePath)) {
                    $this->error("Source is not in book root: {$source}");
                    return 1;
                }
                $normalizedSources[] = $sourcePath;
            } catch (\Exception $e) {
                $this->error("Invalid source path '{$source}': " . $e->getMessage());
                return 1;
            }
        }

        // Process each source
        $renames = [];
        foreach ($normalizedSources as $sourcePath) {
            $basename = basename($sourcePath);
            $dirname = dirname($sourcePath);

            // Apply regex to basename
            $newBasename = $this->applyRegex($basename, $pattern, $replacement, $flags);

            if ($newBasename === $basename) {
                if ($verbose) {
                    $this->line("<fg=gray>No change:</> {$basename}");
                }
                continue;
            }

            $destPath = $dirname . '/' . $newBasename;

            // Check if destination already exists
            if (file_exists($destPath)) {
                $this->error("Destination already exists: {$destPath}");
                $this->error("Skipping: {$basename}");
                continue;
            }

            $renames[] = [
                'source' => $sourcePath,
                'dest' => $destPath,
                'oldBasename' => $basename,
                'newBasename' => $newBasename,
            ];
        }

        if (empty($renames)) {
            $this->info("No files matched the pattern");
            return 0;
        }

        // Show what will be renamed
        $this->info("Found " . count($renames) . " file(s) to rename:");
        foreach ($renames as $rename) {
            $this->line("  {$rename['oldBasename']} → {$rename['newBasename']}");
        }

        if ($dryRun) {
            $this->info("\n=== DRY RUN MODE ===");
            return 0;
        }

        // Confirm before proceeding
        if (!$this->confirm("\nProceed with rename?", true)) {
            $this->info("Cancelled");
            return 0;
        }

        // CRITICAL SAFETY: Use database transaction
        DB::beginTransaction();

        try {
            $totalUpdated = 0;
            $movedPaths = [];

            foreach ($renames as $rename) {
                $sourcePath = $rename['source'];
                $destPath = $rename['dest'];

                // CRITICAL SAFETY: Verify source still exists
                if (!file_exists($sourcePath)) {
                    throw new \Exception("Source disappeared: {$sourcePath}");
                }

                // CRITICAL SAFETY: Verify destination still doesn't exist
                if (file_exists($destPath)) {
                    throw new \Exception("Destination appeared: {$destPath}");
                }

                // Move the directory/file
                if (!rename($sourcePath, $destPath)) {
                    throw new \Exception("Failed to rename: {$sourcePath}");
                }

                $movedPaths[] = ['from' => $sourcePath, 'to' => $destPath];
                $this->info("✓ Renamed: {$rename['oldBasename']} → {$rename['newBasename']}");

                // Update database records
                if (!$noDb) {
                    $sourceRelative = $this->getRelativePath($sourcePath);
                    $destRelative = $this->getRelativePath($destPath);

                    $affectedBooks = $this->findAffectedBooks($sourceRelative);
                    if (!empty($affectedBooks)) {
                        $updated = $this->updateBookRecords($affectedBooks, $sourceRelative, $destRelative);
                        $totalUpdated += $updated;
                    }
                }
            }

            // CRITICAL SAFETY: Commit transaction
            DB::commit();

            if (!$noDb && $totalUpdated > 0) {
                $this->info("✓ Updated {$totalUpdated} book record(s)");
            }

            $this->info("\n✓ Rename completed successfully!");
            return 0;
        } catch (\Exception $e) {
            // CRITICAL SAFETY: Rollback
            DB::rollBack();

            $this->error("Error during rename: " . $e->getMessage());
            $this->error("Rolling back filesystem changes...");

            foreach (array_reverse($movedPaths) as $move) {
                if (file_exists($move['to']) && !file_exists($move['from'])) {
                    if (@rename($move['to'], $move['from'])) {
                        $this->info("✓ Rolled back: " . basename($move['from']));
                    } else {
                        $this->error("✗ Failed to rollback: " . basename($move['from']));
                    }
                }
            }

            return 1;
        }
    }

    /**
     * Parse regex pattern in s/pattern/replacement/flags format
     */
    private function parseRegexPattern(string $pattern): ?array
    {
        // Support s/pattern/replacement/flags format
        if (!preg_match('#^s([/#])(.+?)\1(.*?)\1([gimsx]*)$#', $pattern, $matches)) {
            return null;
        }

        return [
            $matches[2], // pattern
            $matches[3], // replacement
            $matches[4], // flags
        ];
    }

    /**
     * Apply regex transformation
     */
    private function applyRegex(string $text, string $pattern, string $replacement, string $flags): string
    {
        // Build regex with flags
        $modifiers = '';
        if (str_contains($flags, 'i')) {
            $modifiers .= 'i';
        }
        if (str_contains($flags, 'm')) {
            $modifiers .= 'm';
        }
        if (str_contains($flags, 's')) {
            $modifiers .= 's';
        }
        if (str_contains($flags, 'x')) {
            $modifiers .= 'x';
        }

        $regex = '#' . $pattern . '#' . $modifiers;

        // Check if global flag is set
        if (str_contains($flags, 'g')) {
            // Replace all occurrences
            return preg_replace($regex, $replacement, $text);
        } else {
            // Replace first occurrence only
            return preg_replace($regex, $replacement, $text, 1);
        }
    }
}
