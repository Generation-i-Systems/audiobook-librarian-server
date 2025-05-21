<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Jobs\ImportBookFromDirectoryJob;
use App\Services\FirestoreService;

class CreateImportJobsForDirectory implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected $dir;

    /**
     * Create a new job instance.
     *
     * @param string $dir The relative directory to scan for book directories
     */
    public function __construct($dir)
    {
        $this->dir = $dir;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $storagePath = env('BOOK_STORAGE_PATH');
        $absDir = rtrim($storagePath, '/') . '/' . ltrim($this->dir, '/');
        if (!is_dir($absDir)) {
            Log::error("Directory not found for import: $absDir");
            return;
        }

        $firestore = new FirestoreService();
        $jobId = 'import_dir_' . md5($this->dir . '_' . now()->timestamp);

        try {
            // Update job status to processing
            $firestore->updateJobStatus(
                $jobId,
                'directory_import',
                'processing',
                [
                    'directory' => $this->dir,
                    'total_items' => 0,
                    'processed_items' => 0,
                    'queued_items' => 0,
                    'skipped_items' => 0,
                    'started_at' => now()->toDateTimeString()
                ]
            );

            $bookDirs = $this->findBookDirectories($absDir);
            $queued = [];
            $skipped = [];
            $total = count($bookDirs);

            // Update total items count
            $firestore->updateJobStatus(
                $jobId,
                'directory_import',
                'processing',
                ['total_items' => $total]
            );

            foreach ($bookDirs as $dirPath) {
                $relDir = ltrim(str_replace($storagePath, '', $dirPath), '/');
                $exists = false;
                $books = $firestore->listBooks();

                foreach ($books as $book) {
                    if (($book['directory_path'] ?? null) === $relDir) {
                        $exists = true;
                        break;
                    }
                }

                if ($exists) {
                    $skipped[] = $relDir;
                    $firestore->updateJobStatus(
                        $jobId,
                        'directory_import',
                        'processing',
                        ['skipped_items' => count($skipped)]
                    );
                    continue;
                }

                // Create a job for this directory
                $importJob = new ImportBookFromDirectoryJob($relDir);
                $importJob->onQueue('imports');
                dispatch($importJob);

                $queued[] = $relDir;

                // Update progress
                $firestore->updateJobStatus(
                    $jobId,
                    'directory_import',
                    'processing',
                    [
                        'queued_items' => count($queued),
                        'processed_items' => count($queued) + count($skipped)
                    ]
                );
            }

            // Mark job as completed
            $firestore->updateJobStatus(
                $jobId,
                'directory_import',
                'completed',
                [
                    'completed_at' => now()->toDateTimeString(),
                    'queued_dirs' => $queued,
                    'skipped_dirs' => $skipped
                ]
            );

            Log::info('Queued ' . count($queued) . ' book directories for import.', [
                'job_id' => $jobId,
                'queued_dirs' => $queued,
                'skipped_dirs' => $skipped
            ]);
        } catch (\Exception $e) {
            Log::error('Error in CreateImportJobsForDirectory: ' . $e->getMessage(), [
                'directory' => $this->dir,
                'trace' => $e->getTraceAsString()
            ]);

            // Update job status to failed
            if (isset($firestore)) {
                $firestore->updateJobStatus(
                    $jobId,
                    'directory_import',
                    'failed',
                    [
                        'error' => $e->getMessage(),
                        'failed_at' => now()->toDateTimeString()
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
