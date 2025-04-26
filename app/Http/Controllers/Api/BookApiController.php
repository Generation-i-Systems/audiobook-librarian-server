<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Author;
use App\Models\Series;
use App\Models\BookQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ZipArchive;

class BookApiController extends Controller
{
    public function index(Request $request)
    {
        $query = Book::with(['genre', 'author', 'series']);

        // OR-based search for title, author, or series
        if ($request->filled('query')) {
            $search = $request->input('query');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%' . $search . '%')
                  ->orWhereHas('author', function ($q2) use ($search) {
                      $q2->where('name', 'like', '%' . $search . '%');
                  })
                  ->orWhereHas('series', function ($q3) use ($search) {
                      $q3->where('name', 'like', '%' . $search . '%');
                  });
            });
        }

        // Filtering
        if ($request->filled('genre')) {
            $query->whereHas('genre', function ($q) use ($request) {
                $q->where('name', $request->genre);
            });
        }
        if ($request->filled('author')) {
            $query->whereHas('author', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->author . '%');
            });
        }
        if ($request->filled('series')) {
            $query->whereHas('series', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->series . '%');
            });
        }
        if ($request->filled('title')) {
            $query->where('title', 'like', '%' . $request->title . '%');
        }
        if ($request->filled('publication_date')) {
            $query->where('published_year', $request->publication_date);
        }
        if ($request->filled('date_added')) {
            $query->whereDate('date_added', $request->date_added);
        }

        $perPage = $request->input('per_page', 100);
        $withCover = $request->boolean('with_cover', false);
        $inlineCovers = $request->boolean('inlineCovers', false);
        $books = $query->paginate($perPage);

        $books->getCollection()->transform(function ($book) use ($withCover, $inlineCovers) {
            $arr = $this->getBookWithCover($book, $withCover, $inlineCovers);
            $arr['description'] = 'foo'; // TEMPORARY: override all descriptions for index
            return $arr;
        });
        Log::info('Books retrieved successfully');
        Log::info($books);
        return response()->json($books);
    }

    public function show(Book $book, Request $request)
    {
        $withCover = $request->boolean('with_cover', false);
        $inlineCovers = $request->boolean('inlineCovers', false);
        return response()->json($this->getBookWithCover($book, $withCover, $inlineCovers));
    }

    public function browse(Request $request)
    {
        $type = $request->input('type'); // 'genre', 'author', 'series'
        $perPage = $request->input('per_page', 100);
        $search = $request->input('search');

        $modelMap = [
            'genre' => Genre::class,
            'author' => Author::class,
            'series' => Series::class,
        ];
        if (!isset($modelMap[$type])) {
            return response()->json(['error' => 'Invalid browse type'], 400);
        }
        $model = $modelMap[$type];
        $query = $model::query();
        if ($search) {
            $query->where('name', 'like', "%$search%");
        }
        $items = $query->paginate($perPage);
        return response()->json($items);
    }

    public function search(Request $request)
    {
        $query = Book::with(['genre', 'author', 'series']);
        if ($request->filled('title')) {
            $query->where('title', 'like', '%' . $request->title . '%');
        }
        if ($request->filled('author')) {
            $query->whereHas('author', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->author . '%');
            });
        }
        if ($request->filled('series')) {
            $query->whereHas('series', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->series . '%');
            });
        }
        if ($request->filled('publication_date')) {
            $query->where('published_year', $request->publication_date);
        }
        if ($request->filled('date_added')) {
            $query->whereDate('date_added', $request->date_added);
        }
        $perPage = $request->input('per_page', 100);
        $withCover = $request->boolean('with_cover', false);
        $inlineCovers = $request->boolean('inlineCovers', false);
        $books = $query->paginate($perPage);
        $books->getCollection()->transform(function ($book) use ($withCover, $inlineCovers) {
            return $this->getBookWithCover($book, $withCover, $inlineCovers);
        });
        return response()->json($books);
    }

    public function download(Book $book)
    {
        $directoryPath = $book->directory_path;

        if (!$directoryPath || !Storage::exists($directoryPath)) {
            return response()->json(['error' => 'Book directory not found.'], 404);
        }

        $files = Storage::files($directoryPath);

        if (empty($files)) {
            return response()->json(['error' => 'No files found for this book.'], 404);
        }

        $zipFileName = str_replace(' ', '_', $book->title) . '.zip';  //Sanitize filename
        $zipPath = storage_path('app/public/temp/' . $zipFileName);  //Temporary storage

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            return response()->json(['error' => 'Failed to create zip archive.'], 500);
        }

        foreach ($files as $file) {
            $zip->addFile(Storage::path($file), basename($file));
        }

        $zip->close();

        $url = Storage::url('public/temp/' . $zipFileName);

        return response()->json(['download_url' => $url]);
    }

    public function queueDownload(Request $request)
    {
        $user = Auth::user();
        $queue = BookQueue::where('user_id', $user->id)->with('book')->get();
        if ($queue->isEmpty()) {
            return response()->json(['error' => 'No books queued for download.'], 404);
        }
        $zipName = 'bookqueue_' . $user->id . '_' . Str::random(8) . '.zip';
        $zipPath = storage_path('app/public/' . $zipName);

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE) === TRUE) {
            foreach ($queue as $item) {
                $book = $item->book;
                if ($book && $book->directory_path && Storage::exists($book->directory_path)) {
                    $files = Storage::files($book->directory_path);
                    foreach ($files as $file) {
                        $localPath = storage_path('app/' . $file);
                        if (file_exists($localPath)) {
                            $zip->addFile($localPath, basename($file));
                        }
                    }
                }
            }
            $zip->close();
        } else {
            return response()->json(['error' => 'Could not create zip file.'], 500);
        }
        // Optionally, store a record of the zip for later deletion/marking
        return response()->json(['zip_id' => $zipName, 'download_url' => url('storage/' . $zipName)]);
    }

    public function downloadQueuedZip($zipId)
    {
        $zipPath = storage_path('app/public/' . $zipId);
        if (!file_exists($zipPath)) {
            return response()->json(['error' => 'Zip file not found.'], 404);
        }
        return response()->download($zipPath);
    }

    public function markZipDownloaded($zipId)
    {
        $zipPath = storage_path('app/public/' . $zipId);
        if (file_exists($zipPath)) {
            unlink($zipPath);
            return response()->json(['message' => 'Zip file deleted.']);
        }
        return response()->json(['error' => 'Zip file not found.'], 404);
    }

    private function getBookWithCover($book, $withCover = false, $inlineCovers = false)
    {
        $arr = $book->toArray();
        $arr['genre'] = $book?->genre->name;
        $arr['author_name'] = $book->author?->name;
        $arr['series_name'] = $book->series?->name;
        if ($withCover && $book->cover_image && Storage::disk('books')->exists($book->cover_image)) {
            if ($inlineCovers) {
                $coverPath = Storage::disk('books')->path($book->cover_image);
                $arr['cover'] = [
                    'type' => 'base64',
                    'path' => $coverPath,
                    'data' => base64_encode(Storage::disk('books')->get($book->cover_image)),
                ];
            } else {
                // Proxy URL (assuming /api/book/{id}/cover is the proxy endpoint)
                $arr['cover'] = [
                    'type' => 'url',
                    'url' => url('/api/book/' . $book->id . '/cover'),
                ];
            }
        } else {
            $arr['cover'] = null;
        }
        unset($arr['cover_image_content']);
        return $arr;
    }

    public function cover(Book $book)
    {
        if ($book->cover_image && Storage::disk('books')->exists($book->cover_image)) {
            $mime = Storage::disk('books')->getMimeType($book->cover_image);
            return response(Storage::disk('books')->get($book->cover_image), 200)->header('Content-Type', $mime);
        }
        return response()->json(['error' => 'Cover image not found.'], 404);
    }
}
