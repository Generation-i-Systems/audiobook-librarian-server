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
use Illuminate\Support\Facades\Storage;

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
        Log::info("[BulkImport] Starting: {$this->directoryPath}");
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
        $book = new Book();
        $bookTmp = $this->processDirPath($dirPath);
        $book->directory_path = $dirPath;
        Log::info("[BulkImport] Found book: " . json_encode($book));
        $book->author_id = $bookTmp->author->id;
        $book->genre_id = $bookTmp->genre->id;
        if ($bookTmp->series) {
            $book->series_id = $bookTmp->series->id;
            $book->series_number = $bookTmp->series_number;
        }
        $book->title = $bookTmp->title;
        // Do NOT assign or save temporary/relationship fields like genre, series, author, or series_name_author_name here.
        // Only assign attributes that are actual columns in the books table.

        list($coverAuto, $coverCandidates) = $this->findCoverImageCandidate($dirPath);
        Log::info("[BulkImport] Found cover candidates: " . json_encode($coverCandidates));
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
            $book->description = $tags['description'];
        } elseif (!empty($meta['description'])) {
            $book->description = $meta['description'];
        }
        if (!empty($meta['year'])) {
            $book->published_year = $meta['year'];
        }
        if ($coverAuto) {
            $book->cover_image = ltrim($this->directoryPath, '/') . '/' . $coverAuto;
        } elseif (!empty($coverCandidates)) {
            $book->cover_image = ltrim($this->directoryPath, '/') . '/' . $coverCandidates[0];
        }
        Log::info("[BulkImport] Cover image: " . $book->cover_image);
        Log::info("[BulkImport] Description: " . $book->description);
        Log::info("[BulkImport] Published year: " . $book->published_year);

        try {
            if (empty($book->cover_image) || empty($book->description) || empty($book->published_year)) {
                Log::info("[BulkImport] Searching Google Books for: " . $book->title . ' ' . $book->author->name);
                [$matches, $closeMatch] = $this->searchGoogleBooksWithSimilarity($book->title, $book->author->name, $book->series->name ?? '', $book->series_number ?? '');
                if ($closeMatch) {
                    $info = $closeMatch['volumeInfo'];
                    $book->published_year = isset($info['publishedDate']) ? substr($info['publishedDate'], 0, 4) : '';
                    $book->description = $info['description'] ?? '';
                    $cover_image = $info['imageLinks']['thumbnail'] ?? '';
                    Log::info("[BulkImport] Cover image from Google Books: " . $cover_image);
                    $book->cover_image = $this->importCoverImageFromUrl($cover_image, $book->directory_path);

                } else {
                    Log::error("[BulkImport] No close match found for: " . $book->title . ' ' . $book->author->name);
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
                    $this->notifyAdminQuotaFailure($book, $msg, $attempts);
                    Log::error("[BulkImport] Google Books API quota exceeded after 10 retries for book: {$book->title} ({$book->directory_path})");
                    return;
                }
                Log::warning("[BulkImport] Google Books API quota exceeded. Releasing job for retry #$attempts after $delay seconds.");
                $this->release($delay);
                return;
            } else {
                throw $e;
            }
        }

        Log::info("[BulkImport] Cover image: " . $book->cover_image);
        Log::info("[BulkImport] Published year: " . $book->published_year);
        Log::info("--------------------------[BulkImport] Series number: " . $book->series_number);
        $book->date_added = now();
        $book->save();
        Log::info("--------------------------[BulkImport] Series number: " . $book->series_number);
        $dirPath = $book->directory_path;
        $storagePath = env('BOOK_STORAGE_PATH');
        $candidates = [];
        if ($dirPath && $storagePath && Storage::disk('public')->exists($dirPath)) {
            $files = Storage::disk('public')->files($dirPath);
            foreach ($files as $file) {
                if (preg_match('/\.(jpe?g|png|gif|svg)$/i', $file)) {
                    $candidates[] = [
                        'path' => $file,
                        'size' => Storage::disk('public')->size($file)
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

    /**
     * Notify admin of Google Books API quota failure.
     */
    protected function notifyAdminQuotaFailure($book, $msg, $attempts)
    {
        // Send message to all admins (or first admin)
        $admin = \App\Models\User::where('is_admin', true)->first();
        \App\Models\Message::create([
            'subject' => '[ERROR][ImportBookFromDirectoryJob] Google Books API quota exceeded',
            'body' => "Book '{$book->title}' in '{$book->directory_path}' failed after $attempts attempts. Last error: $msg",
            'to_user_id' => $admin ? $admin->id : null,
            'from_user_id' => null,
            'is_read' => false,
        ]);
    }
}
