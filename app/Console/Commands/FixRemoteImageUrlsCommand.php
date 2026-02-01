<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use App\Services\ExternalCoverService;
use App\Traits\BookImportTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FixRemoteImageUrlsCommand extends Command
{
    use BookImportTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'books:fix-remote-images
                            {--limit=0 : Limit the number of books to process}
                            {--dry-run : Don\'t actually download images or update the database}
                            {--force : Force processing of URLs that look like local paths}
                            {--clean-paths : Clean up corrupted cover image paths with embedded quotes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download remote cover images to book directories and update database URLs';

    /**
     * The document store service.
     *
     * @var DocumentStoreServiceInterface
     */
    protected $documentStore;

    /**
     * The external cover service.
     *
     * @var ExternalCoverService
     */
    protected $externalCoverService;

    /**
     * Create a new command instance.
     *
     * @param DocumentStoreServiceInterface $documentStore
     * @param ExternalCoverService $externalCoverService
     * @return void
     */
    public function __construct(DocumentStoreServiceInterface $documentStore, ExternalCoverService $externalCoverService)
    {
        parent::__construct();
        $this->documentStore = $documentStore;
        $this->externalCoverService = $externalCoverService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting to fix remote image URLs...');

        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $dryRun = (bool) $this->option('dry-run');
        $cleanPaths = $this->option('clean-paths');

        if ($dryRun) {
            $this->warn('DRY RUN MODE: No changes will be made');
        }

        if ($cleanPaths) {
            $this->info('CLEAN PATHS MODE: Focusing on cleaning corrupted cover image paths');
        }

        // Get all books with raw database data (not transformed)
        // The listBooks method doesn't include directory_path in the transformed data
        // So we need to get the raw books data
        $books = $this->documentStore->dumpAllBooks();

        // Log the structure for debugging
        Log::debug('Books result structure', ['count' => count($books)]);

        if ($limit) {
            $this->info("Limiting to {$limit} books");
            $books = array_slice($books, 0, $limit);
        }

        $this->info("Found " . count($books) . " books to process");

        $fixed = 0;
        $skipped = 0;
        $errors = 0;

        $progressBar = $this->output->createProgressBar(count($books));
        $progressBar->start();

        // Process each book
        foreach ($books as $book) {
            // Skip books without an ID
            if (!isset($book['id'])) {
                $this->warn("Book missing ID, skipping");
                $skipped++;
                $progressBar->advance();
                continue;
            }

            $id = $book['id'];

            // Raw Eloquent data uses camelCase field names (with accessors)
            $coverUrl = $book['coverImage'] ?? null;
            $directoryPath = $book['directoryPath'] ?? null;

            // Handle cover URL normalization - but only for local paths, not remote URLs
            $fullCoverPath = null;
            if ($coverUrl) {
                // Only normalize if it's NOT a remote URL
                if (!$this->isRemoteUrl($coverUrl)) {
                    $originalCoverUrl = $coverUrl;
                    $normalizedCoverUrl = $this->cleanupCoverUrl($coverUrl); // This now returns just filename

                    if ($normalizedCoverUrl !== $originalCoverUrl) {
                        $this->warn("Book {$id}: Normalizing local cover URL to filename only");
                        $this->warn("  From: {$originalCoverUrl}");
                        $this->warn("  To: {$normalizedCoverUrl}");

                        // Update the database with just the filename
                        if (!$dryRun) {
                            $result = $this->documentStore->updateBook($id, ['coverImage' => $normalizedCoverUrl]);
                            if ($result) {
                                $this->info("Updated book {$id} with normalized cover URL");
                                $fixed++;
                            } else {
                                $this->error("Failed to update book {$id} with normalized cover URL");
                                $errors++;
                            }
                        } else {
                            $this->info("Would update book {$id} with normalized cover URL");
                            $fixed++;
                        }

                        // Update our working variable to the normalized version
                        $coverUrl = $normalizedCoverUrl;

                        // If we're only cleaning paths, skip the rest of the processing
                        if ($cleanPaths) {
                            $progressBar->advance();
                            continue;
                        }
                    }

                    // Get the full path for file operations (combines filename with directory)
                    $fullCoverPath = $this->getFullCoverPath($coverUrl, $directoryPath);
                } else {
                    // For remote URLs, don't normalize yet - we'll do that after download
                    $this->info("Book {$id} has remote cover URL: {$coverUrl}");
                }
            }

            if (!$coverUrl) {
                $this->warn("Book {$id} has no cover URL, skipping");
                $skipped++;
                $progressBar->advance();
                continue;
            }

            // Check if the cover URL is already a local path (non-HTTP)
            if (!$this->isRemoteUrl($coverUrl)) {
                if ($fullCoverPath) {
                    // Check if the file exists using direct filesystem check (handles symlinks)
                    $booksRoot = config('filesystems.disks.books.root') ?? storage_path('app/books');
                    $coverFileSystemPath = rtrim($booksRoot, '/') . '/' . ltrim($fullCoverPath, '/');

                    if (file_exists($coverFileSystemPath)) {
                        $this->info("Book {$id} has valid local cover image: {$fullCoverPath}");

                        // Ensure we have a directory path (extract from full path if needed)
                        if (!$directoryPath && str_contains($fullCoverPath, '/')) {
                            $extractedDirectoryPath = dirname($fullCoverPath);
                            if (!$dryRun) {
                                $this->documentStore->updateBook($id, ['directoryPath' => $extractedDirectoryPath]);
                                $this->info("Updated book {$id} with directory path: {$extractedDirectoryPath}");
                            } else {
                                $this->info("Would update book {$id} with directory path: {$extractedDirectoryPath}");
                            }
                        }

                        $skipped++;
                        $progressBar->advance();
                        continue;
                    } else {
                        $this->warn("Book {$id} has local cover URL but file doesn't exist: {$fullCoverPath}");
                    }
                } else {
                    $this->warn("Book {$id} has local cover URL but no directory path to construct full path");
                    $skipped++;
                    $progressBar->advance();
                    continue;
                }
            }

            if (!$directoryPath) {
                // Try to create directory path from title and author
                $title = $book['title'] ?? null;
                $authors = $book['author'] ?? [];

                if ($title && !empty($authors)) {
                    $authorName = is_array($authors) ? $authors[0] : $authors;
                    $safeTitle = $this->sanitizeFileName($title);
                    $safeAuthor = $this->sanitizeFileName($authorName);

                    // Create a directory path in the format: Author/Title
                    $directoryPath = $safeAuthor . '/' . $safeTitle;

                    $this->info("Book {$id} has no directory path, creating: {$directoryPath}");

                    // Update the book with the new directory path if not in dry-run mode
                    if (!$dryRun) {
                        $this->documentStore->updateBook($id, ['directoryPath' => $directoryPath]);
                    } else {
                        $this->warn("Would update book {$id} with new directory path: {$directoryPath}");
                    }
                } else {
                    $this->warn("Book {$id} has no directory path and insufficient info to create one, skipping");
                    $skipped++;
                    $progressBar->advance();
                    continue;
                }
            }

            // Validate that the directory path exists and contains audiobooks
            if ($directoryPath && !$this->validateDirectoryPath($directoryPath, $book, $dryRun, $id)) {
                $skipped++;
                $progressBar->advance();
                continue;
            }

            $this->line("");
            $this->info("Processing book {$id}: {$book['title']} with cover URL: {$coverUrl}");

            if ($dryRun) {
                $this->warn("Would download {$coverUrl} to {$directoryPath}");
                $fixed++;
                $progressBar->advance();
                continue;
            }

            // Process remote URLs - download them first
            if ($this->isRemoteUrl($coverUrl)) {
                // Download the image from remote URL using ExternalCoverService
                $this->info("Downloading image from remote URL: {$coverUrl}");

                if (!$directoryPath) {
                    $this->warn("Book {$id} has remote URL but no directory path - cannot download");
                    $errors++;
                    $progressBar->advance();
                    continue;
                }

                $downloadResult = $this->externalCoverService->downloadCoverImage(
                    $coverUrl,
                    $directoryPath,
                    'remote',
                    null
                );

                if (!$downloadResult['success']) {
                    $this->error("Failed to download image from remote URL: {$coverUrl}. Error: {$downloadResult['error']}");
                    $errors++;
                    $progressBar->advance();
                    continue;
                }

                // Update the book in the database with just the filename (normalize the path)
                $newCoverPath = $downloadResult['path'];
                $normalizedCoverPath = basename($newCoverPath); // Store just filename
                $result = $this->documentStore->updateBook($id, ['coverImage' => $normalizedCoverPath]);

                if ($result) {
                    $this->info("Downloaded and updated book {$id} with coverImage: {$normalizedCoverPath}");
                    $fixed++;
                } else {
                    $this->error("Downloaded image but failed to update book {$id} in database");
                    $errors++;
                }

                $progressBar->advance();
                continue;
            } else {
                // Handle local-style path
                $this->info("Handling local-style path: {$coverUrl}");

                // Check if the file exists using direct filesystem check (handles symlinks)
                $booksRoot = config('filesystems.disks.books.root') ?? storage_path('app/books');
                $coverFullPath = rtrim($booksRoot, '/') . '/' . ltrim($fullCoverPath, '/');

                if (file_exists($coverFullPath)) {
                    $this->info("File already exists at {$fullCoverPath}");
                    // File exists, ensure database has just the filename (normalized)
                    $normalizedCoverPath = basename($fullCoverPath);

                    // Update the book in the database with normalized filename
                    $result = $this->documentStore->updateBook($id, ['coverImage' => $normalizedCoverPath]);

                    if ($result) {
                        $this->info("Updated book {$id} with coverImage: {$normalizedCoverPath}");
                        $fixed++;
                    } else {
                        $this->error("Failed to update book {$id} in database");
                        $errors++;
                    }
                } else {
                    $this->warn("File does not exist at {$fullCoverPath}, checking if available remotely");

                    // Try to find a remote URL for this book
                    $remoteUrl = $book['image_url'] ?? $book['imageUrl'] ?? $book['coverImageUrl'] ?? null;

                    if ($remoteUrl && $this->isRemoteUrl($remoteUrl)) {
                        $this->info("Found remote URL for book {$id}: {$remoteUrl}");

                        // Download the image using ExternalCoverService
                        $downloadResult = $this->externalCoverService->downloadCoverImage(
                            $remoteUrl,
                            $directoryPath,
                            'remote',
                            null
                        );

                        if ($downloadResult['success']) {
                            $this->info("Downloaded and saved image to {$downloadResult['path']}");

                            // Update the book in the database with just the filename
                            $normalizedPath = basename($downloadResult['path']);
                            $result = $this->documentStore->updateBook($id, ['coverImage' => $normalizedPath]);

                            if ($result) {
                                $this->info("Updated book {$id} with coverImage: {$normalizedPath}");
                                $fixed++;
                            } else {
                                $this->error("Failed to update book {$id} in database");
                                $errors++;
                            }
                        } else {
                            $this->error("Failed to download image from {$remoteUrl}. Error: {$downloadResult['error']}");
                            $errors++;
                        }
                    } else {
                        $this->warn("No remote URL found for book {$id}, skipping");
                        $skipped++;
                    }
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->line("");

        $this->info("Completed processing images:");
        $this->info("- Fixed: {$fixed}");
        $this->info("- Skipped: {$skipped}");
        $this->info("- Errors: {$errors}");

        return 0;
    }

    /**
     * Check if a URL is remote (starts with http:// or https://)
     *
     * @param string $url
     * @return bool
     */
    private function isRemoteUrl($url)
    {
        return (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0);
    }

    /**
     * Validate that the directory path exists and contains audiobooks
     * If not, search for similar paths and prompt user for updates
     *
     * @param string $directoryPath The directory path to validate
     * @param array $book The book data
     * @param bool $dryRun Whether in dry-run mode
     * @param string $bookId The book ID
     * @return bool True if directory is valid or user approves update, false otherwise
     */
    private function validateDirectoryPath(string $directoryPath, array $book, bool $dryRun, string $bookId): bool
    {
        // Check if directory exists and contains audiobooks using direct filesystem operations
        try {
            $booksRoot = config('filesystems.disks.books.root') ?? storage_path('app/books');
            $fullPath = rtrim($booksRoot, '/') . '/' . ltrim($directoryPath, '/');

            if (is_dir($fullPath)) {
                // Use glob to find audiobook files, which handles symlinks properly
                $audioExtensions = '{m4b,mp3,mp4,aac,flac,wav}';
                $audioFiles = glob($fullPath . '/*.{' . str_replace(['{', '}'], '', $audioExtensions) . '}', GLOB_BRACE);

                if (!empty($audioFiles)) {
                    return true; // Directory exists and has audiobooks
                }

                $this->warn("Directory {$directoryPath} exists but contains no audiobook files");
            } else {
                $this->warn("Directory {$directoryPath} does not exist");
            }
        } catch (\Exception $e) {
            $this->warn("Error validating directory {$directoryPath}: " . $e->getMessage());
            // Skip path validation and continue with the existing path
            return true;
        }

        // Search for similar paths
        $similarPaths = $this->findSimilarPaths($directoryPath, $book);

        if (empty($similarPaths)) {
            $this->error("No similar paths found for book {$bookId}");
            return false;
        }

        // Display options to user
        $this->line("");
        $this->warn("Found similar paths for book {$bookId} ({$book['title']}):");

        foreach ($similarPaths as $index => $path) {
            $this->line(($index + 1) . ". {$path}");
        }

        $this->line("0. Skip this book");

        if ($dryRun) {
            $this->info("DRY RUN: Would prompt user to select a path");
            return false;
        }

        $isInteractive = $this->input->isInteractive();
        if (!$isInteractive || app()->environment('testing')) {
            $this->warn("Non-interactive mode: skipping book {$bookId} due to invalid directory path");
            return false;
        }

        $choice = $this->ask("Select a path (0-" . count($similarPaths) . "):");

        if ($choice === '0' || $choice === null) {
            $this->info("Skipping book {$bookId}");
            return false;
        }

        $selectedIndex = (int) $choice - 1;
        if ($selectedIndex < 0 || $selectedIndex >= count($similarPaths)) {
            $this->error("Invalid selection");
            return false;
        }

        $selectedPath = $similarPaths[$selectedIndex];

        // Update the book with the new directory path
        $result = $this->documentStore->updateBook($bookId, ['directoryPath' => $selectedPath]);

        if ($result) {
            $this->info("Updated book {$bookId} with new directory path: {$selectedPath}");
            return true;
        } else {
            $this->error("Failed to update book {$bookId} in database");
            return false;
        }
    }

    /**
     * Find similar directory paths based on book title and author
     *
     * @param string $originalPath The original directory path
     * @param array $book The book data
     * @return array Array of similar paths
     */
    private function findSimilarPaths(string $originalPath, array $book): array
    {
        $title = $book['title'] ?? '';
        $authors = $book['author'] ?? [];
        $authorName = is_array($authors) && !empty($authors) ? $authors[0] : '';

        if (empty($title) && empty($authorName)) {
            return [];
        }

        $similarPaths = [];
        $allDirectories = $this->getAllBookDirectories();

        // Search for paths containing the title
        if (!empty($title)) {
            $titleWords = explode(' ', strtolower($title));
            $titleWords = array_filter($titleWords, function ($word) {
                return strlen($word) > 2; // Filter out small words
            });

            foreach ($allDirectories as $dir) {
                $dirLower = strtolower($dir);
                $matchCount = 0;

                foreach ($titleWords as $word) {
                    if (strpos($dirLower, $word) !== false) {
                        $matchCount++;
                    }
                }

                // If more than half the significant words match, consider it similar
                if ($matchCount > 0 && $matchCount >= count($titleWords) / 2) {
                    $similarPaths[] = $dir;
                }
            }
        }

        // Search for paths containing the author name
        if (!empty($authorName)) {
            $authorWords = explode(' ', strtolower($authorName));
            $authorWords = array_filter($authorWords, function ($word) {
                return strlen($word) > 2;
            });

            foreach ($allDirectories as $dir) {
                if (in_array($dir, $similarPaths)) {
                    continue; // Already found
                }

                $dirLower = strtolower($dir);
                $matchCount = 0;

                foreach ($authorWords as $word) {
                    if (strpos($dirLower, $word) !== false) {
                        $matchCount++;
                    }
                }

                if ($matchCount > 0) {
                    $similarPaths[] = $dir;
                }
            }
        }

        // Remove duplicates and limit results
        $similarPaths = array_unique($similarPaths);
        return array_slice($similarPaths, 0, 10); // Limit to 10 results
    }

    /**
     * Get all book directories that contain audiobook files
     * Uses direct filesystem operations to properly handle symbolic links
     *
     * @return array Array of directory paths
     */
    private function getAllBookDirectories(): array
    {
        $directories = [];

        try {
            // Get the root path for books storage
            $booksRoot = config('filesystems.disks.books.root') ?? storage_path('app/books');

            if (!is_dir($booksRoot)) {
                $this->warn("Books root directory does not exist: {$booksRoot}");
                return $this->getDirectoriesFromDatabase();
            }

            // Use RecursiveDirectoryIterator with FOLLOW_SYMLINKS to handle symbolic links
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $booksRoot,
                    \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $extension = strtolower($file->getExtension());
                    if (in_array($extension, ['m4b', 'mp3', 'mp4', 'aac', 'flac', 'wav'])) {
                        // Get relative path from books root
                        $fullPath = $file->getPath();
                        $relativePath = str_replace($booksRoot . '/', '', $fullPath);
                        $relativePath = ltrim($relativePath, '/');

                        if ($relativePath && !in_array($relativePath, $directories)) {
                            $directories[] = $relativePath;
                        }
                    }
                }
            }

            $this->info("Found " . count($directories) . " directories with audiobook files (including through symlinks)");
        } catch (\Exception $e) {
            $this->error("Error scanning directories: " . $e->getMessage());
            $this->warn("Falling back to database-only directory listing");
            $directories = $this->getDirectoriesFromDatabase();
        }

        return $directories;
    }

    /**
     * Fallback method to get directories from database when filesystem listing fails
     *
     * @return array Array of directory paths
     */
    private function getDirectoriesFromDatabase(): array
    {
        try {
            $booksResult = $this->documentStore->listBooks();
            $books = $booksResult['data'] ?? [];

            $directories = [];
            foreach ($books as $book) {
                $directoryPath = $book['directoryPath'] ?? null;
                if ($directoryPath && !in_array($directoryPath, $directories)) {
                    // Verify this directory actually exists before adding it
                    try {
                        $booksRoot = config('filesystems.disks.books.root') ?? storage_path('app/books');
                        $fullPath = rtrim($booksRoot, '/') . '/' . ltrim($directoryPath, '/');

                        if (is_dir($fullPath)) {
                            $directories[] = $directoryPath;
                        }
                    } catch (\Exception $e) {
                        // Skip directories that can't be accessed
                        continue;
                    }
                }
            }

            $this->info("Fallback: Found " . count($directories) . " directories from database");
            return $directories;
        } catch (\Exception $e) {
            $this->error("Failed to get directories from database: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Clean up cover URLs and normalize to just filename for storage
     * Supports both full paths and filenames as input
     *
     * @param string $coverUrl The cover URL to clean
     * @return string The cleaned cover URL (just filename)
     */
    private function cleanupCoverUrl(string $coverUrl): string
    {
        // Remove any leading/trailing whitespace
        $cleaned = trim($coverUrl);

        // Remove any stray quotes that might be embedded in the path
        $cleaned = str_replace("'/", "/", $cleaned);
        $cleaned = str_replace("'/", "/", $cleaned); // Run twice in case of multiple occurrences
        $cleaned = trim($cleaned, "'\""); // Remove quotes from start/end

        // Fix double slashes
        $cleaned = preg_replace('#/+#', '/', $cleaned);

        // Remove leading slash if present (paths should be relative)
        $cleaned = ltrim($cleaned, '/');

        // Fix any other common path corruptions
        $cleaned = str_replace('\\', '/', $cleaned); // Fix backslashes

        // Extract just the filename - this normalizes both full paths and filenames
        $filename = basename($cleaned);

        return $filename;
    }

    /**
     * Get the full path to a cover image file
     * Handles both full paths and filenames in cover_image field
     *
     * @param string|null $coverImage The cover image value from database
     * @param string|null $directoryPath The directory path for the book
     * @return string|null The full relative path to the cover image
     */
    private function getFullCoverPath(?string $coverImage, ?string $directoryPath): ?string
    {
        if (!$coverImage) {
            return null;
        }

        // Clean the cover image value
        $cleaned = $this->cleanupCoverUrl($coverImage);

        // If it's just a filename and we have a directory path, combine them
        if (!str_contains($cleaned, '/') && $directoryPath) {
            return rtrim($directoryPath, '/') . '/' . $cleaned;
        }

        // If it's already a full path (legacy), extract directory and filename
        if (str_contains($cleaned, '/')) {
            $parts = explode('/', $cleaned);
            $filename = array_pop($parts);
            $pathFromCover = implode('/', $parts);

            // Use the directory from the cover path if no directoryPath is set
            if (!$directoryPath) {
                return $cleaned; // Return the full path as-is
            }

            // Prefer the directoryPath from the database
            return rtrim($directoryPath, '/') . '/' . $filename;
        }

        // Just a filename with no directory path
        return $cleaned;
    }

    /**
     * Sanitize a string to be used as a file name
     *
     * @param string $name The string to sanitize
     * @return string The sanitized string
     */
    protected function sanitizeFileName(string $name): string
    {
        // Replace any character that's not a letter, number, space, hyphen, or underscore with an underscore
        $safe = preg_replace('/[^\w\s-]/', '_', $name);

        // Replace spaces with hyphens
        $safe = str_replace(' ', '-', $safe);

        // Remove consecutive hyphens or underscores
        $safe = preg_replace('/-+/', '-', $safe);
        $safe = preg_replace('/_+/', '_', $safe);

        // Trim hyphens and underscores from beginning and end
        $safe = trim($safe, '-_');

        // Ensure the name is not empty
        if (empty($safe)) {
            $safe = 'untitled';
        }

        return $safe;
    }
}
