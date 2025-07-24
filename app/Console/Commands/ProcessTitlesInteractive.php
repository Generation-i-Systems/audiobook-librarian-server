<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\Series;
use App\Models\Narrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
                
                if ($this->option('force') || $this->confirm('Apply these changes?', true)) {
                    $this->applyChanges($book, $titleInfo);
                    $changesCount++;
                    $this->info('✓ Changes applied');
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
}