<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\Log;

use Illuminate\Console\Command;
use App\Services\FirestoreService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Traits\BookImportTrait;

class RepairBooks extends Command
{
    use BookImportTrait;

    protected $signature = 'books:repair-covers-and-series {book_id?} {directory_path?} {--actions=}';
    protected $description = 'Repair book covers and series numbers.';

    public function handle()
    {
        $this->info('Starting book repair job...');

        // Parse actions argument
        $actionsArg = $this->option('actions');
        $allActions = ['cover', 'series', 'romancefix'];
        if ($actionsArg) {
            $actions = array_map('trim', explode(',', strtolower($actionsArg)));
            $actions = array_intersect($allActions, $actions); // Only valid actions
        } else {
            $actions = $allActions;
        }
        $doCover = in_array('cover', $actions);
        $doSeries = in_array('series', $actions);
        $doRomanceFix = in_array('romancefix', $actions);

        // Unified repair query: books needing cover or series number
        $arg1 = $this->argument('book_id');
        $arg2 = $this->argument('directory_path');
        $bookId = null;
        $dirPath = null;
        if ($arg1 !== null && is_numeric($arg1)) {
            $bookId = $arg1;
            $dirPath = $arg2;
        } elseif ($arg1 !== null) {
            $dirPath = $arg1;
        }
        $query = Book::query();
        $query->where(function($q) use ($doCover, $doSeries) {
            if ($doCover) {
                $q->where('cover_image', '/cover.jpg');
            }
            if ($doCover && $doSeries) {
                $q->orWhere(function($q2) {
                    $q2->whereNotNull('series_id')->whereNull('series_number');
                });
            } elseif ($doSeries) {
                $q->whereNotNull('series_id')->whereNull('series_number');
            }
        });
        if ($bookId) {
            $query->where('id', $bookId);
        }
        if ($dirPath) {
            $query->where('directory_path', 'like', $dirPath . '%');
        }
        $books = $query->get();
        $this->info("Repairing books for: " . $query->toRawSql());
        $this->info("Count: " . $books->count());
        $this->info("Cover count: " . $books->where('cover_image', '/cover.jpg')->count());
        $this->info("Series count: " . $books->whereNotNull('series_id')->whereNull('series_number')->count());

        foreach ($books as $book) {
            // Cover repair
            if ($doCover && $book->cover_image === '/cover.jpg') {
                $this->info("Repairing cover for: {$book->title}");
                $cover = $this->findLocalCover($book);
                if ($cover) {
                    $book->cover_image = $cover;
                    $book->save();
                    $this->info("Updated cover for: {$book->title}");
                } else {
                    $this->warn("No local cover found for: {$book->title} by {$book->author->name}");
                }
            }
            // Series number repair
            if ($doSeries && $book->series_id && !$book->series_number) {
                $this->info("Repairing series number for: {$book->title} by {$book->author->name} {$book->series?->name}");
                $this->info("Directory path: {$book->directory_path}");

                $num = $this->extractSeriesNumberFromPath($book->directory_path, $book->series?->name);
                if ($num) {
                    $book->series_number = $num;
                    $book->save();
                    $this->info("Set series number for {$book->title} to {$num}");
                } else {
                    $this->warn("No series number found for: {$book->title} by {$book->author->name} {$book->series?->name}");
                }
            }
        }

        // --- NEW LOGIC for /Romance/R/ fix ---
        $this->info("doRomanceFix: {$doRomanceFix}, bookId: {$bookId}, dirPath: {$dirPath}");


        if ($doRomanceFix) {
            $romanceQuery = Book::query();
            $romanceQuery->where('directory_path', 'like', '%/Romance/R/%');
            if ($bookId) {
                $romanceQuery->where('id', $bookId);
            }
            if ($dirPath) {
                $romanceQuery->where('directory_path', 'like', $dirPath . '%');
            }
            $this->info("Romance query: " . $romanceQuery->toRawSql());

            $romanceRBooks = $romanceQuery->get();
            $this->info('Found ' . $romanceRBooks->count() . ' books with /Romance/R/ in directory_path (filtered). Reprocessing...');
            foreach ($romanceRBooks as $book) {
                $fixedPath = preg_replace('#/Romance/R/#', '/Romance/', $book->directory_path, 1);
                if ($fixedPath !== $book->directory_path) {
                    if (method_exists($this, 'processDirPath')) {
                        $newBook = $this->processDirPath($fixedPath);
                    } elseif (in_array('App\\Traits\\BookImportTrait', class_uses($this))) {
                        $newBook = $this->processDirPath($fixedPath);
                    } else {
                        $this->warn('processDirPath not available. Skipping.');
                        continue;
                    }
                    $this->warn("genre: {$newBook->genre}, author: {$newBook->author}, series: {$newBook->series}, title: {$newBook->title}");

                    // Update fields if newBook has them
                    $book->genre_id = $newBook->genre_id ?? $book->genre_id;
                    $book->author_id = $newBook->author_id ?? $book->author_id;
                    $book->series_id = $newBook->series_id ?? $book->series_id;
                    $book->title = $newBook->title ?? $book->title;
                    $book->save();
                    $this->info("Reprocessed book {$book->id}: set genre/author/series/title from {$fixedPath}");
                }
            }
        }
        // --- END NEW LOGIC ---

        $this->info('Repair job complete.');
    }

    private function findLocalCover($book)
    {
        $disk = Storage::disk('books');
        $dir = $book->directory_path;
        if (!$disk->exists($dir)) return null;
        // 1. Try cover embedded in first m4b
        $allFiles = collect($disk->allFiles($dir));
        $m4bs = $allFiles->filter(function($file) {
            return Str::endsWith(strtolower($file), '.m4b');
        })->values();
        if ($m4bs->count()) {
            $cover = $this->extractCoverFromM4B($disk->path($m4bs[0]), $disk->path($dir));
            if ($cover) return $cover;
        }
        // 2. cover.*
        $covers = $allFiles->filter(function($file) {
            return preg_match('/\/cover\.[^\/]+$/i', $file);
        });
        foreach ($covers as $file) {
            if ($this->isImage($disk->path($file))) return $this->storeCover($disk->path($file), $book);
        }
        // 3. any image
        $images = $allFiles->filter(function($file) {
            return preg_match('/\.(jpg|jpeg|png|gif)$/i', $file);
        });
        foreach ($images as $file) {
            if ($this->isImage($disk->path($file))) return $this->storeCover($disk->path($file), $book);
        }
        return null;
    }

    private function extractCoverFromM4B($m4b, $dir)
    {
        // Requires ffmpeg installed
        $output = rtrim($dir, DIRECTORY_SEPARATOR) . '/extracted_cover.jpg';
        $cmd = "ffmpeg -y -i " . escapeshellarg($m4b) . " -an -vcodec copy " . escapeshellarg($output);
        exec($cmd);
        if (file_exists($output)) {
            return $this->isImage($output) ? $output : null;
        }
        return null;
    }

    private function isImage($file)
    {
        $mime = mime_content_type($file);
        return Str::startsWith($mime, 'image/');
    }

    private function storeCover($file, $book)
    {
        // Store in books disk, keep filename unique
        $filename = 'covers/' . $book->id . '_' . basename($file);
        Storage::disk('books')->put($filename, file_get_contents($file));
        return $filename;
    }

    private function extractSeriesNumberFromPath($path, $seriesName)
    {
        if (!$path || !$seriesName) return null;
        // Find the segment containing the series name
        $segments = explode('/', trim($path, '/'));
        $seriesIdx = -1;
        foreach ($segments as $i => $seg) {
            if (stripos($seg, $seriesName) !== false) {
                $seriesIdx = $i;
                break;
            }
        }
        if ($seriesIdx !== -1 && isset($segments[$seriesIdx + 1])) {
            $next = $segments[$seriesIdx + 1];
            // 1. book [num], volume [num], vol [num], vol. [num] (case-insensitive, anywhere)
            if (preg_match('/(?:book|volume|vol\.?)[ _-]*(\d{1,2})/i', $next, $m)) {
                return intval($m[1]);
            }
            // 2. number at start
            if (preg_match('/^(\d{1,2})\b/', $next, $m)) {
                return intval($m[1]);
            }
            // 3. number at end
            if (preg_match('/(\d{1,2})$/', $next, $m)) {
                return intval($m[1]);
            }
        }
        return null;
    }
}
