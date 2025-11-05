<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Console\Command;

class FixBookTitlesWithYearAndNarrator extends Command
{
    protected $signature = 'books:fix-titles-year-narrator
                            {--dry-run : Show what would be changed without making changes}
                            {--limit= : Limit the number of books to process}';

    protected $description = 'Fix book titles that start with year and contain narrator info in parentheses';

    private DocumentStoreServiceInterface $documentStore;

    public function __construct(DocumentStoreServiceInterface $documentStore)
    {
        parent::__construct();
        $this->documentStore = $documentStore;
    }

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->info('Scanning books for titles with year prefix and narrator info...');
        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $page = 1;
        $perPage = 100;
        $totalProcessed = 0;
        $totalFixed = 0;

        while (true) {
            $result = $this->documentStore->listBooks($page, $perPage, [], false);
            $books = $result['data'];

            if (empty($books)) {
                break;
            }

            foreach ($books as $book) {
                if ($limit && $totalProcessed >= $limit) {
                    break 2;
                }

                $totalProcessed++;
                $bookId = $book['id'] ?? $book['documentId'] ?? null;
                $title = $book['title'] ?? null;

                if (!$bookId || !$title || !is_string($title)) {
                    continue;
                }

                $changes = $this->analyzeTitle($title);

                if (!empty($changes)) {
                    $totalFixed++;

                    $this->line('');
                    $this->info("Book ID: {$bookId}");
                    $this->line("  Original Title: {$title}");

                    if (isset($changes['new_title'])) {
                        $this->line("  New Title:      {$changes['new_title']}");
                    }

                    if (isset($changes['year'])) {
                        $this->line("  Extracted Year: {$changes['year']}");
                    }

                    if (isset($changes['narrator'])) {
                        $narratorList = is_array($changes['narrator']) ? implode(', ', $changes['narrator']) : $changes['narrator'];
                        $this->line("  Extracted Narrator: {$narratorList}");
                    }

                    if (!$isDryRun) {
                        $this->applyChanges($bookId, $book, $changes);
                        $this->comment('  ✓ Updated');
                    }
                }
            }

            $page++;
        }

        $this->newLine();
        $this->info("Processed {$totalProcessed} books");
        $this->info("Found {$totalFixed} books to fix");

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes were made');
            $this->info('Run without --dry-run to apply changes');
        }

        return 0;
    }

    private function analyzeTitle(string $title): array
    {
        $changes = [];
        $workingTitle = $title;

        // Pattern 1: Year prefix - "2005 - The Colorado Kid"
        if (preg_match('/^(\d{4})\s*-\s*(.+)$/', $workingTitle, $matches)) {
            $year = (int) $matches[1];
            $titleWithoutYear = $matches[2];

            // Only extract year if it's reasonable (1700 to current year)
            $currentYear = (int) date('Y');
            if ($year >= 1700 && $year <= $currentYear) {
                $workingTitle = $titleWithoutYear;
                $changes['year'] = $year;
            }
            // If year is invalid, leave the title unchanged (don't remove the year)
        }

        // Pattern 1b: Year suffix in parentheses - "Book Title (2008)" or "Book Title (2010) (read by...)"
        if (!isset($changes['year']) && preg_match('/^(.+?)\s*\((\d{4})\)(.*)$/', $workingTitle, $matches)) {
            $year = (int) $matches[2];
            $titleWithoutYear = trim($matches[1]);
            $remainingText = $matches[3];

            // Only extract year if it's reasonable (1700 to current year)
            $currentYear = (int) date('Y');
            if ($year >= 1700 && $year <= $currentYear) {
                $workingTitle = trim($titleWithoutYear . $remainingText);
                $changes['year'] = $year;
            }
            // If year is invalid, leave the title unchanged
        }

        // Pattern 2: Narrator in parentheses - "(read by Jeffrey DeMunn)"
        // Common patterns:
        // - (read by Name)
        // - (Narrated by Name)
        // - (performed by Name)
        // - (a Full Cast)
        // - (Name1 and Name2)
        // - (with Name)

        $narratorPatterns = [
            '/\s*\([^)]*read by\s+([^)]+)\)\s*$/i',          // (Nonfiction - read by Name)
            '/\s*\([^)]*narrated by\s+([^)]+)\)\s*$/i',      // (Fiction - narrated by Name)
            '/\s*\([^)]*performed by\s+([^)]+)\)\s*$/i',     // (Drama - performed by Name)
            '/\s*\([^)]*narrator[:\s]+([^)]+)\)\s*$/i',      // (narrator: Name)
            '/\s*\([^)]*voice[:\s]+([^)]+)\)\s*$/i',         // (voice: Name)
        ];

        foreach ($narratorPatterns as $pattern) {
            if (preg_match($pattern, $workingTitle, $matches)) {
                $narratorText = trim($matches[1]);
                $workingTitle = preg_replace($pattern, '', $workingTitle);

                // Parse narrator names
                $narrators = $this->parseNarrators($narratorText);
                if (!empty($narrators)) {
                    $changes['narrator'] = $narrators;
                }
                break;
            }
        }

        // Clean up the title
        $workingTitle = trim($workingTitle);

        // Only add to changes if the title actually changed
        if ($workingTitle !== $title) {
            $changes['new_title'] = $workingTitle;
        }

        return $changes;
    }

    private function parseNarrators(string $narratorText): array
    {
        $narrators = [];

        // Handle "a Full Cast" or "Full Cast"
        if (preg_match('/^(a\s+)?full\s+cast$/i', $narratorText)) {
            return ['Full Cast'];
        }

        // Split by common separators: "and", ",", "&"
        $parts = preg_split('/\s+and\s+|,\s*|&\s*/i', $narratorText);

        foreach ($parts as $part) {
            $part = trim($part);
            if (!empty($part)) {
                // Clean up common prefixes
                $part = preg_replace('/^(a\s+)?full\s+cast$/i', 'Full Cast', $part);
                $narrators[] = $part;
            }
        }

        return array_unique($narrators);
    }

    private function applyChanges(string $bookId, array $book, array $changes): void
    {
        $updates = [];

        if (isset($changes['new_title'])) {
            $updates['title'] = $changes['new_title'];
        }

        if (isset($changes['year'])) {
            // Set release_date to January 1st of the year
            $updates['release_date'] = $changes['year'] . '-01-01';
        }

        if (isset($changes['narrator'])) {
            // Get existing narrators
            $existingNarrators = $book['narrator'] ?? $book['narrators'] ?? [];

            // Normalize to array
            if (!is_array($existingNarrators)) {
                $existingNarrators = $existingNarrators ? [$existingNarrators] : [];
            }

            // Extract names from existing narrator objects if needed
            $existingNames = [];
            foreach ($existingNarrators as $narrator) {
                if (is_array($narrator) && isset($narrator['name'])) {
                    $existingNames[] = $narrator['name'];
                } elseif (is_string($narrator)) {
                    $existingNames[] = $narrator;
                }
            }

            // Merge with new narrators, avoiding duplicates
            $newNarrators = array_unique(array_merge($existingNames, $changes['narrator']));

            // Only update if we're adding new narrators
            if (count($newNarrators) > count($existingNames)) {
                $updates['narrators'] = $newNarrators;
            }
        }

        if (!empty($updates)) {
            $this->documentStore->updateBook($bookId, $updates);
        }
    }
}
