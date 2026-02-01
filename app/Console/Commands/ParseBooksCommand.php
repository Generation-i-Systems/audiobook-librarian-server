<?php

namespace App\Console\Commands;

use App\Services\BookDirectoryParser;
use App\Contracts\DocumentStoreServiceInterface;
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
                            {paths* : One or more directory paths to scan for books. Supports shell wildcards}
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
                            {--apis= : Comma-separated list of APIs for --enrich (google,audible,abbay,hardcover)}
                            {--store : Store parsed book data to Documentstore}
                            {--update-existing : Update existing books in Documentstore instead of skipping them}';

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
    public function handle(BookDirectoryParser $parser, DocumentStoreServiceInterface $documentStoreService)
    {
        $paths = $this->argument('paths');
        $bookStoragePath = rtrim((string) config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
        $expandedPaths = [];
        foreach ($paths as $path) {
            // Absolute path that exists directly
            if (strpos($path, '/') === 0 && File::exists($path) && File::isDirectory($path)) {
                $pattern = $path;
                $this->info("Using absolute path: $pattern");
            } elseif (strpos($path, $bookStoragePath) === 0) {
                // Already relative to storage root
                $pattern = $path;
            } else {
                // Relative to storage root
                $pattern = $bookStoragePath . '/' . ltrim($path, '/');
            }
            // Expand wildcards
            if (strpbrk($pattern, '*?[]')) {
                $matches = glob($pattern, GLOB_ONLYDIR | GLOB_BRACE);
                if ($matches !== false && count($matches) > 0) {
                    $expandedPaths = array_merge($expandedPaths, $matches);
                } else {
                    $this->warn("No matches found for pattern: $pattern");

                    continue; // Don't add unmatched pattern to expandedPaths
                }
            } else {
                $expandedPaths[] = $pattern;
            }
        }
        // Remove duplicates and reindex
        $paths = array_values(array_unique($expandedPaths));

        // If no valid paths remain, show error and exit
        if (empty($paths)) {
            $this->error('No valid directories found to parse.');

            return 1;
        }

        $outputFormat = $this->option('output') ?: 'table';
        $limit = (int) $this->option('limit');
        $extensions = $this->option('extensions');
        $minSize = $this->option('min-size');
        $maxDepth = $this->option('max-depth');
        $dryRun = $this->option('dry-run');
        $shouldSort = $this->option('sort');

        // Validate all paths using resolved storage path
        $validPaths = [];
        foreach ($paths as $inputPath) {
            // Only resolve storage path if not absolute
            if (strpos($inputPath, '/') === 0) {
                $resolvedPath = $inputPath;
            } else {
                $resolvedPath = $parser->resolveStoragePath($inputPath);
            }
            if (!File::exists($resolvedPath) || !File::isDirectory($resolvedPath)) {
                $this->warn("Skipping: $inputPath (resolved: $resolvedPath) does not exist or is not a directory.");

                continue;
            }
            $validPaths[] = $resolvedPath;
        }
        if (empty($validPaths)) {
            $this->error('No valid directories found to parse after wildcard/path expansion.');

            return 1;
        }
        $paths = $validPaths;

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

                // Debug directory structure
                $finder = new \Symfony\Component\Finder\Finder();
                $finder->files()->in($path);

                // Add a custom debug function to the parser
                $debugCallback = function ($message) {
                    $this->line("<fg=yellow>$message</>");
                };
                $parser->setDebugCallback($debugCallback);

                $books = $parser->parseDirectory($path, $config);
                $this->info('Found ' . count($books) . ' books in directory');
                // Set dateAdded for each book from directory mtime or authoritative time
                foreach ($books as &$book) {
                    $dirPath = $book['directoryPath'] ?? $book['path'] ?? null;
                    $storageRoot = rtrim((string) config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
                    if ($dirPath && strpos($dirPath, '/') !== 0) {
                        $fullPath = $storageRoot . '/' . ltrim($dirPath, '/');
                    } else {
                        $fullPath = $dirPath;
                    }

                    $dateAdded = null;
                    $dateAddedFile = null;
                    // Use BookDirectoryParser to get audio files and latest mtime
                    $audioFilesData = $parser->getAudioFiles($fullPath);
                    if (!empty($audioFilesData['audioFiles'])) {
                        $latestMtime = null;
                        $latestFile = null;
                        foreach ($audioFilesData['audioFiles'] as $audioFile) {
                            $mtime = $audioFile->getMTime();
                            if ($latestMtime === null || $mtime > $latestMtime) {
                                $latestMtime = $mtime;
                                $latestFile = $audioFile->getPathname();
                            }
                        }
                        if ($latestMtime !== null) {
                            $dateAdded = date('c', $latestMtime);
                        }
                    }
                    if (!$dateAdded) {
                        $this->error('No audio files found for dateAdded in directory: ' . realpath($fullPath));
                        $dateAdded = date('c');
                    }
                    $coverImage = null;
                    $coverCandidates = [];
                    $coverExtensions = ['jpg', 'jpeg', 'png', 'webp'];
                    $dirCoverPrefix = rtrim($fullPath, '/') . '/';
                    foreach ($coverExtensions as $ext) {
                        foreach (["cover.$ext", "folder.$ext", "front.$ext"] as $name) {
                            if (file_exists($dirCoverPrefix . $name)) {
                                $coverCandidates[] = $name;
                            }
                        }
                    }
                    if (!empty($coverCandidates)) {
                        $coverImage = rtrim($dirPath, '/') . '/' . $coverCandidates[0];
                    } elseif (!empty($book['audibleCoverPath']) && file_exists($book['audibleCoverPath'])) {
                        $audibleExt = pathinfo($book['audibleCoverPath'], PATHINFO_EXTENSION);
                        $audibleTarget = rtrim($fullPath, '/') . '/audible.' . $audibleExt;
                        if (@copy($book['audibleCoverPath'], $audibleTarget)) {
                            $coverImage = rtrim($dirPath, '/') . '/audible.' . $audibleExt;
                            unset($book['audibleCoverPath']);
                        }
                    }
                    if ($coverImage) {
                        $book['coverImage'] = $coverImage;
                    }
                    $book['dateAdded'] = $dateAdded;
                }
                unset($book);
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
            $apis = $apisOpt ? array_intersect(
                array_keys($apiMap),
                array_map('trim', explode(',', strtolower($apisOpt)))
            ) : array_keys($apiMap);
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
                            $titleQuery = $book['title'] ?? '[unknown]';
                            $authorQuery = is_array($book['author'] ?? null) ? implode(', ', $book['author']) : ($book['author'] ?? '[unknown]');
                            $this->info("  Audible: no match (query: '$titleQuery' by '$authorQuery')");
                        }
                    }

                    // AudiobookBay API
                    if (in_array('abbay', $apis) && class_exists('\App\Services\AudiobookBayService')) {
                        $abbayService = app(\App\Services\AudiobookBayService::class);
                        $result = $abbayService->searchAndMerge($book);
                        if ($result) {
                            $book = array_merge($book, $result);
                            $this->info('  AudiobookBay: found and merged');
                        } else {
                            $this->info('  AudiobookBay: no match');
                        }
                    }

                    // Hardcover API
                    if (in_array('hardcover', $apis) && class_exists('\App\Services\HardcoverApiService')) {
                        $hardcoverService = app(\App\Services\HardcoverApiService::class);
                        $result = $hardcoverService->searchAndMerge($book);
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
                    $dirPath = $book['directoryPath'] ?? null;
                    if ($dirPath) {
                        $resolvedDir = $parser->resolveStoragePath($dirPath);
                        if (!is_dir($resolvedDir)) {
                            $this->error("Directory does not exist: $resolvedDir");

                            continue;
                        }
                        $jsonPath = rtrim($resolvedDir, '/') . '/' . $jsonFilename;
                        $jsonData = json_encode($book, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                        if (file_put_contents($jsonPath, $jsonData) !== false) {
                            $this->info('Saved JSON to ' . $jsonPath);
                        } else {
                            $this->error('Failed to write JSON to ' . $jsonPath);
                        }
                    } else {
                        $this->error('No directoryPath for book: ' . ($book['title'] ?? '[unknown]'));
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
                    $pathToUse = $book['directoryPath'] ?? $book['path'] ?? '';

                    // If we don't have a path, try to get it from the full_path
                    if (empty($pathToUse) && !empty($book['full_path'])) {
                        $pathToUse = dirname($book['full_path']);
                    }

                    $this->info("Extracting author from path: $pathToUse");
                    $author = $parser->extractAuthorFromPath($pathToUse);
                    $book['author'] = array_filter((array) $author);
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
                    $author = $parser->extractAuthorFromPath($parentDir);
                    $book['author'] = array_filter((array) $author);
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
                        $seriesMatchesAuthor = strtolower($series) === strtolower(trim($book['author'] ?? ''));
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
            // (intentionally no unset here; loop above is commented out)

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
                    $seriesA = !empty($a['seriesName']) ? strtolower($a['seriesName']) : 'zzz_no_series';
                    $seriesB = !empty($b['seriesName']) ? strtolower($b['seriesName']) : 'zzz_no_series';
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

            // Store to Documentstore if requested
            if ($this->option('store') && !$dryRun) {
                $this->storeToDocumentstore($allBooks, $documentStoreService);
            }

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
                break;

            case 'table':
            default:
                $this->outputTable($books);
        }
    }

    /**
     * Output books as a table.
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

        $headers = [
            '#',
            'Title',
            'Author',
            'Duration',
            'Files',
            'Series',
            'Number',
            'Narrator',
            'Edition',
            'Path',
            'Cover',
            'Needs Review',
        ];

        $rows = [];
        foreach ($books as $index => $book) {
            // Handle author as array or string
            $author = $book['author'] ?? '';
            if (is_array($author)) {
                $author = implode(', ', array_filter($author, fn ($a) => !empty(trim($a))));
            }

            // Ensure all values are scalars or strings (no arrays)
            $durationFormatted = $book['durationFormatted'] ?? 'N/A';
            if (is_array($durationFormatted)) {
                $durationFormatted = json_encode($durationFormatted);
            }
            $audioFileCount = $book['audioFileCount'] ?? 0;
            if (is_array($audioFileCount)) {
                $audioFileCount = json_encode($audioFileCount);
            }
            $seriesName = $book['seriesName'] ?? '';
            if (is_array($seriesName)) {
                $seriesName = implode(', ', $seriesName);
            }
            $seriesNumber = $book['seriesNumber'] ?? '';
            if (is_array($seriesNumber)) {
                $seriesNumber = implode(', ', $seriesNumber);
            }
            $narrator = $book['narrator'] ?? '';
            if (is_array($narrator)) {
                $narrator = implode(', ', $narrator);
            }
            $edition = $book['edition'] ?? '';
            if (is_array($edition)) {
                $edition = implode(', ', $edition);
            }
            $directoryPath = $book['directoryPath'] ?? ($book['path'] ?? '');
            if (is_array($directoryPath)) {
                $directoryPath = implode(', ', $directoryPath);
            }
            $coverImage = $book['coverImage'] ?? '';
            if (is_array($coverImage)) {
                $coverImage = implode(', ', $coverImage);
            }
            $needsReview = $book['needsReview'] ?? false;
            if (is_array($needsReview)) {
                $needsReview = json_encode($needsReview);
            }

            $rows[] = [
                $index + 1,
                $book['title'] ?? '',
                $author,
                $durationFormatted,
                $audioFileCount,
                $seriesName,
                $seriesNumber,
                $narrator,
                $edition,
                $directoryPath,
                $coverImage,
                $needsReview ? 'Yes' : 'No',
            ];
        }

        $this->table($headers, $rows);
    }

    /**
     * Output books as CSV.
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
     * Store books to Documentstore.
     *
     * @param  array  $books  The books to store
     * @param  \App\Contracts\DocumentStoreServiceInterface  $documentStoreService  The Documentstore service
     */
    protected function storeToDocumentstore(array $books, DocumentStoreServiceInterface $documentStoreService): void
    {
        $this->info('\nStoring books to Documentstore...');
        $updateExisting = $this->option('update-existing');

        $bar = $this->output->createProgressBar(count($books));
        $bar->start();

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($books as $book) {
            try {
                // Check if book already exists in Documentstore by directory path
                $directoryPath = $book['directoryPath'] ?? null;

                if (!$directoryPath) {
                    if ($this->getOutput()->isVerbose()) {
                        $this->warn('Skipping book with no directoryPath: ' . ($book['title'] ?? '[unknown]'));
                    }
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                $existingBook = $documentStoreService->findBookByDirectoryPath($directoryPath);

                if ($existingBook && !$updateExisting) {
                    // Skip existing books if not updating
                    $skipped++;
                } elseif ($existingBook) {
                    // Update existing book
                    $documentStoreService->updateBook($existingBook['id'], $book);
                    $updated++;
                } else {
                    // Create new book
                    $documentStoreService->createBook($book);
                    $created++;
                }
            } catch (\Exception $e) {
                $errors++;
                if ($this->getOutput()->isVerbose()) {
                    $this->error('Error storing book: ' . ($book['title'] ?? '[unknown]') . ' - ' . $e->getMessage());
                }
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $this->info('Documentstore storage completed:');
        $this->info("- Created: {$created} books");
        $this->info("- Updated: {$updated} books");
        $this->info("- Skipped: {$skipped} books");
        $this->info("- Errors: {$errors} books");
    }

    /**
     * Output verbose information about the parsing results
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
                    'applied_corrections' => $book['applied_corrections'] ?? [],
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
