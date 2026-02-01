<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use App\Services\BookDeletionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ResolveDuplicateDirectoryPaths extends Command
{
    protected $signature = 'books:resolve-duplicate-paths
                            {--dry-run : Show what would be done without making changes}
                            {--auto : Automatically keep the most complete book}
                            {--limit= : Limit number of duplicates to process}
                            {--permanent : Permanently delete without using trash}';

    protected $description = 'Find and resolve books with duplicate directory paths';

    public function __construct(
        protected DocumentStoreServiceInterface $documentStore,
        protected BookDeletionService $deletionService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $auto = $this->option('auto');
        $limit = (int) $this->option('limit');
        $verbose = $this->option('verbose');
        $permanent = $this->option('permanent');

        if ($permanent) {
            $this->warn('Using --permanent flag: Books will be permanently deleted without trash.');
        } else {
            $this->info('Deleted books will be moved to trash (can be restored from /admin/trash).');
        }

        $this->info('🔍 Searching for duplicate directory paths...');
        $this->newLine();

        // Get all books directly from database to include directory_path field
        // Load relationships for authors, series, narrators, and genres
        $allBooks = \App\Models\Book::with(['authors', 'series', 'narrators', 'genres'])->get()->map(function ($book) {
            $bookArray = $book->toArray();

            // Map relationship data to expected format
            if (isset($bookArray['authors']) && is_array($bookArray['authors'])) {
                $bookArray['author'] = $bookArray['authors'];
            }

            if (isset($bookArray['narrators']) && is_array($bookArray['narrators'])) {
                $bookArray['narrator'] = $bookArray['narrators'];
            }

            if (isset($bookArray['genres']) && is_array($bookArray['genres'])) {
                $bookArray['genre'] = $bookArray['genres'];
            }

            // Use directoryPath field (camelCase from database)
            if (isset($bookArray['directoryPath'])) {
                $bookArray['directory_path'] = $bookArray['directoryPath'];
            }

            return $bookArray;
        })->toArray();

        if ($verbose) {
            $this->line("Total books retrieved: " . count($allBooks));
            if (!empty($allBooks)) {
                $sampleBook = $allBooks[0];
                $this->line("Sample book keys: " . implode(', ', array_keys($sampleBook)));
                $this->line("Sample author field type: " . gettype($sampleBook['author'] ?? null));
                $this->line("Sample author value: " . json_encode($sampleBook['author'] ?? null));
                $this->line("Sample series field type: " . gettype($sampleBook['series'] ?? null));
                $this->line("Sample series value: " . json_encode($sampleBook['series'] ?? null));
                $this->newLine();
            }
        }

        $booksByPath = [];
        $booksWithoutPath = 0;

        foreach ($allBooks as $book) {
            // Try multiple field name variations
            $path = $book['directory_path'] ?? $book['directoryPath'] ?? $book['directory'] ?? null;

            if ($path) {
                if (!isset($booksByPath[$path])) {
                    $booksByPath[$path] = [];
                }
                $booksByPath[$path][] = $book;
            } else {
                $booksWithoutPath++;
                if ($verbose) {
                    $id = $book['id'] ?? $book['_id'] ?? 'unknown';
                    $title = $book['title'] ?? 'unknown';
                    $this->line("Book without path: ID={$id}, Title={$title}");
                }
            }
        }

        if ($verbose) {
            $this->line("Books without directory_path: {$booksWithoutPath}");
            $this->line("Unique paths found: " . count($booksByPath));
            $this->newLine();
        }

        // Filter to only duplicates
        $duplicates = array_filter($booksByPath, fn ($books) => count($books) > 1);

        if (empty($duplicates)) {
            $this->info('✓ No duplicate directory paths found!');
            return Command::SUCCESS;
        }

        $this->warn('Found ' . count($duplicates) . ' directory paths with multiple books');
        $this->newLine();

        $processed = 0;
        $stats = [
            'resolved' => 0,
            'merged_manual' => 0,
            'ignored' => 0,
            'errors' => 0,
        ];

        foreach ($duplicates as $path => $books) {
            $limitVal = $this->option('limit');
            if ($limitVal !== null && $processed >= (int) $limitVal) {
                break;
            }

            $this->displayDuplicateBooks($path, $books);

            if ($auto) {
                // Auto mode: keep first book
                $action = 'keep_0';
            } else {
                $action = $this->askForAction($books);
            }

            if ($action === 'quit') {
                $this->newLine();
                $this->info('🛑 Exiting command...');
                break;
            }

            if ($action === 'ignore') {
                $stats['ignored']++;
                $this->info('⏭️  Skipped');
                $this->newLine();
                $processed++;
                continue;
            }

            try {
                if (str_starts_with($action, 'keep_')) {
                    $keepIndex = (int) substr($action, 5);
                    $this->handleKeepSpecific($books, $keepIndex, $dryRun);
                    $stats['resolved']++;
                } elseif ($action === 'merge_manual') {
                    $this->handleMergeManual($books, $dryRun);
                    $stats['merged_manual']++;
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
            return implode(', ', array_map(fn ($a) => is_string($a) ? $a : ($a['name'] ?? 'Unknown'), $authors));
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
                    $name = $s['name'] ?? $s['series'] ?? $s['seriesName'] ?? 'Unknown';
                    // Check multiple possible field names for the series number
                    $number = $s['series_number'] ?? $s['seriesNumber'] ?? $s['number'] ?? $s['bookNumber'] ?? $s['pivot']['bookNumber'] ?? null;
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
            return implode(', ', array_map(fn ($n) => is_string($n) ? $n : ($n['name'] ?? 'Unknown'), $narrators));
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

        /** @var int<1, max> $total */
        return (int) round(($present / $total) * 100);
    }

    protected function askForAction(array $books): string
    {
        $this->newLine();
        $this->line('<fg=bright-white>Which book would you like to keep?</>');

        foreach ($books as $index => $book) {
            $bookNum = $index + 1;
            $id = $book['id'] ?? $book['_id'];
            $title = $book['title'] ?? 'N/A';
            $score = $this->calculateCompletenessScore($book);
            $this->line("  {$bookNum}. <fg=cyan>Keep Book #{$bookNum}</> (ID: {$id}, completeness: {$score}%)");
        }

        $this->line('  ' . (count($books) + 1) . '. <fg=yellow>Merge manually</> - Choose specific fields from each');
        $this->line('  ' . (count($books) + 2) . '. <fg=gray>Ignore</> - Skip for now');
        $this->line('  <fg=red>q</> or <fg=red>quit</> - Exit command');
        $this->newLine();

        $maxChoice = count($books) + 2;
        $choice = $this->ask("Enter your choice (1-{$maxChoice}, q to quit) [1]");

        // If empty input (just pressed Enter), default to option 1
        if (empty(trim($choice))) {
            return 'keep_0';
        }

        // Check for quit commands
        if (strtolower(trim($choice)) === 'q' || strtolower(trim($choice)) === 'quit') {
            return 'quit';
        }

        $choiceNum = (int) $choice;

        if ($choiceNum >= 1 && $choiceNum <= count($books)) {
            return 'keep_' . ($choiceNum - 1);
        } elseif ($choiceNum === count($books) + 1) {
            return 'merge_manual';
        } elseif ($choiceNum === count($books) + 2) {
            return 'ignore';
        }

        // Invalid input - ask again
        $this->error("Invalid choice. Please enter a number between 1 and {$maxChoice}, or 'q' to quit.");
        return $this->askForAction($books);
    }

    protected function handleKeepSpecific(array $books, int $keepIndex, bool $dryRun): void
    {
        if ($keepIndex < 0 || $keepIndex >= count($books)) {
            throw new \Exception('Invalid book index');
        }

        $keepBook = $books[$keepIndex];
        $keepId = $keepBook['id'] ?? $keepBook['_id'];
        $keepTitle = $keepBook['title'] ?? 'N/A';
        $permanent = $this->option('permanent');

        $this->info("✓ Keeping book ID: {$keepId} - {$keepTitle}");

        // Delete the others
        foreach ($books as $index => $book) {
            if ($index !== $keepIndex) {
                $bookId = $book['id'] ?? $book['_id'];
                $bookTitle = $book['title'] ?? 'N/A';

                if ($dryRun) {
                    $action = $permanent ? 'permanently delete' : 'move to trash';
                    $this->line("  Would {$action} book ID: {$bookId} - {$bookTitle}");
                } else {
                    if ($permanent) {
                        $this->documentStore->deleteBook($bookId);
                        $this->line("  <fg=red>Permanently deleted</> book ID: {$bookId} - {$bookTitle}");
                    } else {
                        $result = $this->deletionService->moveToTrash((string) $bookId, true);
                        if ($result['success']) {
                            $this->line("  <fg=yellow>Moved to trash</> book ID: {$bookId} - {$bookTitle}");
                        } else {
                            $this->error("  Failed to move book ID: {$bookId} to trash - " . ($result['error'] ?? 'Unknown error'));
                        }
                    }

                    Log::info('Deleted duplicate book', [
                        'deleted_id' => $bookId,
                        'kept_id' => $keepId,
                        'directory_path' => $keepBook['directory_path'] ?? 'N/A',
                        'used_trash' => !$permanent
                    ]);
                }
            }
        }
    }

    protected function handleMergeManual(array $books, bool $dryRun): void
    {
        $this->newLine();
        $this->line('<fg=bright-white>Manual Merge: Choose fields from each book</>');
        $this->newLine();

        // Start with first book as base
        $mergedBook = $books[0];
        $baseId = $mergedBook['id'] ?? $mergedBook['_id'];

        // Fields to potentially merge
        $mergeableFields = [
            'title' => 'Title',
            'author' => 'Author(s)',
            'series' => 'Series',
            'narrator' => 'Narrator(s)',
            'publisher' => 'Publisher',
            'year' => 'Year',
            'releaseDate' => 'Release Date',
            'description' => 'Description',
            'coverImage' => 'Cover Image',
            'source' => 'Source',
        ];

        foreach ($mergeableFields as $field => $label) {
            // Show values from each book
            $this->line("<fg=cyan>{$label}:</>");
            $hasMultipleValues = false;
            $values = [];
            $displayValues = [];

            foreach ($books as $index => $book) {
                $bookNum = $index + 1;
                $value = $book[$field] ?? null;

                if ($value !== null && $value !== '' && $value !== 'N/A') {
                    // Format display value based on field type
                    if ($field === 'author' && is_array($value)) {
                        $displayValue = $this->formatAuthors($value);
                        // Add IDs in parentheses
                        $ids = array_map(fn ($a) => $a['id'] ?? '?', $value);
                        $displayValue .= ' (IDs: ' . implode(', ', $ids) . ')';
                    } elseif ($field === 'series' && is_array($value)) {
                        $displayValue = $this->formatSeries($value);
                        // Add IDs in parentheses
                        $ids = array_map(fn ($s) => $s['id'] ?? '?', $value);
                        $displayValue .= ' (IDs: ' . implode(', ', $ids) . ')';
                    } elseif ($field === 'narrator' && is_array($value)) {
                        $displayValue = $this->formatNarrators($value);
                        // Add IDs in parentheses
                        $ids = array_map(fn ($n) => $n['id'] ?? '?', $value);
                        $displayValue .= ' (IDs: ' . implode(', ', $ids) . ')';
                    } elseif (is_array($value)) {
                        $displayValue = json_encode($value);
                    } else {
                        $displayValue = (string) $value;
                    }

                    if (strlen($displayValue) > 150) {
                        $displayValue = substr($displayValue, 0, 150) . '...';
                    }

                    $values[$bookNum] = $value;
                    $displayValues[$bookNum] = $displayValue;
                    $hasMultipleValues = true;
                }
            }

            if (!$hasMultipleValues) {
                $this->line("  <fg=gray>(No values available)</>");
                continue;
            }

            if (count($values) === 1) {
                // Only one book has this field, use it automatically
                $mergedBook[$field] = array_values($values)[0];
                $this->line("  " . array_values($displayValues)[0]);
                $this->line("  <fg=green>→ Using only available value</>");
            } else {
                // Check if all values are identical
                $uniqueDisplayValues = array_unique($displayValues);
                if (count($uniqueDisplayValues) === 1) {
                    // All values are the same, use automatically
                    $mergedBook[$field] = array_values($values)[0];
                    $this->line("  " . array_values($displayValues)[0]);
                    $this->line("  <fg=green>→ All values identical, using automatically</>");
                } else {
                    // Multiple different values, ask user to choose
                    foreach ($displayValues as $num => $display) {
                        $this->line("  {$num}. {$display}");
                    }

                    $choice = $this->ask("  Choose which to use (1-" . count($books) . ", or 'skip')");

                    if (strtolower(trim($choice)) !== 'skip' && isset($values[(int)$choice])) {
                        $mergedBook[$field] = $values[(int)$choice];
                        $this->line("  <fg=green>→ Selected option {$choice}</>");
                    } else {
                        $this->line("  <fg=gray>→ Skipped</>");
                    }
                }
            }

            $this->newLine();
        }

        // Confirm merge
        $this->line('<fg=bright-white>Merged book will have:</>');
        $this->displayBookInfo($mergedBook);
        $this->newLine();

        if (!$this->confirm('Save this merged book?', true)) {
            $this->warn('Merge cancelled');
            return;
        }

        if ($dryRun) {
            $this->info("Would update book ID: {$baseId} with merged data");
            foreach ($books as $index => $book) {
                if ($index > 0) {
                    $bookId = $book['id'] ?? $book['_id'];
                    $this->line("  Would delete book ID: {$bookId}");
                }
            }
        } else {
            // Update the first book with merged data
            // Separate relationship fields from regular fields
            $updateData = [];
            $relationshipFields = ['author', 'series', 'narrator', 'genre'];

            foreach ($mergeableFields as $field => $label) {
                if (isset($mergedBook[$field]) && !in_array($field, $relationshipFields)) {
                    $updateData[$field] = $mergedBook[$field];
                }
            }

            // Update basic fields
            if (!empty($updateData)) {
                $this->documentStore->updateBook($baseId, $updateData);
            }

            // Update relationships using the Book model
            /** @var \App\Models\Book|null $bookModel */
            $bookModel = \App\Models\Book::find($baseId);
            if ($bookModel) {
                // Sync authors
                if (isset($mergedBook['author']) && is_array($mergedBook['author'])) {
                    $authorIds = array_map(fn ($a) => $a['id'], $mergedBook['author']);
                    $bookModel->authors()->sync($authorIds);
                }

                // Sync narrators
                if (isset($mergedBook['narrator']) && is_array($mergedBook['narrator'])) {
                    $narratorIds = array_map(fn ($n) => $n['id'], $mergedBook['narrator']);
                    $bookModel->narrators()->sync($narratorIds);
                }

                // Sync series
                if (isset($mergedBook['series']) && is_array($mergedBook['series'])) {
                    $seriesIds = array_map(fn ($s) => $s['id'], $mergedBook['series']);
                    $bookModel->series()->sync($seriesIds);
                }

                // Sync genres
                if (isset($mergedBook['genre']) && is_array($mergedBook['genre'])) {
                    $genreIds = array_map(fn ($g) => $g['id'], $mergedBook['genre']);
                    $bookModel->genres()->sync($genreIds);
                }
            }

            $this->info("✓ Updated book ID: {$baseId} with merged data");

            // Delete the other books
            $permanent = $this->option('permanent');
            foreach ($books as $index => $book) {
                if ($index > 0) {
                    $bookId = $book['id'] ?? $book['_id'];

                    if ($permanent) {
                        $this->documentStore->deleteBook($bookId);
                        $this->line("  <fg=red>Permanently deleted</> book ID: {$bookId}");
                    } else {
                        $result = $this->deletionService->moveToTrash((string) $bookId, true);
                        if ($result['success']) {
                            $this->line("  <fg=yellow>Moved to trash</> book ID: {$bookId}");
                        } else {
                            $this->error("  Failed to move book ID: {$bookId} to trash - " . ($result['error'] ?? 'Unknown error'));
                        }
                    }
                }
            }
        }
    }

    protected function displaySummary(array $stats, bool $dryRun): void
    {
        $this->line('═══════════════════════════════════════════════════════════════');
        $this->line('  Summary' . ($dryRun ? ' (DRY RUN)' : ''));
        $this->line('═══════════════════════════════════════════════════════════════');
        $this->line("  <fg=green>Resolved:</>          {$stats['resolved']}");
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
