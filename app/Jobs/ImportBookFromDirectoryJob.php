<?php

namespace App\Jobs;

use App\Models\Book;
use App\Traits\BookImportTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ImportBookFromDirectoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, BookImportTrait;

    protected $directoryPath;

    public function __construct($directoryPath)
    {
        $this->directoryPath = $directoryPath;
    }

    public function handle()
    {
        Log::info("[BulkImport] Processing: {$this->directoryPath}");
        // Use BookImportTrait logic to gather metadata, covers, etc.
        $storagePath = env('BOOK_STORAGE_PATH');
        $dir = rtrim($storagePath, '/') . '/' . ltrim($this->directoryPath, '/');
        if (!is_dir($dir)) {
            Log::warning("[BulkImport] Directory does not exist: $dir");
            return;
        }
        // 1. Find cover candidates
        list($coverAuto, $coverCandidates) = $this->findCoverImageCandidate($this->directoryPath);
        // 2. Extract m4b tags/cover if needed
        $m4bs = array_values(array_filter(scandir($dir), function ($f) use ($dir) {
            return is_file($dir . '/' . $f) && strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'm4b';
        }));
        $tags = [];
        if ($m4bs) {
            $firstM4b = $dir . '/' . $m4bs[0];
            $tags = $this->extractTagData($firstM4b);
            if (empty($coverAuto) && empty($coverCandidates)) {
                $coverAuto = $this->extractCoverFromM4B($firstM4b, $dir);
            }
        }
        // 3. metadata.abs
        $meta = $this->extractMetadataAbs($dir);
        // 4. Directory structure (use as fallback)
        $parts = explode('/', trim($this->directoryPath, '/'));
        $title = $parts[count($parts) - 1] ?? '';
        $author = $parts[count($parts) - 2] ?? '';
        $genre = $parts[count($parts) - 3] ?? '';
        // 5. Google Books fallback will be handled in controller if needed
        // Create Book
        $book = new Book();
        $book->directory_path = $this->directoryPath;
        $book->title = $meta['title'] ?? $tags['title'] ?? $title;
        $book->author_name = $meta['author'] ?? $tags['artist'] ?? $author;
        $book->genre_name = $meta['genre'] ?? $tags['genre'] ?? $genre;
        $book->description = $meta['description'] ?? $tags['description'] ?? null;
        $book->published_year = $meta['year'] ?? $tags['year'] ?? null;
        if ($coverAuto) {
            $book->cover_image = ltrim($this->directoryPath, '/') . '/' . $coverAuto;
        }
        // Save book (add further enrichment if needed)
        $book->save();
        Log::info("[BulkImport] Imported book: {$book->title} ({$book->directory_path})");
    }
}
