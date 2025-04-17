<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Author;
use App\Services\GoogleBooksApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use ZipArchive;

class BookController extends Controller
{
    protected $googleBooksApiService;

    public function __construct(GoogleBooksApiService $googleBooksApiService)
    {
        $this->googleBooksApiService = $googleBooksApiService;
    }

    public function index(Request $request)
    {
        $query = Book::query();

        if ($request->has('genre_id')) {
            $query->where('genre_id', $request->genre_id);
        }

        if ($request->has('author_id')) {
            $query->where('author_id', $request->author_id);
        }

        if ($request->has('series')) {
            $query->where('series', 'like', "%{$request->series}%");
        }

        if ($request->has('title')) {
            $query->where('title', 'like', "%{$request->title}%");
        }

        $books = $query->paginate(12); // You can adjust the pagination size

        return view('books.index', compact('books'));
    }

    public function show(Book $book)
    {
        return view('books.show', compact('book'));
    }

    public function download(Book $book)
    {
        $directoryPath = $book->directory_path;

        if (!$directoryPath || !Storage::exists($directoryPath)) {
            abort(404, 'Book directory not found.');
        }

        $files = Storage::files($directoryPath);

        if (empty($files)) {
            abort(404, 'No files found for this book.');
        }

        $zipFileName = str_replace(' ', '_', $book->title) . '.zip';  //Sanitize filename
        $zipPath = storage_path('app/public/temp/' . $zipFileName);  //Temporary storage

        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            abort(500, 'Failed to create zip archive.');
        }

        foreach ($files as $file) {
            $zip->addFile(Storage::path($file), basename($file));
        }

        $zip->close();

        // Return the zip file as a download
        return response()->download($zipPath, $zipFileName)->deleteFileAfterSend(true); // Delete the temp zip file after sending.
    }
}
