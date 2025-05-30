<?php

namespace App\Console\Commands;

use App\Services\BookDirectoryParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class TestBookParserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'books:test-parse
                            {paths* : One or more directory paths to scan for books}
                            {--output= : Output format (json, table, csv, sql, array). Default: table}
                            {--limit=0 : Maximum number of books to process (0 for no limit)}
                            {--extensions= : Comma-separated list of file extensions to include}
                            {--min-size= : Minimum file size in bytes}
                            {--max-depth= : Maximum directory depth to scan}
                            {--dry-run : Show what would be done without making any changes}
                            {--sort : Sort output by author, series, series number, and title}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the book directory parser with a given path';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(BookDirectoryParser $parser)
    {
        $paths = $this->argument('paths');
        $outputFormat = $this->option('output') ?: 'table';
        $limit = (int) $this->option('limit');
        $extensions = $this->option('extensions');
        $minSize = $this->option('min-size');
        $maxDepth = $this->option('max-depth');
        $dryRun = $this->option('dry-run');
        $shouldSort = $this->option('sort');

        // If no paths provided, use current directory
        if (empty($paths)) {
            $paths = [getcwd()];
        }

        // Validate all paths
        foreach ($paths as $path) {
            if (!File::exists($path) || !File::isDirectory($path)) {
                $this->error("The specified path does not exist or is not a directory: $path");
                return 1;
            }
        }

        // Configure the parser
        $config = [
            'extensions' => $extensions ? explode(',', $extensions) : null,
            'min_file_size' => $minSize ? (int) $minSize : null,
            'max_depth' => $maxDepth ? (int) $maxDepth : null,
        ];

        // Remove null values
        $config = array_filter($config);

        // Parse all directories
        $this->info('Parsing book directories...');
        $startTime = microtime(true);
        $allBooks = [];

        try {
            foreach ($paths as $path) {
                $this->info("\nScanning: $path");
                $books = $parser->parseDirectory($path, $config);
                $allBooks = array_merge($allBooks, $books);
            }

            $duration = round(microtime(true) - $startTime, 2);

            // First pass: Clean up basic fields
            foreach ($allBooks as &$book) {
                // Clean up author
                if (empty($book['author']) || str_contains($book['author'] ?? '', 'Unknown')) {
                    // Use the directory path from the book data if available, otherwise use the path from the book file
                    $pathToUse = $book['directory_path'] ?? $book['path'] ?? '';
                    
                    // If we don't have a path, try to get it from the full_path
                    if (empty($pathToUse) && !empty($book['full_path'])) {
                        $pathToUse = dirname($book['full_path']);
                    }
                    
                    $this->info("Extracting author from path: $pathToUse");
                    $book['author'] = $this->extractAuthorFromPath($pathToUse);
                    
                    // If we still don't have an author, try to get it from the parent directory
                    if (($book['author'] === 'Unknown Author' || empty($book['author'])) && !empty($book['full_path'])) {
                        $parentDir = dirname($book['full_path']);
                        $this->info("Trying parent directory for author: $parentDir");
                        $book['author'] = $this->extractAuthorFromPath($parentDir);
                    }
                }

                // Clean up title
                if (!empty($book['title'])) {
                    $book['title'] = ucfirst(trim($book['title']));
                }

                // Clean up series - don't use author name as series
                if (!empty($book['series'])) {
                    $book['series'] = trim($book['series']);
                    
                    // If series is the same as author (case-insensitive), unset it
                    if (strtolower($book['series']) === strtolower($book['author'] ?? '')) {
                        $book['series'] = '';
                        $book['series_number'] = null;
                    }
                }
            }
            unset($book); // Break the reference

            // Second pass: Fix common typos in series and titles
            $seriesMap = $this->buildSeriesMap($allBooks);
            foreach ($allBooks as &$book) {
                // Fix series names using fuzzy matching
                if (!empty($book['series'])) {
                    $book['series'] = $this->fixSeriesName($book['series'], $seriesMap);
                }

                // Clean up common title issues
                $cleanupResult = $this->cleanupTitle($book['title'] ?? '');
                $book['title'] = $cleanupResult['title'];

                // Store metadata for review if needed
                if ($cleanupResult['metadata']['needs_review']) {
                    $book['needs_review'] = true;
                    $book['review_reason'] = 'Title may need manual review';
                    if (!empty($cleanupResult['metadata']['applied_corrections'])) {
                        $book['applied_corrections'] = $cleanupResult['metadata']['applied_corrections'];
                    }
                }
            }
            unset($book); // Break the reference

            // Third pass: Rebuild series map with corrected names
            $seriesMap = $this->buildSeriesMap($allBooks);

            // Fourth pass: Ensure series consistency
            foreach ($allBooks as &$book) {
                if (!empty($book['series']) && isset($seriesMap[$book['series']])) {
                    $book['series'] = $seriesMap[$book['series']];
                }
            }
            unset($book); // Break the reference

            // Sort the books if requested
            if ($shouldSort) {
                usort($allBooks, function ($a, $b) {
                    // First sort by author (case-insensitive)
                    $authorA = strtolower($a['author'] ?? '');
                    $authorB = strtolower($b['author'] ?? '');
                    $cmp = strnatcasecmp($authorA, $authorB);
                    if ($cmp !== 0)
                        return $cmp;

                    // Then by series (case-insensitive, empty series last)
                    $seriesA = !empty($a['series']) ? strtolower($a['series']) : 'zzz_no_series';
                    $seriesB = !empty($b['series']) ? strtolower($b['series']) : 'zzz_no_series';
                    $cmp = strnatcasecmp($seriesA, $seriesB);
                    if ($cmp !== 0)
                        return $cmp;

                    // Then by series number (handle null/empty values)
                    $aNum = !empty($a['series_number']) ? (float) $a['series_number'] : 0;
                    $bNum = !empty($b['series_number']) ? (float) $b['series_number'] : 0;
                    if ($aNum != $bNum)
                        return $aNum <=> $bNum;

                    // Finally by title (case-insensitive)
                    return strnatcasecmp($a['title'] ?? '', $b['title'] ?? '');
                });
            }

            if ($limit > 0) {
                $allBooks = array_slice($allBooks, 0, $limit);
            }

            $this->info(sprintf(
                'Found %d books in %s seconds',
                count($allBooks),
                $duration
            ));

            if (empty($allBooks)) {
                $this->warn('No books found matching the criteria.');
                return 0;
            }

            // Output the results in the requested format
            $this->outputResults($allBooks, $outputFormat);

            if ($this->getOutput()->isVerbose()) {
                $this->outputVerboseInfo($allBooks);
            }

            return 0;

        } catch (\Exception $e) {
            $this->error('An error occurred while parsing the directory:');
            $this->error($e->getMessage());
            $this->line('');
            $this->line('Stack trace:');
            $this->line($e->getTraceAsString());

            return 1;
        }
    }

    /**
     * Output the results in the specified format.
     *
     * @param array $books
     * @param string $format
     * @return void
     */
    protected function outputResults(array $books, string $format): void
    {
        switch (strtolower($format)) {
            case 'json':
                $this->line(json_encode($books, JSON_PRETTY_PRINT));
                break;

            case 'csv':
                $this->outputCsv($books);
                break;

            case 'sql':
                $this->outputSql($books);
                break;

            case 'array':
                dump($books);
                break;

            case 'table':
            default:
                $this->outputTable($books);
        }
    }

    /**
     * Output books as a table.
     *
     * @param array $books
     * @return void
     */
    protected function outputTable(array $books): void
    {
        $headers = ['#', 'Title', 'Author', 'Duration', 'Files', 'Series', 'Number', 'Narrator', 'Edition'];

        $rows = [];
        foreach ($books as $index => $book) {
            $rows[] = [
                $index + 1,
                $book['title'] ?? '',
                $book['author'] ?? '',
                $book['duration_formatted'] ?? 'N/A',
                $book['audio_file_count'] ?? 0,
                $book['series'] ?? '',
                $book['series_number'] ?? '',
                $book['narrator'] ?? '',
                $book['edition'] ?? '',
            ];
        }

        $this->table($headers, $rows);
    }

    /**
     * Output books as CSV.
     *
     * @param array $books
     * @return void
     */
    protected function outputCsv(array $books): void
    {
        if (empty($books)) {
            return;
        }

        // Get headers from the first book
        $headers = array_keys($books[0]);

        // Open output stream
        $handle = fopen('php://output', 'w');

        // Write headers
        fputcsv($handle, $headers);

        // Write data
        foreach ($books as $book) {
            // Ensure all fields are present and in the same order as headers
            $row = [];
            foreach ($headers as $header) {
                $row[] = $book[$header] ?? '';
            }
            fputcsv($handle, $row);
        }

        fclose($handle);
    }

    /**
     * Output books as SQL INSERT statements.
     *
     * @param array $books
     * @return void
     */
    protected function outputSql(array $books): void
    {
        if (empty($books)) {
            return;
        }

        $table = config('bookparser.database_table', 'books');

        foreach ($books as $book) {
            // Prepare values for SQL
            $values = [];
            foreach ($book as $key => $value) {
                if (is_array($value)) {
                    $value = json_encode($value);
                } elseif (is_bool($value)) {
                    $value = $value ? '1' : '0';
                } elseif (is_null($value)) {
                    $value = 'NULL';
                } else {
                    $value = "'" . addslashes((string) $value) . "'";
                }

                $values[$key] = $value;
            }

            $columns = implode(', ', array_map(function ($col) {
                return "`$col`";
            }, array_keys($values)));

            $values = implode(', ', array_values($values));

            $this->line("INSERT INTO `$table` ($columns) VALUES ($values);");
        }
    }

    /**
     * Extract author name from file path
     *
     * @param string $path The full path to the book file
     * @return string The extracted author name, or 'Unknown Author' if not found
     */
    protected function extractAuthorFromPath(string $path): string
    {
        $this->info("\nExtracting author from path: $path");
        
        // If the path is empty, return unknown
        if (empty($path)) {
            $this->info("Path is empty");
            return 'Unknown Author';
        }
        
        // Normalize the path
        $normalizedPath = str_replace('\\', '/', $path);
        $this->info("Normalized path: $normalizedPath");
        
        // Split the path into parts
        $parts = explode('/', $normalizedPath);
        $parts = array_filter($parts, function ($part) {
            return !empty($part) && $part !== '.' && $part !== '..';
        });
        
        // Reset array keys after filtering
        $parts = array_values($parts);
        $this->info("Path parts: [" . implode('] [', $parts) . "]");
        
        // Common directories to skip when looking for author name
        $skipDirs = [
            'books', 'book', 'audiobooks', 'audiobook', 'litrpg', 'scifi', 'fantasy',
            'science fiction', 'fiction', 'nonfiction', 'non-fiction', 'unabridged',
            'abridged', 'full cast', 'dramatization', 'dramatized', 'dramatisation',
            'dramatised', 'audio', 'audible', 'mp3', 'm4b', 'aac', 'flac', 'wav',
            'mp4', 'm4a', 'aax', 'aaxc', 'aax+', 'aaxplus', 'aax-plus', 'aax_plus',
            'surgeon', 'battlefield', 'kaiju' // Skip common words from titles
        ];
        
        // Check each directory component from deepest to shallowest
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $currentPart = $parts[$i];
            $currentPartLower = strtolower($currentPart);
            $this->info("\nChecking part: $currentPart");
            
            // Skip if part is in skip list or is numeric
            if (in_array($currentPartLower, $skipDirs) || 
                is_numeric($currentPart) || 
                preg_match('/^\d+$/', $currentPart) || 
                preg_match('/^[\s\d-]+$/', $currentPart)) {
                $this->info("Skipping: $currentPart (in skip list or numeric)");
                continue;
            }
            
            // Special case: If we find 'matt dinniman' in any part, use it
            if (stripos($currentPart, 'matt dinniman') !== false) {
                $this->info("Found 'Matt Dinniman' in path part: $currentPart");
                return 'Matt Dinniman';
            }
            
            // Check if the current part looks like an author name (Firstname Lastname)
            if (preg_match('/^[A-Z][a-z]+ [A-Z][a-z]+$/', $currentPart)) {
                $this->info("Found author name: $currentPart");
                return $currentPart;
            }
            
            // Check for directory names with hyphens (e.g., 'Author - Series Name')
            if (str_contains($currentPart, ' - ')) {
                $subParts = explode(' - ', $currentPart, 2);
                $potentialAuthor = trim($subParts[0]);
                
                if (preg_match('/^[A-Z][a-z]+ [A-Z][a-z]+$/', $potentialAuthor)) {
                    $this->info("Found author in hyphenated name: $potentialAuthor");
                    return $potentialAuthor;
                }
            }
            
            // If we're not at the first part, check the parent directory
            if ($i > 0) {
                $parentDir = $parts[$i - 1];
                $parentDirLower = strtolower($parentDir);
                $this->info("Checking parent directory: $parentDir");
                
                // Special case: If parent directory is 'matt dinniman', use it
                if ($parentDirLower === 'matt dinniman') {
                    $this->info("Found author in parent directory: $parentDir");
                    return 'Matt Dinniman';
                }
                
                // If parent directory looks like an author name, use it
                if (preg_match('/^[A-Z][a-z]+ [A-Z][a-z]+$/', $parentDir)) {
                    $this->info("Found author in parent directory: $parentDir");
                    return $parentDir;
                }
            }
        }
        
        // If we're here, we couldn't find an author in the path
        // Let's check if any part contains 'matt dinniman' (case insensitive)
        foreach ($parts as $part) {
            if (stripos($part, 'matt dinniman') !== false) {
                $this->info("Found 'Matt Dinniman' in path part: $part");
                return 'Matt Dinniman';
            }
        }
        
        $this->info("Could not determine author from path");
        return 'Unknown Author';
    }

    /**
     * Clean up common title issues
     *
     * @param string $title
     * @return array Returns an array with 'title' and 'metadata' about the cleanup
     */
    protected function cleanupTitle(string $title): array
    {
        if (empty($title)) {
            return [
                'title' => $title,
                'metadata' => [
                    'needs_review' => false,
                    'applied_corrections' => [],
                ],
            ];
        }

        $originalTitle = $title;
        $appliedCorrections = [];

        // Remove common parentheticals that might contain series info
        $cleaned = preg_replace('/\s*\([^)]*\)\s*$/', '', $title);
        if ($cleaned !== $title) {
            $appliedCorrections[] = 'Removed parenthetical suffix';
            $title = $cleaned;
        }

        // Normalize whitespace
        $title = trim(preg_replace('/\s+/', ' ', $title));

        // Check if the title needs review
        $needsReview = $this->titleNeedsReview($title);

        return [
            'title' => $title,
            'metadata' => [
                'needs_review' => $needsReview,
                'original_title' => $originalTitle,
                'applied_corrections' => $appliedCorrections,
            ],
        ];
    }

    /**
     * Determine if a title needs manual review
     *
     * @param string $title
     * @return bool
     */
    protected function titleNeedsReview(string $title): bool
    {
        // Title is too short
        if (strlen($title) < 3) {
            return true;
        }

        // Title is all uppercase or all lowercase
        if ($title === strtoupper($title) || $title === strtolower($title)) {
            return true;
        }

        // Title contains common issues that need review
        $commonIssues = [
            '  ',  // Double space
            ' ,',  // Space before comma
            ' .',  // Space before period
            ' :',  // Space before colon
            ' - ', // Spaces around dash
        ];

        foreach ($commonIssues as $issue) {
            if (str_contains($title, $issue)) {
                return true;
            }
        }

        return false;
    }
    /**
     * Build a map of series names to their canonical forms
     *
     * @param array $books
     * @return array
     */
    protected function buildSeriesMap(array $books): array
    {
        $seriesMap = [];
        $seriesNames = [];

        // First, collect all unique series names
        foreach ($books as $book) {
            if (!empty($book['series'])) {
                $seriesNames[$book['series']] = true;
            }
        }

        $seriesNames = array_keys($seriesNames);

        // Create a map of common typos/variations to their canonical forms
        foreach ($seriesNames as $series) {
            $normalized = $this->normalizeSeriesName($series);
            $seriesMap[$series] = $normalized;

            // Add common variations
            $variations = [
                str_replace(' ', '', $series),  // Remove spaces
                str_replace('-', ' ', $series), // Replace hyphens with spaces
                str_replace(':', '', $series),  // Remove colons
                str_replace("'", '', $series),  // Remove apostrophes
                str_replace('&', 'and', $series), // Replace & with 'and'
                str_replace(' and ', ' & ', $series), // Replace 'and' with '&'
                str_replace('The ', '', $series), // Remove leading 'The'
                'The ' . $series, // Add leading 'The'
            ];

            foreach ($variations as $variation) {
                if ($variation !== $series && !isset($seriesMap[$variation])) {
                    $seriesMap[$variation] = $normalized;
                }
            }
        }

        return $seriesMap;
    }

    /**
     * Normalize a series name to its canonical form
     *
     * @param string $name
     * @return string
     */
    protected function normalizeSeriesName(string $name): string
    {
        $name = trim($name);

        // Common replacements
        $replacements = [
            '&' => 'and',
            '  ' => ' ', // Multiple spaces to single space
        ];

        $name = str_replace(array_keys($replacements), array_values($replacements), $name);

        // Title case the series name (first letter of each word)
        $name = ucwords(strtolower($name));

        // Handle special cases
        $specialCases = [
            'Dcc' => 'DCC',
            'Rpg' => 'RPG',
            'LitRpg' => 'LitRPG',
            'Litrpggamelit' => 'LitRPG',
        ];

        foreach ($specialCases as $from => $to) {
            if (str_contains($name, $from)) {
                $name = str_replace($from, $to, $name);
            }
        }

        return $name;
    }

    /**
     * Fix series name using fuzzy matching
     *
     * @param string $series
     * @param array $seriesMap
     * @return string
     */
    protected function fixSeriesName(string $series, array $seriesMap): string
    {
        $normalized = $this->normalizeSeriesName($series);

        // Check for exact match first
        if (isset($seriesMap[$normalized])) {
            return $seriesMap[$normalized];
        }

        // Check for close matches using Levenshtein distance
        $bestMatch = $normalized;
        $bestDistance = PHP_INT_MAX;

        foreach ($seriesMap as $candidate => $canonical) {
            $distance = levenshtein(strtolower($normalized), strtolower($candidate));

            // If we find a perfect match (distance 0-1), return it immediately
            if ($distance <= 1) {
                return $canonical;
            }

            // Track the best match so far
            if ($distance < $bestDistance) {
                $bestMatch = $canonical;
                $bestDistance = $distance;
            }
        }

        // If we found a reasonably close match, use it
        if ($bestDistance <= 3) {
            return $bestMatch;
        }

        // Otherwise, return the normalized version
        return $normalized;
    }

    /**
     * Output verbose information about the parsing results
     *
     * @param array $books
     * @return void
     */
    protected function outputVerboseInfo(array $books): void
    {
        $this->line('\n<comment>=== Statistics ===</comment>');

        // Count by author
        $authors = [];
        $needsReview = [];

        foreach ($books as $book) {
            $author = $book['author'] ?? 'Unknown';
            $authors[$author] = ($authors[$author] ?? 0) + 1;

            // Track books that need review
            if (!empty($book['needs_review'])) {
                $needsReview[] = [
                    'title' => $book['title'] ?? 'Unknown Title',
                    'author' => $author,
                    'reason' => $book['review_reason'] ?? 'Unknown reason',
                    'original_title' => $book['metadata']['original_title'] ?? $book['title'] ?? 'Unknown',
                    'applied_corrections' => $book['applied_corrections'] ?? []
                ];
            }
        }
        arsort($authors);

        $this->line('\n<comment>Books by author:</comment>');
        foreach ($authors as $author => $count) {
            $this->line("  $author: $count");
        }

        // Count by series
        $series = [];
        foreach ($books as $book) {
            if (!empty($book['series'])) {
                $series[$book['series']] = ($series[$book['series']] ?? 0) + 1;
            }
        }
        arsort($series);

        if (!empty($series)) {
            $this->line('\n<comment>Books by series:</comment>');
            foreach ($series as $seriesName => $count) {
                $this->line("  $seriesName: $count");
            }
        }

        // Show books that need review
        if (!empty($needsReview)) {
            $this->line('\n<comment>=== Titles Needing Review ===</comment>');
            $this->line(sprintf('  Found %d title(s) that may need manual review:', count($needsReview)));

            foreach ($needsReview as $index => $book) {
                $this->line(sprintf('\n  <fg=yellow>%d. %s</>', $index + 1, $book['title']));
                $this->line(sprintf('     Author: %s', $book['author']));
                $this->line(sprintf('     Reason: %s', $book['reason']));
                $this->line(sprintf('     Original: %s', $book['original_title']));

                if (!empty($book['applied_corrections'])) {
                    $this->line('     Applied corrections:');
                    foreach ($book['applied_corrections'] as $correction) {
                        $this->line("       - $correction");
                    }
                }
            }
        }

        // Count by file extension
        $extensions = [];
        foreach ($books as $book) {
            $ext = strtolower(pathinfo($book['path'] ?? '', PATHINFO_EXTENSION));
            if ($ext) {
                $extensions[$ext] = ($extensions[$ext] ?? 0) + 1;
            }
        }

        $this->line('\n<comment>Books by file extension:</comment>');
        foreach ($extensions as $ext => $count) {
            $this->line("  .$ext: $count");
        }
    }
}
