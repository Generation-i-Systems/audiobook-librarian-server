<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use App\Services\GoogleBooksApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use ZipArchive;
use getID3;
use Illuminate\Support\Carbon;
use App\Traits\BookImportTrait;

class BookController extends Controller
{
    use BookImportTrait;

    protected $googleBooksApiService;

    private $storagePath;

    public function __construct(GoogleBooksApiService $googleBooksApiService)
    {
        $this->setGoogleBooksApiService($googleBooksApiService);
        $this->storagePath = env('BOOK_STORAGE_PATH');
    }

    public function index(Request $request)
    {
        $firestore = new FirestoreService();
        $books = $firestore->listBooks();

        // Filtering
        if ($request->filled('search')) {
            $search = strtolower($request->input('search'));
            $books = array_filter($books, function($book) use ($search) {
                return (isset($book['title']) && stripos($book['title'], $search) !== false)
                    || (isset($book['author_name']) && stripos($book['author_name'], $search) !== false);
            });
        }
        if ($request->filled('author_id')) {
            $books = array_filter($books, function($book) use ($request) {
                return isset($book['author_id']) && $book['author_id'] == $request->input('author_id');
            });
        }
        if ($request->filled('genre_id')) {
            $books = array_filter($books, function($book) use ($request) {
                return isset($book['genre_id']) && $book['genre_id'] == $request->input('genre_id');
            });
        }
        // Sorting
        $sort = $request->input('sort', 'recent_desc');
        $books = array_values($books);
        usort($books, function($a, $b) use ($sort) {
            switch ($sort) {
                case 'recent_desc':
                    return strtotime($b['created_at'] ?? 0) <=> strtotime($a['created_at'] ?? 0);
                case 'recent_asc':
                    return strtotime($a['created_at'] ?? 0) <=> strtotime($b['created_at'] ?? 0);
                case 'author_asc':
                    return strcmp($a['author_name'] ?? '', $b['author_name'] ?? '');
                case 'author_desc':
                    return strcmp($b['author_name'] ?? '', $a['author_name'] ?? '');
                case 'title_asc':
                    return strcmp($a['title'] ?? '', $b['title'] ?? '');
                case 'title_desc':
                    return strcmp($b['title'] ?? '', $a['title'] ?? '');
                case 'year_asc':
                    return ($a['published_year'] ?? 0) <=> ($b['published_year'] ?? 0);
                case 'year_desc':
                    return ($b['published_year'] ?? 0) <=> ($a['published_year'] ?? 0);
                default:
                    return strtotime($b['created_at'] ?? 0) <=> strtotime($a['created_at'] ?? 0);
            }
        });
        // Pagination
        $page = max(1, (int)$request->input('page', 1));
        $perPage = 20;
        $total = count($books);
        $books = array_slice($books, ($page - 1) * $perPage, $perPage);
        // Return view (update as needed for array pagination)
        return view('admin.books.index', [
            'books' => $books,
            'sort' => $sort,
            'total' => $total,
            'perPage' => $perPage,
            'currentPage' => $page
        ]);
    }

    public function create(Request $request)
    {
        $firestore = new FirestoreService();
        $initial = [
            'directory_path' => $request->path
        ];
        $coverCandidates = [];
        $coverAuto = null;
        $biggestCover = null;
        $biggestSize = 0;
        $directory_path = $request->old('directory_path') ?? $initial['directory_path'] ?? '';
        if ($directory_path) {
            [$coverAuto, $coverCandidates] = $this->findCoverImageCandidate($directory_path);
            // If no cover and no images, try m4b extraction
            if (empty($coverAuto) && empty($coverCandidates)) {
                $dir = rtrim($this->storagePath, '/') . '/' . ltrim($directory_path, '/');
                if (is_dir($dir)) {
                    $m4bs = array_values(array_filter(scandir($dir), function ($f) use ($dir) {
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
                            $initial['description'] = $tags['description'];
                        }
                    }
                    // Also check metadata.abs for description and year
                    $meta = $this->extractMetadataAbs($dir);
                    if (!empty($meta['description']) && empty($initial['description'])) {
                        $initial['description'] = $meta['description'];
                    }
                    if (!empty($meta['year']) && empty($initial['published_year'])) {
                        $initial['published_year'] = $meta['year'];
                    }
                }
            }
        }
        if ($directory_path && Storage::disk('books')->exists($directory_path)) {
            $files = Storage::disk('books')->files($directory_path);
            foreach ($files as $file) {
                if (preg_match('/\.(jpe?g|png|gif|svg)$/i', $file)) {
                    $candidate = basename($file);
                    $coverCandidates[] = $candidate;
                    $size = Storage::disk('books')->size($file);
                    if ($size > $biggestSize) {
                        $biggestSize = $size;
                        $biggestCover = $candidate;
                    }
                }
            }
        }
        $firestore = new FirestoreService();
        $genreList = $firestore->listGenres();
        $authorList = $firestore->listAuthors();
        $seriesList = $firestore->listSeries();
        if ($request->ajax()) {
            return view('admin.books.create_form', compact('genreList', 'authorList', 'seriesList', 'initial', 'coverCandidates', 'coverAuto', 'biggestCover', 'directory_path'))
                ->with('isModal', true)
                ->with('layout', 'layouts.modal');
        }
        return view('admin.books.create_form', compact('genreList', 'authorList', 'seriesList', 'initial', 'coverCandidates', 'coverAuto', 'biggestCover', 'directory_path'));
    }

    public function import()
    {
        return view('admin.books.import_directory');
    }

    public function store(Request $request)
    {
        $firestore = new FirestoreService();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'author_id' => 'required|string|max:255',
            'series_id' => 'nullable|string|max:255',
            'series_number' => 'nullable|numeric',
            'genre_id' => 'required|string|max:255',
            'cover_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'description' => 'nullable|string',
            'directory_path' => 'nullable|string|max:255',
            'published_year' => 'nullable|digits:4',
        ]);

        // Handle author_id: create if not numeric
        if (!is_numeric($validated['author_id'])) {
            $author = $firestore->findOrCreateAuthorByName($validated['author_id']);
            $validated['author_id'] = $author['id'];
        }
        // Handle series_id: create if not numeric and not empty
        if (!empty($validated['series_id']) && !is_numeric($validated['series_id'])) {
            $series = $firestore->findOrCreateSeriesByName($validated['series_id']);
            $validated['series_id'] = $series['id'];
        }

        $originalDirectoryPath = $request->input('original_directory_path');
        $newDirectoryPath = $validated['directory_path'] ?? null;
        if ($originalDirectoryPath && $newDirectoryPath && $originalDirectoryPath !== $newDirectoryPath) {
            $oldAbs = $this->storagePath . '/' . ltrim($originalDirectoryPath, '/');
            $newAbs = $this->storagePath . '/' . ltrim($newDirectoryPath, '/');
            if (is_dir($oldAbs) && !is_dir($newAbs)) {
                if (!@rename($oldAbs, $newAbs)) {
                    return back()->withErrors(['directory_path' => 'Failed to rename directory from ' . $originalDirectoryPath . ' to ' . $newDirectoryPath]);
                }
            }
        }
        // Handle cover image upload or import from autofill/candidate
        $coverImagePath = null;
        if ($request->hasFile('cover_image')) {
            $ext = $request->file('cover_image')->getClientOriginalExtension();
            $coverImagePath = ($validated['directory_path'] ? trim($validated['directory_path'], '/') . '/' : '') . 'cover.' . $ext;
            Storage::disk('books')->put($coverImagePath, file_get_contents($request->file('cover_image')->getRealPath()));
            $validated['cover_image'] = $coverImagePath;
        } elseif ($request->input('cover_image_url')) {
            // Generate dirPath if not provided
            $dirPath = $validated['directory_path'] ?? null;
            if (!$dirPath) {
                if (!empty($validated['series_id'])) {
                    $series = $firestore->getSeries($validated['series_id']);
                    $seriesName = $series ? $series->name : '';
                    $genreName = !empty($validated['genre_id']) ? (($g = $firestore->getGenre($validated['genre_id'])) ? $g['name'] : '') : '';
                    $authorName = !empty($validated['author_id']) ? (($a = $firestore->getAuthor($validated['author_id'])) ? $a['name'] : '') : '';
                    $dirPath = '/' . implode('/', [
                        $genreName,
                        $authorName,
                        $seriesName,
                        ($validated['series_number'] ? $validated['series_number'] . ' ' : '') . $validated['title']
                    ]);
                } else {
                    $genreName = !empty($validated['genre_id']) ? (($g = $firestore->getGenre($validated['genre_id'])) ? $g['name'] : '') : '';
                    $authorName = !empty($validated['author_id']) ? (($a = $firestore->getAuthor($validated['author_id'])) ? $a['name'] : '') : '';
                    $dirPath = '/' . implode('/', [
                        $genreName,
                        $authorName,
                        $validated['title']
                    ]);
                }
            }
            if ($this->storagePath && $dirPath) {
                Storage::makeDirectory($this->storagePath . '/' . ltrim($dirPath, '/'));
            }
            $coverImagePath = $this->importCoverImageFromUrl($request->input('cover_image_url'), $dirPath);
            if ($coverImagePath) {
                $validated['cover_image'] = $coverImagePath;
            }
        } elseif ($request->filled('cover_image_candidate')) {
            $candidate = $request->input('cover_image_candidate');
            $validated['cover_image'] = ltrim($validated['directory_path'], '/') . '/' . $candidate;
        }
        // Create book in Firestore with correct cover_image
        $bookId = $firestore->createBook($validated);

        // Handle Book File Uploads and directory creation
        if (!$this->storagePath) {
            // Handle the case where BOOK_STORAGE_PATH is not defined
            Log::error('BOOK_STORAGE_PATH is not defined in the .env file.');
            return back()->withErrors(['error' => 'Configuration error: BOOK_STORAGE_PATH is not defined.']);
        }
        if ($request->directory_path) {
            $bookDirectory = $request->directory_path;
        } else {
            $firestore = new FirestoreService();
            if (!empty($validated['series_id'])) {
                $series = $firestore->getSeries($validated['series_id']);
                $seriesName = $series ? $series['name'] : '';
                $genreName = !empty($validated['genre_id']) ? (($g = $firestore->getGenre($validated['genre_id'])) ? $g['name'] : '') : '';
                $authorName = !empty($validated['author_id']) ? (($a = $firestore->getAuthor($validated['author_id'])) ? $a['name'] : '') : '';
                $bookDirectory = '/' . implode('/', [
                    $genreName,
                    $authorName,
                    $seriesName,
                    ($validated['series_number'] ? $validated['series_number'] . ' ' : '') . $validated['title']
                ]);
            } else {
                $genreName = !empty($validated['genre_id']) ? (($g = $firestore->getGenre($validated['genre_id'])) ? $g['name'] : '') : '';
                $authorName = !empty($validated['author_id']) ? (($a = $firestore->getAuthor($validated['author_id'])) ? $a['name'] : '') : '';
                $bookDirectory = '/' . implode('/', [
                    $genreName,
                    $authorName,
                    $validated['title']
                ]);
            }
        }
        Storage::makeDirectory($this->storagePath . '/' . $bookDirectory); // Creates a directory in storage/app/public/books/{book_id}

        if ($request->hasFile('book_files')) {
            $files = $request->file('book_files');
            foreach ($files as $file) {
                $filename = $file->getClientOriginalName();
                $file->storeAs($this->storagePath . '/' . $bookDirectory, $filename);
            }
        }

        // TODO: Replace with appropriate FirestoreService logic to get the new book's ID, or redirect to the book list if not available
        return redirect()->route('admin.books.index')->with('success', 'Book created successfully!');
    }

    public function show($id)
    {
        $firestore = new FirestoreService();
        $book = $firestore->getBook($id);
        if (!$book) abort(404);
        return view('admin.books.show', compact('book'));
    }

    public function edit($id, Request $request)
    {
        $firestore = new FirestoreService();
        $book = $firestore->getBook($id);
        if (!$book) abort(404);
        $firestore = new FirestoreService();
        $authorList = $firestore->listAuthors();
        $genreList = $firestore->listGenres();
        $seriesList = $firestore->listSeries();

        // If no cover image, find image candidates in directory
        $coverCandidates = [];
        $coverAuto = null;
        $biggestCover = null;
        $biggestSize = 0;
        if (empty($book['cover_image']) && !empty($book['directory_path'])) {
            list($coverAuto, $coverCandidates) = $this->findCoverImageCandidate($book['directory_path']);
            // If no cover and no images, try m4b extraction
            if (empty($coverAuto) && empty($coverCandidates)) {
                $dir = rtrim($this->storagePath, '/') . '/' . ltrim($book['directory_path'], '/');
                if (is_dir($dir)) {
                    $m4bs = array_values(array_filter(scandir($dir), function ($f) use ($dir) {
                        return is_file($dir . '/' . $f) && strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'm4b';
                    }));
                    if ($m4bs) {
                        $firstM4b = $dir . '/' . $m4bs[0];
                        $coverFile = $this->extractCoverFromM4B($firstM4b, $dir);
                        if ($coverFile) {
                            $coverAuto = $coverFile;
                            $book['cover_image'] = ltrim($book['directory_path'], '/') . '/' . $coverFile;
                        }
                        if (empty($book['description'])) {
                            $tags = $this->extractTagData($firstM4b);
                            if (!empty($tags['description'])) {
                                $book['description'] = $tags['description'];
                            }
                        }
                    }
                    // Also check metadata.abs for description and year
                    $meta = $this->extractMetadataAbs($dir);
                    if (!empty($meta['description']) && empty($book['description'])) {
                        $book['description'] = $meta['description'];
                    }
                    if (!empty($meta['year']) && empty($book['published_year'])) {
                        $book['published_year'] = $meta['year'];
                    }
                }
            }
        }
        if ($book['directory_path'] && Storage::disk('books')->exists($book['directory_path'])) {
            $files = Storage::disk('books')->files($book['directory_path']);
            foreach ($files as $file) {
                if (preg_match('/\.(jpe?g|png|gif|svg)$/i', $file)) {
                    $candidate = basename($file);
                    $coverCandidates[] = $candidate;
                    $size = Storage::disk('books')->size($file);
                    if ($size > $biggestSize) {
                        $biggestSize = $size;
                        $biggestCover = $candidate;
                    }
                }
            }
        }
        $isModal = $request->ajax() || $request->get('modal') == 1;
        $layout = $isModal ? 'layouts.modal' : 'layouts.app';

        $coverCandidatesForDefault = [];
        if ($request->hasFile('cover_image')) {
            $ext = $request->file('cover_image')->getClientOriginalExtension();
            $coverPath = ($book['directory_path'] ? trim($book['directory_path'], '/') . '/' : '') . 'cover.' . $ext;
            Storage::disk('books')->put($coverPath, file_get_contents($request->file('cover_image')->getRealPath()));
            $book['cover_image'] = $coverPath;
            Log::info('Book Edit: cover_image uploaded via file to ' . $coverPath);
            $coverCandidatesForDefault[] = [
                'path' => $coverPath,
                'size' => Storage::disk('books')->size($coverPath)
            ];
        } elseif ($request->input('cover_image_path')) {
            $book['cover_image'] = $request->input('cover_image_path');
            Log::info('Book Edit: cover_image_path = ' . $request->input('cover_image_path'));
            $coverCandidatesForDefault[] = [
                'path' => $request->input('cover_image_path'),
                'size' => Storage::disk('books')->exists($request->input('cover_image_path')) ? Storage::disk('books')->size($request->input('cover_image_path')) : 0
            ];
        } elseif ($request->input('cover_image_url')) {
            Log::info('Book Edit: Importing cover image from URL: ' . $request->input('cover_image_url'));
            // Ensure the book's directory exists before importing
            $dirPath = $book['directory_path'];
            if (!$dirPath) {
                // Predict the directory as it will be set below
                if ($request->directory_path) {
                    $dirPath = $request->directory_path;
                } else {
                    $firestore = new \App\Services\FirestoreService();
                    if (!empty($book['series_id'])) {
                        $series = $firestore->getSeries($book['series_id']);
                        $seriesName = $series ? $series['name'] : '';
                        $genreName = !empty($book['genre_id']) ? (($g = $firestore->getGenre($book['genre_id'])) ? $g['name'] : '') : '';
                        $authorName = !empty($book['author_id']) ? (($a = $firestore->getAuthor($book['author_id'])) ? $a['name'] : '') : '';
                        $dirPath = '/' . implode('/', [
                            $genreName,
                            $authorName,
                            $seriesName,
                            ($book['series_number'] ? $book['series_number'] . ' ' : '') . $book['title']
                        ]);
                    } else {
                        $genreName = !empty($book['genre_id']) ? (($g = $firestore->getGenre($book['genre_id'])) ? $g['name'] : '') : '';
                        $authorName = !empty($book['author_id']) ? (($a = $firestore->getAuthor($book['author_id'])) ? $a['name'] : '') : '';
                        $dirPath = '/' . implode('/', [
                            $genreName,
                            $authorName,
                            $book['title']
                        ]);
                    }
                }
            }
            if ($this->storagePath && $dirPath) {
                Storage::makeDirectory($this->storagePath . '/' . ltrim($dirPath, '/'));
            }
            $coverPath = $this->importCoverImageFromUrl($request->input('cover_image_url'), $dirPath);
            Log::info('Book Edit: importCoverImageFromUrl returned: ' . ($coverPath ?: 'null'));
            if ($coverPath) {
                $book['cover_image'] = $coverPath;
                $coverCandidatesForDefault[] = [
                    'path' => $coverPath,
                    'size' => Storage::disk('books')->exists($coverPath) ? Storage::disk('books')->size($coverPath) : 0
                ];
            }
        } elseif ($request->filled('cover_image_candidate')) {
            // Use selected candidate from directory
            $candidate = $request->input('cover_image_candidate');
            $candidatePath = ltrim($book['directory_path'], '/') . '/' . $candidate;
            $book['cover_image'] = $candidatePath;
            $coverCandidatesForDefault[] = [
                'path' => $candidatePath,
                'size' => Storage::disk('books')->exists($candidatePath) ? Storage::disk('books')->size($candidatePath) : 0
            ];
        }
        // If there are multiple candidates (including Google Books), pick the largest as default
        if (count($coverCandidatesForDefault) > 1) {
            usort($coverCandidatesForDefault, function ($a, $b) {
                return $b['size'] <=> $a['size'];
            });
            $book['cover_image'] = $coverCandidatesForDefault[0]['path'];
        }

        return view(
            'admin.books.edit',
            compact('genreList', 'authorList', 'seriesList', 'book', 'isModal', 'layout', 'coverCandidates', 'coverAuto', 'biggestCover')
        );
    }

    public function update(Request $request, $id)
    {
        $firestore = new FirestoreService();
        $book = $firestore->getBook($id);
        if (!$book) abort(404);
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'author_id' => 'required|string|max:255',
            'series_id' => 'nullable|string|max:255',
            'series_number' => 'nullable|numeric',
            'genre_id' => 'required|string|max:255',
            'cover_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'description' => 'nullable|string',
            'directory_path' => 'nullable|string|max:255',
            'published_year' => 'nullable|digits:4',
        ]);

        // Handle author_id: create if not numeric
        if (!is_numeric($validated['author_id'])) {
            $author = $firestore->findOrCreateAuthorByName($validated['author_id']);
            $validated['author_id'] = $author['id'];
        }
        // Handle series_id: create if not numeric and not empty
        if (!empty($validated['series_id']) && !is_numeric($validated['series_id'])) {
            $series = $firestore->findOrCreateSeriesByName($validated['series_id']);
            $validated['series_id'] = $series['id'];
        }

        $oldDirectoryPath = $book['directory_path'];
        $newDirectoryPath = $validated['directory_path'] ?? $oldDirectoryPath;
        if ($oldDirectoryPath && $newDirectoryPath && $oldDirectoryPath !== $newDirectoryPath) {
            $oldAbs = $this->storagePath . '/' . ltrim($oldDirectoryPath, '/');
            $newAbs = $this->storagePath . '/' . ltrim($newDirectoryPath, '/');
            if (is_dir($oldAbs) && !is_dir($newAbs)) {
                if (!@rename($oldAbs, $newAbs)) {
                    return back()->withErrors(['directory_path' => 'Failed to rename directory from ' . $oldDirectoryPath . ' to ' . $newDirectoryPath]);
                }
            }
        }
        // Handle cover image upload or import from autofill/candidate (same as store)
        $coverImagePath = null;
        if ($request->hasFile('cover_image')) {
            $ext = $request->file('cover_image')->getClientOriginalExtension();
            $coverImagePath = ($newDirectoryPath ? trim($newDirectoryPath, '/') . '/' : '') . 'cover.' . $ext;
            Storage::disk('books')->put($coverImagePath, file_get_contents($request->file('cover_image')->getRealPath()));
            $validated['cover_image'] = $coverImagePath;
        } elseif ($request->input('cover_image_url')) {
            // Generate dirPath if not provided
            $dirPath = $validated['directory_path'] ?? $book['directory_path'] ?? null;
            if (!$dirPath) {
                $firestore = new \App\Services\FirestoreService();
                if (!empty($validated['series_id'])) {
                    $series = $firestore->getSeries($validated['series_id']);
                    $seriesName = $series ? $series['name'] : '';
                    $genreName = !empty($validated['genre_id']) ? (($g = $firestore->getGenre($validated['genre_id'])) ? $g['name'] : '') : '';
                    $authorName = !empty($validated['author_id']) ? (($a = $firestore->getAuthor($validated['author_id'])) ? $a['name'] : '') : '';
                    $dirPath = '/' . implode('/', [
                        $genreName,
                        $authorName,
                        $seriesName,
                        ($validated['series_number'] ? $validated['series_number'] . ' ' : '') . $validated['title']
                    ]);
                } else {
                    $genreName = !empty($validated['genre_id']) ? (($g = $firestore->getGenre($validated['genre_id'])) ? $g['name'] : '') : '';
                    $authorName = !empty($validated['author_id']) ? (($a = $firestore->getAuthor($validated['author_id'])) ? $a['name'] : '') : '';
                    $dirPath = '/' . implode('/', [
                        $genreName,
                        $authorName,
                        $validated['title']
                    ]);
                }
            }
            if ($this->storagePath && $dirPath) {
                Storage::makeDirectory($this->storagePath . '/' . ltrim($dirPath, '/'));
            }
            $coverImagePath = $this->importCoverImageFromUrl($request->input('cover_image_url'), $dirPath);
            if ($coverImagePath) {
                $validated['cover_image'] = $coverImagePath;
            }
        } elseif ($request->filled('cover_image_candidate')) {
            $candidate = $request->input('cover_image_candidate');
            $dir = ltrim($validated['directory_path'] ?? $book['directory_path'], '/');
            $validated['cover_image'] = $dir ? ($dir . '/' . ltrim($candidate, '/')) : $candidate;
        }

        $firestore->updateBook($id, $validated);

        return redirect()->route('admin.books.show', $id)->with('success', 'Book updated successfully!');
    }

    /**
     * Remove the specified book from storage.
     */
    public function destroy($id)
    {
        $firestore = new FirestoreService();
        $firestore->deleteBook($id);
        return redirect()->route('admin.books.index')->with('success', 'Book deleted successfully.');
    }

    public function download($id)
    {
        $firestore = new FirestoreService();
        $book = $firestore->getBook($id);
        $directoryPath = $book['directory_path'];

        if (!$directoryPath || !Storage::disk('books')->exists($directoryPath)) {
            abort(404, 'Book directory not found.');
        }

        $files = Storage::disk('books')->files($directoryPath);

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
            $zip->addFile(Storage::disk('books')->path($file), basename($file));
        }

        $zip->close();

        // Return the zip file as a download
        return response()->download($zipPath, $zipFileName)->deleteFileAfterSend(true); // Delete the temp zip file after sending.
    }

    public function googleBooks(Request $request)
    {
        $title = $request->query('title');
        $author = $request->query('author');
        $series = $request->query('series', '');
        $seriesNumber = $request->query('series_number', '');
        if (!$title || !$author) {
            return response()->json(['error' => 'Title and author are required.'], 400);
        }
        // Use trait method for similarity
        [$matches, $closeMatch] = $this->searchGoogleBooksWithSimilarity($title, $author, $series, $seriesNumber);
        $more = $request->query('more');
        $limit = min((int)$request->query('limit', 10), 40); // Default 10, max 40
        if ($closeMatch && !$more) {
            $info = $closeMatch['volumeInfo'];
            $autofill = [
                'title' => $info['title'] ?? '',
                'authors' => isset($info['authors']) ? implode(', ', $info['authors']) : '',
                'published_year' => isset($info['publishedDate']) ? substr($info['publishedDate'], 0, 4) : '',
                'description' => $info['description'] ?? '',
                'cover_image_url' => $info['imageLinks']['thumbnail'] ?? '',
                'id' => $closeMatch['item']['id'] ?? '',
                'series' => $info['series'] ?? '',
                'seriesNumber' => $info['seriesNumber'] ?? '',
                'score' => $closeMatch['score'],
                'match_type' => 'close',
                'matches' => [],
            ];
            return response()->json($autofill);
        } else {
            // Prepare a list of up to $limit possible matches for user selection
            $tableMatches = [];
            foreach (array_slice($matches, 0, $limit) as $m) {
                $info = $m['item']['volumeInfo'];
                $tableMatches[] = [
                    'title' => $info['title'] ?? '',
                    'authors' => isset($info['authors']) ? implode(', ', $info['authors']) : '',
                    'published_year' => isset($info['publishedDate']) ? substr($info['publishedDate'], 0, 4) : '',
                    'description' => $info['description'] ?? '',
                    'cover_image_url' => $info['imageLinks']['thumbnail'] ?? '',
                    'id' => $m['item']['id'] ?? '',
                    'series' => $info['series'] ?? '',
                    'seriesNumber' => $info['seriesNumber'] ?? '',
                    'score' => $m['score']
                ];
            }
            return response()->json([
                'error' => 'No close match found.',
                'match_type' => 'list',
                'matches' => $tableMatches,
                'maxed' => count($matches) <= $limit // true if all results are shown
            ], 200);
        }
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

    /**
     * Recursively queue import jobs for all book directories under a given path.
     */
    public function bulkImportBooks(Request $request)
    {
        $root = $request->input('dir');
        $storagePath = env('BOOK_STORAGE_PATH');
        $absRoot = rtrim($storagePath, '/') . '/' . ltrim($root, '/');
        if (!is_dir($absRoot)) {
            return response()->json(['error' => 'Directory not found.'], 404);
        }
        $bookDirs = $this->findBookDirectories($absRoot);
        $queued = [];
        foreach ($bookDirs as $dir) {
            $relDir = ltrim(str_replace($storagePath, '', $dir), '/');
            // Check for duplicate queued jobs and database entries
            $alreadyQueued = false;
            // Check jobs table for queued jobs with this directory
            $pendingJobs = DB::table('jobs')->get();
            foreach ($pendingJobs as $job) {
                $payload = json_decode($job->payload, true);
                if (
                    isset($payload['data']['command']) &&
                    preg_match('/directoryPath";s:\\d+:"([^"]+)"/', $payload['data']['command'], $matches) &&
                    $matches[1] === $relDir
                ) {
                    $alreadyQueued = true;
                    break;
                }
            }
            // Check for existing book record
            if (Book::where('directory_path', $relDir)->exists()) {
                $alreadyQueued = true;
            }
            if (!$alreadyQueued) {
                \App\Jobs\ImportBookFromDirectoryJob::dispatch($relDir);
                $queued[] = $relDir;
            }
        }
        return response()->json([
            'message' => 'Queued ' . count($queued) . ' book directories for import.',
            'skipped' => count($bookDirs) - count($queued),
            'queued_dirs' => $queued
        ], 200);
    }

    /**
     * Bulk import all book directories from a specific directory (recursive, queued)
     */
    public function bulkImportBooksFromDir(Request $request)
    {
        $dir = $request->input('dir');
        // Dispatch a single job that will queue all the import jobs
        \App\Jobs\CreateImportJobsForDirectory::dispatch($dir);
        return response()->json([
            'message' => 'Queued job to scan and import all book directories.',
        ], 200);
    }

    /**
     * AJAX endpoint for Tom Select: returns series matching query string, or all if no query.
     */
    public function seriesAjax(Request $request)
    {
        $q = $request->input('q', '');
        $firestore = new \App\Services\FirestoreService();
        $series = $firestore->listSeries();
        if ($q) {
            $series = array_filter($series, function($item) use ($q) {
                return stripos($item['name'], $q) !== false;
            });
        }
        usort($series, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        $series = array_slice($series, 0, 20);
        return response()->json(['data' => array_values($series)]);
    }

    /**
     * AJAX: Rename a file or folder in the import directory browser.
     */
    public function renameImportItem(Request $request)
    {
        Log::info($request->all());
        $request->validate([
            'path' => 'required|string', // relative path to file/folder
            'new_name' => 'required|string', // new name only, not path
        ]);
        $relPath = trim($request->input('path'), '/');
        $path = $this->storagePath . '/' . $relPath;
        $newName = $request->input('new_name');

        $dir = dirname($path);
        $newPath = $dir . DIRECTORY_SEPARATOR . $newName;

        Log::info("{$path} -> {$newPath}");

        $oldRel = str_replace($this->storagePath, '', $path);
        $newRel = str_replace($this->storagePath, '', $newPath);
        Log::info("({$this->storagePath}) {$oldRel} -> {$newRel}");

        if (!file_exists($path)) {
            return response()->json(['error' => 'Original file/folder does not exist.'], 404);
        }
        if (file_exists($newPath)) {
            return response()->json(['error' => 'A file/folder with the new name already exists.'], 409);
        }
        Log::info("{$path} -> {$newPath}");
        // Try to rename
        $success = @rename($path, $newPath);
        if ($success) {
            // If it's a directory, update any Book records using this directory_path
            if (is_dir($newPath)) {
                $oldRel = str_replace($this->storagePath, '', $path);
                $newRel = str_replace($this->storagePath, '', $newPath);
                Log::info("({$this->storagePath}) {$oldRel} -> {$newRel}");
                // Update Firestore books whose directory_path matches $oldRel
                $firestore = new \App\Services\FirestoreService();
                $booksToUpdate = array_filter($firestore->listBooks(), function($book) use ($oldRel) {
                    return isset($book['directory_path']) && $book['directory_path'] === $oldRel;
                });
                foreach ($booksToUpdate as $book) {
                    $firestore->updateBook($book['id'], ['directory_path' => $newRel]);
                }
            }
            // Always return relative paths to the frontend!
            return response()->json([
                'success' => true,
                'new_path' => ltrim($newRel, '/'),
                'new_name' => $newName
            ]);
        } else {
            return response()->json(['error' => 'Rename failed. Check permissions.'], 500);
        }
    }

    /**
     * AJAX: List files in a book directory, filtered by audiobook extensions or all files.
     */
    public function filesAjax(Request $request)
    {
        $directory = $request->input('directory');
        $showAll = $request->boolean('show_all', false);
        $storagePath = env('BOOK_STORAGE_PATH');
        $dir = rtrim($storagePath, '/') . '/' . ltrim($directory, '/');
        $files = [];
        if (is_dir($dir)) {
            $allFiles = scandir($dir);
            $audioExts = ['mp3', 'm4b', 'm4a', 'aac', 'flac', 'ogg', 'wav'];
            foreach ($allFiles as $file) {
                if ($file === '.' || $file === '..') continue;
                $path = $dir . '/' . $file;
                if (!is_file($path)) continue;
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if ($showAll || in_array($ext, $audioExts)) {
                    $files[] = $file;
                }
            }
        }
        return response()->json(['files' => $files]);
    }
}
