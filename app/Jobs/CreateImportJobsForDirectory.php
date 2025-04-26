<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Jobs\ImportBookFromDirectoryJob;
use App\Models\Book;

class CreateImportJobsForDirectory implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
        $bookDirs = $this->findBookDirectories($absDir);
        $queued = [];
        foreach ($bookDirs as $dirPath) {
            $relDir = ltrim(str_replace($storagePath, '', $dirPath), '/');
            if (Book::where('directory_path', $relDir)->exists()) {
                continue;
            }
            ImportBookFromDirectoryJob::dispatch($relDir);
            $queued[] = $relDir;
        }
        Log::info('Queued ' . count($queued) . ' book directories for import.', ['queued_dirs' => $queued]);
    }

    /**
     * Find book directories recursively (reuse logic from controller if needed)
     */
    protected function findBookDirectories($dir)
    {
        $results = [];
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
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
