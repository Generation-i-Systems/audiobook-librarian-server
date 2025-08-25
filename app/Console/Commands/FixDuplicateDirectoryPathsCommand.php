<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixDuplicateDirectoryPathsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'books:fix-duplicate-paths {--path=/media/audiobooks/books : Path to the audiobooks directory} {--dry-run : Don\'t actually update the database} {--min-count=3 : Minimum number of books sharing a directory path}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix books that share the same directory path by finding better paths';

    /**
     * The document store service.
     */
    protected DocumentStoreServiceInterface $documentStore;

    /**
     * Create a new command instance.
     */
    public function __construct(DocumentStoreServiceInterface $documentStore)
    {
        parent::__construct();
        $this->documentStore = $documentStore;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $minCount = (int)$this->option('min-count');

        if ($dryRun) {
            $this->warn('DRY RUN MODE: No changes will be made');
        }

        // Find directory paths with multiple books
        $this->info('Finding directory paths with multiple books...');
        $duplicatePaths = DB::select("SELECT directory_path, COUNT(*) as cnt FROM books WHERE directory_path IS NOT NULL GROUP BY directory_path HAVING cnt >= ?", [$minCount]);

        if (empty($duplicatePaths)) {
            $this->info('No directory paths with multiple books found.');
            return 0;
        }

        $this->info(sprintf('Found %d directory paths with %d or more books', count($duplicatePaths), $minCount));

        $fixed = 0;
        $errors = 0;
        $skipped = 0;

        foreach ($duplicatePaths as $pathInfo) {
            $directoryPath = $pathInfo->directory_path;
            $count = $pathInfo->cnt;

            $this->info(sprintf('Processing directory path: %s (used by %d books)', $directoryPath, $count));

            // Get all books with this directory path
            $books = DB::table('books')
                ->where('directory_path', $directoryPath)
                ->select('id', 'title', 'directory_path')
                ->get();

            $this->info(sprintf('Found %d books with this directory path', count($books)));

            // For each book, check if there's a more specific directory that includes the title
            foreach ($books as $book) {
                $this->info(sprintf('Processing book: %s (ID: %s)', $book->title, $book->id));

                // Skip if no title
                if (empty($book->title)) {
                    $this->warn('Book has no title, skipping');
                    $skipped++;
                    continue;
                }

                // Generate a sanitized title for directory name
                $sanitizedTitle = $this->sanitizeForPath($book->title);

                // Check for potential better directory paths
                $potentialPaths = [
                    $directoryPath . '/' . $sanitizedTitle,
                    $directoryPath . '_' . $sanitizedTitle,
                    $directoryPath . '/' . $book->id,
                ];

                // Look for directories with pattern 'title (narrator)'
                $basePath = $this->option('path');
                $fullPath = $basePath . '/' . $directoryPath;

                if (is_dir($fullPath)) {
                    $subdirs = array_map('basename', glob($fullPath . '/*', GLOB_ONLYDIR));

                    foreach ($subdirs as $subdirName) {
                        // Check if the subdirectory follows the 'title (narrator)' pattern
                        if (preg_match('/^(.+)\s+\((.+)\)$/', $subdirName, $matches)) {
                            $titlePart = $matches[1];

                            // Check if the title part matches the book title
                            if (
                                stripos($book->title, $titlePart) !== false ||
                                stripos($titlePart, $book->title) !== false
                            ) {
                                $potentialPaths[] = $directoryPath . '/' . $subdirName;
                            }
                        }
                    }
                }

                $betterPath = null;
                $basePath = $this->option('path');

                // Check if any of these directories exist
                foreach ($potentialPaths as $path) {
                    $fullPath = $basePath . '/' . $path;
                    if (is_dir($fullPath)) {
                        $this->info(sprintf('Found better path: %s', $path));
                        $betterPath = $path;
                        break;
                    }
                }

                // If no better path found, check if there are subdirectories that contain the title
                if (!$betterPath) {
                    try {
                        $fullDirectoryPath = $basePath . '/' . $directoryPath;
                        if (is_dir($fullDirectoryPath)) {
                            $subdirs = glob($fullDirectoryPath . '/*', GLOB_ONLYDIR);

                            foreach ($subdirs as $subdir) {
                                $subdirName = basename($subdir);
                                $sanitizedBookTitle = $this->sanitizeForPath($book->title);

                                // Check for 'title (narrator)' pattern
                                if (preg_match('/^(.+?)\s+\((.+?)\)$/', $subdirName, $matches)) {
                                    $title = $matches[1];
                                    $narrator = $matches[2];

                                    // Check if the subdirectory title part matches the book title
                                    // Use more flexible matching to catch variations
                                    $sanitizedTitle = $this->sanitizeForPath($title);
                                    if (
                                        stripos($sanitizedBookTitle, $sanitizedTitle) !== false ||
                                        stripos($sanitizedTitle, $sanitizedBookTitle) !== false ||
                                        similar_text($sanitizedBookTitle, $sanitizedTitle) > min(strlen($sanitizedBookTitle), strlen($sanitizedTitle)) * 0.7
                                    ) {
                                        $this->info(sprintf('Found matching subdirectory with narrator: %s', $subdirName));
                                        $betterPath = sprintf('%s/%s', $directoryPath, $subdirName);
                                        break;
                                    }
                                } else {
                                    // Also check for direct title match without narrator pattern
                                    $sanitizedSubdirName = $this->sanitizeForPath($subdirName);
                                    if (
                                        stripos($sanitizedBookTitle, $sanitizedSubdirName) !== false ||
                                        stripos($sanitizedSubdirName, $sanitizedBookTitle) !== false ||
                                        similar_text($sanitizedBookTitle, $sanitizedSubdirName) > min(strlen($sanitizedBookTitle), strlen($sanitizedSubdirName)) * 0.7
                                    ) {
                                        $this->info(sprintf('Found matching subdirectory: %s', $subdirName));
                                        $betterPath = sprintf('%s/%s', $directoryPath, $subdirName);
                                        break;
                                    }
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        $this->error('Error checking subdirectories: ' . $e->getMessage());
                    }
                }

                // Update the book if a better path was found
                if ($betterPath) {
                    $this->info(sprintf(
                        'Updating book %s directory path from %s to %s',
                        $book->id,
                        $directoryPath,
                        $betterPath
                    ));

                    if (!$dryRun) {
                        try {
                            $result = $this->documentStore->updateBook($book->id, ['directory_path' => $betterPath]);

                            if ($result) {
                                $this->info('Successfully updated book directory path');
                                $fixed++;
                            } else {
                                $this->error('Failed to update book directory path');
                                $errors++;
                            }
                        } catch (\Exception $e) {
                            $this->error('Error updating book: ' . $e->getMessage());
                            $errors++;
                        }
                    } else {
                        $this->info('[DRY RUN] Would update book directory path');
                        $fixed++;
                    }
                } else {
                    $this->warn('No better directory path found for this book, skipping');
                    $skipped++;
                }
            }
        }

        $this->info('');
        $this->info('Summary:');
        $this->info(sprintf('Books fixed: %d', $fixed));
        $this->info(sprintf('Books skipped: %d', $skipped));
        $this->info(sprintf('Errors: %d', $errors));

        return 0;
    }

    /**
     * Sanitize a string to be used as a directory name
     *
     * @param string $name The string to sanitize
     * @return string The sanitized string
     */
    private function sanitizeForPath(string $name): string
    {
        // Replace characters that are not allowed in file names
        $name = preg_replace('/[\/\\\:*?"<>|]/', '_', $name);
        // Replace multiple spaces with a single space
        $name = preg_replace('/\s+/', ' ', $name);
        // Trim spaces from the beginning and end
        $name = trim($name);
        // Replace spaces with underscores
        $name = str_replace(' ', '_', $name);
        // Convert to lowercase
        $name = strtolower($name);

        return $name;
    }
}
