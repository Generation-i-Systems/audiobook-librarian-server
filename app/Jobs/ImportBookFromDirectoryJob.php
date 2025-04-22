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
        // Inject GoogleBooksApiService for the trait
        $this->setGoogleBooksApiService(app(\App\Services\GoogleBooksApiService::class));

        Log::info("[BulkImport] Processing: {$this->directoryPath}");

        $dirPath = '/' . ltrim($this->directoryPath, '/');

        $storagePath = rtrim(env('BOOK_STORAGE_PATH'), '/');
        $fullPath = $storagePath . $dirPath;
        if (!is_dir($fullPath)) {
            Log::error("[BulkImport] Directory does not exist: $fullPath");
            return;
        }

        $bookRec = Book::where('directory_path', $dirPath)->first();
        if ($bookRec) {
            Log::error("[BulkImport] Book already exists: " . json_encode($bookRec));
            return;
        }
        // 1. Create a Book instance and set directory_path
        $book = new Book();
        $book->directory_path = $dirPath;

        $bookTmp = $this->processDirPath($dirPath);

        // copy from a temporary object to avoid having actual objects set in the new book to be created
        // seems crazy but it is what I have gotten to work
        $book->author_id = $bookTmp->author->id;
        $book->genre_id = $bookTmp->genre->id;
        if ($bookTmp->series) {
            $book->series_id = $bookTmp->series->id;
        }
        $book->title = $bookTmp->title;

        // 3. Find cover candidates and extract cover if needed
        list($coverAuto, $coverCandidates) = $this->findCoverImageCandidate($dirPath);

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
        // 4. metadata.abs
        $meta = $this->extractMetadataAbs($fullPath);
        // 5. Fill description and published_year
        if (!empty($tags['description'])) {
            $book->description = $tags['description'];
        } elseif (!empty($meta['description'])) {
            $book->description = $meta['description'];
        }
        if (!empty($meta['year'])) {
            $book->published_year = $meta['year'];
        }
        // 6. Cover image
        if ($coverAuto) {
            $book->cover_image = ltrim($this->directoryPath, '/') . '/' . $coverAuto;
        } elseif (!empty($coverCandidates)) {
            $book->cover_image = ltrim($this->directoryPath, '/') . '/' . $coverCandidates[0];
        }

        if (empty($book->cover_image) || empty($book->description) || empty($book->published_year)) {
            $results = $this->searchGoogleBooks($book->title . ' ' . $book->author->name);
            if (!empty($results['items'][0])) {
                $info = $results['items'][0]['volumeInfo'];
                $book->published_year = isset($info['publishedDate']) ? substr($info['publishedDate'], 0, 4) : '';
                $book->description = $info['description'] ?? '';
                $cover_image = $info['imageLinks']['thumbnail'] ?? '';

                $book->cover_image = $this->importCoverImageFromUrl($cover_image);
            }
        }
        // 7. Set type
        $book->type = 'audiobook';
        // 8. Set date_added
        $book->date_added = now();
        // 9. Save book
        $book->save();

        // After importing/assigning cover images, pick the largest one as default
        $dirPath = $book->directory_path;
        $storagePath = env('BOOK_STORAGE_PATH');
        $candidates = [];
        if ($dirPath && $storagePath && \Storage::disk('public')->exists($dirPath)) {
            $files = \Storage::disk('public')->files($dirPath);
            foreach ($files as $file) {
                if (preg_match('/\.(jpe?g|png|gif|svg)$/i', $file)) {
                    $candidates[] = [
                        'path' => $file,
                        'size' => \Storage::disk('public')->size($file)
                    ];
                }
            }
        }
        if (count($candidates) > 0) {
            usort($candidates, function($a, $b) {
                return $b['size'] <=> $a['size'];
            });
            $book->cover_image = $candidates[0]['path'];
            $book->save();
        }

        Log::info("[BulkImport] Book imported: {$book->title} ({$book->id}) {$book->directory_path}");
    }
}
