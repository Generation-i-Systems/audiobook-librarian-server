<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\Series;
use App\Models\Narrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;

class ProcessTitlesInteractive extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'books:process-titles-interactive {--force : Skip confirmation prompts} {--no-backup : Skip automatic database backup}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Interactively process book titles to clean up formatting, extract series info, narrators, and years (creates a database backup by default)';

    public function __construct(
        protected \App\Services\BookDirectoryParser $parser,
        protected \App\Contracts\DocumentStoreServiceInterface $documentStore
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Create a database backup unless --no-backup is specified
        if (!$this->option('no-backup')) {
            $this->info('Creating a database backup before interactive title processing...');
            $this->call('backup:database');
            $this->info('Database backup created.');
        }

        $this->info('Starting interactive title processing...');

        $books = Book::all();
        $processedCount = 0;
        $changesCount = 0;

        foreach ($books as $book) {
            $originalTitle = $book->title;
            $directoryPath = $book->directoryPath ?? '';

            // Process the title through various cleanup steps
            $titleInfo = $this->processTitleAndDirectory($originalTitle, $directoryPath);

            if ($this->hasChanges($originalTitle, $book, $titleInfo)) {
                $this->displayProposedChanges($book, $titleInfo, $originalTitle);

                $options = ['Apply changes', 'Skip'];
                if (!empty($titleInfo['isMultiBookCandidate'])) {
                    $options[] = 'Reprocess as Multi-Book Archive';
                }
                if (!empty($titleInfo['isPartCandidate'])) {
                    $options[] = 'Merge into Parent Book';
                }

                $choice = 'Skip';
                if ($this->option('force')) {
                    $choice = 'Apply changes';
                } else {
                    $choice = $this->choice('Action?', $options, 0);
                }

                if ($choice === 'Apply changes') {
                    $this->applyChanges($book, $titleInfo);
                    $changesCount++;
                    $this->info('✓ Changes applied');
                } elseif ($choice === 'Reprocess as Multi-Book Archive') {
                    $this->reprocessAsMultiBook($book);
                    $changesCount++;
                } elseif ($choice === 'Merge into Parent Book') {
                    $this->mergeIntoParent($book);
                    $changesCount++;
                } else {
                    $this->info('✗ Changes skipped');
                }

                $this->newLine();
            }

            $processedCount++;

            if ($processedCount % 10 == 0) {
                $this->info("Processed {$processedCount}/{$books->count()} books...");
            }
        }

        $this->info("Processing complete! Processed {$processedCount} books, applied changes to {$changesCount} books.");

        return Command::SUCCESS;
    }

    /**
     * Process title and directory to extract information
     */
    protected function processTitleAndDirectory(string $title, string $directoryPath): array
    {
        $result = [
            'title' => $title,
            'seriesNumber' => null,
            'seriesName' => null,
            'narrator' => null,
            'year' => null,
            'multipleBooks' => [],
            'isMultiBookCandidate' => false,
            'isPartCandidate' => false,
            'changes' => []
        ];

        // Step 1: Remove leading spaces and dashes
        $cleanTitle = $this->cleanLeadingChars($title);
        if ($cleanTitle !== $title) {
            $result['changes'][] = "Remove leading spaces/dashes";
            $result['title'] = $cleanTitle;
        }

        // Step 2: Remove (Unab) - short for Unabridged
        $cleanTitle = $this->removeUnabridged($result['title']);
        if ($cleanTitle !== $result['title']) {
            $result['changes'][] = "Remove '(Unab)' marker";
            $result['title'] = $cleanTitle;
        }

        // Step 3: Extract narrator from (read by ...) patterns
        $narratorInfo = $this->extractReadByNarrator($result['title']);
        if ($narratorInfo['narrator']) {
            $result['narrator'] = $narratorInfo['narrator'];
            $result['title'] = $narratorInfo['title'];
            $result['changes'][] = "Extract narrator from '(read by ...)'";
        }

        // Step 4: Extract narrator from parentheses like (Jackson)
        $narratorInfo = $this->extractParenthesesNarrator($result['title']);
        if ($narratorInfo['narrator']) {
            $result['narrator'] = $narratorInfo['narrator'];
            $result['title'] = $narratorInfo['title'];
            $result['changes'][] = "Extract narrator from parentheses";
        }

        // Step 5: Parse complex title patterns for series info
        $seriesInfo = $this->parseComplexTitlePattern($result['title']);
        if ($seriesInfo['seriesNumber'] || $seriesInfo['seriesName']) {
            $result['seriesNumber'] = $seriesInfo['seriesNumber'];
            $result['seriesName'] = $seriesInfo['seriesName'];
            $result['title'] = $seriesInfo['title'];
            if ($seriesInfo['seriesNumber']) {
                $result['changes'][] = "Extract series number: {$seriesInfo['seriesNumber']}";
            }
            if ($seriesInfo['seriesName']) {
                $result['changes'][] = "Extract series name: {$seriesInfo['seriesName']}";
            }
        }

        // Step 6: Process directory for multiple book numbers and year
        $dirInfo = $this->processDirectoryInfo($directoryPath);
        if ($dirInfo['multipleBooks']) {
            $result['multipleBooks'] = $dirInfo['multipleBooks'];
            $result['changes'][] = "Multiple books detected: " . implode(', ', $dirInfo['multipleBooks']);
        }
        if ($dirInfo['year']) {
            $result['year'] = $dirInfo['year'];
            $result['changes'][] = "Extract year: {$dirInfo['year']}";
        }
        if ($dirInfo['seriesName'] && !$result['seriesName']) {
            $result['seriesName'] = $dirInfo['seriesName'];
            $result['changes'][] = "Extract series from directory: {$dirInfo['seriesName']}";
        }

        // Step 7: Check for structural issues (Multi-book or Part)
        if (!empty($directoryPath)) {
            $storageRoot = rtrim(config('filesystems.disks.books.root') ?? config('app.book_root'), '/');
            $fullPath = $storageRoot . '/' . ltrim($directoryPath, '/');

            if (is_dir($fullPath)) {
                $hasKeywords = preg_match('/(Series|Trilogy|Quartet|Duo|Collection|Anthology)/i', $title) ||
                               preg_match('/(Series|Trilogy|Quartet|Duo|Collection|Anthology)/i', $directoryPath);

                try {
                    $audioData = $this->parser->getAudioFiles($fullPath);
                    $fileCount = $audioData['count'];

                    // Case 1: Multi-book archive candidate (Files in root)
                    // Trigger if there are multiple audio files, regardless of keywords
                    if ($fileCount > 1) {
                        $result['isMultiBookCandidate'] = true;
                        $result['changes'][] = "Possible Multi-Book Archive ({$fileCount} files in root)";
                    }

                    // Case 2: Nested Multi-book archive candidate (Subdirectories with audio)
                    // Check subdirectories if we have few files in root (e.g. just an intro or no files)
                    if ($fileCount <= 1) {
                        $finder = new Finder();
                        try {
                            $finder->directories()->in($fullPath)->depth(0);
                            foreach ($finder as $subDir) {
                                $subAudio = $this->parser->getAudioFiles($subDir->getPathname());
                                if ($subAudio['count'] > 0) {
                                    $result['isMultiBookCandidate'] = true;
                                    $result['changes'][] = "Possible Multi-Book Archive (Contains subdirectories with audio)";
                                    break;
                                }
                            }
                        } catch (\Exception $e) {
                            // Ignore finder errors
                        }
                    }

                    // Case 3: Part candidate
                    if (preg_match('/^(CD|Disc|Disk|Part|Vol|Volume)\s*[0-9]+$/i', basename($directoryPath))) {
                        $result['isPartCandidate'] = true;
                        $result['changes'][] = "Possible Part of a Book (Directory name looks like a part)";
                    }
                } catch (\Exception $e) {
                    // Ignore errors accessing files
                }
            }
        }

        return $result;
    }

    /**
     * Clean leading spaces and dashes
     */
    protected function cleanLeadingChars(string $title): string
    {
        return preg_replace('/^[\s\-]+/', '', trim($title));
    }

    /**
     * Remove (Unab) - short for Unabridged
     */
    protected function removeUnabridged(string $title): string
    {
        return preg_replace('/\s*\(Unab\)\s*/i', ' ', $title);
    }

    /**
     * Extract narrator from (read by ...) patterns
     */
    protected function extractReadByNarrator(string $title): array
    {
        $pattern = '/\s*\(read by ([^)]+)\)\s*/i';

        if (preg_match($pattern, $title, $matches)) {
            return [
                'narrator' => trim($matches[1]),
                'title' => preg_replace($pattern, ' ', $title)
            ];
        }

        return ['narrator' => null, 'title' => $title];
    }

    /**
     * Extract narrator from simple parentheses like (Jackson)
     */
    protected function extractParenthesesNarrator(string $title): array
    {
        // Look for patterns like "(Jackson)" that might be narrators
        $pattern = '/\s*\(([A-Za-z\s]+)\)\s*/';

        if (preg_match($pattern, $title, $matches)) {
            $possibleNarrator = trim($matches[1]);

            // Simple heuristic: if it's a single word or two words, might be a narrator
            if (str_word_count($possibleNarrator) <= 2 && ctype_alpha(str_replace(' ', '', $possibleNarrator))) {
                return [
                    'narrator' => $possibleNarrator,
                    'title' => preg_replace($pattern, ' ', $title, 1)
                ];
            }
        }

        return ['narrator' => null, 'title' => $title];
    }

    /**
     * Parse complex title patterns like "- ECA-04 - Spy Night on Union Station"
     */
    protected function parseComplexTitlePattern(string $title): array
    {
        $result = ['seriesNumber' => null, 'seriesName' => null, 'title' => $title];

        // Pattern for "- ECA-04 - Title" or similar
        $pattern = '/^-\s*([A-Z]+)-(\d+)\s*-\s*(.*?)(?:\s+\d+k\s+[\d:.]+\s+\{[^}]+\}\s+by\s+\w+)?$/';

        if (preg_match($pattern, $title, $matches)) {
            $result['seriesName'] = $matches[1];
            $result['seriesNumber'] = (int)$matches[2];
            $result['title'] = trim($matches[3]);
        }

        return $result;
    }

    /**
     * Process directory information for multiple books and years
     */
    protected function processDirectoryInfo(string $directoryPath): array
    {
        $result = ['multipleBooks' => [], 'year' => null, 'seriesName' => null];

        $dirName = basename($directoryPath);

        // Extract year (4 digits in parentheses)
        if (preg_match('/\((\d{4})\)/', $dirName, $matches)) {
            $result['year'] = (int)$matches[1];
        }

        // Extract multiple book numbers like "09,10"
        if (preg_match('/(\d+),(\d+)/', $dirName, $matches)) {
            $result['multipleBooks'] = [(int)$matches[1], (int)$matches[2]];
        }

        // Extract series name from directory
        $cleanDirName = preg_replace('/\d+[,\d]*\s*/', '', $dirName); // Remove numbers
        $cleanDirName = preg_replace('/\(\d{4}\)/', '', $cleanDirName); // Remove year
        $cleanDirName = trim($cleanDirName);

        if (!empty($cleanDirName) && strlen($cleanDirName) > 3) {
            $result['seriesName'] = $cleanDirName;
        }

        return $result;
    }

    /**
     * Check if there are any changes to apply
     */
    protected function hasChanges(string $originalTitle, Book $book, array $titleInfo): bool
    {
        return !empty($titleInfo['changes']) ||
               $titleInfo['narrator'] ||
               $titleInfo['year'] ||
               $titleInfo['seriesNumber'] ||
               !empty($titleInfo['multipleBooks']);
    }

    /**
     * Display proposed changes to the user
     */
    protected function displayProposedChanges(Book $book, array $titleInfo, string $originalTitle): void
    {
        $this->info("Book ID: {$book->id}");
        $this->info("Directory: {$book->directoryPath}");
        $this->line("Original Title: <fg=red>{$originalTitle}</>");
        $this->line("New Title: <fg=green>{$titleInfo['title']}</>");

        if (!empty($titleInfo['changes'])) {
            $this->info("Changes:");
            foreach ($titleInfo['changes'] as $change) {
                $this->line("  • {$change}");
            }
        }

        if ($titleInfo['narrator']) {
            $this->line("Narrator: <fg=yellow>{$titleInfo['narrator']}</>");
        }

        if ($titleInfo['year']) {
            $this->line("Year: <fg=yellow>{$titleInfo['year']}</>");
        }

        if ($titleInfo['seriesName'] || $titleInfo['seriesNumber']) {
            $seriesText = $titleInfo['seriesName'] ?? 'Unknown';
            if ($titleInfo['seriesNumber']) {
                $seriesText .= " (Book {$titleInfo['seriesNumber']})";
            }
            $this->line("Series: <fg=yellow>{$seriesText}</>");
        }

        if (!empty($titleInfo['multipleBooks'])) {
            $this->line("Multiple Books: <fg=yellow>" . implode(', ', $titleInfo['multipleBooks']) . "</>");
        }
    }

    /**
     * Apply the changes to the book
     */
    protected function applyChanges(Book $book, array $titleInfo): void
    {
        DB::transaction(function () use ($book, $titleInfo) {
            // Update title
            $book->title = trim($titleInfo['title']);

            // Update release date if year is found
            if ($titleInfo['year']) {
                $book->release_date = $titleInfo['year'] . '-01-01';
            }

            // Handle narrator
            if ($titleInfo['narrator']) {
                $narrator = Narrator::firstOrCreate(['name' => $titleInfo['narrator']]);
                // Attach narrator to book if not already attached
                if (!$book->narrators()->where('narrator_id', $narrator->id)->exists()) {
                    $book->narrators()->attach($narrator->id);
                }
            }

            // Handle series
            if ($titleInfo['seriesName']) {
                $series = Series::firstOrCreate(['name' => $titleInfo['seriesName']]);
                $seriesNumber = $titleInfo['seriesNumber'] ?? 1;

                // Handle multiple books in the same series
                if (!empty($titleInfo['multipleBooks'])) {
                    foreach ($titleInfo['multipleBooks'] as $bookNumber) {
                        if (!$book->series()->where('series_id', $series->id)->exists()) {
                            $book->series()->attach($series->id, ['series_number' => $bookNumber]);
                        }
                    }
                } else {
                    // Single book
                    if (!$book->series()->where('series_id', $series->id)->exists()) {
                        $book->series()->attach($series->id, ['series_number' => $seriesNumber]);
                    }
                }
            }

            $book->save();
        });
    }

    /**
     * Reprocess a single book as a multi-book archive (split into sub-books).
     */
    protected function reprocessAsMultiBook(Book $book): void
    {
        $path = $book->directoryPath;
        $this->info("Reprocessing book ID {$book->id} as multi-book archive...");
        $this->info("Directory: {$path}");

        $storageRoot = rtrim(config('filesystems.disks.books.root') ?? config('app.book_root'), '/');
        $fullPath = $storageRoot . '/' . ltrim($path, '/');

        // Check for flat files
        try {
            $audioData = $this->parser->getAudioFiles($fullPath);
            if ($audioData['count'] > 0) {
                $this->warn("Found {$audioData['count']} audio files in the root directory.");
                if ($this->confirm('Do you want to move each file into its own book directory? (Recommended for flat archives)', true)) {
                    $this->explodeFlatArchive($fullPath, $audioData['audioFiles']);
                }
            }
        } catch (\Exception $e) {
            $this->warn("Could not check for flat files: " . $e->getMessage());
        }

        // Delete the current book (it's a container)
        $book->delete();

        // Reparse the directory using the parser
        // We pass the relative path, parseDirectory handles resolving
        $booksData = $this->parser->parseDirectory($path);

        $createdCount = 0;
        foreach ($booksData as $bookData) {
            try {
                // Check if book already exists
                $existing = $this->documentStore->findBookByDirectoryPath($bookData['directoryPath']);
                if ($existing) {
                    $this->warn("Book already exists: " . $bookData['title']);
                } else {
                    $this->documentStore->createBook($bookData);
                    $createdCount++;
                    $this->info("Created book: " . $bookData['title']);
                }
            } catch (\Exception $e) {
                $this->error("Failed to create book: " . $e->getMessage());
            }
        }

        $this->info("Reprocessing complete. Created {$createdCount} new books.");
    }

    /**
     * Move files into separate directories based on filename.
     */
    protected function explodeFlatArchive(string $directory, array $files): void
    {
        foreach ($files as $file) {
            $filename = $file->getFilename();
            $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);

            // Create directory
            $newDir = $directory . '/' . $nameWithoutExt;
            if (!file_exists($newDir)) {
                mkdir($newDir, 0755, true);
            }

            // Move file
            $newPath = $newDir . '/' . $filename;
            rename($file->getPathname(), $newPath);
            $this->info("Moved: {$filename} -> {$nameWithoutExt}/");
        }
    }

    /**
     * Merge a book (part) into its parent directory as a single book.
     */
    protected function mergeIntoParent(Book $book): void
    {
        $path = $book->directoryPath;
        $parentPath = dirname($path);

        // Ensure parent path is relative to storage root if $path was relative
        if ($parentPath === '.') {
            $this->error("Cannot merge into root directory.");
            return;
        }

        $this->info("Merging book ID {$book->id} into parent: {$parentPath}");

        // Delete the current book
        $book->delete();

        // Parse parent directory
        // This relies on the updated BookDirectoryParser logic which now aggregates parts
        $booksData = $this->parser->parseDirectory($parentPath);

        $mergedCount = 0;
        foreach ($booksData as $bookData) {
            // We expect one book representing the parent, or maybe multiple if parent contains other books
            // We should find the one that matches the parent directory

            if ($bookData['directoryPath'] === $parentPath || str_starts_with($path, $bookData['directoryPath'])) {
                try {
                    $existing = $this->documentStore->findBookByDirectoryPath($bookData['directoryPath']);
                    if ($existing) {
                        $this->documentStore->updateBook($existing['id'], $bookData);
                        $this->info("Updated parent book: " . $bookData['title']);
                    } else {
                        $this->documentStore->createBook($bookData);
                        $this->info("Created parent book: " . $bookData['title']);
                    }
                    $mergedCount++;
                } catch (\Exception $e) {
                    $this->error("Failed to process parent book: " . $e->getMessage());
                }
            }
        }

        if ($mergedCount === 0) {
            $this->warn("No parent book created/updated. Please check directory structure.");
        } else {
            $this->info("Merged successfully.");
        }
    }
}
