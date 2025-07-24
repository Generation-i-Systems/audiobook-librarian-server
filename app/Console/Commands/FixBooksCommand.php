<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use MongoDB\BSON\ObjectId;
use MongoDB\Client;

class FixBooksCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'books:fix-duplicates-and-review-flags {--dry-run} {--ids=} {--no-backup : Skip automatic database backup}'; // --ids=comma,separated,ids

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove duplicate books by directoryPath and flag books for review with needsReviewReasons (creates a database backup by default).';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // Create a database backup unless --no-backup is specified
        if (!$this->option('no-backup')) {
            $this->info('Creating a database backup before fixing books...');
            $this->call('backup:database');
            $this->info('Database backup created.');
        }

        $this->info('Starting fix for duplicate books and review flags...');
        $mongoUri = config('mongodb.uri');
        $mongoDb = config('mongodb.database');
        $client = new Client($mongoUri);
        $books = $client->$mongoDb->books;
        // Support filtering by _id for faster testing
        $idsOption = $this->option('ids');
        $filter = [];
        if ($idsOption) {
            $ids = [];
            foreach (preg_split('/[,	\s]+/', $idsOption, -1, PREG_SPLIT_NO_EMPTY) as $id) {
                $id = trim($id);
                if ($id === '') {
                    continue;
                }
                try {
                    $ids[] = new \MongoDB\BSON\ObjectId($id);
                } catch (\Exception $e) {
                    // If ObjectId fails, treat as string _id (for non-ObjectId collections)
                    $ids[] = $id;
                }
            }
            if ($ids) {
                $filter = ['_id' => ['$in' => $ids]];
            } else {
                $this->warn('No valid _id values provided to --ids; nothing will be processed.');
                $allBooks = [];
            }
        }
        $allBooks = $books->find($filter)->toArray();

        // Remove duplicates by directoryPath
        $byDir = [];
        foreach ($allBooks as $book) {
            $arrBook = (array) $book;
            $dir = $arrBook['directoryPath'] ?? null;
            if (!$dir) {
                continue;
            }
            $byDir[$dir][] = $arrBook;
        }
        $toDelete = [];
        $deletedCount = 0;
        foreach ($byDir as $dir => $group) {
            if (count($group) > 1) {
                usort($group, function ($a, $b) {
                    $aArr = (array) $a;
                    $bArr = (array) $b;
                    $aFields = count(array_filter($aArr, fn ($v) => $v && $v !== '' && $v !== []));
                    $bFields = count(array_filter($bArr, fn ($v) => $v && $v !== '' && $v !== []));
                    $aUpdated = isset($aArr['updatedAt']) ? (is_object($aArr['updatedAt']) ? $aArr['updatedAt']->toDateTime()->getTimestamp() : strtotime($aArr['updatedAt'])) : 0;
                    $bUpdated = isset($bArr['updatedAt']) ? (is_object($bArr['updatedAt']) ? $bArr['updatedAt']->toDateTime()->getTimestamp() : strtotime($bArr['updatedAt'])) : 0;
                    if ($aUpdated !== $bUpdated) {
                        return $bUpdated <=> $aUpdated;
                    }
                    return $bFields <=> $aFields;
                });
                // Keep the first, delete the rest
                for ($i = 1; $i < count($group); $i++) {
                    $toDelete[] = $group[$i]['_id'];
                }
            }
        }
        if ($toDelete) {
            if ($this->option('dry-run')) {
                $this->warn('Would delete ' . count($toDelete) . ' duplicate books by directoryPath');
            } else {
                $result = $books->deleteMany(['_id' => ['$in' => $toDelete]]);
                $deletedCount = $result->getDeletedCount();
                $this->info('Deleted ' . $deletedCount . ' duplicate books by directoryPath');
            }
        }

        // Flag books for review
        $needsReview = [];
        foreach ($allBooks as $book) {
            $reasons = $this->getNeedsReviewReasons($book);
            if ($reasons) {
                $needsReview[] = [
                    '_id' => $book['_id'],
                    'needsReviewReasons' => $reasons,
                ];
            }
        }
        // Fix root seriesNumber -> series[].number if missing
        $fixedSeriesNumber = 0;
        $fixedDuration = 0;
        $updatedCount = 0;
        $needsReviewCount = 0;
        foreach ($allBooks as $book) {
            $arrBook = (array) $book;
            $rootSeriesName = $arrBook['seriesName'] ?? null;
            $rootSeriesNumber = $arrBook['seriesNumber'] ?? null;
            $seriesArr = $arrBook['series'] ?? null;
            $updateFields = [];
            // Fix series[].number
            if ($rootSeriesName && $rootSeriesNumber && is_array($seriesArr)) {
                $updated = false;
                foreach ($seriesArr as $i => $seriesObj) {
                    if ($seriesObj instanceof \MongoDB\Model\BSONDocument) {
                        $seriesObj = (array) $seriesObj;
                    }
                    // If the number or seriesNumber is missing in the series object but present at root, fix it and DO NOT flag as needsReview
                    if (($seriesObj['seriesName'] ?? null) === $rootSeriesName && empty($seriesObj['number']) && empty($seriesObj['seriesNumber'])) {
                        // Prefer to set 'number', but do not overwrite if 'seriesNumber' is already set
                        if (empty($seriesObj['seriesNumber'])) {
                            $seriesArr[$i]['number'] = $rootSeriesNumber;
                        } else {
                            $seriesArr[$i]['seriesNumber'] = $rootSeriesNumber;
                        }
                        $updated = true;
                    }
                }
                if ($updated) {
                    $updateFields['series'] = $seriesArr;
                    $fixedSeriesNumber++;
                }
            }
            // Round duration to int
            if (isset($arrBook['duration']) && is_numeric($arrBook['duration'])) {
                $roundedDuration = (int) round($arrBook['duration']);
                if ($arrBook['duration'] != $roundedDuration) {
                    $updateFields['duration'] = $roundedDuration;
                    $fixedDuration++;
                }
            }
            // Apply updates if needed
            if ($updateFields) {
                $updatedCount++;
                if ($this->option('dry-run')) {
                    $this->warn('Would fix fields for book ' . $arrBook['_id'] . ': ' . implode(', ', array_keys($updateFields)));
                } else {
                    $books->updateOne([
                        '_id' => $arrBook['_id'],
                    ], [
                        '$set' => $updateFields,
                    ]);
                    $this->info('Fixed fields for book ' . $arrBook['_id'] . ': ' . implode(', ', array_keys($updateFields)));
                }
            }
        }
        $this->info(($this->option('dry-run') ? 'Would fix ' : 'Fixed ') . $fixedSeriesNumber . ' books with root seriesNumber to series[].number');
        $this->info(($this->option('dry-run') ? 'Would round ' : 'Rounded ') . $fixedDuration . ' duration fields to int');
        $needsReviewCount = 0;
        foreach ($needsReview as $entry) {
            $needsReviewCount++;
            if ($this->option('dry-run')) {
                $this->warn('Would flag book ' . $entry['_id'] . ' for review: ' . implode('; ', $entry['needsReviewReasons']));
            } else {
                $books->updateOne([
                    '_id' => $entry['_id'],
                ], [
                    '$set' => [
                        'needsReview' => true,
                        'needsReviewReasons' => $entry['needsReviewReasons'],
                    ],
                ]);
            }
        }
        $this->info(($this->option('dry-run') ? 'Would flag ' : 'Flagged ') . $needsReviewCount . ' books for review');
        $this->info(($this->option('dry-run') ? 'Would delete ' : 'Deleted ') . $deletedCount . ' duplicate books');
        $this->info(($this->option('dry-run') ? 'Would update ' : 'Updated ') . $updatedCount . ' records');
        $this->info('Fix process completed.');
    }

    private function getNeedsReviewReasons($book)
    {
        $reasons = [];
        $dir = $book['directoryPath'] ?? null;
        if ($dir) {
            $parsed = $this->parseDirectoryPath($dir);
            if (isset($parsed['genre'], $book['genre']) && !in_array($parsed['genre'], (array) $book['genre'])) {
                $reasons[] = "directoryPath genre mismatch\nParsed: {$parsed['genre']}\nDocument: " . implode(', ', (array) $book['genre']);
            }
            if (isset($parsed['author'], $book['author']) && !in_array($parsed['author'], (array) $book['author'])) {
                $reasons[] = "directoryPath author mismatch\nParsed: {$parsed['author']}\nDocument: " . implode(', ', (array) $book['author']);
            }
            if (isset($parsed['series'], $book['series'])) {
                $seriesArr = (array) $book['series'];
                $seriesNames = array_map(function ($s) {
                    if ($s instanceof \MongoDB\Model\BSONDocument) {
                        $s = (array) $s;
                    }
                    return is_array($s) ? ($s['seriesName'] ?? null) : $s;
                }, $seriesArr);
                $seriesNamesStr = implode(', ', array_map(fn ($v) => is_scalar($v) ? $v : json_encode($v), $seriesNames));
                // Only flag series mismatch if parsed series is not in any seriesName
                if (!in_array($parsed['series'], $seriesNames)) {
                    $reasons[] = "directoryPath series mismatch\nParsed: {$parsed['series']}\nDocument: " . $seriesNamesStr;
                }
                // Check for missing number/seriesNumber in the matching series object
                foreach ($seriesArr as $s) {
                    if ($s instanceof \MongoDB\Model\BSONDocument) {
                        $s = (array) $s;
                    }
                    if (($s['seriesName'] ?? null) === $parsed['series']) {
                        // If any number field is present (series[number], series[seriesNumber], root seriesNumber, root series_number), do not flag as missing
                        $hasSeriesNumber = false;
                        $fieldsToCheck = [
                            $s['number'] ?? null,
                            $s['seriesNumber'] ?? null,
                            $book['seriesNumber'] ?? null,
                            $book['series_number'] ?? null
                        ];
                        foreach ($fieldsToCheck as $numVal) {
                            if (is_string($numVal)) {
                                $numVal = ltrim($numVal, '0');
                            }
                            if (!empty($numVal)) {
                                $hasSeriesNumber = true;
                                break;
                            }
                        }
                    }
                }
            }
            if (isset($parsed['title'], $book['title'])) {
                $parsedTitle = $parsed['title'];
                $docTitle = $book['title'];
                // Normalize: lowercase, trim, remove leading number (with or without zeros), remove 'Book N' prefix, remove trailing semicolons, remove parenthetical
                $normalizeTitle = function ($title) {
                    $title = strtolower(trim($title));
                    $title = preg_replace('/^book\s*\d+\s*/i', '', $title); // Remove 'Book N' prefix
                    $title = preg_replace('/^0*(\d+)\s+/', '', $title); // Remove leading number (with zeros)
                    $title = preg_replace('/\s*\([^)]*\)$/', '', $title); // Remove trailing parenthetical
                    $title = rtrim($title, ';'); // Remove trailing semicolons
                    return trim($title);
                };
                $parsedTitleNorm = $normalizeTitle($parsedTitle);
                $docTitleNorm = $normalizeTitle($docTitle);
                if ($parsedTitleNorm !== $docTitleNorm) {
                    $reasons[] = "directoryPath title mismatch\nParsed: {$parsed['title']}\nDocument: {$book['title']}";
                }
            }
            // Do not flag for missing series number in title or directory, only mismatches.
        }
        if (empty($book['coverImage'])) {
            $coverUrl = $book['coverImageUrl'] ?? $book['googleBooksCoverImageUrl'] ?? null;
            $hasLocal = false;
            if ($dir) {
                $storageRoot = rtrim(config('bookparser.book_storage_path', '/mnt/books'), '/');
                $coverGlob = glob($storageRoot . '/' . ltrim($dir, '/') . '/cover*.*');
                $hasLocal = !empty($coverGlob);
            }
            if ($coverUrl) {
                $reasons[] = 'missing cover but coverImageUrl present';
            } elseif ($hasLocal) {
                $reasons[] = 'missing cover but local image present';
            } else {
                $reasons[] = 'missing cover';
            }
        }
        if (!empty($book['series']) && !empty($book['author'])) {
            $seriesNames = array_map(function ($s) {
                if (is_array($s)) {
                    return isset($s['seriesName']) && is_string($s['seriesName']) ? $s['seriesName'] : null;
                }
                return is_string($s) ? $s : null;
            }, (array) $book['series']);
            $authors = (array) $book['author'];
            foreach ($seriesNames as $sn) {
                if ($sn && in_array($sn, $authors)) {
                    $reasons[] = 'series name matches author name';
                    break;
                }
            }
        }
        if (!empty($book['series'])) {
            foreach ((array) $book['series'] as $s) {
                $name = (is_array($s) && isset($s['seriesName']) && is_string($s['seriesName'])) ? $s['seriesName'] : null;
                $number = is_array($s) ? ($s['number'] ?? null) : null;
                if ($name && !$number) {
                    $reasons[] = 'series with no number';
                }
            }
        }
        return $reasons;
    }

    private function parseDirectoryPath($dir)
    {
        $parts = explode('/', $dir);
        if (count($parts) >= 5) {
            return [
                'genre' => $parts[0],
                'author' => $parts[1],
                'series' => $parts[2],
                'number' => $parts[3],
                'title' => $parts[4],
            ];
        } elseif (count($parts) >= 3) {
            return [
                'genre' => $parts[0],
                'author' => $parts[1],
                'title' => $parts[count($parts) - 1],
            ];
        }
        return [];
    }
}
