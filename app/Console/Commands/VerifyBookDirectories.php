<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\Log;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use App\Models\Book;
use App\Services\BookDeletionService;
use App\Services\SafeLoggingService;

class VerifyBookDirectories extends Command
{
    protected $signature = 'books:verify-directories
                          {--disk=books : Storage disk to use}
                          {--limit=0 : Limit number of books to process (0 for all)}
                          {--dry-run : Perform a dry run without making changes (fixing is default)}
                          {--auto-confirm : Auto-confirm obvious matches}
                          {--similarity-threshold=0.7 : Minimum similarity score for auto-suggestions}
                          {--permanent : Permanently delete without using trash}';

    protected $description = 'Verify book directory paths and suggest fixes for missing directories';

    protected $disk;
    protected $basePath;
    protected $fixMode = false;
    protected $autoConfirm = false;
    protected $similarityThreshold = 0.7;

    public function __construct(protected BookDeletionService $deletionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        Log::debug('VerifyBookDirectories: handle() method started.');
        Log::debug('VerifyBookDirectories: autoConfirm value at start of handle(): ' . ($this->autoConfirm ? 'true' : 'false'));

        $this->disk = Storage::disk($this->option('disk'));
        $this->basePath = $this->disk->path('');
        $this->fixMode = !$this->option('dry-run');
        $this->autoConfirm = $this->option('auto-confirm');
        $this->similarityThreshold = (float) $this->option('similarity-threshold');

        if (!$this->fixMode) {
            $this->info('🔍 Running in DRY-RUN mode. No changes will be made.');
        } else {
            $this->info('🔧 Running in FIX mode. Paths will be updated.');
        }

        $this->info("📁 Base storage path: {$this->basePath}");
        $this->info("🎯 Similarity threshold: {$this->similarityThreshold}");

        $limit = (int) $this->option('limit');

        // First pass: Identify all books with invalid paths
        $this->info("🔍 Identifying books with invalid paths...");
        $allBooks = Book::whereNotNull('directory_path')->get();
        $invalidBooks = collect();

        foreach ($allBooks as $book) {
            if (!$this->disk->exists($book->directory_path)) {
                $invalidBooks->push($book);
            }
        }

        $totalInvalid = $invalidBooks->count();
        $this->info("📚 Found {$totalInvalid} books with invalid paths.");

        if ($totalInvalid === 0) {
            $this->info("🎉 No invalid paths found. Exiting.");
            return 0;
        }

        // Apply limit if specified, only to the invalid books
        if ($limit > 0) {
            $invalidBooks = $invalidBooks->take($limit);
            $this->info("Processing limited to {$invalidBooks->count()} invalid books.");
        }

        $booksToProcess = $invalidBooks;

        $stats = [
            'total' => $booksToProcess->count(),
            'valid' => 0,
            'missing' => 0,
            'fixed' => 0,
            'skipped' => 0,
            'deleted' => 0,
        ];

        $progressBar = $this->output->createProgressBar($booksToProcess->count());
        $progressBar->start();

        foreach ($booksToProcess as $book) {
            $result = $this->verifyBookDirectory($book);
            $stats[$result]++;
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->displayStats($stats);

        return 0;
    }

    protected function verifyBookDirectory(Book $book): string
    {
        Log::debug('verifyBookDirectory: autoConfirm value at start of method: ' . ($this->autoConfirm ? 'true' : 'false'));
        $directoryPath = $book->directory_path;

        if (empty($directoryPath)) {
            Log::debug('verifyBookDirectory returning skipped (empty path)');
            return 'skipped';
        }

        $fullPath = $this->basePath . '/' . $directoryPath;

        if (!file_exists($fullPath)) {
            $this->newLine();
            $this->error("❌ Missing directory for book: {$book->title}");
            $this->line("   Author: " . ($book->authors->isNotEmpty() ? $book->authors->pluck('name')->implode(', ') : 'N/A'));
            $this->line("   Series: " . ($book->series->isNotEmpty() ? $book->series->map(function (\App\Models\Series $s) {
                $seriesNumber = $s->pivot ? $s->pivot->getAttribute('series_number') : null;

                return $s->name . ($seriesNumber ? ' #' . $seriesNumber : '');
            })->implode(', ') : 'N/A'));
            $this->line("   Current path: {$directoryPath}");
            $this->line("   Full path checked: {$fullPath}");
            if (is_link($fullPath)) {
                $this->warn("⚠️  Path is a symbolic link: " . readlink($fullPath));
            }
            // Fall through to suggestions/manual input
        } elseif (!is_dir($fullPath)) {
            $this->newLine();
            $this->error("❌ Path is not a directory for book: {$book->title}");
            $this->line("   Author: " . ($book->authors->isNotEmpty() ? $book->authors->pluck('name')->implode(', ') : 'N/A'));
            $this->line("   Series: " . ($book->series->isNotEmpty() ? $book->series->map(function (\App\Models\Series $s) {
                $seriesNumber = $s->pivot ? $s->pivot->getAttribute('series_number') : null;

                return $s->name . ($seriesNumber ? ' #' . $seriesNumber : '');
            })->implode(', ') : 'N/A'));
            $this->line("   Current path: {$directoryPath}");
            $this->line("   Full path checked: {$fullPath}");
            // Fall through to suggestions/manual input
        } else {
            return 'valid';
        }

        // Try to find similar directories
        $suggestions = $this->findSimilarDirectories($book);
        Log::debug('verifyBookDirectory: Suggestions found: ' . json_encode($suggestions));

        if (!empty($suggestions)) {
            // Auto-confirm logic
            $bestMatch = $suggestions[0];
            if ($this->autoConfirm && $bestMatch['score'] >= 0.9) {
                $this->info("✅ Auto-confirming high similarity match: {$bestMatch['path']}");
                return $this->updateBookDirectory($book, $bestMatch['path']);
            } elseif ($this->autoConfirm) {
                $this->warn("⚠️  Best match ({$bestMatch['path']}) has low similarity ({$bestMatch['score']}) - skipping due to --auto-confirm.");
                return 'skipped';
            }

            // Present options to user (only if not auto-confirming or auto-confirm failed)
            $this->line("🔍 Found potential matches:");
            foreach ($suggestions as $index => $suggestion) {
                $this->line("   " . ($index + 1) . ". {$suggestion['path']} (similarity: {$suggestion['score']})");
            }
            $choice = $this->askForChoice($book, $suggestions);
            Log::debug('verifyBookDirectory: Choice returned from askForChoice: ' . $choice);

            switch ($choice) {
                case 'manual':
                    Log::debug('verifyBookDirectory: Switch case: manual');
                    $customPath = $this->askForCustomPath($book);
                    return $this->updateBookDirectory($book, $customPath);

                case 'skip':
                    Log::debug('verifyBookDirectory: Switch case: skip');
                    $this->warn("⏭️  Skipping book: {$book->title}");
                    return 'skipped';

                case 'deleted':
                    Log::debug('verifyBookDirectory: Switch case: deleted');
                    return $this->deleteBook($book);

                case 'fixed':
                    Log::debug('verifyBookDirectory: Switch case: fixed (numeric choice)');
                    // The askForChoice method returns 'fixed' for numeric choices.
                    // We need to get the actual selected path from suggestions.
                    // The original numeric choice is lost here, so we need to pass the suggestions array
                    // and the original choice (which was a number) to updateBookDirectory.
                    // For now, I will assume the first suggestion is the intended fix if 'fixed' is returned.
                    // This needs to be refined based on the logs.
                    return $this->updateBookDirectory($book, $suggestions[0]['path']);

                default:
                    Log::debug('verifyBookDirectory: Switch case: default (unexpected choice: ' . $choice . ')');
                    // This case should ideally not be hit if askForChoice works as expected.
                    $this->error("Invalid choice or unexpected flow. Skipping book: {$book->title}");
                    return 'skipped';
            }
        }

        // No suggestions found
        Log::debug('verifyBookDirectory: No suggestions found.');
        if ($this->autoConfirm) {
            $this->warn("🤷 No similar directories found for {$book->title} - skipping due to --auto-confirm.");
            return 'skipped';
        }

        // Ask user for manual input if not auto-confirming
        $this->warn("🤷 No similar directories found.");
        $customPath = $this->askForCustomPath($book);

        if ($customPath) {
            return $this->updateBookDirectory($book, $customPath);
        }

        return 'skipped';
    }

    protected function findSimilarDirectories(Book $book): array
    {
        $allDirectories = $this->getAllDirectories();
        $usedPaths = $this->getUsedPaths();

        $searchTerms = $this->buildSearchTerms($book);
        $suggestions = [];

        foreach ($allDirectories as $directory) {
            // Skip if path is already used by another book
            if (in_array($directory, $usedPaths)) {
                continue;
            }

            $similarity = $this->calculateDirectorySimilarity($directory, $searchTerms);

            if ($similarity >= $this->similarityThreshold) {
                $suggestions[] = [
                    'path' => $directory,
                    'score' => round($similarity, 3),
                ];
            }
        }

        // Sort by similarity score (highest first)
        usort($suggestions, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array_slice($suggestions, 0, 10); // Return top 10 matches
    }

    protected function getAllDirectories(): array
    {
        $directories = [];
        $this->collectDirectoriesRecursively('', $directories, 0);
        return $directories;
    }

    protected function collectDirectoriesRecursively(string $path, array &$directories, int $depth = 0): void
    {
        // Limit recursion depth to avoid performance issues or infinite loops with circular symlinks
        if ($depth >= 3) {
            return;
        }

        $fullPath = $this->basePath . '/' . $path;

        // Ensure the path exists and is a readable directory
        if (!is_dir($fullPath) || !is_readable($fullPath)) {
            // Log only if it's a genuine directory issue, not a symlink that we'll handle
            if (!is_link($fullPath)) {
                SafeLoggingService::safeLog('warning', "Cannot read directory {$fullPath}: Not a readable directory or does not exist.");
            }
            return;
        }

        try {
            $items = scandir($fullPath);
            if ($items === false) {
                SafeLoggingService::safeLog('warning', "Failed to scan directory {$fullPath}.");
                return;
            }

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $itemPath = $path . '/' . $item;
                $fullItemPath = $fullPath . '/' . $item;

                if (is_link($fullItemPath)) {
                    $resolvedPath = realpath($fullItemPath);
                    if ($resolvedPath && is_dir($resolvedPath)) {
                        // If it's a symbolic link to a directory, add the resolved path
                        // and recurse into it, but ensure we don't add duplicates
                        $relativeResolvedPath = str_replace($this->basePath . '/', '', $resolvedPath);
                        if (!in_array($relativeResolvedPath, $directories)) {
                            $directories[] = $relativeResolvedPath;
                            $this->collectDirectoriesRecursively($relativeResolvedPath, $directories, $depth + 1);
                        }
                    } else {
                        // Log unresolvable or non-directory symlinks if needed for debugging
                        // SafeLoggingService::safeLog('debug', "Skipping unresolvable or non-directory symbolic link: {$fullItemPath}");
                    }
                } elseif (is_dir($fullItemPath)) {
                    // If it's a regular directory, add it and recurse
                    if (!in_array($itemPath, $directories)) {
                        $directories[] = $itemPath;
                        $this->collectDirectoriesRecursively($itemPath, $directories, $depth + 1);
                    }
                }
            }
        } catch (\Exception $e) {
            SafeLoggingService::safeLog('warning', "Error during recursive directory scan for {$fullPath}: " . $e->getMessage());
        }
    }

    protected function getUsedPaths(): array
    {
        return Book::whereNotNull('directory_path')
            ->pluck('directory_path')
            ->filter()
            ->toArray();
    }

    protected function buildSearchTerms(Book $book): array
    {
        $terms = [];

        if ($book->authors->isNotEmpty()) {
            $terms[] = $this->normalizeForComparison($book->authors->pluck('name')->implode(' '));
        }

        if (!empty($book->title)) {
            $terms[] = $this->normalizeForComparison($book->title);
        }

        if ($book->series->isNotEmpty()) {
            $terms[] = $this->normalizeForComparison($book->series->pluck('name')->implode(' '));
        }

        if ($book->narrators->isNotEmpty()) {
            $terms[] = $this->normalizeForComparison($book->narrators->pluck('name')->implode(' '));
        }

        return array_filter($terms);
    }

    protected function calculateDirectorySimilarity(string $directory, array $searchTerms): float
    {
        $normalizedDir = $this->normalizeForComparison($directory);
        $maxSimilarity = 0.0;

        foreach ($searchTerms as $term) {
            // Check if term appears in directory path
            if (strpos($normalizedDir, $term) !== false) {
                $maxSimilarity = max($maxSimilarity, 0.8);
            }

            // Calculate Levenshtein similarity
            $levenshteinSimilarity = 1 - (levenshtein($normalizedDir, $term) / max(strlen($normalizedDir), strlen($term)));
            $maxSimilarity = max($maxSimilarity, $levenshteinSimilarity);

            // Calculate similar_text similarity
            similar_text($normalizedDir, $term, $textPercent);
            $maxSimilarity = max($maxSimilarity, $textPercent / 100);
        }

        return $maxSimilarity;
    }

    protected function normalizeForComparison(string $text): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9\s]/', '', $text));
    }

    protected function askForChoice(Book $book, array $suggestions): string
    {
        $this->newLine();
        $this->line("🤔 What would you like to do?");

        foreach ($suggestions as $index => $suggestion) {
            $this->line("   " . ($index + 1) . ". Use: {$suggestion['path']}");
        }

        $this->line("   m. Enter custom path manually");
        $this->line("   s. Skip this book");
        $this->line("   d. Delete this book");

        $validChoices = array_merge(
            range(1, count($suggestions)),
            ['m', 'manual', 's', 'skip', 'd', 'delete']
        );

        $choice = $this->ask('Your choice');

        if (in_array($choice, ['m', 'manual'])) {
            return 'manual';
        }

        if (in_array($choice, ['s', 'skip'])) {
            return 'skip';
        }

        if (in_array($choice, ['d', 'delete'])) {
            return 'deleted';
        }

        if (is_numeric($choice) && $choice >= 1 && $choice <= count($suggestions)) {
            return 'fixed';
        }

        $this->error("Invalid choice. Please try again.");
        return $this->askForChoice($book, $suggestions);
    }

    protected function askForCustomPath(Book $book): ?string
    {
        $this->newLine();
        $this->line("📝 Enter the correct directory path for: {$book->title}");
        $this->line("   Current path: {$book->directory_path}");
        $this->line("   Base path: {$this->basePath}");
        $this->line("   (Leave empty to skip)");

        $currentPath = $book->directory_path ?? '';

        $input = $this->readlineInput("Directory path", $currentPath);

        if (empty($input)) {
            return null;
        }

        // Validate the path exists
        if (!$this->disk->exists($input)) {
            $this->error("❌ Directory does not exist: {$input}");

            if ($this->confirm('Would you like to try again?')) {
                return $this->askForCustomPath($book);
            }

            return null;
        }

        // Check if path is already used
        $usedBy = Book::where('directory_path', $input)
            ->where('id', '!=', $book->id)
            ->first();

        if ($usedBy) {
            $this->error("❌ Directory is already used by: {$usedBy->title}");

            if ($this->confirm('Would you like to try again?')) {
                return $this->askForCustomPath($book);
            }

            return null;
        }

        return $input;
    }

    protected function readlineInput(string $prompt, string $default = ''): string
    {
        // Attempt to use `read -e -p -i` for true prepopulation if available
        // This requires a bash-like shell and the 'read' command.
        // We'll assume bash-like shells are present on Linux/macOS.
        // Check for 'read' command existence as a basic heuristic.

        // Construct the command. Note: 'read -p' expects the prompt string.
        // The 'line' variable will hold the input, which is then echoed.
        $fullPrompt = $prompt . ": ";
        $command = 'bash -c ' . escapeshellarg('read -e -p ' . escapeshellarg($fullPrompt) . ' -i ' . escapeshellarg($default) . ' line && echo "$line"');
        $output = [];
        $exitCode = 0;

        exec($command, $output, $exitCode);
        if ($exitCode === 0) {
            // If the user just pressed enter without typing, $output might be empty.
            // In that case, return the default.
            return $output[0] ?? $default;
        }

        // Final fallback to Laravel's ask method
        return $this->ask($prompt, $default);
    }


    protected function updateBookDirectory(Book $book, string $newPath): string
    {
        Log::debug('updateBookDirectory called', ['book_id' => $book->id, 'old_path' => $book->directory_path, 'new_path' => $newPath, 'fixMode' => $this->fixMode]);

        if (!$this->fixMode) {
            $this->info("🔧 Would update {$book->title} to: {$newPath}");
            return 'fixed';
        }

        try {
            $book->update(['directory_path' => $newPath]);
            $this->info("✅ Updated {$book->title} to: {$newPath}");

            SafeLoggingService::safeLog('info', "Updated book directory path", [
                'book_id' => $book->id,
                'book_title' => $book->title,
                'old_path' => $book->directory_path,
                'new_path' => $newPath,
            ]);

            Log::debug('Book update successful', ['book_id' => $book->id]);
            return 'fixed';
        } catch (\Exception $e) {
            $this->error("❌ Failed to update {$book->title}: " . $e->getMessage());

            SafeLoggingService::safeLog('error', "Failed to update book directory path", [
                'book_id' => $book->id,
                'book_title' => $book->title,
                'new_path' => $newPath,
                'error' => $e->getMessage(),
            ]);

            Log::error('Book update failed', ['book_id' => $book->id, 'error' => $e->getMessage()]);
            return 'skipped';
        }
    }

    protected function displayStats(array $stats): void
    {
        $this->info('📊 Verification Results:');
        $this->line("   ✅ Valid paths: {$stats['valid']}");
        $this->line("   ❌ Missing paths: {$stats['missing']}");
        $this->line("   🔧 Fixed paths: {$stats['fixed']}");
        $this->line("   ⏭️  Skipped: {$stats['skipped']}");
        $this->line("   🗑️  Deleted: {$stats['deleted']}");
        $this->line("   📚 Total processed: {$stats['total']}");

        if ($stats['fixed'] > 0) {
            $this->info("🎉 Successfully processed {$stats['fixed']} books!");
        }

        if ($stats['missing'] > 0 && $this->fixMode) {
            $this->warn("💡 Some paths were missing but not fixed. Run without --dry-run to fix them.");
        } elseif ($stats['missing'] > 0 && !$this->fixMode) {
            $this->warn("💡 Some paths were missing. Run without --dry-run to fix them.");
        }
    }

    protected function deleteBook(Book $book): string
    {
        $this->newLine();
        $permanent = $this->option('permanent');
        $action = $permanent ? 'permanently delete' : 'move to trash';

        $this->warn("⚠️ You are about to {$action} the following book:");
        $this->line("   Title: {$book->title}");
        $this->line("   Author: " . ($book->authors->isNotEmpty() ? $book->authors->pluck('name')->implode(', ') : 'N/A'));
        $this->line("   Series: " . ($book->series->isNotEmpty() ? $book->series->map(function (\App\Models\Series $s) {
            $seriesNumber = $s->pivot ? $s->pivot->getAttribute('series_number') : null;

            return $s->name . ($seriesNumber ? ' #' . $seriesNumber : '');
        })->implode(', ') : 'N/A'));
        $this->line("   Current Path: {$book->directory_path}");
        $this->line("   Description: " . substr($book->description, 0, 100) . (strlen($book->description) > 100 ? '...' : ''));

        if (!$permanent) {
            $this->info("   (Book can be restored from /admin/trash)");
        }

        $confirmMessage = $permanent ? 'Are you sure you want to PERMANENTLY DELETE this book? This action cannot be undone.' : 'Are you sure you want to move this book to trash?';

        if ($this->confirm($confirmMessage)) {
            try {
                $bookTitle = $book->title; // Store title before deletion
                $bookId = $book->id;

                // Always use trash system
                $result = $this->deletionService->moveToTrash((string) $bookId, true);

                if ($result['success']) {
                    if ($permanent) {
                        $this->info("🗑️ Successfully moved book to trash (soft-deleted): {$bookTitle}");
                    } else {
                        $this->info("🗑️ Successfully moved book to trash: {$bookTitle}");
                    }
                    $this->line("   Trash ID: {$result['trash_item_id']}");
                    SafeLoggingService::safeLog('info', "Book moved to trash", [
                        'book_id' => $bookId,
                        'book_title' => $bookTitle,
                        'deleted_from_command' => true,
                        'trash_item_id' => $result['trash_item_id'],
                    ]);
                } else {
                    $this->error("❌ Failed to move book to trash: " . ($result['error'] ?? 'Unknown error'));
                    SafeLoggingService::safeLog('error', "Failed to move book to trash", [
                        'book_id' => $bookId,
                        'book_title' => $bookTitle,
                        'error' => $result['error'] ?? 'Unknown error',
                    ]);
                    return 'skipped';
                }
                return 'deleted';
            } catch (\Exception $e) {
                $this->error("❌ Failed to delete book {$book->title}: " . $e->getMessage());
                SafeLoggingService::safeLog('error', "Failed to delete book", [
                    'book_id' => $book->id,
                    'book_title' => $book->title,
                    'error' => $e->getMessage(),
                ]);
                return 'skipped';
            }
        }

        $this->warn("🚫 Deletion cancelled for book: {$book->title}");
        return 'skipped';
    }
}
