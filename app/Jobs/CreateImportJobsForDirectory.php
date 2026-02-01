<?php

namespace App\Jobs;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CreateImportJobsForDirectory implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct($dir, DocumentStoreServiceInterface $documentStoreService)
    {
        $this->dir = $dir;
        $this->documentStoreService = $documentStoreService;
    }

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The directory to process
     *
     * @var string
     */
    protected $dir;

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $startTime = microtime(true);
        Log::info('[DIRECTORY_IMPORT] Starting directory import job', [
            'directory' => $this->dir,
            'job_id' => $this->job ? $this->job->getJobId() : 'sync',
        ]);
        echo '[DIRECTORY_IMPORT] Starting import for directory: ' . $this->dir . "\n";

        $storagePath = config('filesystems.disks.books.root') ?? config('app.book_root');
        if (empty($storagePath)) {
            $error = 'BOOK_STORAGE_PATH is not set in environment';
            Log::error('[DIRECTORY_IMPORT] ' . $error);
            throw new \RuntimeException($error);
        }

        $absDir = rtrim($storagePath, '/') . '/' . ltrim($this->dir, '/');
        if (!is_dir($absDir)) {
            $error = "Directory not found for import: $absDir";
            Log::error('[DIRECTORY_IMPORT] ' . $error);
            throw new \RuntimeException($error);
        }

        Log::debug('[DIRECTORY_IMPORT] Directory scan starting', [
            'storage_path' => $storagePath,
            'absolute_path' => $absDir,
        ]);

        $documentStore = $this->documentStoreService;
        $jobId = 'import_dir_' . md5($this->dir . '_' . now()->timestamp);

        try {
            // Update job status to processing
            $documentStore->updateJobStatus(
                $jobId,
                'directory_import',
                'processing',
                [
                    'directory' => $this->dir,
                    'total_items' => 0,
                    'processed_items' => 0,
                    'queued_items' => 0,
                    'skipped_items' => 0,
                    'started_at' => now()->toDateTimeString(),
                ]
            );

            $bookDirs = $this->findBookDirectories($absDir);
            $queued = [];
            $skipped = [];
            $total = count($bookDirs);

            // Update total items count
            $documentStore->updateJobStatus(
                $jobId,
                'directory_import',
                'processing',
                ['total_items' => $total]
            );

            echo 'Looping through ' . count($bookDirs) . " directories\n";
            foreach ($bookDirs as $dirPath) {
                $relDir = ltrim(str_replace($storagePath, '', $dirPath), '/');
                $exists = false;
                $books = $documentStore->listBooks();

                foreach ($books as $book) {
                    if (($book['directoryPath'] ?? null) === $relDir) {
                        $exists = true;
                        break;
                    }
                }

                if ($exists) {
                    $skipped[] = $relDir;
                    $documentStore->updateJobStatus(
                        $jobId,
                        'directory_import',
                        'processing',
                        ['skipped_items' => count($skipped)]
                    );

                    continue;
                }

                $jobNumber = count($queued) + 1;
                $totalDirs = count($bookDirs);

                Log::info(sprintf(
                    '[DIRECTORY_IMPORT] Queueing import job %d/%d for: %s',
                    $jobNumber,
                    $totalDirs,
                    $relDir
                ));

                try {
                    $importJob = new ImportBookFromDirectoryJob($relDir);
                    $importJob->onQueue('imports');

                    Log::debug('[DIRECTORY_IMPORT] Dispatching ImportBookFromDirectoryJob', [
                        'directory' => $relDir,
                        'queue' => 'imports',
                    ]);

                    $dispatchId = (string) Str::uuid();

                    Log::debug('[DIRECTORY_IMPORT] About to dispatch job', [
                        'dispatch_id' => $dispatchId,
                        'directory' => $relDir,
                    ]);

                    dispatch($importJob);

                    Log::info('[DIRECTORY_IMPORT] Successfully queued job', [
                        'dispatch_id' => $dispatchId,
                        'directory' => $relDir,
                        'queue' => 'imports',
                    ]);

                    echo sprintf("[DIRECTORY_IMPORT] Queued job %s for: %s\n", $dispatchId, $relDir);

                    $queued[] = [
                        'dispatch_id' => $dispatchId,
                        'directory' => $relDir,
                        'queued_at' => now()->toDateTimeString(),
                    ];
                } catch (\Exception $e) {
                    Log::error('[DIRECTORY_IMPORT] Failed to queue job', [
                        'directory' => $relDir,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    throw $e;
                }

                // Update progress
                $documentStore->updateJobStatus(
                    $jobId,
                    'directory_import',
                    'processing',
                    [
                        'queued_items' => count($queued),
                        'processed_items' => count($queued) + count($skipped),
                    ]
                );
            }

            // Mark job as completed
            $documentStore->updateJobStatus(
                $jobId,
                'directory_import',
                'completed',
                [
                    'completed_at' => now()->toDateTimeString(),
                    'queued_dirs' => $queued,
                    'skipped_dirs' => $skipped,
                ]
            );

            Log::info('Queued ' . count($queued) . ' book directories for import.', [
                'job_id' => $jobId,
                'queued_dirs' => $queued,
                'skipped_dirs' => $skipped,
            ]);
        } catch (\Exception $e) {
            Log::error('Error in CreateImportJobsForDirectory: ' . $e->getMessage(), [
                'directory' => $this->dir,
                'trace' => $e->getTraceAsString(),
            ]);

            // Update job status to failed
            if (isset($documentStore)) {
                $documentStore->updateJobStatus(
                    $jobId,
                    'directory_import',
                    'failed',
                    [
                        'error' => $e->getMessage(),
                        'failed_at' => now()->toDateTimeString(),
                    ]
                );
            }

            // Re-throw to allow Laravel to handle the failure
            throw $e;
        }
    }

    /**
     * Find book directories recursively (reuse logic from controller if needed)
     */
    protected function findBookDirectories($dir)
    {
        $results = [];
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                // If directory contains audio files, treat as book dir
                $audioFiles = glob($path . '/*.{mp3,m4b,m4a}', GLOB_BRACE);
                if (!empty($audioFiles)) {
                    $results[] = $path;
                } else {
                    // Recursively search subdirectories
                    $results = array_merge($results, $this->findBookDirectories($path));
                }
            }
        }

        return $results;
    }
}
