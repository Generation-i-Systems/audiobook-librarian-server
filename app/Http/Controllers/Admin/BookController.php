<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Author;
use App\Models\Series;
use App\Services\GoogleBooksApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use ZipArchive;
use getID3;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class BookController extends Controller
{
    protected $googleBooksApiService;

    public function __construct(GoogleBooksApiService $googleBooksApiService)
    {
        $this->googleBooksApiService = $googleBooksApiService;
    }

    public function index(Request $request)
    {
        $search = $request->input('search');
        $query = Book::query();

        if ($search) {
            $query->where('title', 'like', "%{$search}%")
                ->orWhereHas('author', function ($authorQuery) use ($search) {
                    $authorQuery->where('name', 'like', "%{$search}%");
                })
                ->orWhere('series', 'like', "%{$search}%");
        }

        $books = $query->orderBy('title')->paginate(20);
        return view('admin.books.index', compact('books', 'search'));
    }

    public function create(Request $request)
    {
        $initial = new Book();
        if ($request->path) {
            $initial->directory_path = $request->path;
            $this->processGenres($initial);
            $this->processAuthors($initial);
            $this->processSeriesOrBooks($initial);
            $initial->author_id = $initial->author->id;
            $initial->genre_id = $initial->genre->id;
            $initial->series_id = $initial->series->id;
        }
        $genreList = Genre::all();
        $authorList = Author::all();
        $seriesList = Series::all();
        return view(
            'admin.books.create_form',
            compact('genreList', 'authorList', 'seriesList', 'initial')
        );
    }
    public function import()
    {
        return view('admin.books.import_directory');
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'author_id' => 'required|string|max:255',
            'series' => 'nullable|string|max:255',
            'genre_id' => 'required|string|max:255',
            'cover_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'description' => 'nullable|string',
            'book_files' => 'required|array',
            'book_files.*' => 'file|max:10240', // Example: 10MB limit per file
            'type' => 'required|in:ebook,audiobook',
            'publication_date' => 'nullable|date',  //Validate publication date.
        ]);

        $book = new Book();
        $book->title = $request->title;
        $book->author_id = $request->author_id;
        $book->genre_id = $request->genre_id;
        $book->description = $request->description;
        $book->type = $request->type;
        $book->date_added = Carbon::now();  //Set to todays date.
        $book->publication_date = $request->publication_date; //Publication Date
        $book->series_id = $request->series_id;
        $book->series_number = $request->series_number;

        //Handle Cover Image Upload
        if ($request->hasFile('cover_image')) {
            $image = $request->file('cover_image');
            $imageName = time() . '.' . $image->getClientOriginalExtension();
            $image->storeAs('public/covers', $imageName); //Store in storage/app/public/covers

            $book->cover_image = 'covers/' . time() . '.' . $image->getClientOriginalExtension();  //Store time
        }

        $book->save();

        // Handle Book File Uploads and directory creation
        $storagePath = env('BOOK_STORAGE_PATH');

        if (!$storagePath) {
            // Handle the case where BOOK_STORAGE_PATH is not defined
            Log::error('BOOK_STORAGE_PATH is not defined in the .env file.');
            return back()->withErrors(['error' => 'Configuration error: BOOK_STORAGE_PATH is not defined.']);
        }
        if ($book->series) {
            $bookDirectory = implode('/', [$book->genre->name, $book->author->name, $book->series->name, $book->series_number . ' ' . $book->title]);
        } else {
            $bookDirectory = implode('/', [$book->genre->name, $book->author->name, $book->title]);
        }
        Storage::makeDirectory($storagePath . '/' . $bookDirectory); // Creates a directory in storage/app/public/books/{book_id}

        $book->directory_path = $bookDirectory;  //relative path to the book directory

        if ($request->hasFile('book_files')) {
            $files = $request->file('book_files');
            foreach ($files as $file) {
                $filename = $file->getClientOriginalName();
                $file->storeAs($storagePath . '/' . $bookDirectory, $filename);
            }
        }

        $book->save();

        return redirect()->route('admin.books.show', $book)->with('success', 'Book created successfully!');
    }

    public function edit(Book $book)
    {
        $genres = Genre::all();
        $authors = Author::all();
        return view('admin.books.edit', compact('book', 'genres', 'authors'));
    }

    public function update(Request $request, Book $book)
    {
        $request->validate([
            'title' => [
                'required',
                'string',
                'max:255',
            ],
            'author' => 'required|string|max:255',
            'series' => 'nullable|string|max:255',
            'genre' => 'required|string|max:255',
            'cover_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'description' => 'nullable|string',
            'series_number' => 'nullable|numeric',
            'type' => 'required|in:ebook,audiobook',
            'publication_date' => 'nullable|date',
        ]);

        $book->update($request->all());

        return redirect()->route('admin.books.show', $book)->with('success', 'Book updated successfully!');
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

    private function processGenres(Book &$book)
    {
        $parts = explode("/", $book->directory_path);

        $genre = Genre::where('name', 'like', "%{$parts[1]}%")->first();
        if ($genre) {
            $book->genre = $genre;
        } else {
            $genre = Genre::create(['name' => $parts[1]]);
        }
    }

    private function processAuthors(Book &$book)
    {
        $parts = explode("/", $book->directory_path);

        $author = Author::where('name', 'like', "%{$parts[2]}%")->first();
        if ($author) {
            $book->author = $author;
        } else {
            $author = Author::create(['name' => $parts[2]]);
        }
    }

    private function processSeriesOrBooks(Book &$book)
    {
        $parts = explode("/", $book->directory_path);

        if (count($parts) == 5) {
            $series = Series::where('name', 'like', "%{$parts[3]}%")->first();
            if ($series) {
                $book->series = $series;
            } else {
                $series = Series::create(['name' => $parts[3]]);
            }
            $book->series = $series;
            if (preg_match("/([0-9]+) (.*)/", $parts[4], $matches)) {
                $bookRec = Book::where('title', 'like', "%{$matches[2]}%")
                    ->where("author_id", $book->author_id)
                    ->first();
                if ($bookRec) {
                    $book->book = $bookRec;
                }
                $book->seriesNumber = $matches[1];
                $book->title = $matches[2];
            }
        } else {
            $bookRec = Book::where('title', 'like', "%{$parts[3]}%")
                ->where("author_id", $book->author_id)
                ->first();
            if ($bookRec) {
                $book->book = $bookRec;
            }
            $book->title = $parts[3];
        }
    }
}
