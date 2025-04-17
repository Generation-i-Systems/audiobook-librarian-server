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
use getID3;

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

    public function create()
    {
        $genres = Genre::all();
        $authors = Author::all();
        return view('books.create', compact('book', 'genres', 'authors'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'author_id' => 'required|exists:authors,id',
            'series' => 'nullable|string|max:255',
            'genre_id' => 'required|exists:genres,id',
            'cover_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'description' => 'nullable|string',
            'book_files' => 'required|array',
            'book_files.*' => 'file|max:10240', // Example: 10MB limit per file
            'type' => 'required|in:ebook,audiobook'
        ]);

        $book = new Book();
        $book->title = $request->title;
        $book->author_id = $request->author_id;
        $book->series = $request->series;
        $book->genre_id = $request->genre_id;
        $book->description = $request->description;
        $book->type = $request->type;

         //Handle Cover Image Upload
        if ($request->hasFile('cover_image')) {
            $image = $request->file('cover_image');
            $imageName = time() . '.' . $image->getClientOriginalExtension();
            $image->storeAs('public/covers', $imageName); //Store in storage/app/public/covers

            $book->cover_image = 'covers/' . $imageName;  //Store relative path to the image for retrival.
        }

        $book->save();

           // Handle Book File Uploads and directory creation
        $bookDirectory = 'books/' . $book->id;
        Storage::makeDirectory('public/' . $bookDirectory); // Creates a directory in storage/app/public/books/{book_id}
        $book->directory_path = $bookDirectory;  //relative path to the book directory

        if ($request->hasFile('book_files')) {
            $files = $request->file('book_files');
            foreach ($files as $file) {
                $filename = $file->getClientOriginalName();
                $file->storeAs('public/' . $bookDirectory, $filename);
            }
        }

        $book->save();

        return redirect()->route('books.show', $book)->with('success', 'Book created successfully!');
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

    public function edit(Book $book)
    {
        $genres = Genre::all();
        $authors = Author::all();
        return view('books.edit', compact('book', 'genres', 'authors'));
    }

    public function update(Request $request, Book $book)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'author_id' => 'required|exists:authors,id',
            'series' => 'nullable|string|max:255',
            'genre_id' => 'required|exists:genres,id', //Check if genre exists, new entry
            'cover_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'description' => 'nullable|string',
            'type' => 'required|in:ebook,audiobook'
        ]);

         //Handle Cover Image Upload
        if ($request->hasFile('cover_image')) {
            $image = $request->file('cover_image');
            $imageName = time() . '.' . $image->getClientOriginalExtension();
            $image->storeAs('public/covers', $imageName); //Store in storage/app/public/covers

            $book->cover_image = 'covers/' . $imageName;  //Store relative path to the image for retrival.
        }

        //Updates existing book record with all non null entries.
        $book->update( $request->except(['cover_image']));

        return redirect()->route('books.show', $book)->with('success', 'Book updated successfully!');
    }

    public function import()
    {
        return view('books.import');
    }

     public function importFromTitle()
    {
        return view('books.import_from_title');
    }

    public function searchGoogleBooks(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'author' => 'nullable|string|max:255', //Added author filter for better results
        ]);

        $title = $request->input('title');
        $author = $request->input('author');
        $query = $title;

        if ($author) {
            $query = "$title author:$author";
        }

        $results = $this->googleBooksApiService->searchBooks($query);

        if (isset($results['items'])) {
            return view('books.search_results', ['books' => $results['items']]);
        } else {
            return back()->with('error', 'No books found.');
        }
    }

    public function importFromGoogleBooks(Request $request)
    {
        $request->validate([
            'volume_id' => 'required|string',
        ]);

        $volumeId = $request->input('volume_id');
        $bookDetails = $this->googleBooksApiService->getBookDetails($volumeId);

        if ($bookDetails) {
            // Extract relevant information from $bookDetails
            $title = $bookDetails['volumeInfo']['title'] ?? 'Untitled';
            $authors = $bookDetails['volumeInfo']['authors'] ?? ['Unknown Author'];
            $author = implode(', ', $authors);  //Concatenate authors array
            $description = $bookDetails['volumeInfo']['description'] ?? null;
            $genre = $bookDetails['volumeInfo']['categories'][0] ?? 'Unknown Genre'; //Choose first option
            $genreModel = Genre::firstOrCreate(['name' => $genre]);  //Create genre entry if needed
            $coverLink = $bookDetails['volumeInfo']['imageLinks']['thumbnail'] ?? null;  //Cover link
            $coverImage = null; // Initialize cover image to null

            if ($coverLink) {
                $coverImage = $this->saveCoverImage($coverLink);  //Save to the storage and get a relative link.
            }

            $book = Book::create([
                'title' => $title,
                'author' => $author,
                'genre_id' => $genreModel->id, //ID instead of the value
                'description' => $description,
                'cover_image' => $coverImage
                // Add other fields as needed
            ]);

            return redirect()->route('books.show', $book)->with('success', 'Book imported successfully!');
        } else {
            return back()->with('error', 'Failed to retrieve book details from Google Books.');
        }
    }

    private function saveCoverImage($imageUrl)
    {
        try {
            $imageContents = file_get_contents($imageUrl);  //Downloads contents

            if ($imageContents === false) {
                return null;  //Error.
            }

            $filename = 'covers/' . uniqid() . '.jpg'; // Generate a unique file name
            Storage::disk('public')->put($filename, $imageContents); //Store in storage/app/public/covers
            return $filename;

        } catch (\Exception $e) {
            return null;
        }
    }

    public function processImport(Request $request)
    {
        $request->validate([
            'library_path' => 'required|string',
        ]);

        $libraryPath = $request->input('library_path');

        if (!is_dir($libraryPath)) {
            return back()->withErrors(['library_path' => 'Invalid library path.']);
        }

        $this->importBooksFromDirectory($libraryPath);

        return redirect()->route('books.index')->with('success', 'Books imported successfully.');
    }

    private function importBooksFromDirectory($libraryPath)
    {
        $genres = scandir($libraryPath);
        foreach ($genres as $genre) {
            if ($genre === '.' || $genre === '..') continue;
            $genrePath = $libraryPath . '/' . $genre;

            if (is_dir($genrePath)) {
                $authors = scandir($genrePath);
                foreach ($authors as $author) {
                    if ($author === '.' || $author === '..') continue;
                    $authorPath = $genrePath . '/' . $author;

                    if (is_dir($authorPath)) {
                        $seriesOrBooks = scandir($authorPath);

                        foreach ($seriesOrBooks as $seriesOrBook) {
                            if ($seriesOrBook === '.' || $seriesOrBook === '..') continue;
                            $seriesOrBookPath = $authorPath . '/' . $seriesOrBook;

                            if (is_dir($seriesOrBookPath)) { //Could be series or Book
                                $bookPath = $seriesOrBookPath;
                                $series = null;
                                $bookDirName = $seriesOrBook;

                                $files = scandir($seriesOrBookPath);
                                $bookTitle = null;
                                foreach ($files as $file) {
                                    if ($file === '.' || $file === '..') continue;
                                    if (is_dir($seriesOrBookPath . '/' . $file)) {
                                        $series = $seriesOrBook;
                                        $bookDirName = $file;
                                        $bookPath = $seriesOrBookPath . '/' . $file;
                                        break;
                                    } else {
                                        $bookTitle = $seriesOrBook;
                                    }
                                }

                                if (!$bookTitle) $bookTitle = $bookDirName;

                                $this->createBook($genre, $author, $series, $bookTitle, $bookPath);

                            }
                        }
                    }
                }
            }
        }

        private function createBook($genre, $author, $series, $title, $directoryPath)
        {
            $authorModel = Author::firstOrCreate(['name' => $author]);
             // Check for audio files in the directory
            $audioFiles = Storage::files('public/'.$directoryPath);
            $audioFiles = array_filter($audioFiles, function ($file) {
                $extension = pathinfo($file, PATHINFO_EXTENSION);
                return in_array(strtolower($extension), ['mp3', 'm4b']);
            });

            $tagData = null;

            if (!empty($audioFiles)) {
                $tagData = $this->extractTagData(Storage::path($audioFiles[0])); // Use the first audio file
            }

            // Create book record in the database
            $genreModel = Genre::firstOrCreate(['name' => $genre]);
            Book::create([
                'title' => $title,
                'author_id' => $authorModel->id,
                'series' => $series,
                'genre_id' => $genreModel->id, //ID instead of the value
                'directory_path' => $directoryPath, // relative path from storage folder
                'type' => 'audiobook',  // You can auto-detect file types later, or have a config option.
                'description' => $tagData['description'] ?? null,
            ]);
        }

        private function extractTagData($filePath)
        {
            $process = new Process([
                'ffmpeg',
                '-i',
                $filePath,
                '-f',
                'ffmetadata',
                'pipe:1'  // Output to standard output
            ]);

            $process->run();

            if (!$process->isSuccessful()) {
                return []; // Return empty array if FFmpeg fails
            }

            $output = $process->getOutput();
            $lines = explode("\n", $output);

            $tags = [];
            foreach ($lines as $line) {
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2); // Limit to 2 parts in case value also contains '='
                    $tags[trim($key)] = trim($value);
                }
            }

            $title = $tags['title'] ?? null;
            $artist = $tags['artist'] ?? null;
            $album = $tags['album'] ?? null;
            $comment = $tags['comment'] ?? $tags['description'] ?? null;

            // Check if tags match the directory structure
            $directoryPath = dirname($filePath);
            $tagMatch = true;

             if($artist && !str_contains(strtolower($directoryPath), strtolower($artist))){
                $tagMatch = false;
            }

            if($album && !str_contains(strtolower($directoryPath), strtolower($album))){
                $tagMatch = false;
            }

            return [
                'title' => $title,
                'artist' => $artist,
                'album' => $album,
                'description' => $comment,
                'tagMatch' => $tagMatch,
            ];
        }
    }
