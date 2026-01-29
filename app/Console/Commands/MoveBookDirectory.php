<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use App\Exceptions\PathEscapesBookRootException;
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
                            {paths* : Source and destination paths (minimum 2 required)}
                            {--dry-run : Show what would be done without making changes}
                            {--no-db : Only move files, do not update database}
                            {--no-parse : Do not parse and update metadata from new path}
                            {--require-book : Fail if no matching books are found (do not fall back to filesystem-only move)}
                            {--verify : Interactively review planned path/database changes before applying}
                            {--regex= : Use regex rename (format: s/pattern/replacement/flags)}
                            {--mv-options=* : Options to pass to mv command}';

    /**
     * The console command description.
     */
    protected $description = 'Move a book directory and update all database references';

    private string $bookRoot = '';
    private DocumentStoreServiceInterface $documentStore;
    private BookDirectoryParser $directoryParser;
    private bool $verboseOutput = false;
    private bool $forcedNoDb = false;

    public function __construct(DocumentStoreServiceInterface $documentStore, BookDirectoryParser $directoryParser)
    {
        parent::__construct();
        $this->documentStore = $documentStore;
        $this->directoryParser = $directoryParser;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $paths = $this->argument('paths');
        $dryRun = $this->option('dry-run');
        $noDb = $this->option('no-db');
        $verbose = $this->option('verbose');
        $regexPattern = $this->option('regex');

        $this->logDebug('Documentstore service resolved', [
            'service_class' => $this->documentStore::class,
        ]);

        $this->verboseOutput = (bool) $verbose;

        // Need at least source and destination
        if (count($paths) < 2) {
            $this->error('At least 2 paths required: source(s) and destination');
            return 1;
        }

        // Last path is always destination
        $destination = array_pop($paths);
        $sources = $paths;

        try {
            $this->bookRoot = $this->resolveBookRoot();
        } catch (\RuntimeException $exception) {
            $this->logError('Unable to resolve book root', ['error' => $exception->getMessage()]);
            $this->error($exception->getMessage());

            return 1;
        }

        $this->logDebug('Command invoked', [
            'sources' => $sources,
            'destination' => $destination,
            'dry_run' => (bool) $dryRun,
            'no_db' => (bool) $noDb,
            'regex' => (bool) $regexPattern,
            'resolved_book_root' => $this->bookRoot,
        ]);

        if ($verbose) {
            $this->line("<fg=blue>[DEBUG]</> Received sources: " . json_encode($sources));
        }

        // Handle regex mode
        if ($regexPattern) {
            return $this->handleRegexRename($sources, $regexPattern, $dryRun, $noDb, $verbose);
        }

        $this->logDebug('Primary move request prepared', [
            'destination' => $destination,
            'source_count' => count($sources),
        ]);

        if ($verbose) {
            $this->line("<fg=blue>[DEBUG]</> Destination: {$destination}");
            $this->line("<fg=blue>[DEBUG]</> Remaining sources: " . json_encode($sources));
        }

        if (empty($sources)) {
            $this->logWarning('No source files specified for move operation');
            $this->error("No source files specified");
            return 1;
        }

        // CRITICAL SAFETY: Validate book root is set and exists
        if (empty($this->bookRoot)) {
            $this->logError('Book root resolved to empty string');
            $this->error("BOOK_STORAGE_PATH not configured");
            return 1;
        }

        if ($verbose) {
            $this->line("<fg=blue>[DEBUG]</> Book root: {$this->bookRoot}");
        }

        if (!is_dir($this->bookRoot)) {
            $this->logError('Book root directory does not exist', ['book_root' => $this->bookRoot]);
            $this->error("Book root directory does not exist: {$this->bookRoot}");
            return 1;
        }

        // CRITICAL SAFETY: Validate database connection (unless --no-db)
        if (!$noDb) {
            try {
                DB::connection()->getPdo();
            } catch (\Exception $e) {
                $this->logError('Database connection failed', ['message' => $e->getMessage()]);
                $this->error("Database connection failed: " . $e->getMessage());
                $this->error("Use --no-db to skip database updates");
                return 1;
            }
        }

        // MINOR EDGE CASE: Validate book root is writable
        if (!is_writable($this->bookRoot)) {
            $this->logError('Book root is not writable', ['book_root' => $this->bookRoot]);
            $this->error("Book root is not writable: {$this->bookRoot}");
            return 1;
        }

        // CRITICAL SAFETY: Prevent moving book root itself
        $absBookRoot = $this->normalizePathString(realpath($this->bookRoot) ?: $this->bookRoot);
        foreach ($sources as $source) {
            try {
                $absSource = $this->normalizePath($source, false);
            } catch (\Throwable) {
                $absSource = $this->normalizePathString(str_replace("\0", '', (string) $source));
            }

            if ($this->normalizePathString($absSource) === $absBookRoot) {
                $this->logWarning('Attempted to move the book root directory', ['source' => $source]);
                $this->error("Cannot move the book root directory itself");
                return 1;
            }
        }

        // CRITICAL SAFETY: Validate ALL sources exist before ANY operations
        foreach ($sources as $source) {
            try {
                $sourcePath = $this->normalizePath($source);
            } catch (\Throwable $exception) {
                $this->logWarning('Invalid source path', ['source' => $source, 'error' => $exception->getMessage()]);
                $this->error($exception->getMessage());
                return 1;
            }
            if (!file_exists($sourcePath)) {
                $this->logWarning('Source path does not exist', ['source' => $source, 'normalized' => $sourcePath]);
                $this->error("Source does not exist: {$source}");
                return 2;
            }
        }

        // Tentatively normalize destination path - we'll validate it later if there are books
        $destinationOutsideBookRoot = false;
        $destPath = null;

        try {
            $destPath = $this->normalizePath($destination);
        } catch (PathEscapesBookRootException $exception) {
            // Defer handling until we know if there are any books
            $destinationOutsideBookRoot = true;
            $destPath = $this->normalizePath($destination, false);
        }

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
            $mkdirError = null;
            set_error_handler(function ($errno, $errstr) use (&$mkdirError) {
                $mkdirError = $errstr;
                return true;
            });
            $mkdirOk = mkdir($destDir, 0755, true);
            restore_error_handler();

            if (!$mkdirOk && !is_dir($destDir)) {
                $this->logError('Failed to create destination directory', [
                    'destination_directory' => $destDir,
                    'error' => $mkdirError ?? 'No PHP error captured',
                    'parent_exists' => is_dir(dirname($destDir)),
                    'parent_writable' => is_writable(is_dir(dirname($destDir)) ? dirname($destDir) : '/'),
                    'user' => posix_getpwuid(posix_geteuid())['name'] ?? 'unknown',
                ]);
                $this->error("Failed to create destination directory: {$destDir}. Error: " . ($mkdirError ?? 'Unknown error'));
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

                $this->logDebug('Computed relative path for source', [
                    'source' => $sourcePath,
                    'relative' => $sourceRelative,
                ]);

                if ($verbose) {
                    $this->line("<fg=blue>[DEBUG]</> Relative path: {$sourceRelative}");
                }

                $affectedBooks = $this->findAffectedBooks($sourceRelative);

                $this->logDebug('Affected books located', [
                    'source' => $sourcePath,
                    'relative' => $sourceRelative,
                    'count' => count($affectedBooks),
                ]);

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
                $this->logDebug('Source lies outside configured book root; skipping', [
                    'source' => $sourcePath,
                    'book_root' => $this->bookRoot,
                ]);

                $this->warn("Skipping source outside book root: {$sourcePath}");
            }
        }

        // If no book sources found, this is not a book move
        if (empty($bookSources)) {
            $this->logWarning('No book sources detected; deferring to mv fallback', [
                'sources' => $sources,
                'destination' => $destPath,
                'book_root' => $this->bookRoot,
            ]);

            if ((bool) $this->option('require-book')) {
                $this->error('No matching books were found for the provided sources. Aborting because --require-book is set.');
                return 1;
            }

            $this->warn('No directories within the book root matched the provided sources. Falling back with exit code 2.');
            return 2; // Signal to fall back to regular mv
        }

        // Now that we know we have books, validate the destination path
        if ($destinationOutsideBookRoot) {
            $this->logWarning('Destination path escapes book root', [
                'destination' => $destination,
                'book_root' => $this->bookRoot,
            ]);

            $this->warn("Path escapes book root: {$destPath} (from: {$destination}, bookRoot: {$this->bookRoot})");

            $isAbsoluteDestination = str_starts_with(trim((string) $destination), '/');
            if (!$isAbsoluteDestination) {
                $this->error('Destination is outside the configured book root. Aborting.');
                return 1;
            }

            $isInteractive = $this->input->isInteractive();
            if (!$isInteractive) {
                $this->error('Destination is outside the configured book root. Aborting.');
                return 1;
            }

            if (!$this->confirm('Destination is outside the configured book root. Continue with filesystem move only (database will not be updated)?')) {
                $this->info('Operation cancelled.');
                return 0;
            }

            if (!$noDb) {
                $this->warn('Skipping database updates because the destination is outside the book root.');
            }

            $noDb = true;
            $this->forcedNoDb = true;

            $this->logWarning('Continuing without database updates for destination outside book root', [
                'destination' => $destPath,
            ]);
        }

        $this->logDebug('Destination path normalized', [
            'input_destination' => $destination,
            'normalized_destination' => $destPath,
            'outside_book_root' => $destinationOutsideBookRoot,
        ]);

        $this->info("Found " . count($allAffectedBooks) . " book(s) to update across " . count($bookSources) . " source(s)");

        if ((bool) $this->option('verify')) {
            $isInteractive = $this->input->isInteractive();
            if (!$isInteractive) {
                $this->error('Cannot use --verify in non-interactive mode.');
                return 1;
            }

            $this->info("\n=== VERIFY MODE ===");
            foreach ($bookSources as $bookSource) {
                $sourcePath = $bookSource['path'];

                $finalDest = $destPath;
                $destinationIsDirectory = is_dir($destPath) || str_ends_with($destination, '/');
                if (count($sources) > 1 || $destinationIsDirectory) {
                    $finalDest = $destPath . '/' . basename($sourcePath);
                }

                $sourceRelative = $bookSource['relative'];
                $destRelative = $this->getRelativePath($finalDest);

                $this->line("\nMove: {$sourceRelative} → {$destRelative}");

                if ((bool) $noDb) {
                    continue;
                }

                foreach ($bookSource['books'] as $book) {
                    $oldPath = $book['directoryPath'] ?? '';
                    $newPath = $this->calculateNewPath($oldPath, $sourceRelative, $destRelative);
                    $title = $book['title'] ?? '';
                    $this->line("  • {$title}: {$oldPath} → {$newPath}");
                }
            }

            $confirmPrompt = $noDb ? 'Proceed with filesystem move?' : 'Proceed with filesystem move and database updates?';

            if (!$this->confirm($confirmPrompt, false)) {
                $this->info('Operation cancelled.');
                return 0;
            }
        }

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

            $destinationIsDirectory = is_dir($destPath) || str_ends_with($destination, '/');

            // If multiple sources or trailing slash, treat as "move into directory" operation.
            // If destination exists as a directory:
            // - If it has the same basename as the source and user did not include a trailing slash,
            //   treat as a collision (attempted rename onto existing directory).
            // - Otherwise, treat as moving into that directory.
            if (count($sources) > 1 || str_ends_with($destination, '/')) {
                $finalDest = $destPath . '/' . basename($sourcePath);
            } elseif (is_dir($destPath)) {
                if (basename($destPath) !== basename($sourcePath)) {
                    $finalDest = $destPath . '/' . basename($sourcePath);
                }
            } elseif ($destinationIsDirectory) {
                $finalDest = $destPath . '/' . basename($sourcePath);
            }

            if ($verbose) {
                $this->line("<fg=blue>[DEBUG]</> Checking destination: {$finalDest}");
                $this->line("<fg=blue>[DEBUG]</> Source basename: " . basename($sourcePath));
                $this->line("<fg=blue>[DEBUG]</> Destination ends with /: " . (str_ends_with($destination, '/') ? 'yes' : 'no'));
                $this->line("<fg=blue>[DEBUG]</> Destination is directory: " . (is_dir($destPath) ? 'yes' : 'no'));
            }

            if (file_exists($finalDest)) {
                $this->logError('Destination already exists', ['destination' => $finalDest]);
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
                $destinationIsDirectory = is_dir($destPath) || str_ends_with($destination, '/');
                if (count($sources) > 1 || str_ends_with($destination, '/')) {
                    $finalDest = $destPath . '/' . basename($sourcePath);
                } elseif (is_dir($destPath)) {
                    if (basename($destPath) !== basename($sourcePath)) {
                        $finalDest = $destPath . '/' . basename($sourcePath);
                    }
                } elseif ($destinationIsDirectory) {
                    $finalDest = $destPath . '/' . basename($sourcePath);
                }

                // CRITICAL SAFETY: Verify source still exists (race condition check)
                if (!file_exists($sourcePath)) {
                    $this->logError('Source disappeared during operation', ['source' => $sourcePath]);
                    throw new \Exception("Source disappeared during operation: {$sourcePath}");
                }

                // CRITICAL SAFETY: Verify destination still doesn't exist
                if (file_exists($finalDest)) {
                    $this->logError('Destination appeared during operation', ['destination' => $finalDest]);
                    throw new \Exception("Destination appeared during operation: {$finalDest}");
                }

                // Move the directory/file
                if (!rename($sourcePath, $finalDest)) {
                    $this->logError('Filesystem rename failed', ['source' => $sourcePath, 'destination' => $finalDest]);
                    throw new \Exception("Failed to move: {$sourcePath}");
                }

                $movedPaths[] = ['from' => $sourcePath, 'to' => $finalDest];
                $this->info("✓ Moved: " . basename($sourcePath));

                // Update database records
                if (!$noDb) {
                    $destRelative = $this->getRelativePath($finalDest);
                    $this->logDebug('Updating book records', [
                        'source_relative' => $sourceRelative,
                        'destination_relative' => $destRelative,
                        'book_count' => count($bookSource['books']),
                    ]);
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
                $this->logDebug('Database records updated', ['count' => $totalUpdated]);
                $this->info("✓ Updated {$totalUpdated} book record(s)");
            }

            $this->logDebug('Move operation completed successfully');
            $this->info("\n✓ Move completed successfully!");

            if ($this->forcedNoDb) {
                $this->warn('Database updates were skipped because the destination is outside the book root. Remember to update or remove affected book records manually.');
            }
            return 0;
        } catch (\Exception $e) {
            // CRITICAL SAFETY: Rollback database changes
            DB::rollBack();

            $this->logError('Move operation failed', [
                'message' => $e->getMessage(),
            ]);
            $this->error("Error during move: " . $e->getMessage());
            $this->error("Rolling back filesystem changes...");

            // CRITICAL SAFETY: Attempt to rollback filesystem changes
            foreach (array_reverse($movedPaths) as $move) {
                if (file_exists($move['to']) && !file_exists($move['from'])) {
                    if (@rename($move['to'], $move['from'])) {
                        $this->logDebug('Rolled back filesystem move', $move);
                        $this->info("✓ Rolled back: " . basename($move['from']));
                    } else {
                        $this->logError('Failed to rollback filesystem move', $move);
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
    private function normalizePath(string $path, bool $enforceBookRoot = true): string
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

        $normalizedTarget = $this->normalizePathString($realNormalized);

        if (!$enforceBookRoot) {
            return $normalizedTarget;
        }

        $candidateRoots = array_values(array_filter([
            $this->bookRoot,
            realpath($this->bookRoot) ?: null,
            config('app.book_root'),
            config('filesystems.disks.books.root'),
        ]));

        $isWithinRoot = false;

        foreach ($candidateRoots as $candidateRoot) {
            $normalizedRoot = $this->normalizePathString(realpath($candidateRoot) ?: $candidateRoot);

            if ($normalizedRoot === '') {
                continue;
            }

            $targetSegments = $this->splitPathSegments($normalizedTarget);
            $rootSegments = $this->splitPathSegments($normalizedRoot);

            if ($this->startsWithSegments($targetSegments, $rootSegments)) {
                $isWithinRoot = true;
                break;
            }
        }

        if (!$isWithinRoot) {
            throw new PathEscapesBookRootException("Path escapes book root: {$normalizedTarget} (from: {$path}, bookRoot: {$this->bookRoot})");
        }

        return $normalizedTarget;
    }

    private function normalizePathString(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $normalized = preg_replace('#/+#', '/', $normalized);

        return rtrim($normalized, '/');
    }

    private function resolveBookRoot(): string
    {
        $configBookRoot = config('filesystems.disks.books.root') ?? config('app.book_root');
        $diskRoot = config('filesystems.disks.books.root');

        $bookStoragePath = $diskRoot ?: $configBookRoot;

        if (empty($bookStoragePath)) {
            throw new \RuntimeException('Book storage path is not configured. Set config("app.book_root") or filesystems.disks.books.root.');
        }

        $realPath = realpath($bookStoragePath) ?: $bookStoragePath;

        return rtrim($realPath, '/');
    }

    /**
     * Check if path is within book root
     */
    private function isInBookRoot(string $path): bool
    {
        $realPath = $this->normalizePathString(realpath($path) ?: $path);
        $realBookRoot = $this->normalizePathString(realpath($this->bookRoot) ?: $this->bookRoot);

        return $this->startsWithSegments(
            $this->splitPathSegments($realPath),
            $this->splitPathSegments($realBookRoot)
        );
    }

    /**
     * Get path relative to book root
     */
    private function getRelativePath(string $absolutePath): string
    {
        $normalizedAbsolute = $this->normalizePathString(realpath($absolutePath) ?: $absolutePath);
        $normalizedRoot = $this->normalizePathString(realpath($this->bookRoot) ?: $this->bookRoot);

        $absoluteSegments = $this->splitPathSegments($normalizedAbsolute);
        $rootSegments = $this->splitPathSegments($normalizedRoot);

        if (!$this->startsWithSegments($absoluteSegments, $rootSegments)) {
            return trim(str_replace($normalizedRoot, '', $normalizedAbsolute), '/');
        }

        $relativeSegments = array_slice($absoluteSegments, count($rootSegments));

        return implode('/', $relativeSegments);
    }

    private function splitPathSegments(string $path): array
    {
        $normalized = $this->normalizePathString($path);
        $normalized = trim($normalized, '/');

        if ($normalized === '' || $normalized === '.') {
            return [];
        }

        return explode('/', $normalized);
    }

    private function startsWithSegments(array $segments, array $prefix): bool
    {
        $prefixCount = count($prefix);

        if ($prefixCount === 0) {
            return true;
        }

        if (count($segments) < $prefixCount) {
            return false;
        }

        for ($i = 0; $i < $prefixCount; $i++) {
            if (strcasecmp($segments[$i], $prefix[$i]) !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Find all books affected by this move (fast query)
     */
    private function findAffectedBooks(string $relativePath): array
    {
        try {
            // Use raw query for speed - find books where directoryPath starts with the source path
            $books = DB::table('books')
                ->whereNull('deleted_at')
                ->where(function ($query) use ($relativePath) {
                    $query->where('directory_path', $relativePath)
                        ->orWhere('directory_path', 'like', $relativePath . '/%');
                })
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
            $this->logError('Error finding affected books', [
                'relative_path' => $relativePath,
                'message' => $e->getMessage(),
            ]);
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
                        $this->logWarning('Book model not found during update', ['book_id' => $book['_id']]);
                        $this->error("  ✗ Book model not found for ID {$book['_id']}");
                        throw new \RuntimeException("Book model not found for ID {$book['_id']}");
                    }

                    // Update directory path
                    $bookModel->directory_path = $newPath;

                    // Extract genre from path structure if it changed
                    // The first segment of the relative path is the genre directory
                    $pathSegments = explode('/', trim($newPath, '/'));
                    $newGenre = $pathSegments[0];
                    if ($newGenre !== '') {
                        $currentGenres = $bookModel->genres->pluck('name')->toArray();
                        $currentPrimaryGenre = $currentGenres[0] ?? null;

                        // Only update genre if the directory genre is different from current primary genre
                        if ($newGenre !== $currentPrimaryGenre && !empty($newGenre)) {
                            $this->line("    • Genre: " . ($currentPrimaryGenre ?: '(none)') . " → {$newGenre}");
                            $bookModel->genres()->detach();
                            $genre = Genre::firstOrCreate(['name' => trim($newGenre)]);
                            $bookModel->genres()->attach($genre->id, ['is_primary' => true]);
                        }
                    }

                    // Parse the new path and update OTHER metadata if enabled
                    // IMPORTANT: Full metadata parsing is DISABLED by default because the parser
                    // extracts incorrect data from absolute paths (e.g., "media" as author)
                    // We only update genre above, which comes from the directory structure
                    $parseMetadata = (bool) config('app.enable_move_book_directory_parse', false);
                    if ($parseMetadata && !$noParse) {
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
                                    $currentSeriesModel = $bookModel->series->first();
                                    $currentSeries = $currentSeriesModel ? $currentSeriesModel->name : null;
                                    $newSeriesName = is_array($metadata['series']) ? array_key_first($metadata['series']) : $metadata['series'];
                                    $newSeriesNumber = is_array($metadata['series']) ? $metadata['series'][$newSeriesName] : ($metadata['seriesNumber'] ?? null);

                                    if ($newSeriesName !== $currentSeries) {
                                        $this->line("    • Series: " . ($currentSeries ?: '(none)') . " → {$newSeriesName}");
                                        $bookModel->series()->detach();
                                        $series = Series::firstOrCreate(['name' => trim($newSeriesName)]);
                                        $bookModel->series()->attach($series->id, [
                                            'series_number' => $newSeriesNumber,
                                        ]);
                                        $metadataUpdated = true;
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            $this->logWarning('Could not parse new path metadata', [
                                'path' => $fullPath,
                                'message' => $e->getMessage(),
                            ]);
                            $this->warn("    ⚠ Could not parse new path metadata: " . $e->getMessage());
                        }
                    }

                    $bookModel->save();

                    // Update librarian.json with new metadata (only if directory exists)
                    if (is_dir($bookModel->directory_path)) {
                        try {
                            $this->updateLibraryJson($bookModel);
                        } catch (\Exception $e) {
                            $this->logWarning('Could not update librarian.json', [
                                'book_id' => $bookModel->id,
                                'message' => $e->getMessage(),
                            ]);
                            $this->warn("    ⚠ Could not update librarian.json: " . $e->getMessage());
                        }
                    }

                    $updated++;
                    $this->line("  ✓ Updated: {$book['title']}");
                }
            } catch (\Exception $e) {
                $this->logError('Failed to update book record', [
                    'book_id' => $book['_id'],
                    'message' => $e->getMessage(),
                ]);
                $this->error("  ✗ Failed to update book {$book['_id']}: " . $e->getMessage());

                // CRITICAL SAFETY: propagate the exception so the outer handler can
                // roll back the DB transaction and undo filesystem moves.
                throw $e;
            }
        }

        return $updated;
    }

    private function formatContext(array $context): string
    {
        if (empty($context)) {
            return '';
        }

        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($encoded === false) {
            return ' ' . var_export($context, true);
        }

        return ' ' . $encoded;
    }

    private function logDebug(string $message, array $context = []): void
    {
        Log::debug('[MoveBookDirectory] ' . $message, $context);

        if ($this->verboseOutput) {
            $this->line('<fg=blue>[DEBUG]</> ' . $message . $this->formatContext($context));
        }
    }

    private function logWarning(string $message, array $context = []): void
    {
        Log::warning('[MoveBookDirectory] ' . $message, $context);

        if ($this->verboseOutput) {
            $this->warn($message . $this->formatContext($context));
        }
    }

    private function logError(string $message, array $context = []): void
    {
        Log::error('[MoveBookDirectory] ' . $message, $context);

        if ($this->verboseOutput) {
            $this->error($message . $this->formatContext($context));
        }
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
        // Support s<delim>pattern<delim>replacement<delim>flags format
        // Delimiter can be any single non-whitespace character.
        if (!preg_match('~^s(\S)(.*?)\1(.*?)\1([gimsx]*)$~', $pattern, $matches)) {
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
