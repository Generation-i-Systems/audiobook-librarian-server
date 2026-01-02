<?php

namespace App\Console\Commands;

use App\Models\Book;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class ValidateBookDirectoriesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'books:validate-directories {--force : Force full rescan}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Validate book directories and find orphaned directories';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $force = $this->option('force');
        $storageRoot = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');

        $this->info('Validating book directories...');

        // Step 1: Validate existing books
        $this->info('\nStep 1: Checking books for missing directories');
        $books = Book::whereNotNull('directory_path')->get();
        $bar = $this->output->createProgressBar($books->count());

        $missing = 0;
        $found = 0;

        foreach ($books as $book) {
            $fullPath = $storageRoot . '/' . ltrim($book->directory_path, '/');
            $exists = File::exists($fullPath) && File::isDirectory($fullPath);

            if ($book->directory_exists !== $exists || $force) {
                $book->directory_exists = $exists;
                $book->directory_last_checked = now();
                $book->save();
            }

            if ($exists) {
                $found++;
            } else {
                $missing++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info(sprintf('Found: %d', $found));
        $this->warn(sprintf('Missing: %d', $missing));

        // Step 2: Find orphaned directories
        $this->info('\nStep 2: Scanning for orphaned directories');
        $orphanedDirs = $this->findOrphanedDirectories($storageRoot);

        $this->info(sprintf('Found %d orphaned directories', count($orphanedDirs)));

        // Cache results for report page
        Cache::put('book_validation_results', [
            'missing_directories' => $missing,
            'orphaned_directories' => count($orphanedDirs),
            'last_scan' => now()->toDateTimeString(),
        ], now()->addDay());

        Cache::put('orphaned_directories', $orphanedDirs, now()->addDay());

        $this->info('\n✓ Validation complete. Results cached for 24 hours.');

        return 0;
    }

    protected function findOrphanedDirectories(string $storageRoot): array
    {
        $orphaned = [];
        $dbPaths = Book::whereNotNull('directory_path')
            ->pluck('directory_path')
            ->map(fn ($path) => $storageRoot . '/' . ltrim($path, '/'))
            ->toArray();

        // Scan common genre directories
        $genreDirs = File::directories($storageRoot);

        foreach ($genreDirs as $genreDir) {
            $genreName = basename($genreDir);

            // Skip system directories and ebook directories
            if (
                in_array($genreName, ['.', '..', 'lost+found', '.Trash-1000']) ||
                stripos($genreName, 'ebook') !== false
            ) {
                continue;
            }

            // Scan author directories
            $authorDirs = File::directories($genreDir);

            foreach ($authorDirs as $authorDir) {
                // Scan series/book directories
                $bookDirs = File::directories($authorDir);

                foreach ($bookDirs as $bookDir) {
                    // Check if this directory has subdirectories (series with books)
                    $subDirs = File::directories($bookDir);

                    if (!empty($subDirs)) {
                        // This is a series directory, check each book
                        foreach ($subDirs as $subDir) {
                            if (!in_array($subDir, $dbPaths) && $this->hasAudioFiles($subDir)) {
                                $orphaned[] = [
                                    'path' => str_replace($storageRoot . '/', '', $subDir),
                                    'full_path' => $subDir,
                                    'size' => $this->getDirectorySize($subDir),
                                    'writable' => is_writable($subDir),
                                    'parent_writable' => is_writable(dirname($subDir)),
                                ];
                            }
                        }
                    } else {
                        // This is a standalone book directory
                        if (!in_array($bookDir, $dbPaths) && $this->hasAudioFiles($bookDir)) {
                            $orphaned[] = [
                                'path' => str_replace($storageRoot . '/', '', $bookDir),
                                'full_path' => $bookDir,
                                'size' => $this->getDirectorySize($bookDir),
                                'writable' => is_writable($bookDir),
                                'parent_writable' => is_writable(dirname($bookDir)),
                            ];
                        }
                    }
                }
            }
        }

        return $orphaned;
    }

    protected function hasAudioFiles(string $path): bool
    {
        $audioExtensions = ['mp3', 'm4a', 'm4b', 'ogg', 'opus', 'flac', 'wav', 'aac'];
        $files = File::allFiles($path);

        foreach ($files as $file) {
            $extension = strtolower($file->getExtension());
            if (in_array($extension, $audioExtensions)) {
                return true;
            }
        }

        return false;
    }

    protected function getDirectorySize(string $path): int
    {
        $size = 0;
        $files = File::allFiles($path);

        foreach ($files as $file) {
            $size += $file->getSize();
        }

        return $size;
    }
}
