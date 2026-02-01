<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\BookDirectoryParser;
use App\Services\BookImportService;
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

    protected string $directoryPath;

    public function __construct(string $directoryPath)
    {
        $this->directoryPath = $directoryPath;
    }

    public function handle(): void
    {
        try {
            Log::info("[BulkImport] Starting: {$this->directoryPath}");

            $dirPath = '/' . ltrim($this->directoryPath, '/');
            $storagePath = rtrim((string) (config('filesystems.disks.books.root') ?? config('app.book_root')), '/');
            $fullPath = $storagePath . $dirPath;

            if (! is_dir($fullPath)) {
                $error = "[BulkImport] Directory does not exist: $fullPath";
                Log::error($error);
                throw new \RuntimeException($error);
            }

            $parser = app(BookDirectoryParser::class);
            $importService = app(BookImportService::class);

            // Parse the directory to get book metadata
            $bookData = $parser->parseDirectory($fullPath);

            if (empty($bookData) || empty($bookData['title'])) {
                Log::warning("[BulkImport] Skipped directory {$dirPath}: No valid book data found");

                return;
            }

            Log::info('[BulkImport] Processing directory: ' . $dirPath, [
                'title' => $bookData['title'] ?? 'Unknown',
            ]);

            // Build audiobook structure from parsed data
            $audiobook = [
                'path' => $fullPath,
                'files' => $bookData['files'] ?? [],
            ];

            // Create the book in the database
            // Since this is for books already in the library structure,
            // we don't need to move files - just register in DB
            $book = $importService->createBookFromMetadata($bookData, $audiobook, [
                'skip_file_operations' => true,  // Files are already in place
            ]);

            if ($book) {
                Log::info("[BulkImport] Successfully imported: {$book->title}");
            } else {
                Log::warning("[BulkImport] Import returned null for: {$dirPath}");
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
