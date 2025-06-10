<?php

namespace App\Http\Controllers;

use App\Services\FirestoreService;
use App\Services\GoogleBooksApiService;
use Illuminate\Http\Request;

class BookController extends Controller
{
    protected $googleBooksApiService;

    public function __construct(GoogleBooksApiService $googleBooksApiService)
    {
        $this->googleBooksApiService = $googleBooksApiService;
    }

    // ... Other methods...

    public function index(Request $request)
    {
        $firestore = new FirestoreService;
        $books = $firestore->listBooks();
        $genres = $firestore->listGenres();
        $authors = $firestore->listAuthors();
        $recentBooks = array_slice(array_reverse($books), 0, 5);

        return view('books.index', compact('books', 'genres', 'authors', 'recentBooks'));
    }

    public function show($id)
    {
        $firestore = new FirestoreService;
        $book = $firestore->getBook($id);
        if (! $book) {
            abort(404);
        }

        return view('books.show', compact('book'));
    }

    public function download($id)
    {
        $firestore = new FirestoreService;
        $book = $firestore->getBook($id);
        if (! $book) {
            abort(404);
        }
        $directoryPath = $book['directory_path'] ?? null;
        if (! $directoryPath || ! \Storage::disk('books')->exists($directoryPath)) {
            abort(404, 'Book directory not found.');
        }
        $files = \Storage::disk('books')->files($directoryPath);
        if (empty($files)) {
            abort(404, 'No files found for this book.');
        }
        $zipFileName = str_replace(' ', '_', $book['title']).'.zip';
        $zipPath = storage_path('app/public/temp/'.$zipFileName);
        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Failed to create zip archive.');
        }
        foreach ($files as $file) {
            $zip->addFile(\Storage::disk('books')->path($file), basename($file));
        }
        $zip->close();

        return response()->download($zipPath, $zipFileName)->deleteFileAfterSend(true);
    }
}
