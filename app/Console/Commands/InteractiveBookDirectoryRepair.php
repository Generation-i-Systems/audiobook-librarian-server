<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use App\Models\Book;
use App\Services\SafeLoggingService;

class InteractiveBookDirectoryRepair extends Command
{
    protected $signature = 'books:repair-directories-interactive
                          {--disk=books : Storage disk to use}
                          {--limit=0 : Limit number of books to process (0 for all)}
                          {--similarity-threshold=0.6 : Minimum similarity score for suggestions}
                          {--book-id= : Process specific book ID only}';

    protected $description = 'Interactive repair of book directory paths with readline support';

    protected $disk;
    protected $basePath;
    protected $similarityThreshold = 0.6;
    protected $allDirectories = [];
    protected $usedPaths = [];

    public function handle(): int
    {
        $this->info('🔧 Interactive Book Directory Repair Tool');
        $this->line('This tool will help you fix broken directory paths for your books.');
        $this->newLine();

        if (!function_exists('readline')) {
            $this->warn('⚠️  Readline not available. Using basic input method.');
        }

        $this->disk = Storage::disk($this->option('disk'));
        $this->basePath = $this->disk->path('');
        $this->similarityThreshold = (float) $this->option('similarity-threshold');

        $this->info("📁 Base storage path: {$this->basePath}");
        $this->info("🎯 Similarity threshold: {$this->similarityThreshold}");
        $this->newLine();

        // Pre-load directories and used paths for performance
        $this->info('🔍 Scanning available directories...');
        $this->allDirectories = $this->getAllDirectories();
        $this->usedPaths = $this->getUsedPaths();
        $this->info("Found " . count($this->allDirectories) . " total directories");
        $this->info("Found " . count($this->usedPaths) . " directories already in use");
        $this->newLine();

        $books = $this->getBooksToProcess();

        if ($books->isEmpty()) {
            $this->info('✅ No books with missing directories found!');
            return 0;
        }

        $this->info("📚 Found {$books->count()} books with missing directories");
        $this->newLine();

        $stats = [
            'processed' => 0,
            'fixed' => 0,
            'skipped' => 0,
            'errors' => 0
        ];

        foreach ($books as $book) {
            $result = $this->processBook($book);
            $stats[$result]++;
            $stats['processed']++;

            $this->line(str_repeat('-', 80));
        }

        $this->displayFinalStats($stats);
        return 0;
    }

    protected function getBooksToProcess()
    {
        $query = Book::with(['authors', 'narrators', 'series'])->whereNotNull('directory_path');

        if ($bookId = $this->option('book-id')) {
            $query->where('id', $bookId);
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get()->filter(function ($book) {
            return !empty($book->directory_path) && !$this->disk->exists($book->directory_path);
        });
    }

    protected function processBook(Book $book): string
    {
        $this->displayBookInfo($book);

        $suggestions = $this->findSimilarDirectories($book);

        if (!empty($suggestions)) {
            $this->displaySuggestions($suggestions);
        } else {
            $this->warn("🤷 No similar directories found automatically.");
        }

        return $this->getUserChoice($book, $suggestions);
    }

    protected function displayBookInfo(Book $book): void
    {
        $this->info("📖 Book: {$book->title}");

        $authorNames = $book->relationLoaded('authors') ? $book->authors->pluck('name')->implode(', ') : '';
        $seriesNames = $book->relationLoaded('series') ? $book->series->pluck('name')->implode(', ') : '';
        $narratorNames = $book->relationLoaded('narrators') ? $book->narrators->pluck('name')->implode(', ') : '';
        $publishedYear = $book->getAttribute('published_year');

        $this->line("   👤 Author: " . ($authorNames !== '' ? $authorNames : 'N/A'));
        $this->line("   📚 Series: " . ($seriesNames !== '' ? $seriesNames : 'N/A'));
        $this->line("   🎙️  Narrator: " . ($narratorNames !== '' ? $narratorNames : 'N/A'));
        $this->line("   📅 Year: " . ($publishedYear !== null ? (string) $publishedYear : 'N/A'));
        $this->error("   ❌ Missing path: {$book->directory_path}");
        $this->newLine();
    }

    protected function displaySuggestions(array $suggestions): void
    {
        $this->line("🔍 Suggested directories (by similarity):");
        foreach ($suggestions as $index => $suggestion) {
            $score = number_format($suggestion['score'] * 100, 1);
            $this->line("   " . ($index + 1) . ". {$suggestion['path']} ({$score}% match)");
        }
        $this->newLine();
    }

    protected function getUserChoice(Book $book, array $suggestions): string
    {
        while (true) {
            $options = [];

            if (!empty($suggestions)) {
                for ($i = 1; $i <= count($suggestions); $i++) {
                    $options[] = $i;
                }
            }

            $this->line("Available options:");
            if (!empty($suggestions)) {
                $this->line("   1-" . count($suggestions) . ". Select a suggested directory");
            }
            $this->line("   m. Manually enter/edit path");
            $this->line("   b. Browse available directories");
            $this->line("   s. Skip this book");
            $this->line("   q. Quit");
            $this->newLine();

            $choice = $this->readlineInput("Your choice", strtolower($book->title[0] ?? 'm'));

            switch (strtolower($choice)) {
                case 'm':
                    return $this->handleManualPath($book);

                case 'b':
                    $this->browseDirectories();
                    continue 2;

                case 's':
                    $this->warn("⏭️  Skipping: {$book->title}");
                    return 'skipped';

                case 'q':
                    $this->info("👋 Goodbye!");
                    exit(0);

                default:
                    if (is_numeric($choice) && $choice >= 1 && $choice <= count($suggestions)) {
                        $selectedPath = $suggestions[$choice - 1]['path'];
                        return $this->confirmAndUpdate($book, $selectedPath);
                    }

                    $this->error("Invalid choice. Please try again.");
                    continue 2;
            }
        }
    }

    protected function handleManualPath(Book $book): string
    {
        $currentPath = $book->directory_path;

        $this->line("📝 Current path: {$currentPath}");
        $this->line("💡 You can edit the path below. Use tab completion if available.");
        $this->line("💡 Leave empty to skip, or type '?' for help");
        $this->newLine();

        while (true) {
            $newPath = $this->readlineInput("Enter directory path", $currentPath);

            if (empty($newPath)) {
                $this->warn("⏭️  Skipping: {$book->title}");
                return 'skipped';
            }

            if ($newPath === '?') {
                $this->showPathHelp();
                continue;
            }

            if ($newPath === $currentPath) {
                $this->line("Path unchanged. Trying some suggestions...");
                $this->suggestSimilarPaths($currentPath);
                continue;
            }

            // Validate path
            $validation = $this->validatePath($newPath, $book);

            if ($validation === 'valid') {
                return $this->confirmAndUpdate($book, $newPath);
            } elseif ($validation === 'not_exists') {
                $this->error("❌ Directory does not exist: {$newPath}");

                // Try to suggest similar paths
                $this->suggestSimilarPaths($newPath);

                if (!$this->confirm('Try again?')) {
                    return 'skipped';
                }
            } elseif ($validation === 'in_use') {
                $usedBy = Book::where('directory_path', $newPath)
                    ->where('id', '!=', $book->id)
                    ->first();

                $this->error("❌ Path already used by: {$usedBy->title}");

                if (!$this->confirm('Try again?')) {
                    return 'skipped';
                }
            }
        }
    }

    protected function readlineInput(string $prompt, string $default = ''): string
    {
        if (function_exists('readline')) {
            $input = readline("{$prompt}" . ($default ? " [{$default}]" : '') . ": ");

            if (empty($input) && !empty($default)) {
                return $default;
            }

            if (!empty($input)) {
                readline_add_history($input);
            }

            return $input;
        }

        // Fallback for systems without readline
        return $this->ask($prompt, $default);
    }

    protected function validatePath(string $path, Book $book): string
    {
        if (!$this->disk->exists($path)) {
            return 'not_exists';
        }

        $usedBy = Book::where('directory_path', $path)
            ->where('id', '!=', $book->id)
            ->first();

        if ($usedBy) {
            return 'in_use';
        }

        return 'valid';
    }

    protected function suggestSimilarPaths(string $inputPath): void
    {
        $normalizedInput = $this->normalizeForComparison($inputPath);
        $suggestions = [];

        foreach ($this->allDirectories as $dir) {
            if (in_array($dir, $this->usedPaths)) {
                continue;
            }

            $similarity = $this->calculateStringSimilarity($normalizedInput, $this->normalizeForComparison($dir));

            if ($similarity > 0.5) {
                $suggestions[] = [
                    'path' => $dir,
                    'score' => $similarity
                ];
            }
        }

        if (!empty($suggestions)) {
            usort($suggestions, fn ($a, $b) => $b['score'] <=> $a['score']);

            $this->line("💡 Did you mean one of these?");
            foreach (array_slice($suggestions, 0, 5) as $suggestion) {
                $score = number_format($suggestion['score'] * 100, 1);
                $this->line("   {$suggestion['path']} ({$score}% similar)");
            }
            $this->newLine();
        }
    }

    protected function showPathHelp(): void
    {
        $this->newLine();
        $this->line("📖 Path Help:");
        $this->line("   • Paths are relative to: {$this->basePath}");
        $this->line("   • Use forward slashes (/) as separators");
        $this->line("   • Examples:");
        $this->line("     - Authors/Stephen King/The Stand");
        $this->line("     - Fantasy/Brandon Sanderson/Stormlight Archive/Book 1");
        $this->line("   • Type 'b' to browse available directories");
        $this->line("   • Leave empty to skip this book");
        $this->newLine();
    }

    protected function browseDirectories(): void
    {
        $this->line("📁 Available directories (unused):");
        $this->line("   (Showing directories not assigned to any book)");
        $this->newLine();

        $unused = array_diff($this->allDirectories, $this->usedPaths);

        if (empty($unused)) {
            $this->warn("No unused directories found!");
            return;
        }

        // Group by depth for better display
        $byDepth = [];
        foreach ($unused as $dir) {
            $depth = substr_count($dir, '/');
            $byDepth[$depth][] = $dir;
        }

        ksort($byDepth);

        foreach ($byDepth as $depth => $dirs) {
            if ($depth > 3) {
                break;
            } // Don't show too deep

            $this->line("Level " . ($depth + 1) . ":");
            sort($dirs);

            foreach (array_slice($dirs, 0, 20) as $dir) {
                $this->line("   {$dir}");
            }

            if (count($dirs) > 20) {
                $this->line("   ... and " . (count($dirs) - 20) . " more at this level");
            }

            $this->newLine();
        }

        $this->line("Press Enter to continue...");
        $this->readlineInput("");
    }

    protected function confirmAndUpdate(Book $book, string $newPath): string
    {
        $this->line("📝 Summary:");
        $this->line("   Book: {$book->title}");
        $this->line("   Old path: {$book->directory_path}");
        $this->line("   New path: {$newPath}");
        $this->newLine();

        if (!$this->confirm('Update this book?')) {
            return 'skipped';
        }

        try {
            $book->update(['directory_path' => $newPath]);

            // Update our cache
            $oldPath = $book->directory_path;
            if (($key = array_search($oldPath, $this->usedPaths)) !== false) {
                unset($this->usedPaths[$key]);
            }
            $this->usedPaths[] = $newPath;

            $this->info("✅ Successfully updated: {$book->title}");

            SafeLoggingService::safeLog('info', "Book directory path updated via interactive repair", [
                'book_id' => $book->id,
                'book_title' => $book->title,
                'old_path' => $oldPath,
                'new_path' => $newPath
            ]);

            return 'fixed';
        } catch (\Exception $e) {
            $this->error("❌ Failed to update book: " . $e->getMessage());

            SafeLoggingService::safeLog('error', "Failed to update book directory path via interactive repair", [
                'book_id' => $book->id,
                'book_title' => $book->title,
                'new_path' => $newPath,
                'error' => $e->getMessage()
            ]);

            return 'errors';
        }
    }

    protected function findSimilarDirectories(Book $book): array
    {
        $searchTerms = $this->buildSearchTerms($book);
        $suggestions = [];

        foreach ($this->allDirectories as $directory) {
            if (in_array($directory, $this->usedPaths)) {
                continue;
            }

            $similarity = $this->calculateDirectorySimilarity($directory, $searchTerms);

            if ($similarity >= $this->similarityThreshold) {
                $suggestions[] = [
                    'path' => $directory,
                    'score' => $similarity
                ];
            }
        }

        usort($suggestions, fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($suggestions, 0, 8);
    }

    protected function buildSearchTerms(Book $book): array
    {
        $terms = [];

        if ($book->relationLoaded('authors') && $book->authors->isNotEmpty()) {
            $authorNames = $book->authors->pluck('name')->filter()->toArray();
            foreach ($authorNames as $authorName) {
                $terms[] = $this->normalizeForComparison((string) $authorName);
            }
        }
        if ($book->title) {
            $terms[] = $this->normalizeForComparison($book->title);
        }

        if ($book->relationLoaded('series') && $book->series->isNotEmpty()) {
            $seriesNames = $book->series->pluck('name')->filter()->toArray();
            foreach ($seriesNames as $seriesName) {
                $terms[] = $this->normalizeForComparison((string) $seriesName);
            }
        }

        if ($book->relationLoaded('narrators') && $book->narrators->isNotEmpty()) {
            $narratorNames = $book->narrators->pluck('name')->filter()->toArray();
            foreach ($narratorNames as $narratorName) {
                $terms[] = $this->normalizeForComparison((string) $narratorName);
            }
        }

        return array_filter($terms);
    }

    protected function calculateDirectorySimilarity(string $directory, array $searchTerms): float
    {
        $normalizedDir = $this->normalizeForComparison($directory);
        $maxSimilarity = 0.0;

        foreach ($searchTerms as $term) {
            $similarity = $this->calculateStringSimilarity($normalizedDir, $term);
            $maxSimilarity = max($maxSimilarity, $similarity);
        }

        return $maxSimilarity;
    }

    protected function calculateStringSimilarity(string $str1, string $str2): float
    {
        // Substring match (highest weight)
        if (strpos($str1, $str2) !== false || strpos($str2, $str1) !== false) {
            return 0.9;
        }

        // Similar text
        similar_text($str1, $str2, $percent);
        $similarTextScore = $percent / 100;

        // Levenshtein distance
        $maxLen = max(strlen($str1), strlen($str2));
        if ($maxLen === 0) {
            return 1.0;
        }

        $levenshteinScore = 1 - (levenshtein($str1, $str2) / $maxLen);

        // Return the best score
        return max($similarTextScore, $levenshteinScore);
    }

    protected function normalizeForComparison(string $text): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9\s]/', '', $text));
    }

    protected function getAllDirectories(): array
    {
        $directories = [];
        $this->collectDirectoriesRecursively('', $directories);
        return $directories;
    }

    protected function collectDirectoriesRecursively(string $path, array &$directories): void
    {
        try {
            $contents = $this->disk->directories($path);

            foreach ($contents as $directory) {
                $directories[] = $directory;

                if (substr_count($directory, '/') < 4) {
                    $this->collectDirectoriesRecursively($directory, $directories);
                }
            }
        } catch (\Exception $e) {
            // Silently skip problematic directories
        }
    }

    protected function getUsedPaths(): array
    {
        return Book::whereNotNull('directory_path')
            ->pluck('directory_path')
            ->filter()
            ->toArray();
    }

    protected function displayFinalStats(array $stats): void
    {
        $this->newLine();
        $this->info('🎉 Interactive Repair Complete!');
        $this->line("   📚 Books processed: {$stats['processed']}");
        $this->line("   ✅ Successfully fixed: {$stats['fixed']}");
        $this->line("   ⏭️  Skipped: {$stats['skipped']}");
        $this->line("   ❌ Errors: {$stats['errors']}");

        if ($stats['fixed'] > 0) {
            $this->info("🔧 Great job! You've repaired {$stats['fixed']} book directories.");
        }
    }
}
