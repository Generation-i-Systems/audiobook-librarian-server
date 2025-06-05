<?php

namespace App\Console\Commands;

use App\Services\BookDirectoryParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ParseBooksCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'books:parse
                            {paths* : One or more directory paths to scan for books}
                            {--output= : Output format (json, table, csv, sql, array). Default: table}
                            {--limit=0 : Maximum number of books to process (0 for no limit)}
                            {--extensions= : Comma-separated list of file extensions to include}
                            {--min-size= : Minimum file size in bytes}
                            {--max-depth= : Maximum directory depth to scan}
                            {--dry-run : Show what would be done without making any changes}
                            {--sort : Sort output by author, series, series number, and title}
                            {--save-json : Save output JSON into each book directory}
                            {--json-filename= : Filename for saved JSON (default: librarian.json)}
                            {--enrich : Lookup and enrich metadata from selected APIs}
                            {--apis= : Comma-separated list of APIs to use with --enrich (google,audible,abbay,hardcover)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Parse book directories and output metadata for each book';

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

        // Validate all paths using resolved storage path
        foreach ($paths as $inputPath) {
            $resolvedPath = $parser->resolveStoragePath($inputPath);
            if (!File::exists($resolvedPath) || !File::isDirectory($resolvedPath)) {
                $this->error("The specified path does not exist or is not a directory: $inputPath (resolved: $resolvedPath)");
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

            // Enrich metadata from APIs if requested
            $enrich = $this->option('enrich');
            $apisOpt = $this->option('apis');
            $apiMap = [
                'google' => 'Google Books',
                'audible' => 'Audible',
                'abbay' => 'AudiobookBay',
                'hardcover' => 'Hardcover',
            ];
            $apis = $apisOpt ? array_intersect(array_keys($apiMap), array_map('trim', explode(',', strtolower($apisOpt)))) : array_keys($apiMap);
            if ($enrich) {
                foreach ($allBooks as &$book) {
                    $title = $book['title'] ?? '';
                    $author = is_array($book['author'] ?? null) ? ($book['author'][0] ?? '') : ($book['author'] ?? '');
                    $this->info("Enriching metadata for: $title by $author");

                    // Google Books API
                    if (in_array('google', $apis) && class_exists('\App\Services\GoogleBooksApiService')) {
                        $googleApi = app(\App\Services\GoogleBooksApiService::class);
                        $result = $googleApi->searchAndMerge($book);
                        if ($result) {
                            $book = array_merge($book, $result);
                            $this->info('  Google Books: found and merged');
                        } else {
                            $this->info('  Google Books: no match');
                        }
                    }
                    // Audible API
                    if (in_array('audible', $apis) && class_exists('\App\Services\AudibleService')) {
                        $audibleApi = app(\App\Services\AudibleService::class);
                        $result = $audibleApi->searchAndMerge($book);
                        if ($result) {
                            $book = array_merge($book, $result);
                            $this->info('  Audible: found and merged');
                        } else {
                            $this->info('  Audible: no match');
                        }
                    }
                    // AudiobookBay API
                    if (in_array('abbay', $apis) && trait_exists('\App\Traits\AudiobookBayApiTrait')) {
                        $trait = new class () {
                            use \App\Traits\AudiobookBayApiTrait;
                        };
                        $result = $trait->searchAndMerge($book);
                        if ($result) {
                            $book = array_merge($book, $result);
                            $this->info('  AudiobookBay: found and merged');
                        } else {
                            $this->info('  AudiobookBay: no match');
                        }
                    }
                    // Hardcover API
                    if (in_array('hardcover', $apis) && trait_exists('\App\Traits\HardcoverApiTrait')) {
                        $trait = new class () {
                            use \App\Traits\HardcoverApiTrait;
                        };
                        $result = $trait->searchAndMerge($book);
                        if ($result) {
                            $book = array_merge($book, $result);
                            $this->info('  Hardcover: found and merged');
                        } else {
                            $this->info('  Hardcover: no match');
                        }
                    }
                }
                unset($book);
            }

            // Save JSON files if requested
            $saveJson = $this->option('save-json');
            $jsonFilename = $this->option('json-filename') ?: 'librarian.json';
            if ($saveJson) {
                foreach ($allBooks as $book) {
                    $dirPath = $book['directory_path'] ?? null;
                    if ($dirPath) {
                        $resolvedDir = $parser->resolveStoragePath($dirPath);
                        if (!is_dir($resolvedDir)) {
                            $this->error("Directory does not exist: $resolvedDir");
                            continue;
                        }
                        $jsonPath = rtrim($resolvedDir, '/').'/'.$jsonFilename;
                        $jsonData = json_encode($book, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                        if (file_put_contents($jsonPath, $jsonData) !== false) {
                            $this->info("Saved JSON to $jsonPath");
                        } else {
                            $this->error("Failed to write JSON to $jsonPath");
                        }
                    } else {
                        $this->error("No directory_path for book: ".($book['title'] ?? '[unknown]'));
                    }
                }
            }

            // First pass: Clean up basic fields
            foreach ($allBooks as &$book) {
                // Clean up author - handle both string and array cases
                $hasValidAuthor = false;

                if (isset($book['author'])) {
                    if (is_array($book['author'])) {
                        $hasValidAuthor = !empty(array_filter($book['author'], function ($author) {
                            return !empty(trim($author)) && !str_contains($author, 'Unknown');
                        }));
                    } else {
                        $hasValidAuthor = !empty(trim($book['author'])) && !str_contains($book['author'], 'Unknown');
                    }
                }

                if (!$hasValidAuthor) {
                    // Use the directory path from the book data if available, otherwise use the path from the book file
                    $pathToUse = $book['directory_path'] ?? $book['path'] ?? '';

                    // If we don't have a path, try to get it from the full_path
                    if (empty($pathToUse) && !empty($book['full_path'])) {
                        $pathToUse = dirname($book['full_path']);
                    }

                    $this->info("Extracting author from path: $pathToUse");
                    $author = $parser->extractAuthorFromPath($pathToUse);
                    $book['author'] = is_array($author) ? $author : [$author];
                }

                // Check if we still don't have a valid author
                $hasNoAuthor = false;
                if (is_array($book['author'])) {
                    $hasNoAuthor = empty(array_filter($book['author'], function ($a) {
                        return !empty(trim($a)) && $a !== 'Unknown Author';
                    }));
                } else {
                    $hasNoAuthor = empty($book['author']) || $book['author'] === 'Unknown Author';
                }

                if ($hasNoAuthor && !empty($book['full_path'])) {
                    $parentDir = dirname($book['full_path']);
                    $this->info("Trying parent directory for author: $parentDir");
                    $author = $this->extractAuthorFromPath($parentDir);
                    $book['author'] = is_array($author) ? $author : [$author];
                }

                // Clean up title
                if (!empty($book['title'])) {
                    $book['title'] = ucfirst(trim($book['title']));
                }

                // Clean up series - don't use author name as series
                if (!empty($book['seriesName'])) {
                    $series = $book['seriesName'];
                    $seriesNumber = $book['seriesNumber'];

                    // Check if series matches any of the authors (case-insensitive)
                    $seriesMatchesAuthor = false;
                    if (is_array($book['author'] ?? null)) {
                        foreach ($book['author'] as $author) {
                            if (strtolower($series) === strtolower(trim($author))) {
                                $seriesMatchesAuthor = true;
                                break;
                            }
                        }
                    } else {
                        $seriesMatchesAuthor = strtolower($series) === strtolower(trim($book['author'] ?? '')); // $book['author'] is always an array, but this fallback is safe
                    }

                    if ($seriesMatchesAuthor) {
                        $book['seriesName'] = '';
                        $book['seriesNumber'] = null;
                    } else {
                        $book['seriesName'] = $series;
                        $book['seriesNumber'] = $seriesNumber;
                    }
                }
            }
            unset($book); // Break the reference

            // Second pass: Fix common typos in series and titles
            $seriesMap = $parser->buildSeriesMap($allBooks);
            foreach ($allBooks as &$book) {
                // Fix series names using fuzzy matching
                if (!empty($book['seriesName'])) {
                    $book['seriesName'] = $parser->normalizeSeriesName($book['seriesName']);
                }

                // Clean up common title issues using the parser
                $cleanupResult = $parser->cleanupTitle($book['title'] ?? '');
                $book['title'] = $cleanupResult['title'];

                // Set the needs_review flag from the cleanup result
                $book['needs_review'] = $cleanupResult['needs_review'] ?? false;

                // Store metadata for review if needed
                if ($cleanupResult['metadata']['needs_review'] ?? false) {
                    $book['needs_review'] = true;
                    $book['review_reason'] = 'Title may need manual review';
                    if (!empty($cleanupResult['metadata']['applied_corrections'])) {
                        $book['applied_corrections'] = $cleanupResult['metadata']['applied_corrections'];
                    }
                }
            }
            unset($book); // Break the reference

            // // Third pass: Rebuild series map with corrected names
            // $seriesMap = $parser->buildSeriesMap($allBooks);

            // // Fourth pass: Ensure series consistency
            // foreach ($allBooks as &$book) {
            //     if (!empty($book['seriesName']) && isset($seriesMap[$book['seriesName']])) {
            //         $book['seriesName'] = $seriesMap[$book['seriesName']];
            //     }
            // }
            unset($book); // Break the reference

            // Sort the books if requested
            if ($shouldSort) {
                usort($allBooks, function ($a, $b) {
                    // First sort by author (case-insensitive)
                    $authorA = strtolower($a['author'] ?? '');
                    $authorB = strtolower($b['author'] ?? '');
                    $cmp = strnatcasecmp($authorA, $authorB);
                    if ($cmp !== 0) {
                        return $cmp;
                    }

                    // Then by series (case-insensitive, empty series last)
                    $seriesA = !empty($a['seriesName'])
                        ? strtolower($a['seriesName'])
                        : 'zzz_no_series';
                    $seriesB = !empty($b['seriesName'])
                        ? strtolower($b['seriesName'])
                        : 'zzz_no_series';
                    $cmp = strnatcasecmp($seriesA, $seriesB);

                    if ($cmp !== 0) {
                        return $cmp;
                    }

                    // Then by series number (handle null/empty values)
                    $aNum = !empty($a['seriesNumber']) ? (float) $a['seriesNumber'] : 0;
                    $bNum = !empty($b['seriesNumber']) ? (float) $b['seriesNumber'] : 0;
                    if ($aNum != $bNum) {
                        return $aNum <=> $bNum;
                    }

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
        // Sort books by author, series, and series number
        usort($books, function ($a, $b) {
            // Get primary author for comparison (first author in the array)
            $aAuthor = is_array($a['author'] ?? '') ? ($a['author'][0] ?? '') : ($a['author'] ?? '');
            $bAuthor = is_array($b['author'] ?? '') ? ($b['author'][0] ?? '') : ($b['author'] ?? '');

            // Compare authors
            $authorCmp = strcasecmp($aAuthor, $bAuthor);
            if ($authorCmp !== 0) {
                return $authorCmp;
            }

            // If same author, compare series
            $seriesCmp = strcasecmp($a['seriesName'] ?? '', $b['seriesName'] ?? '');
            if ($seriesCmp !== 0) {
                return $seriesCmp;
            }

            // If same series, compare series numbers (treat empty as 0)
            $aNum = !empty($a['seriesNumber']) ? (float) $a['seriesNumber'] : 0;
            $bNum = !empty($b['seriesNumber']) ? (float) $b['seriesNumber'] : 0;
            return $aNum <=> $bNum;
        });

        $headers = ['#', 'Title', 'Author', 'Duration', 'Files', 'Series', 'Number', 'Narrator', 'Edition', 'Path', 'Cover', 'Needs Review'];

        $rows = [];
        foreach ($books as $index => $book) {
            // Handle author as array or string
            $author = $book['author'] ?? '';
            if (is_array($author)) {
                $author = implode(', ', array_filter($author, function ($a) {
                    return !empty(trim($a));
                }));
            }

            $rows[] = [
                $index + 1,
                $book['title'] ?? '',
                $author,
                $book['duration_formatted'] ?? 'N/A',
                $book['audio_file_count'] ?? 0,
                $book['seriesName'] ?? '',
                $book['seriesNumber'] ?? '',
                $book['narrator'] ?? '',
                $book['edition'] ?? '',
                $book['path'] ?? '',
                $book['cover_image'] ?? '',
                $book['needs_review'] ? 'Yes' : 'No',
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
