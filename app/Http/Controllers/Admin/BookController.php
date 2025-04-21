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
use App\Traits\GoogleBooksTrait;
use App\Traits\BookImportTrait;

class BookController extends Controller
{
    use GoogleBooksTrait, BookImportTrait;

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
            if ($initial->series) {
                $initial->series_id = $initial->series->id;
            }
        }
        $coverCandidates = [];
        $coverAuto = null;
        $directory_path = $request->old('directory_path') ?? ($initial->directory_path ?? '');
        if ($directory_path) {
            list($coverAuto, $coverCandidates) = $this->findCoverImageCandidate($directory_path);
            // If no cover and no images, try m4b extraction
            if (empty($coverAuto) && empty($coverCandidates)) {
                $storagePath = env('BOOK_STORAGE_PATH');
                $dir = rtrim($storagePath, '/') . '/' . ltrim($directory_path, '/');
                if (is_dir($dir)) {
                    $m4bs = array_values(array_filter(scandir($dir), function($f) use ($dir) {
                        return is_file($dir . '/' . $f) && strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'm4b';
                    }));
                    if ($m4bs) {
                        $firstM4b = $dir . '/' . $m4bs[0];
                        $coverFile = $this->extractCoverFromM4B($firstM4b, $dir);
                        if ($coverFile) {
                            $coverAuto = $coverFile;
                        }
                        $tags = $this->extractTagData($firstM4b);
                        if (!empty($tags['description'])) {
                            $initial->description = $tags['description'];
                        }
                    }
                    // Also check metadata.abs for description and year
                    $meta = $this->extractMetadataAbs($dir);
                    if (!empty($meta['description']) && empty($initial->description)) {
                        $initial->description = $meta['description'];
                    }
                    if (!empty($meta['year']) && empty($initial->published_year)) {
                        $initial->published_year = $meta['year'];
                    }
                }
            }
        }
        $genreList = Genre::all();
        $authorList = Author::all();
        $seriesList = Series::all();
        if ($request->ajax()) {
            return view('admin.books.create_form', compact('genreList', 'authorList', 'seriesList', 'initial', 'coverCandidates', 'coverAuto', 'directory_path'))
                ->with('isModal', true)
                ->with('layout', 'layouts.modal');
        }
        return view('admin.books.create_form', compact('genreList', 'authorList', 'seriesList', 'initial', 'coverCandidates', 'coverAuto', 'directory_path'));
    }

    public function import()
    {
        return view('admin.books.import_directory');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'author_id' => 'required|string|max:255',
            'series_id' => 'nullable|string|max:255',
            'series_number' => 'nullable|numeric',
            'genre_id' => 'required|string|max:255',
            'cover_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'description' => 'nullable|string',
            'directory_path' => 'nullable|string|max:255',
            'type' => 'required|in:ebook,audiobook',
            'published_year' => 'nullable|digits:4',
        ]);

        $book = new Book($validated);

        // Handle cover image upload or import from autofill
        if ($request->hasFile('cover_image')) {
            $ext = $request->file('cover_image')->getClientOriginalExtension();
            $coverPath = ($book->directory_path ? trim($book->directory_path, '/') . '/' : '') . 'cover.' . $ext;
            \Storage::disk('public')->put($coverPath, file_get_contents($request->file('cover_image')->getRealPath()));
            $book->cover_image = $coverPath;
        } elseif ($request->input('cover_image_url')) {
            // Ensure the book's directory exists before importing
            $dirPath = $book->directory_path;
            if (!$dirPath) {
                // Predict the directory as it will be set below
                if ($request->directory_path) {
                    $dirPath = $request->directory_path;
                } else {
                    if (!empty($book->series_id)) {
                        $series = \App\Models\Series::find($book->series_id);
                        $seriesName = $series ? $series->name : '';
                        $bookDirectory = '/' . implode('/', [
                            $book->genre_id ? \App\Models\Genre::find($book->genre_id)->name : '',
                            $book->author_id ? \App\Models\Author::find($book->author_id)->name : '',
                            $seriesName,
                            ($book->series_number ? $book->series_number . ' ' : '') . $book->title
                        ]);
                    } else {
                        $bookDirectory = '/' . implode('/', [
                            $book->genre_id ? \App\Models\Genre::find($book->genre_id)->name : '',
                            $book->author_id ? \App\Models\Author::find($book->author_id)->name : '',
                            $book->title
                        ]);
                    }
                    $dirPath = $bookDirectory;
                }
            }
            $storagePath = env('BOOK_STORAGE_PATH');
            if ($storagePath && $dirPath) {
                \Storage::makeDirectory($storagePath . '/' . ltrim($dirPath, '/'));
            }
            $coverPath = $this->importCoverImageFromUrl($request->input('cover_image_url'), $dirPath);
            if ($coverPath) {
                $book->cover_image = $coverPath;
            }
        } elseif ($request->filled('cover_image_candidate')) {
            // Use selected candidate from directory
            $candidate = $request->input('cover_image_candidate');
            $book->cover_image = ltrim($book->directory_path, '/') . '/' . $candidate;
        }

        $book->save();

        // Handle Book File Uploads and directory creation
        $storagePath = env('BOOK_STORAGE_PATH');

        if (!$storagePath) {
            // Handle the case where BOOK_STORAGE_PATH is not defined
            Log::error('BOOK_STORAGE_PATH is not defined in the .env file.');
            return back()->withErrors(['error' => 'Configuration error: BOOK_STORAGE_PATH is not defined.']);
        }
        if ($request->directory_path) {
            $bookDirectory = $request->directory_path;
        } else {
            if ($book->series) {
                $bookDirectory = '/' . implode('/', [$book->genre->name, $book->author->name, $book->series->name, $book->series_number . ' ' . $book->title]);
            } else {
                $bookDirectory = '/' . implode('/', [$book->genre->name, $book->author->name, $book->title]);
            }
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

    public function show(Book $book)
    {
        return view('admin.books.show', compact('book'));
    }

    public function edit(Book $book, Request $request)
    {
        $authorList = Author::all();
        $genreList = Genre::all();
        $seriesList = Series::all();

        // If no cover image, find image candidates in directory
        $coverCandidates = [];
        $coverAuto = null;
        if (empty($book->cover_image) && $book->directory_path) {
            list($coverAuto, $coverCandidates) = $this->findCoverImageCandidate($book->directory_path);
            // If no cover and no images, try m4b extraction
            if (empty($coverAuto) && empty($coverCandidates)) {
                $storagePath = env('BOOK_STORAGE_PATH');
                $dir = rtrim($storagePath, '/') . '/' . ltrim($book->directory_path, '/');
                if (is_dir($dir)) {
                    $m4bs = array_values(array_filter(scandir($dir), function($f) use ($dir) {
                        return is_file($dir . '/' . $f) && strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'm4b';
                    }));
                    if ($m4bs) {
                        $firstM4b = $dir . '/' . $m4bs[0];
                        $coverFile = $this->extractCoverFromM4B($firstM4b, $dir);
                        if ($coverFile) {
                            $coverAuto = $coverFile;
                            $book->cover_image = ltrim($book->directory_path, '/') . '/' . $coverFile;
                        }
                        if (empty($book->description)) {
                            $tags = $this->extractTagData($firstM4b);
                            if (!empty($tags['description'])) {
                                $book->description = $tags['description'];
                            }
                        }
                    }
                    // Also check metadata.abs for description and year
                    $meta = $this->extractMetadataAbs($dir);
                    if (!empty($meta['description']) && empty($book->description)) {
                        $book->description = $meta['description'];
                    }
                    if (!empty($meta['year']) && empty($book->published_year)) {
                        $book->published_year = $meta['year'];
                    }
                }
            }
            if ($coverAuto) {
                $book->cover_image = ltrim($book->directory_path, '/') . '/' . $coverAuto;
            }
        }

        $isModal = $request->ajax() || $request->get('modal') == 1;
        $layout = $isModal ? 'layouts.modal' : 'layouts.app';
        return view(
            'admin.books.edit',
            compact('genreList', 'authorList', 'seriesList', 'book', 'isModal', 'layout', 'coverCandidates', 'coverAuto')
        );
    }

    public function update(Request $request, Book $book)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'author_id' => 'required|string|max:255',
            'series_id' => 'nullable|string|max:255',
            'series_number' => 'nullable|numeric',
            'genre_id' => 'required|string|max:255',
            'cover_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'description' => 'nullable|string',
            'directory_path' => 'nullable|string|max:255',
            'type' => 'required|in:ebook,audiobook',
            'published_year' => 'nullable|digits:4',
        ]);

        $book->fill($validated);

        // DEBUG: Log cover_image_url value
        \Log::info('Book Edit: cover_image_url = ' . $request->input('cover_image_url'));

        // Handle cover image upload or import from autofill
        if ($request->hasFile('cover_image')) {
            $ext = $request->file('cover_image')->getClientOriginalExtension();
            $coverPath = ($book->directory_path ? trim($book->directory_path, '/') . '/' : '') . 'cover.' . $ext;
            \Storage::disk('public')->put($coverPath, file_get_contents($request->file('cover_image')->getRealPath()));
            $book->cover_image = $coverPath;
            \Log::info('Book Edit: cover_image uploaded via file to ' . $coverPath);
        } elseif ($request->input('cover_image_path')) {
            $book->cover_image = $request->input('cover_image_path');
            \Log::info('Book Edit: cover_image_path = ' . $request->input('cover_image_path'));
        } elseif ($request->input('cover_image_url')) {
            \Log::info('Book Edit: Importing cover image from URL: ' . $request->input('cover_image_url'));
            // Ensure the book's directory exists before importing
            $dirPath = $book->directory_path;
            if (!$dirPath) {
                // Predict the directory as it will be set below
                if ($request->directory_path) {
                    $dirPath = $request->directory_path;
                } else {
                    if (!empty($book->series_id)) {
                        $series = \App\Models\Series::find($book->series_id);
                        $seriesName = $series ? $series->name : '';
                        $bookDirectory = '/' . implode('/', [
                            $book->genre_id ? \App\Models\Genre::find($book->genre_id)->name : '',
                            $book->author_id ? \App\Models\Author::find($book->author_id)->name : '',
                            $seriesName,
                            ($book->series_number ? $book->series_number . ' ' : '') . $book->title
                        ]);
                    } else {
                        $bookDirectory = '/' . implode('/', [
                            $book->genre_id ? \App\Models\Genre::find($book->genre_id)->name : '',
                            $book->author_id ? \App\Models\Author::find($book->author_id)->name : '',
                            $book->title
                        ]);
                    }
                    $dirPath = $bookDirectory;
                }
            }
            $storagePath = env('BOOK_STORAGE_PATH');
            if ($storagePath && $dirPath) {
                \Storage::makeDirectory($storagePath . '/' . ltrim($dirPath, '/'));
            }
            $coverPath = $this->importCoverImageFromUrl($request->input('cover_image_url'), $dirPath);
            \Log::info('Book Edit: importCoverImageFromUrl returned: ' . ($coverPath ?: 'null'));
            if ($coverPath) {
                $book->cover_image = $coverPath;
            }
        } elseif ($request->filled('cover_image_candidate')) {
            // Use selected candidate from directory
            $candidate = $request->input('cover_image_candidate');
            $book->cover_image = ltrim($book->directory_path, '/') . '/' . $candidate;
        }

        $book->save();

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

    public function autofillFromGoogleBooks(Request $request)
    {
        $title = $request->input('title');
        $author = $request->input('author');
        if (!$title || !$author) {
            return response()->json(['error' => 'Title and author are required.'], 400);
        }
        $query = $title . ' ' . $author;
        $results = $this->searchGoogleBooks($query);
        if (!empty($results['items'][0])) {
            $info = $results['items'][0]['volumeInfo'];
            $autofill = [
                'published_year' => isset($info['publishedDate']) ? substr($info['publishedDate'], 0, 4) : '',
                'description' => $info['description'] ?? '',
                'cover_image_url' => $info['imageLinks']['thumbnail'] ?? '',
            ];
            return response()->json($autofill);
        }
        return response()->json(['error' => 'No book found.'], 404);
    }

    /**
     * Serve an image from BOOK_STORAGE_PATH for preview (secure).
     */
    public function previewImage(Book $book, $filename)
    {
        $storagePath = env('BOOK_STORAGE_PATH');
        $dir = rtrim($storagePath, '/') . '/' . ltrim($book->directory_path, '/');
        $fullPath = $dir . '/' . $filename;
        if (!file_exists($fullPath)) {
            abort(404);
        }
        $mime = mime_content_type($fullPath);
        return response()->file($fullPath, [
            'Content-Type' => $mime,
            'Cache-Control' => 'no-store',
        ]);
    }

    private function processGenres(Book &$book)
    {
        $parts = explode("/", $book->directory_path);

        $genre = Genre::where('name', 'like', "%{$parts[1]}%")->first();
        if ($genre) {
            $book->genre = $genre;
        } else {
            $book->genre = Genre::create(['name' => $parts[1]]);
        }
    }

    private function processAuthors(Book &$book)
    {
        $parts = explode("/", $book->directory_path);

        $author = Author::where('name', 'like', "%{$parts[2]}%")->first();
        if ($author) {
            $book->author = $author;
        } else {
            $book->author = Author::create(['name' => $parts[2]]);
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
                $book->series = Series::create(['name' => $parts[3]]);
            }
            if (preg_match("/([0-9]+) (.*)/", $parts[4], $matches)) {
                $bookRec = Book::where('title', 'like', "%{$matches[2]}%")
                    ->where("author_id", $book->author_id)
                    ->first();
                if ($bookRec) {
                    $book->book = $bookRec;
                }
                $book->seriesNumber = $matches[1];
                $book->title = $matches[2];
            } else {
                $bookRec = Book::where('title', 'like', "%{$parts[4]}%")
                    ->where("author_id", $book->author_id)
                    ->first();
                if ($bookRec) {
                    $book->book = $bookRec;
                }
                $book->title = $parts[4];
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

    /**
     * Utility: Scan directory for images, prefer one with 'cover' in the name.
     * Returns [selected, candidates[]]
     */
    private function findCoverImageCandidate($directoryPath)
    {
        $storagePath = env('BOOK_STORAGE_PATH');
        $dir = rtrim($storagePath, '/') . '/' . ltrim($directoryPath, '/');
        if (!is_dir($dir))
            return [null, []];
        $images = [];
        $selected = null;
        foreach (scandir($dir) as $file) {
            if ($file === '.' || $file === '..')
                continue;
            $full = $dir . '/' . $file;
            if (!is_file($full))
                continue;
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']))
                continue;
            $images[] = $file;
            if (!$selected && stripos($file, 'cover') !== false) {
                $selected = $file;
            }
        }
        // If no 'cover' found, leave $selected null
        return [$selected, $images];
    }

    /**
     * Download and store a remote cover image, return the local path for storage in DB.
     */
    private function importCoverImageFromUrl($url, $directoryPath = null)
    {
        if (!$url)
            return null;
        try {
            $storagePath = env('BOOK_STORAGE_PATH'); // absolute path
            if (!$storagePath) {
                \Log::error('BOOK_STORAGE_PATH is not defined.');
                return null;
            }
            $fullDir = rtrim($storagePath, '/') . '/' . ltrim($directoryPath, '/');
            if (!is_dir($fullDir)) {
                if (!mkdir($fullDir, 0775, true) && !is_dir($fullDir)) {
                    \Log::error("importCoverImageFromUrl error: Unable to create directory at $fullDir");
                    return null;
                }
            }

            // Use cURL with a browser User-Agent
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3');
            $contents = curl_exec($ch);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);

            if ($contents === false || !$contents)
                return null;

            // Determine extension
            $ext = 'jpg';
            if (strpos($contentType, 'png') !== false)
                $ext = 'png';
            elseif (strpos($contentType, 'gif') !== false)
                $ext = 'gif';
            elseif (strpos($contentType, 'jpeg') !== false)
                $ext = 'jpg';

            $filename = 'cover.' . $ext;
            $fullPath = $fullDir . '/' . $filename;
            if (file_put_contents($fullPath, $contents) === false) {
                \Log::error("importCoverImageFromUrl error: Unable to write file $fullPath");
                return null;
            }
            // Return only the path relative to BOOK_STORAGE_PATH
            return (ltrim($directoryPath, '/') . '/' . $filename);
        } catch (\Exception $e) {
            \Log::error('importCoverImageFromUrl error: ' . $e->getMessage());
            return null;
        }
    }

    private function extractMetadataAbs($dir)
    {
        $metaFile = $dir . '/metadata.abs';
        if (!file_exists($metaFile)) {
            return [];
        }
        $meta = [];
        $lines = file($metaFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            list($key, $value) = explode(':', $line, 2);
            $meta[trim($key)] = trim($value);
        }
        return $meta;
    }
}
