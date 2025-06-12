<?php

namespace App\Jobs;

use App\Services\FirestoreService;
use App\Traits\BookImportTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImportBookFromDirectoryJob implements ShouldQueue
{
    use BookImportTrait;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The directory path to import
     *
     * @var string
     */
    protected $directoryPath;

    public function __construct($directoryPath)
    {
        $this->directoryPath = $directoryPath;
    }

    public function handle()
    {
        $firestore = new FirestoreService();
        $jobId = 'import_book_' . md5($this->directoryPath . '_' . now()->timestamp);

        try {
            // Update job status to processing
            $firestore->updateJobStatus(
                $jobId,
                'book_import',
                'processing',
                [
                    'directory_path' => $this->directoryPath,
                    'started_at' => now()->toDateTimeString(),
                    'message' => 'Starting book import from directory: ' . $this->directoryPath,
                ]
            );

            Log::info("[BulkImport] Starting: {$this->directoryPath}");
            $this->setGoogleBooksApiService(app(\App\Services\GoogleBooksApiService::class));
            Log::info("[BulkImport] Processing: {$this->directoryPath}");

            $dirPath = '/' . ltrim($this->directoryPath, '/');
            $storagePath = rtrim(env('BOOK_STORAGE_PATH'), '/');
            $fullPath = $storagePath . $dirPath;

            if (!is_dir($fullPath)) {
                $error = "[BulkImport] Directory does not exist: $fullPath";
                Log::error($error);
                throw new \RuntimeException($error);
            }

            // Check for existing book in Firestore
            $existingBooks = $firestore->listBooks();
            foreach ($existingBooks as $b) {
                if (($b['directory_path'] ?? null) === $dirPath) {
                    $error = '[BulkImport] Book already exists: ' . json_encode($b);
                    Log::error($error);
                    throw new \RuntimeException('Book already exists in the database');
                }
            }

            $bookTmp = $this->processDirPath($dirPath);

            if (isset($bookTmp['skipped']) && $bookTmp['skipped'] === true) {
                $reason = $bookTmp['reason'] ?? 'Unknown reason';
                Log::warning("[BulkImport] Skipped directory {$dirPath}: {$reason}");

                return;
            }

            if (!is_array($bookTmp) || !isset($bookTmp['author']) || !isset($bookTmp['title'])) {
                Log::error('[BulkImport] Failed to process directory: ' . $dirPath);

                return;
            }

            Log::info('[BulkImport] Processing directory: ' . $dirPath);

            // Format authors as array of strings
            $authors = [];
            if (isset($bookTmp['author'])) {
                if (is_array($bookTmp['author'])) {
                    $authors = array_filter($bookTmp['author'], 'is_string');
                } elseif (is_string($bookTmp['author'])) {
                    $authors = [$bookTmp['author']];
                }
            }
            if (empty($authors)) {
                $authors = ['Unknown Author'];
            }

            // Process series
            $series = [];
            if (isset($bookTmp['series'])) {
                if (is_array($bookTmp['series'])) {
                    foreach ($bookTmp['series'] as $seriesName => $seriesNumber) {
                        if (
                            is_string($seriesName) &&
                            (
                                is_string($seriesNumber) ||
                                is_numeric($seriesNumber) ||
                                $seriesNumber === null
                            )
                        ) {
                            $series[$seriesName] = $seriesNumber;
                        }
                    }
                }
            }

            // Process genres
            $genres = [];
            if (isset($bookTmp['genre']) && is_array($bookTmp['genre'])) {
                $genres = array_filter($bookTmp['genre'], 'is_string');
            }
            if (empty($genres)) {
                $genres = ['Unknown Genre'];
            }

            $bookData = [
                'title' => $bookTmp['title'] ?? basename($dirPath),
                'authors' => $authors,
                'genres' => $genres,
                'series' => $series,
                'directory_path' => $dirPath,
                'description' => $bookTmp['description'] ?? null,
                'published_year' => $bookTmp['published_year'] ?? null,
                'type' => $bookTmp['type'] ?? 'audiobook',
            ];

            [$coverAuto, $coverCandidates] = $this->findCoverImageCandidate($dirPath);
            Log::info('[BulkImport] Found cover candidates: ' . json_encode($coverCandidates));
            $m4bs = is_dir($fullPath) ? array_values(array_filter(scandir($fullPath), function ($f) use ($fullPath) {
                return is_file($fullPath . '/' . $f) && strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'm4b';
            })) : [];
            $tags = [];
            if ($m4bs) {
                $firstM4b = $fullPath . '/' . $m4bs[0];
                $tags = $this->extractTagData($firstM4b);
                if (empty($coverAuto) && empty($coverCandidates)) {
                    $coverAuto = $this->extractCoverFromM4B($firstM4b, $fullPath);
                }
            }
            $meta = $this->extractMetadataAbs($fullPath);
            if (!empty($tags['description'])) {
                $bookData['description'] = $tags['description'];
            } elseif (!empty($meta['description'])) {
                $bookData['description'] = $meta['description'];
            }
            if (!empty($meta['year'])) {
                $bookData['published_year'] = $meta['year'];
            }
            if ($coverAuto) {
                $bookData['cover_image'] = ltrim($this->directoryPath, '/') . '/' . $coverAuto;
            } elseif (!empty($coverCandidates)) {
                $bookData['cover_image'] = ltrim($this->directoryPath, '/') . '/' . $coverCandidates[0];
            }
            Log::info('[BulkImport] Cover image: ' . ($bookData['cover_image'] ?? ''));
            Log::info('[BulkImport] Description: ' . ($bookData['description'] ?? ''));
            Log::info('[BulkImport] Published year: ' . ($bookData['published_year'] ?? ''));

            try {
                $shouldSearchGoogle = empty($bookData['cover_image']) ||
                    empty($bookData['description']) ||
                    empty($bookData['published_year']);

                if ($shouldSearchGoogle) {
                    // Safely get author name
                    $authorName = 'Unknown Author';
                    if (isset($bookData['authors'])) {
                        if (is_array($bookData['authors']) && !empty($bookData['authors'][0])) {
                            $authorName = is_string($bookData['authors'][0])
                                ? $bookData['authors'][0]
                                : 'Unknown Author';
                        }
                    }

                    // Safely get series info
                    $seriesName = '';
                    $seriesNumber = null;
                    if (isset($bookData['series']) && is_array($bookData['series'])) {
                        $seriesKeys = array_filter(
                            array_keys($bookData['series']),
                            'is_string'
                        );
                        if (!empty($seriesKeys)) {
                            $seriesName = $seriesKeys[0];
                            $seriesNumber = $bookData['series'][$seriesName] ?? null;
                        }
                    }

                    Log::info(sprintf(
                        '[BulkImport] Searching Google Books for: %s by %s',
                        $bookData['title'],
                        $authorName
                    ));
                    [$matches, $closeMatch] = $this->searchGoogleBooksWithSimilarity(
                        $bookData['title'],
                        $authorName,
                        $seriesName,
                        $seriesNumber
                    );

                    function getPublishedYear($info)
                    {
                        if (isset($info['publishedDate'])) {
                            return $info['publishedDate'];
                        } else {
                            return isset($info['publishedDate']) ? substr($info['publishedDate'], 0, 4) : null;
                        }
                    }

                    if ($closeMatch) {
                        $info = $closeMatch['volumeInfo'];
                        $bookData['publishedYear'] = getPublishedYear($info);
                        $bookData['description'] = $info['description'] ?? '';

                        if (empty($bookData['coverImage']) && !empty($info['imageLinks']['thumbnail'])) {
                            $coverImage = $info['imageLinks']['thumbnail'];
                            Log::info('[BulkImport] Cover image from Google Books: ' . $coverImage);
                            $coverImagePath = $this->importCoverImageFromUrl(
                                $coverImage,
                                $bookData['directoryPath']
                            );
                            $storageRoot = rtrim(env('BOOK_STORAGE_PATH'), '/');
                            if ($coverImagePath && strpos($coverImagePath, $storageRoot) === 0) {
                                $coverImagePath = ltrim(substr($coverImagePath, strlen($storageRoot)), '/');
                            }
                            // Ensure path is directoryPath/filename for Firestore
                            if ($coverImagePath && strpos($coverImagePath, $bookData['directoryPath']) === false) {
                                $coverImagePath = rtrim($bookData['directoryPath'], '/') . '/' .
                                    ltrim(basename($coverImagePath), '/');
                            }
                            $bookData['coverImage'] = $coverImagePath;
                        }
                    } else {
                        Log::error(
                            '[BulkImport] No close match found for: ' . $bookData['title'] . ' by ' . $authorName
                        );
                    }
                }
            } catch (\Exception $e) {
                $msg = $e->getMessage();
                if (strpos($msg, 'Quota exceeded for quota metric') !== false) {
                    $attempts = $this->attempts();
                    if ($attempts == 1) {
                        // Random delay between 5 and 30 minutes
                        $delay = rand(5 * 60, 30 * 60);
                    } elseif ($attempts == 2) {
                        // 4 hours
                        $delay = 4 * 60 * 60;
                    } elseif ($attempts >= 3 && $attempts < 10) {
                        // 8 hours
                        $delay = 8 * 60 * 60;
                    } else {
                        // Max retries reached, notify admin
                        $this->notifyAdminQuotaFailure($bookData, $msg, $attempts);
                        Log::error(sprintf(
                            '[BulkImport] Google Books API quota exceeded after 10 retries for book: %s (%s)',
                            $bookData['title'] ?? 'Unknown',
                            $bookData['directory_path'] ?? 'Unknown'
                        ));

                        return;
                    }
                    Log::warning(
                        "[BulkImport] Google Books API quota exceeded. Releasing job for retry #$attempts after " .
                        $delay . ' seconds.'
                    );
                    $this->release($delay);

                    return;
                } else {
                    throw $e;
                }
            }

            $seriesNumber = '';
            if (!empty($bookData['series']) && is_array($bookData['series'])) {
                $firstSeries = array_key_first($bookData['series']);
                if ($firstSeries !== null) {
                    $seriesNumber = $bookData['series'][$firstSeries] ?? '';
                }
            }

            Log::info(sprintf(
                '[BulkImport] Series number: %s',
                $seriesNumber
            ));

            // Set dateAdded to the latest file modification date in the directory (fallback to authoritative
            // current time)
            try {
                $dirPath = $bookData['directory_path'] ?? $bookData['directoryPath'] ?? null;
                $storageRoot = rtrim(env('BOOK_STORAGE_PATH'), '/');
                if ($dirPath && strpos($dirPath, '/') !== 0) {
                    $scanPath = $storageRoot . '/' . ltrim($dirPath, '/');
                } else {
                    $scanPath = $dirPath;
                }

                $latestMtime = null;
                $latestFile = null;
                if (is_dir($scanPath)) {
                    $audioExtensions = ['mp3', 'm4b', 'm4a', 'flac', 'ogg', 'wav', 'aac'];
                    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($scanPath));
                    foreach ($iterator as $fileinfo) {
                        if ($fileinfo->isFile()) {
                            $ext = strtolower($fileinfo->getExtension());
                            if (in_array($ext, $audioExtensions, true)) {
                                $mtime = $fileinfo->getMTime();
                                if ($latestMtime === null || $mtime > $latestMtime) {
                                    $latestMtime = $mtime;
                                    $latestFile = $fileinfo->getPathname();
                                }
                            }
                        }
                    }
                }
                if ($latestMtime !== null) {
                    $bookData['dateAdded'] = date('c', $latestMtime);
                } else {
                    $bookData['dateAdded'] = date('c');
                }
            } catch (\Throwable $e) {
                $bookData['dateAdded'] = date('c');
            }

            // If we have a book ID, update it, otherwise create a new one

            Log::info(sprintf(
                '[BulkImport] Cover image: %s, Published year: %s, Series number: %s',
                $bookData['coverImage'] ?? 'None',
                $bookData['publishedYear'] ?? 'None',
                $seriesNumber ?? 'None'
            ));
            $bookId = $firestore->createBook($bookData);
            Log::info("[BulkImport] Created new book: {$bookData['title']} (ID: {$bookId})");

            $dirPath = $bookData['directoryPath'];
            $storagePath = env('BOOK_STORAGE_PATH');
            $candidates = [];
            if ($dirPath && $storagePath && Storage::disk('public')->exists($dirPath)) {
                $files = Storage::disk('public')->files($dirPath);
                foreach ($files as $file) {
                    if (preg_match('/\.(jpe?g|png|gif|svg)$/i', $file)) {
                        $candidates[] = [
                            'path' => $file,
                            'size' => Storage::disk('public')->size($file),
                        ];
                    }
                }
            }

            if (count($candidates) > 0) {
                usort($candidates, function ($a, $b) {
                    return $b['size'] <=> $a['size'];
                });
                // Update cover_image in Firestore
                $coverImagePath = $candidates[0]['path'];
                $storageRoot = rtrim(env('BOOK_STORAGE_PATH'), '/');
                if ($coverImagePath && strpos($coverImagePath, $storageRoot) === 0) {
                    $coverImagePath = ltrim(substr($coverImagePath, strlen($storageRoot)), '/');
                }
                // Ensure path is directoryPath/filename for Firestore
                if ($coverImagePath && strpos($coverImagePath, $bookData['directoryPath']) === false) {
                    $coverImagePath = rtrim($bookData['directoryPath'], '/') . '/' .
                        ltrim(basename($coverImagePath), '/');
                }
                $firestore->updateBook($bookId, ['coverImage' => $coverImagePath]);
            }

            Log::info(
                '[BulkImport] Book imported: ' . ($bookData['title'] ??
                    '') . " ({$bookId}) " . ($bookData['directoryPath'] ??
                    '')
            );

            // Update job status to completed
            $firestore->updateJobStatus(
                $jobId,
                'book_import',
                'completed',
                [
                    'book_id' => $bookId,
                    'title' => $bookData['title'] ?? '',
                    'completed_at' => now()->toDateTimeString(),
                    'message' => 'Book imported successfully',
                ]
            );
        } catch (\Exception $e) {
            Log::error('[BulkImport] Error importing book: ' . $e->getMessage(), [
                'directory_path' => $this->directoryPath,
                'trace' => $e->getTraceAsString(),
            ]);

            // Update job status to failed
            $firestore->updateJobStatus(
                $jobId,
                'book_import',
                'failed',
                [
                    'error' => $e->getMessage(),
                    'failed_at' => now()->toDateTimeString(),
                    'message' => 'Failed to import book: ' . $e->getMessage(),
                ]
            );

            // Re-throw to allow Laravel to handle the failure
            throw $e;
        }
    }

    /**
     * Notify admin of Google Books API quota failure.
     */
    protected function notifyAdminQuotaFailure($book, $msg, $attempts)
    {
        $firestore = new FirestoreService();
        $jobId = 'quota_failure_' . md5(($book['title'] ?? '') . '_' . now()->timestamp);

        // Log the quota failure as a special job type
        $firestore->updateJobStatus(
            $jobId,
            'quota_failure',
            'failed',
            [
                'book_title' => $book['title'] ?? 'Unknown',
                'directoryPath' => $book['directoryPath'] ?? 'Unknown',
                'attempts' => $attempts,
                'error' => $msg,
                'occurred_at' => now()->toDateTimeString(),
                'message' => "Google Books API quota exceeded after $attempts attempts",
            ]
        );

        Log::error(
            "[ERROR][ImportBookFromDirectoryJob] Google Books API quota exceeded for '" .
            ($book['title'] ?? 'Unknown') . "' in '" . ($book['directoryPath'] ?? 'Unknown') . "' after $attempts' .
            ' attempts. Last error: $msg"
        );
    }
}
