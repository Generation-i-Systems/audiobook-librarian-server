<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
                    $authorQuery->where('name', 'like', "%{$searchTerm}%");
                })
                ->orWhere('series', 'like', "%{$searchTerm}%");
        }

        $books = $query->orderBy('title')->paginate(20);
        return view('admin.books.index', compact('books', 'search'));
    }

    public function create()
    {
        $genres = Genre::all();
        $authors = Author::all();
        return view('admin.books.create', compact('genres', 'authors'));
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
            'type' => 'required|in:ebook,audiobook',
            'publication_date' => 'nullable|date',  //Validate publication date.
        ]);

        $book = new Book();
        $book->title = $request->title;
        $book->author_id = $request->author_id;
        $book->series = $request->series;
        $book->genre_id = $request->genre_id;
        $book->description = $request->description;
        $book->type = $request->type;
        $book->date_added = Carbon::now();  //Set to todays date.
        $book->publication_date = $request->publication_date; //Publication Date

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
        $storagePath = env('BOOK_STORAGE_PATH');

        if (!$storagePath) {
            // Handle the case where BOOK_STORAGE_PATH is not defined
            Log::error('BOOK_STORAGE_PATH is not defined in the .env file.');
            return back()->withErrors(['error' => 'Configuration error: BOOK_STORAGE_PATH is not defined.']);
        }
        $bookDirectory = 'books/' . $book->id;
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
            'title' => 'required|string|max:255',
            'author_id' => 'required|exists:authors,id',
            'series' => 'nullable|string|max:255',
            'genre_id' => 'required|exists:genres,id', //Check if genre exists, new entry
            'cover_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'description' => 'nullable|string',
            'type' => 'required|in:ebook,audiobook',
            'publication_date' => 'nullable|date', //Publication Date
        ]);

        //Handle Cover Image Upload
        if ($request->hasFile('cover_image')) {
            $image = $request->file('cover_image');
            $imageName = time() . '.' . $image->getClientOriginalExtension();
            $image->storeAs('public/covers', $imageName); //Store in storage/app/public/covers

            $book->cover_image = 'covers/' . $imageName;  //Store relative path to the image for retrival.
        }

        //Updates existing book record with all non null entries.
        $book->update($request->except(['cover_image']));

        return redirect()->route('admin.books.show', $book)->with('success', 'Book updated successfully!');
    }

    public function importFromTitle()
    {
        return view('admin.books.import_from_title');
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
            return view('admin.books.search_results', ['books' => $results['items']]);
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

            $authorModel = Author::firstOrCreate(['name' => $author]);
            $book = Book::create([
                'title' => $title,
                'author_id' => $authorModel->id,
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

        return redirect()->route('admin.books.index')->with('success', 'Books imported successfully.');
    }

    private function importBooksFromDirectory($libraryPath)
    {
        $this->processGenres($libraryPath);
    }

    private function processGenres($libraryPath)
    {
        $genres = $this->scanDirectory($libraryPath);
        foreach ($genres as $genre) {
            if ($genre === '.' || $genre === '..')
                continue;
            $genrePath = $libraryPath . '/' . $genre;

            if (is_dir($genrePath)) {
                $this->processAuthors($genrePath, $genre);
            }
        }
    }

    private function processAuthors($genrePath, $genre)
    {
        $authors = $this->scanDirectory($genrePath);
        foreach ($authors as $author) {
            if ($author === '.' || $author === '..')
                continue;
            $authorPath = $genrePath . '/' . $author;

            if (is_dir($authorPath)) {
                $this->processSeriesOrBooks($authorPath, $genre, $author);
            }
        }
    }

    private function processSeriesOrBooks($authorPath, $genre, $author)
    {
        $seriesOrBooks = $this->scanDirectory($authorPath);
        foreach ($seriesOrBooks as $seriesOrBook) {
            if ($seriesOrBook === '.' || $seriesOrBook === '..')
                continue;
            $seriesOrBookPath = $authorPath . '/' . $seriesOrBook;

            if (is_dir($seriesOrBookPath)) { //Could be series or Book
                $bookPath = $seriesOrBookPath;
                $series = null;
                $bookDirName = $seriesOrBook;

                $files = $this->scanDirectory($seriesOrBookPath);
                $bookTitle = null;
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..')
                        continue;
                    if (is_dir($seriesOrBookPath . '/' . $file)) {
                        $series = $seriesOrBook;
                        $bookDirName = $file;
                        $bookPath = $seriesOrBookPath . '/' . $file;
                        break;
                    } else {
                        $bookTitle = $seriesOrBook;
                    }
                }

                if (!$bookTitle)
                    $bookTitle = $bookDirName;

                $this->createBook($genre, $author, $series, $bookTitle, $bookPath);
            }
        }
    }

    private function scanDirectory($path)
    {
        if (!is_dir($path)) {
            Log::error("Invalid directory in scanDirectory: $path");
            return []; //Return an empty array
        }

        $files = scandir($path);

        if ($files === false) {
            Log::error("Failed to scan directory: $path");
            return [];  // Return an empty array on failure
        }

        return $files;
    }

    private function createBook($genre, $author, $series, $title, $directoryPath)
    {
        $authorModel = Author::firstOrCreate(['name' => $author]);
        // Check for audio files in the directory
        $storagePath = env('BOOK_STORAGE_PATH');
        if (!$storagePath) {
            Log::error('BOOK_STORAGE_PATH is not defined in the .env file.');
            return back()->withErrors(['error' => 'Configuration error: BOOK_STORAGE_PATH is not defined.']);
        }
        $audioFiles = Storage::files($directoryPath);
        $audioFiles = array_filter($audioFiles, function ($file) {
            $extension = pathinfo($file, PATHINFO_EXTENSION);
            return in_array(strtolower($extension), ['mp3', 'm4b', 'm4a']);
        });

        $tagData = null;

        if (!empty($audioFiles)) {
            $tagData = $this->extractTagData($audioFiles[0]); // Use the first audio file
        }

        // Create book record in the database
        $genreModel = Genre::firstOrCreate(['name' => $genre]);
        $book = Book::create([
            'title' => $title,
            'author_id' => $authorModel->id,
            'series' => $series,
            'genre_id' => $genreModel->id, //ID instead of the value
            'directory_path' => $directoryPath, // relative path from storage folder
            'type' => 'audiobook',  // You can auto-detect file types later, or have a config option.
            'description' => $tagData['description'] ?? null,
            'date_added' => Carbon::now(),  //Set to todays date.
        ]);
    }

    private function extractTagData($filePath)
    {
        $storagePath = env('BOOK_STORAGE_PATH');
        if (!$storagePath) {
            Log::error('BOOK_STORAGE_PATH is not defined in the .env file.');
            return back()->withErrors(['error' => 'Configuration error: BOOK_STORAGE_PATH is not defined.']);
        }
        $directoryPath = dirname($filePath);
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
        $tagMatch = true;

        if ($artist && !str_contains(strtolower($directoryPath), strtolower($artist))) {
            $tagMatch = false;
        }

        if ($album && !str_contains(strtolower($directoryPath), strtolower($album))) {
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

    public function destroy(Book $book)
    {
        // Delete the book
        $book->delete();

        // Redirect to the admin index page
        return redirect()->route('admin.books.index')->with('success', 'Book deleted successfully.');
    }
    public function directoryBrowser(Request $request)
    {
        $storagePath = env('BOOK_STORAGE_PATH');

        if (!$storagePath) {
            Log::error('BOOK_STORAGE_PATH is not defined in the .env file.');
            return response()->json(['error' => 'The book store path is invalid. Verify the .env exists and the value is valid.'], 400);
        }
        if (!is_dir($storagePath)) {
            Log::error('BOOK_STORAGE_PATH is not a directory');
            return response()->json(['error' => 'The book store path is invalid. Verify is_dir is not conflicting with an existing function.'], 400);
        }

        $path = $request->input('path', $storagePath);
        if (!is_dir($path)) {
            Log::error('Requested Directory path in BOOK_STORAGE_PATH is invalid.');
            return response()->json(['error' => ' The path directory requested is not valid.'], 400);
        }

        $files = scandir($path);
        if ($files === false) {
            Log::error('Attempt to perform a scandir but failed to get value.');
            return response()->json(['error' => 'The scan for all files has been invalid to be done.'], 400);
        }
        $data = [];

        foreach ($files as $file) {
            if ($file === '.' || $file === '..')
                continue;

            $filePath = $path . '/' . $file;

            if (is_dir($filePath)) {
                $data[] = [
                    'type' => 'directory',
                    'name' => $file,
                    'path' => $filePath,
                ];
            } else {
                $extension = pathinfo($file, PATHINFO_EXTENSION);
                //Show the file if this is the valid one.
                if (in_array(strtolower($extension), ['mp3', 'm4b', 'm4a'])) {
                    $data[] = [
                        'type' => 'file',
                        'name' => $file,
                        'path' => $filePath,
                    ];
                }

            }
        }

        return response()->json($data);
    }

    private function checkStoragePermissions(): bool
    {
        return true;
    }
}
