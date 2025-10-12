<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ResolveDuplicateDirectoryPaths extends Command
{
    protected $signature = 'books:resolve-duplicate-paths
                            {--dry-run : Show what would be done without making changes}
                            {--auto : Automatically keep the most complete book}
                            {--limit= : Limit number of duplicates to process}';

    protected $description = 'Find and resolve books with duplicate directory paths';

    public function __construct(
        protected DocumentStoreServiceInterface $documentStore
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $auto = $this->option('auto');
        $limit = $this->option('limit');

        $this->info('🔍 Searching for duplicate directory paths...');
        $this->newLine();

        // Get all books and group by directory_path
        $allBooks = $this->documentStore->listBooks(1, 100000, [], true)['data'] ?? [];
        
        $booksByPath = [];
        foreach ($allBooks as $book) {
            $path = $book['directory_path'] ?? $book['directoryPath'] ?? null;
            if ($path) {
                if (!isset($booksByPath[$path])) {
                    $booksByPath[$path] = [];
                }
                $booksByPath[$path][] = $book;
            }
        }

        // Filter to only duplicates
        $duplicates = array_filter($booksByPath, fn($books) => count($books) > 1);

        if (empty($duplicates)) {
            $this->info('✓ No duplicate directory paths found!');
            return Command::SUCCESS;
        }

        $this->warn('Found ' . count($duplicates) . ' directory paths with multiple books');
        $this->newLine();

        $processed = 0;
        $stats = [
            'kept_as_is' => 0,
            'merged_auto' => 0,
            'merged_manual' => 0,
            'ignored' => 0,
            'errors' => 0,
        ];

        foreach ($duplicates as $path => $books) {
            if ($limit && $processed >= $limit) {
                break;
            }

            $this->displayDuplicateBooks($path, $books);

            if ($auto) {
                $action = 'merge_auto';
            } else {
                $action = $this->askForAction();
            }

            if ($action === 'ignore') {
                $stats['ignored']++;
                $this->info('⏭️  Skipped');
                $this->newLine();
                $processed++;
                continue;
            }

            try {
                switch ($action) {
                    case 'keep_as_is':
                        $this->handleKeepAsIs($books, $dryRun);
                        $stats['kept_as_is']++;
                        break;
                    case 'merge_auto':
                        $this->handleMergeAuto($books, $dryRun);
                        $stats['merged_auto']++;
                        break;
                    case 'merge_manual':
                        $this->handleMergeManual($books, $dryRun);
                        $stats['merged_manual']++;
                        break;
                }
            } catch (\Exception $e) {
                $this->error("Error processing: " . $e->getMessage());
                $stats['errors']++;
            }

            $this->newLine();
            $processed++;
        }

        // Display summary
        $this->displaySummary($stats, $dryRun);

        return Command::SUCCESS;
    }

    protected function displayDuplicateBooks(string $path, array $books): void
    {
        $this->line('═══════════════════════════════════════════════════════════════');
        $this->line("📁 Directory Path: <fg=cyan>{$path}</>");
        $this->line("📚 Found <fg=yellow>" . count($books) . "</> books with this path");
        $this->line('═══════════════════════════════════════════════════════════════');
        $this->newLine();

        foreach ($books as $index => $book) {
            $bookNum = $index + 1;
            $this->line("<fg=bright-white>Book #{$bookNum}:</>");
            $this->displayBookInfo($book);
            $this->newLine();
        }
    }

    protected function displayBookInfo(array $book): void
    {
        $id = $book['id'] ?? $book['_id'] ?? 'N/A';
        $title = $book['title'] ?? 'N/A';
        $authors = $this->formatAuthors($book['author'] ?? $book['authors'] ?? []);
        $series = $this->formatSeries($book['series'] ?? []);
        $narrator = $this->formatNarrators($book['narrator'] ?? $book['narrators'] ?? []);
        $publisher = $book['publisher'] ?? 'N/A';
        $year = $book['year'] ?? $book['releaseDate'] ?? 'N/A';
        $isbn = $book['isbn'] ?? 'N/A';
        $source = $book['source'] ?? 'N/A';
        $coverImage = $book['coverImage'] ?? $book['cover_image'] ?? 'N/A';

        $this->line("  ID:        <fg=yellow>{$id}</>");
        $this->line("  Title:     {$title}");
        $this->line("  Author(s): {$authors}");
        if ($series !== 'N/A') {
            $this->line("  Series:    {$series}");
        }
        if ($narrator !== 'N/A') {
            $this->line("  Narrator:  {$narrator}");
        }
        $this->line("  Publisher: {$publisher}");
        $this->line("  Year:      {$year}");
        $this->line("  ISBN:      {$isbn}");
        $this->line("  Source:    {$source}");
        $this->line("  Cover:     {$coverImage}");

        // Calculate completeness score
        $score = $this->calculateCompletenessScore($book);
        $this->line("  <fg=bright-cyan>Completeness: {$score}%</>");
    }

    protected function formatAuthors($authors): string
    {
        if (empty($authors)) {
            return 'N/A';
        }
        if (is_string($authors)) {
            return $authors;
        }
        if (is_array($authors)) {
            return implode(', ', array_map(fn($a) => is_string($a) ? $a : ($a['name'] ?? 'Unknown'), $authors));
        }
        return 'N/A';
    }

    protected function formatSeries($series): string
    {
        if (empty($series)) {
            return 'N/A';
        }
        if (is_string($series)) {
            return $series;
        }
        if (is_array($series)) {
            $formatted = [];
            foreach ($series as $s) {
                if (is_string($s)) {
                    $formatted[] = $s;
                } elseif (is_array($s)) {
                    $name = $s['name'] ?? $s['series'] ?? 'Unknown';
                    $number = $s['series_number'] ?? $s['number'] ?? null;
                    $formatted[] = $number ? "{$name} #{$number}" : $name;
                }
            }
            return implode(', ', $formatted);
        }
        return 'N/A';
    }

    protected function formatNarrators($narrators): string
    {
        if (empty($narrators)) {
            return 'N/A';
        }
        if (is_string($narrators)) {
            return $narrators;
        }
        if (is_array($narrators)) {
            return implode(', ', array_map(fn($n) => is_string($n) ? $n : ($n['name'] ?? 'Unknown'), $narrators));
        }
        return 'N/A';
    }

    protected function calculateCompletenessScore(array $book): int
    {
        $fields = [
            'title', 'author', 'authors', 'series', 'narrator', 'narrators',
            'publisher', 'year', 'releaseDate', 'isbn', 'description',
            'coverImage', 'cover_image', 'language'
        ];

        $present = 0;
        $total = 0;

        foreach ($fields as $field) {
            if (in_array($field, ['author', 'authors'])) {
                if ($total === 0 || !isset($book['author'])) {
                    $total++;
                    if (!empty($book['author']) || !empty($book['authors'])) {
                        $present++;
                    }
                }
            } elseif (in_array($field, ['coverImage', 'cover_image'])) {
                if ($total === 0 || !isset($book['coverImage'])) {
                    $total++;
                    if (!empty($book['coverImage']) || !empty($book['cover_image'])) {
                        $present++;
                    }
                }
            } else {
                $total++;
                if (!empty($book[$field])) {
                    $present++;
                }
            }
        }

        return $total > 0 ? (int) round(($present / $total) * 100) : 0;
    }

    protected function askForAction(): string
    {
        $this->newLine();
        $this->line('<fg=bright-white>What would you like to do?</>');
        $this->line('  1. <fg=green>Keep as is</> - Keep both books unchanged');
        $this->line('  2. <fg=cyan>Merge automatically</> - Keep most complete book, delete others');
        $this->line('  3. <fg=yellow>Merge manually</> - Choose which book to keep');
        $this->line('  4. <fg=gray>Ignore</> - Skip for now');
        $this->newLine();

        $choice = $this->ask('Enter your choice (1-4)', '2');

        return match ($choice) {
            '1' => 'keep_as_is',
            '2' => 'merge_auto',
            '3' => 'merge_manual',
            '4' => 'ignore',
            default => 'merge_auto',
        };
    }

    protected function handleKeepAsIs(array $books, bool $dryRun): void
    {
        $this->info('✓ Keeping both books as is');
        if (!$dryRun) {
            Log::info('Kept duplicate books as is', [
                'book_ids' => array_map(fn($b) => $b['id'] ?? $b['_id'], $books)
            ]);
        }
    }

    protected function handleMergeAuto(array $books, bool $dryRun): void
    {
        // Find the most complete book
        $bestBook = null;
        $bestScore = -1;

        foreach ($books as $book) {
            $score = $this->calculateCompletenessScore($book);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestBook = $book;
            }
        }

        $keepId = $bestBook['id'] ?? $bestBook['_id'];
        $this->info("✓ Keeping book ID: {$keepId} (completeness: {$bestScore}%)");

        // Delete the others
        foreach ($books as $book) {
            $bookId = $book['id'] ?? $book['_id'];
            if ($bookId !== $keepId) {
                if ($dryRun) {
                    $this->line("  Would delete book ID: {$bookId}");
                } else {
                    $this->documentStore->deleteBook($bookId);
                    $this->line("  <fg=red>Deleted</> book ID: {$bookId}");
                }
            }
        }
    }

    protected function handleMergeManual(array $books, bool $dryRun): void
    {
        $this->newLine();
        $this->line('<fg=bright-white>Which book would you like to keep?</>');
        
        foreach ($books as $index => $book) {
            $bookNum = $index + 1;
            $id = $book['id'] ?? $book['_id'];
            $title = $book['title'] ?? 'N/A';
            $score = $this->calculateCompletenessScore($book);
            $this->line("  {$bookNum}. ID: {$id} - {$title} (completeness: {$score}%)");
        }

        $this->newLine();
        $choice = $this->ask('Enter book number to keep (1-' . count($books) . ')', '1');
        $keepIndex = ((int) $choice) - 1;

        if ($keepIndex < 0 || $keepIndex >= count($books)) {
            throw new \Exception('Invalid book number');
        }

        $keepBook = $books[$keepIndex];
        $keepId = $keepBook['id'] ?? $keepBook['_id'];
        $this->info("✓ Keeping book ID: {$keepId}");

        // Delete the others
        foreach ($books as $index => $book) {
            if ($index !== $keepIndex) {
                $bookId = $book['id'] ?? $book['_id'];
                if ($dryRun) {
                    $this->line("  Would delete book ID: {$bookId}");
                } else {
                    $this->documentStore->deleteBook($bookId);
                    $this->line("  <fg=red>Deleted</> book ID: {$bookId}");
                }
            }
        }
    }

    protected function displaySummary(array $stats, bool $dryRun): void
    {
        $this->line('═══════════════════════════════════════════════════════════════');
        $this->line('  Summary' . ($dryRun ? ' (DRY RUN)' : ''));
        $this->line('═══════════════════════════════════════════════════════════════');
        $this->line("  <fg=green>Kept as is:</>        {$stats['kept_as_is']}");
        $this->line("  <fg=cyan>Merged auto:</>       {$stats['merged_auto']}");
        $this->line("  <fg=yellow>Merged manual:</>     {$stats['merged_manual']}");
        $this->line("  <fg=gray>Ignored:</>           {$stats['ignored']}");
        $this->line("  <fg=red>Errors:</>            {$stats['errors']}");
        $this->line('═══════════════════════════════════════════════════════════════');

        if ($dryRun) {
            $this->newLine();
            $this->info('Run without --dry-run to apply these changes');
        }
    }
}
