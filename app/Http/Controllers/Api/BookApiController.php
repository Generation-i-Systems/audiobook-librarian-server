<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class BookApiController extends Controller
{
    public function index(Request $request)
    {
        $query = Book::query();

        if ($request->has('genre')) {
            $query->where('genre', $request->genre);
        }

        $books = $query->get();

        return response()->json($books);
    }

    public function show(Book $book)
    {
        return response()->json($book);
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

        $zip = new ZipArchive();

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
}
