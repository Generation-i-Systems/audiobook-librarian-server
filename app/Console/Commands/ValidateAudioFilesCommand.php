<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\LibraryRepairIssueType;
use App\Models\Book;
use App\Services\AudioFileAnalyzer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ValidateAudioFilesCommand extends Command
{
    protected $signature = 'library:validate-audio
                            {paths?* : Directory paths to scan (supports glob patterns like /path/*)}
                            {--book-id= : Only scan a specific book ID}
                            {--dry-run : Show what would be marked without actually creating issues}
                            {--json : Output JSON summary instead of table}';

    protected $description = 'Recursively scan audiobook files and mark books with invalid audio as library repair issues.';

    private const AUDIO_EXTENSIONS = ['mp3', 'm4a', 'm4b', 'm4p', 'mp4', 'aac', 'ogg', 'oga', 'wav', 'flac', 'wma'];

    private int $booksScanned = 0;
    private int $booksWithInvalidAudio = 0;
    private int $totalInvalidFiles = 0;
    private int $totalValidFiles = 0;

    public function __construct(
        private AudioFileAnalyzer $audioAnalyzer
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $paths = (array) $this->argument('paths');
        $bookId = $this->option('book-id');
        $dryRun = $this->option('dry-run');

        if ($bookId) {
            return $this->scanSingleBook($bookId, $dryRun);
        }

        if (!empty($paths)) {
            return $this->scanMultiplePaths($paths, $dryRun);
        }

        return $this->scanEntireLibrary($dryRun);
    }

    private function scanMultiplePaths(array $paths, bool $dryRun): int
    {
        $this->info("Scanning multiple paths: " . implode(', ', $paths));

        foreach ($paths as $path) {
            // Expand glob patterns
            $expandedPaths = $this->expandGlobPattern($path);

            if (empty($expandedPaths)) {
                $this->warn("No matches found for: {$path}");
                continue;
            }

            $this->info("Found " . count($expandedPaths) . " match(es) for: {$path}");

            foreach ($expandedPaths as $expandedPath) {
                $this->line('');
                $this->info("--- Scanning: {$expandedPath} ---");

                $result = $this->scanSpecificPath($expandedPath, $dryRun);
            }
        }

        $this->line('');
        $this->info("Multi-path scan complete.");
        $this->info("Total scanned: {$this->booksScanned}");
        $this->info("Books with invalid audio: {$this->booksWithInvalidAudio}");
        $this->info("Total valid files: {$this->totalValidFiles}");
        $this->info("Total invalid files: {$this->totalInvalidFiles}");

        return $this->booksWithInvalidAudio > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function expandGlobPattern(string $pattern): array
    {
        // Check if pattern contains glob characters
        if (!preg_match('/[*?\[\]]/', $pattern)) {
            // No glob characters - return as-is if it exists
            return File::exists($pattern) ? [$pattern] : [];
        }

        // It's a glob pattern
        $matches = glob($pattern, GLOB_NOSORT);
        return $matches ?: [];
    }

    private function scanSpecificPath(string $path, bool $dryRun): int
    {
        if (!File::exists($path)) {
            $this->error("Path does not exist: {$path}");
            return Command::FAILURE;
        }

        $this->info("Scanning specific path: {$path}");

        $bookRoot = config('app.book_root', '/media/lyra_data1/audiobooks/books');

        if (File::isDirectory($path)) {
            // Check if this path is within the book root
            if (str_starts_with($path, $bookRoot)) {
                // It's within book root - scan for books
                return $this->scanDirectoryForBooks($path, $dryRun);
            }

            // Not within book root - find books by path prefix matching
            return $this->scanBooksMatchingPath($path, $dryRun);
        }

        // Single file - find which book it belongs to
        $book = $this->findBookByFilePath($path);
        if (!$book) {
            $this->warn("File does not belong to any book: {$path}");
            return Command::FAILURE;
        }

        return $this->scanSingleBook($book->id, $dryRun);
    }

    private function scanBooksMatchingPath(string $path, bool $dryRun): int
    {
        if (!File::exists($path)) {
            $this->error("Path does not exist: {$path}");
            return Command::FAILURE;
        }

        $this->info("Scanning files in: {$path}");

        // Collect audio files from filesystem
        $audioFiles = $this->collectAudioFilesFromDirectory($path);

        $totalFiles = count($audioFiles);
        $this->info("Found {$totalFiles} audio file(s) to validate.");

        if ($totalFiles === 0) {
            $this->warn("No audio files found in the specified path.");
            return Command::SUCCESS;
        }

        // Group files by book directory and find books
        $bookRoot = config('app.book_root', '/media/lyra_data1/audiobooks/books');
        $filesByBook = $this->groupFilesByBook($audioFiles, $bookRoot);

        $bar = $this->output->createProgressBar($totalFiles);
        $bar->setFormat(' %current%/%max% [%valid%|%invalid%] %bar% %percent%%');
        $bar->start();

        $results = [];
        $bookRootLen = strlen($bookRoot) + 1;

        foreach ($audioFiles as $filePath) {
            $isValid = $this->isAudioFileValid($filePath);

            // Determine which book this file belongs to
            $relativePath = substr($filePath, $bookRootLen);
            if (str_starts_with($relativePath, $path)) {
                $relativePath = substr($filePath, strlen($path) + 1);
            }
            $bookDir = dirname($relativePath);
            if ($bookDir === '.') {
                $bookDir = $relativePath;
            }

            if (!$isValid) {
                $this->totalInvalidFiles++;
                $bar->setMessage((string) $this->totalValidFiles, 'valid');
                $bar->setMessage((string) $this->totalInvalidFiles, 'invalid');

                $bookId = $filesByBook[$bookDir]['book_id'] ?? null;
                if ($bookId) {
                    $existingIndex = null;
                    foreach ($results as $idx => $r) {
                        if ($r['book_id'] === $bookId) {
                            $existingIndex = $idx;
                            break;
                        }
                    }

                    if ($existingIndex !== null) {
                        $results[$existingIndex]['invalid_files'][] = [
                            'relativePath' => $relativePath,
                            'error' => 'Failed to validate - file may be corrupted or invalid',
                        ];
                        $results[$existingIndex]['invalid_count']++;
                    } else {
                        $book = Book::find($bookId);
                        $results[] = [
                            'book_id' => $bookId,
                            'title' => $book ? $book->title : 'Unknown',
                            'directory_path' => $bookDir,
                            'invalid_count' => 1,
                            'invalid_files' => [[
                                'relativePath' => $relativePath,
                                'error' => 'Failed to validate - file may be corrupted or invalid',
                            ]],
                        ];
                    }
                }
            } else {
                $this->totalValidFiles++;
                $bar->setMessage((string) $this->totalValidFiles, 'valid');
                $bar->setMessage((string) $this->totalInvalidFiles, 'invalid');
            }

            $bar->advance();
        }
        $this->line('');

        $this->booksScanned = count($filesByBook);
        $this->booksWithInvalidAudio = count($results);

        if (!$dryRun) {
            foreach ($results as $result) {
                $book = Book::find($result['book_id']);
                if ($book) {
                    $this->createLibraryRepairIssue($book, $result['invalid_files']);
                }
            }
        }

        $this->displaySummary($results, $dryRun);

        return $this->booksWithInvalidAudio > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function scanSingleBook(string|int $bookId, bool $dryRun): int
    {
        $book = Book::with('authors')->find($bookId);

        if (!$book) {
            $this->error("Book not found: {$bookId}");
            return Command::FAILURE;
        }

        $bookRoot = config('app.book_root', '/media/lyra_data1/audiobooks/books');
        $fullPath = $bookRoot . '/' . ltrim($book->directory_path, '/');

        if (!File::exists($fullPath)) {
            $this->error("Book directory does not exist: {$fullPath}");
            return Command::FAILURE;
        }

        $this->info("Scanning book: {$book->title}");

        // Collect audio files from the book's directory
        $audioFiles = $this->collectAudioFilesFromDirectory($fullPath);

        $totalFiles = count($audioFiles);
        $this->info("Found {$totalFiles} audio file(s) to validate.");

        if ($totalFiles === 0) {
            $this->warn("No audio files found in the book's directory.");
            return Command::SUCCESS;
        }

        $bar = $this->output->createProgressBar($totalFiles);
        $bar->setFormat(' %current%/%max% [%valid%|%invalid%] %bar% %percent%%');
        $bar->start();

        $invalidFiles = [];
        $bookRootLen = strlen($bookRoot) + 1;

        foreach ($audioFiles as $filePath) {
            $isValid = $this->isAudioFileValid($filePath);

            if (!$isValid) {
                $this->totalInvalidFiles++;
                $bar->setMessage((string) $this->totalValidFiles, 'valid');
                $bar->setMessage((string) $this->totalInvalidFiles, 'invalid');
                $relativePath = substr($filePath, $bookRootLen);
                $invalidFiles[] = [
                    'relativePath' => $relativePath,
                    'error' => 'Failed to validate - file may be corrupted or invalid',
                ];
            } else {
                $this->totalValidFiles++;
                $bar->setMessage((string) $this->totalValidFiles, 'valid');
                $bar->setMessage((string) $this->totalInvalidFiles, 'invalid');
            }

            $bar->advance();
        }

        $bar->finish();
        $this->line('');

        $this->booksScanned = 1;

        if (!empty($invalidFiles)) {
            $this->booksWithInvalidAudio = 1;
            $results = [[
                'book_id' => $book->id,
                'title' => $book->title,
                'directory_path' => $book->directory_path,
                'invalid_count' => count($invalidFiles),
                'invalid_files' => $invalidFiles,
            ]];

            if (!$dryRun) {
                $this->createLibraryRepairIssue($book, $invalidFiles);
            }

            $this->displaySummary($results, $dryRun);
        } else {
            $this->info('All audio files are valid.');
        }

        return !empty($invalidFiles) ? Command::FAILURE : Command::SUCCESS;
    }

    private function scanEntireLibrary(bool $dryRun): int
    {
        $bookRoot = config('app.book_root', '/media/lyra_data1/audiobooks/books');

        if (!File::exists($bookRoot)) {
            $this->error("Book root directory does not exist: {$bookRoot}");
            return Command::FAILURE;
        }

        $this->info("Scanning entire library: {$bookRoot}");

        return $this->scanDirectoryForBooks($bookRoot, $dryRun);
    }

    private function scanDirectoryForBooks(string $directory, bool $dryRun): int
    {
        $bookRoot = config('app.book_root', '/media/lyra_data1/audiobooks/books');

        if (!File::exists($directory)) {
            $this->error("Directory does not exist: {$directory}");
            return Command::FAILURE;
        }

        $this->info("Scanning files in: {$directory}");

        // First, collect all audio files from the filesystem
        $audioFiles = $this->collectAudioFilesFromDirectory($directory);

        $totalFiles = count($audioFiles);
        $this->info("Found {$totalFiles} audio file(s) to validate.");

        if ($totalFiles === 0) {
            $this->warn("No audio files found in the specified directory.");
            return Command::SUCCESS;
        }

        // Group files by book directory
        $filesByBook = $this->groupFilesByBook($audioFiles, $bookRoot);

        $bar = $this->output->createProgressBar($totalFiles);
        $bar->setFormat(' %current%/%max% [%valid%|%invalid%] %bar% %percent%%');
        $bar->start();

        $results = [];
        $bookRootLen = strlen($bookRoot) + 1;

        foreach ($audioFiles as $filePath) {
            $isValid = $this->isAudioFileValid($filePath);

            // Determine which book this file belongs to
            $relativePath = substr($filePath, $bookRootLen);
            $bookDir = dirname($relativePath);
            if ($bookDir === '.') {
                $bookDir = $relativePath;
            }

            if (!$isValid) {
                $this->totalInvalidFiles++;
                $bar->setMessage((string) $this->totalValidFiles, 'valid');
                $bar->setMessage((string) $this->totalInvalidFiles, 'invalid');

                // Add to results
                $bookId = $filesByBook[$bookDir]['book_id'] ?? null;
                if ($bookId) {
                    $existingIndex = array_search($bookId, array_column($results, 'book_id'));
                    if ($existingIndex !== false) {
                        $results[$existingIndex]['invalid_files'][] = [
                            'relativePath' => $relativePath,
                            'error' => 'Failed to validate - file may be corrupted or invalid',
                        ];
                        $results[$existingIndex]['invalid_count']++;
                    } else {
                        $book = Book::find($bookId);
                        $results[] = [
                            'book_id' => $bookId,
                            'title' => $book ? $book->title : 'Unknown',
                            'directory_path' => $bookDir,
                            'invalid_count' => 1,
                            'invalid_files' => [[
                                'relativePath' => $relativePath,
                                'error' => 'Failed to validate - file may be corrupted or invalid',
                            ]],
                        ];
                    }
                }
            } else {
                $this->totalValidFiles++;
                $bar->setMessage((string) $this->totalValidFiles, 'valid');
                $bar->setMessage((string) $this->totalInvalidFiles, 'invalid');
            }

            $bar->advance();
        }

        $bar->finish();
        $this->line('');

        $this->booksScanned = count($filesByBook);
        $this->booksWithInvalidAudio = count($results);

        // Create issues for books with invalid audio
        if (!$dryRun) {
            foreach ($results as $result) {
                $book = Book::find($result['book_id']);
                if ($book) {
                    $this->createLibraryRepairIssue($book, $result['invalid_files']);
                }
            }
        }

        $this->displaySummary($results, $dryRun);

        return $this->booksWithInvalidAudio > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function collectAudioFilesFromDirectory(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (in_array($extension, self::AUDIO_EXTENSIONS)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function groupFilesByBook(array $files, string $bookRoot): array
    {
        $bookRootLen = strlen($bookRoot) + 1;
        $grouped = [];

        foreach ($files as $filePath) {
            $relativePath = substr($filePath, $bookRootLen);
            $bookDir = dirname($relativePath);
            if ($bookDir === '.') {
                $bookDir = $relativePath;
            }

            if (!isset($grouped[$bookDir])) {
                // Try to find book by directory path
                $book = Book::where('directory_path', $bookDir)->first();
                $grouped[$bookDir] = [
                    'book_id' => $book?->id,
                    'book_title' => $book?->title,
                ];
            }
        }

        return $grouped;
    }

    private function isAudioFileValid(string $filePath): bool
    {
        $result = $this->audioAnalyzer->validateAudioFile($filePath);
        return $result['valid'];
    }

    private function createLibraryRepairIssue(Book $book, array $invalidFiles): void
    {
        $notes = [];
        foreach ($invalidFiles as $file) {
            $notes[] = "File: {$file['relativePath']} - {$file['error']}";
        }

        $metadata = [
            'invalid_files' => $invalidFiles,
            'invalid_count' => count($invalidFiles),
            'scanned_at' => now()->toIso8601String(),
        ];

        $attributes = [
            'book_id' => $book->id,
            'issue_type' => LibraryRepairIssueType::INVALID_AUDIO->value,
            'directory_path' => $book->directory_path,
        ];

        $issue = \App\Models\LibraryRepairIssue::query()->firstOrNew($attributes);
        $issue->metadata = $metadata;
        $issue->status = 'pending';
        $issue->auto_resolved = false;
        $issue->save();

        Log::info('Created invalid_audio library repair issue', [
            'book_id' => $book->id,
            'book_title' => $book->title,
            'invalid_count' => count($invalidFiles),
        ]);
    }

    private function findBookByFilePath(string $filePath): ?Book
    {
        $bookRoot = config('app.book_root', '/media/lyra_data1/audiobooks/books');

        if (!str_starts_with($filePath, $bookRoot)) {
            return null;
        }

        $relativePath = substr($filePath, strlen($bookRoot) + 1);

        // Try exact match first
        $book = Book::where('directory_path', $relativePath)->first();
        if ($book) {
            return $book;
        }

        // Try parent directory
        $parentDir = dirname($relativePath);
        $book = Book::where('directory_path', $parentDir)->first();
        if ($book) {
            return $book;
        }

        // Try matching prefix
        $book = Book::where('directory_path', 'like', $relativePath . '%')->first();
        return $book;
    }

    private function displaySummary(array $results, bool $dryRun): void
    {
        if ($dryRun) {
            $this->warn('DRY RUN - No issues were created.');
            $this->line('');
        }

        $this->info("Scanned: {$this->booksScanned} books");
        $this->info("Books with invalid audio: {$this->booksWithInvalidAudio}");
        $this->info("Total valid files: {$this->totalValidFiles}");
        $this->info("Total invalid files: {$this->totalInvalidFiles}");

        if ($this->option('json')) {
            $payload = [
                'scanned_books' => $this->booksScanned,
                'books_with_invalid_audio' => $this->booksWithInvalidAudio,
                'total_valid_files' => $this->totalValidFiles,
                'total_invalid_files' => $this->totalInvalidFiles,
                'dry_run' => $dryRun,
                'results' => $results,
            ];
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        if (!empty($results)) {
            $this->line('');
            $this->warn('Books with invalid audio files:');

            foreach ($results as $result) {
                $this->line("  - {$result['title']} ({$result['invalid_count']} invalid file(s))");
                $this->line("    Path: {$result['directory_path']}");
            }
        }
    }
}
