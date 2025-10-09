<?php

namespace App\Jobs;

use App\Services\BookDirectoryParser;
use App\Services\UnifiedBookImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ImportBookFromDirectoryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 3;
    protected $directoryPath;

    public function __construct($directoryPath)
    {
        $this->directoryPath = $directoryPath;
    }

    public function handle()
    {
        try {
            Log::info("[BulkImport] Starting: {$this->directoryPath}");

            $dirPath = '/' . ltrim($this->directoryPath, '/');
            $storagePath = rtrim(env('BOOK_STORAGE_PATH'), '/');
            $fullPath = $storagePath . $dirPath;

            if (!is_dir($fullPath)) {
                $error = "[BulkImport] Directory does not exist: $fullPath";
                Log::error($error);
                throw new \RuntimeException($error);
            }

            // Use unified importer
            $parser = app(BookDirectoryParser::class);
            $importer = app(UnifiedBookImporter::class);

            // Parse the directory
            $bookData = $parser->parseDirectory($fullPath);

            if (empty($bookData) || empty($bookData['title'])) {
                Log::warning("[BulkImport] Skipped directory {$dirPath}: No valid book data found");
                return;
            }

            Log::info('[BulkImport] Processing directory: ' . $dirPath);

            // Use unified importer
            $result = $importer->importBook($bookData, [
                'source_path' => $fullPath,
                'dry_run' => false,
                'force' => false,
            ]);

            // Handle result
            switch ($result['status']) {
                case 'imported':
                    Log::info("[BulkImport] Successfully imported: {$bookData['title']}");
                    break;
                case 'updated':
                    Log::info("[BulkImport] Successfully updated: {$bookData['title']}");
                    break;
                case 'skipped':
                    $reason = $result['reason'] ?? 'unknown';
                    Log::warning("[BulkImport] Skipped {$bookData['title']}: {$reason}");
                    break;
                case 'error':
                    $error = $result['error'] ?? 'Unknown error';
                    Log::error("[BulkImport] Error importing {$bookData['title']}: {$error}");
                    throw new \RuntimeException($error);
            }
        } catch (\Exception $e) {
            Log::error('[BulkImport] Job failed', [
                'directory' => $this->directoryPath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
