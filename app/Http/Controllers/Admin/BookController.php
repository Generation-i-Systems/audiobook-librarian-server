<?php

namespace App\Http\Controllers\Admin;

use App\Events\NewBookAdded;
use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use App\Services\GoogleBooksApiService;
use App\Traits\BookImportTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class BookController extends Controller
{
    // Register in routes/web.php:
    // Route::post('/admin/books/resync-from-path', [BookController::class, 'resyncFromPath'])
    // ->name('admin.books.resyncFromPath');
    use BookImportTrait;

    /**
     * AJAX: Resync title, author, and series from a directory path.
     * POST /admin/books/resync-from-path
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function resyncFromPath(Request $request)
    {
        $request->validate([
            'directoryPath' => 'required|string',
        ]);
        $directoryPath = $request->input('directoryPath');
        try {
            $parser = new \App\Services\BookDirectoryParser();
            $storagePath = env('BOOK_STORAGE_PATH');
            $absPath = $parser->resolveStoragePath($directoryPath);
            if (!is_dir($absPath)) {
                return response()->json(['success' => false, 'message' => 'Directory does not exist . '], 404);
            }
            $parsed = $parser->parseDirectory($absPath);
            $book = is_array($parsed) && count($parsed) > 0 ? $parsed[0] : null;
            if (!$book) {
                return response()->json(['success' => false, 'message' => 'Could not parse directory . ']);
            }
            // Normalize output for JS
            $authors = [];
            if (!empty($book['author'])) {
                $authors = is_array($book['author']) ? $book['author'] : [$book['author']];
            }
            $series = [];
            if (!empty($book['series']) && is_array($book['series'])) {
                foreach ($book['series'] as $name => $number) {
                    $series[] = ['name' => $name, 'number' => $number];
                }
            } elseif (!empty($book['series'])) {
                $series[] = ['name' => $book['series'], 'number' => $book['seriesNumber'] ?? ''];
            }

            return response()->json([
                'success' => true,
                'title' => $book['title'] ?? '',
                'authors' => $authors,
                'series' => $series,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    protected $googleBooksApiService;

    protected $firestoreService;

    private $storagePath;

    public function __construct(GoogleBooksApiService $googleBooksApiService, FirestoreService $firestoreService)
    {
        $this->setGoogleBooksApiService($googleBooksApiService);
        $this->firestoreService = $firestoreService;
        $this->storagePath = env('BOOK_STORAGE_PATH');
    }

    public function index(Request $request)
    {
        $firestore = new FirestoreService();
        $books = $firestore->listBooks();

        // Filtering
        if ($request->filled('search')) {
            $search = strtolower($request->input('search'));
            $books = array_filter(
                $books,
                function ($book) use ($search) {
                    return (isset($book['title']) && stripos($book['title'], $search) !== false)
                        || (isset($book['author_name']) && stripos($book['author_name'], $search) !== false);
                }
            );
        }
        if ($request->filled('author')) {
            $books = array_filter(
                $books,
                function ($book) use ($request) {
                    return isset($book['author']) && $book['author'] == $request->input('author');
                }
            );
        }
        if ($request->filled('genre_id')) {
            $books = array_filter(
                $books,
                function ($book) use ($request) {
                    return isset($book['genre_id']) && $book['genre_id'] == $request->input('genre_id');
                }
            );
        }
        // Sorting
        $sort = $request->input('sort', 'recent_desc');
        $books = array_values($books);
        usort(
            $books,
            function ($a, $b) use ($sort) {
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
            }
        );
        // Pagination
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 20;
        $total = count($books);
        $booksForPage = array_slice($books, ($page - 1) * $perPage, $perPage);

        // Wrap in paginator
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $booksForPage,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view(
            'admin.books.index',
            [
                'books' => $paginator,
                'sort' => $sort,
                // You may not need to pass total, perPage, currentPage anymore
            ]
        );
    }

    public function create(Request $request)
    {
        $firestore = new FirestoreService();
        // Always initialize author and genre as arrays for the form
        $initial = [
            'directoryPath' => $request->path,
            'author' => [''],
            'genre' => [''],
        ];
        $coverCandidates = [];
        $coverAuto = null;
        $biggestCover = null;
        $biggestSize = 0;
        $directoryPath = $request->old('directoryPath') ?? $initial['directoryPath'] ?? '';
        // Use processDirPath to extract initial values from the directory
        if ($directoryPath) {
            $dirMeta = $this->processDirPath($directoryPath);
            if (is_array($dirMeta)) {
                $initial = array_merge($initial, $dirMeta);
                // Ensure author and genre are arrays
                if (empty($initial['author']) || !is_array($initial['author'])) {
                    $initial['author'] = [''];
                }
                if (empty($initial['genre']) || !is_array($initial['genre'])) {
                    $initial['genre'] = [''];
                }
            }
            [$coverAuto, $coverCandidates] = $this->findCoverImageCandidate($directoryPath);
            // If no cover and no images, try m4b extraction
            if (empty($coverAuto) && empty($coverCandidates)) {
                $dir = rtrim($this->storagePath, '/') . '/' . ltrim($directoryPath, '/');
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
                    if (!empty($meta['year']) && empty($initial['publishedYear'])) {
                        $initial['publishedYear'] = $meta['year'];
                    }
                }
            }
        }
        if ($directoryPath && Storage::disk('books')->exists($directoryPath)) {
            $files = Storage::disk('books')->files($directoryPath);
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
        // Always fetch genreList as array for the form
        $genreList = $firestore->listGenres();
        if (!is_array($genreList)) {
            $genreList = [];
        }
        // Guarantee initial['author'] and initial['genre'] are arrays for JS and Blade
        if (empty($initial['author']) || !is_array($initial['author'])) {
            $initial['author'] = [''];
        }
        if (empty($initial['genre']) || !is_array($initial['genre'])) {
            $initial['genre'] = [''];
        }
        $book = []; // Initialize empty book array

        if (!isset($initial['directoryPath'])) {
            $initial['directoryPath'] = '';
        }

        if ($request->ajax()) {
            return view(
                'admin.books.create_form',
                compact(
                    'genreList',
                    'initial',
                    'coverCandidates',
                    'coverAuto',
                    'biggestCover',
                    'directoryPath'
                )
            )
                ->with('isModal', true)
                ->with('layout', 'layouts.modal');
        }

        return view(
            'admin.books.create_form',
            compact(
                'genreList',
                'initial',
                'coverCandidates',
                'coverAuto',
                'biggestCover',
                'directoryPath'
            )
        );
    }

    public function import()
    {
        return view('admin.books.import_directory');
    }

    /**
     * Show the form for editing the specified book.
     *
     * @param  string  $id
     * @return \Illuminate\Contracts\View\View
     */
    public function edit($id)
    {
        $firestore = new FirestoreService();
        $book = $firestore->getBook($id);
        if (!$book) {
            abort(404, 'Book not found');
        }
        $genreList = $firestore->listGenres();
        $coverCandidates = [];
        $coverAuto = null;
        $directoryPath = $book['directoryPath'] ?? null;
        // Find cover candidates for this directory
        if ($directoryPath && Storage::disk('books')->exists($directoryPath)) {
            $files = Storage::disk('books')->files($directoryPath);
            foreach ($files as $file) {
                if (preg_match('/\.(jpe?g|png|gif|svg)$/i', $file)) {
                    $candidate = basename($file);
                    $coverCandidates[] = $candidate;
                }
            }
        }

        return view('admin.books.edit', [
            'book' => $book,
            'genreList' => $genreList,
            'coverCandidates' => $coverCandidates,
            'coverAuto' => $coverAuto,
            'directoryPath' => $directoryPath,
            'isModal' => request()->ajax() || request('isModal', false),
        ]);
    }

    public function store(Request $request)
    {
        \Log::debug('BookController@store called', ['input' => $request->all()]);
        try {
            $firestore = new FirestoreService();

            $validated = $request->validate(
                [
                    'title' => 'required|string|max:255',
                    'author' => 'required|array|min:1',
                    'author.*' => 'required|string|max:255',
                    'series' => 'nullable|array',
                    'series.*' => 'nullable|string|max:255',
                    'seriesNumber' => 'nullable|array',
                    'seriesNumber.*' => 'nullable|numeric',
                    'genre' => 'required|array|min:1',
                    'genre.*' => 'required|string|max:255',
                    'coverImage' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                    'description' => 'nullable|string',
                    'directoryPath' => 'nullable|string|max:255',
                    'publishedYear' => 'nullable|digits:4',
                ]
            );
            Log::debug('BookController@store validated', ['validated' => $validated]);

            // Build the book record in processDirPath format
            $book = [];
            $book['title'] = $validated['title'];
            $book['author'] = array_values(array_filter($validated['author']));
            $book['genre'] = array_values(array_filter($validated['genre']));
            $book['directoryPath'] = $validated['directoryPath'] ?? null;
            $book['description'] = $validated['description'] ?? '';
            $book['publishedYear'] = $validated['publishedYear'] ?? null;
            // Handle series as an associative array with name as key and number as value
            $book['series'] = [];
            if (!empty($validated['series'])) {
                foreach ($validated['series'] as $i => $seriesName) {
                    $seriesName = trim($seriesName);
                    if ($seriesName === '') {
                        continue;
                    }

                    $number = isset($validated['seriesNumber'][$i]) ? $validated['seriesNumber'][$i] : null;
                    if ($number !== null && $number !== '') {
                        $book['series'][$seriesName] = (float) $number;
                    } else {
                        $book['series'][$seriesName] = null;
                    }
                }
            }

            // Remove any empty series entries
            $book['series'] = array_filter($book['series'], function ($name) {
                return !empty($name);
            }, ARRAY_FILTER_USE_KEY);

            $dirPath = $validated['directoryPath'] ?? null;
            if (empty($dirPath)) {
                if (!empty($validated['series'])) {
                    $series = $validated['series'];
                    $genreName = !empty($validated['genre'][0]);
                    // generate authorName from author array by implodeing with ' & ' and removing any empty values
                    $authorName = implode(' & ', array_filter($validated['author']));
                    // generate series name from the first series in the series array
                    $seriesName = !empty($validated['series'][0]);
                    // generate the dir path from genre, author, series, and series number and title
                    // (author/series/[series number] title) or author/title
                    if ($series) {
                        $dirPath = '/' . implode('/', [
                            $genreName,
                            $authorName,
                            $series,
                            ($validated['seriesNumber'] ? $validated['seriesNumber'] . ' ' : '') . $validated['title'],
                        ]);
                    } else {
                        $dirPath = '/' . implode('/', [
                            $genreName,
                            $authorName,
                            $validated['title'],
                        ]);
                    }
                } else {
                    $genreName = !empty($validated['genre'][0]);
                    // generate authorName from author array by implodeing with ' & ' and removing any empty values
                    $authorName = implode(' & ', array_filter($validated['author']));
                    $dirPath = '/' . implode('/', [
                        $genreName,
                        $authorName,
                        $validated['title'],
                    ]);
                }
                if ($this->storagePath && $dirPath) {
                    Storage::makeDirectory($this->storagePath . '/' . ltrim($dirPath, '/'));
                }
            }
            $book['directoryPath'] = $dirPath;

            // Handle cover image upload or import from autofill/candidate
            $coverImagePath = null;
            if ($request->hasFile('coverImage')) {
                $ext = $request->file('coverImage')->getClientOriginalExtension();
                $coverImagePath = ($validated['directoryPath'] ? trim($validated['directoryPath'], '/') . '/' : '') .
                    'cover . ' . $ext;
                Storage::disk('books')->put(
                    $coverImagePath,
                    file_get_contents($request->file('coverImage')->getRealPath())
                );
                $validated['coverImage'] = $coverImagePath;
            } elseif ($request->input('coverImageURL')) {
                $coverImagePath = $this->importCoverImageFromUrl(
                    $request->input('coverImageURL'),
                    $dirPath,
                );
                if ($coverImagePath) {
                    $book['coverImage'] = $coverImagePath;
                }
            } elseif ($request->filled('coverImageCandidate')) {
                $candidate = $request->input('coverImageCandidate');
                $book['coverImage'] = ltrim($book['directoryPath'], '/') . '/' . $candidate;
            }

            // Generate dirPath if not provided
            Log::debug('BookController@store book data', ['book' => $book]);

            // Store the book record in Firestore
            $bookId = $firestore->createBook($book);

            if ($bookId) {
                // Get the created book data from Firestore
                $createdBook = $firestore->getBook($bookId);
                if ($createdBook) {
                    // Dispatch the NewBookAdded event with the Firestore document data
                    event(new NewBookAdded($createdBook));
                }
            }

            // Handle Book File Uploads and directory creation
            if (!$this->storagePath) {
                Log::error('BOOK_STORAGE_PATH is not defined in the .env file.');

                return back()->withErrors(['error' => 'Configuration error: BOOK_STORAGE_PATH is not defined . ']);
            }
            // Make sure the directory exists (create or rename if necessary)
            $bookDirectory = $book['directoryPath'] ?? null;
            if ($bookDirectory) {
                $absPath = $this->storagePath . '/' . ltrim($bookDirectory, '/');
                if (!is_dir($absPath)) {
                    Storage::makeDirectory($absPath);
                }
            }
            if ($request->hasFile('book_files')) {
                $files = $request->file('book_files');
                foreach ($files as $file) {
                    $filename = $file->getClientOriginalName();
                    $file->storeAs($this->storagePath . '/' . $bookDirectory, $filename);
                }
            }

            if ($request->ajax()) {
                // Render the row HTML for the new book
                $rowHtml = view('admin.books.partials.directory_row', [
                    'item' => [
                        'type' => 'book',
                        'id' => $bookId,
                        'name' => basename($book['directoryPath']),
                        'path' => $book['directoryPath'],
                        'edit_url' => route('admin.books.edit', ['book' => $bookId]),
                        'created_at' => now(),
                        'size' => 0,
                        'mime_type' => 'directory',
                        'book' => $book,
                    ],
                ])->render();

                return response()->json([
                    'success' => true,
                    'book_id' => $bookId,
                    'edit_url' => route('admin.books.edit', ['book' => $bookId]),
                    'row_html' => $rowHtml,
                    'directoryPath' => $book['directoryPath'],
                ]);
            }

            return redirect()->route('admin.books.index')->with('success', 'Book created successfully!');
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('BookController@store validation error', [
                'errors' => $e->errors(),
                'input' => $request->all(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::error('BookController@store exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'input' => $request->all(),
            ]);
            throw $e;
        }
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
        $directoryPath = $book['directoryPath'];

        if (!$directoryPath || !Storage::disk('books')->exists($directoryPath)) {
            abort(404, 'Book directory not found.');
        }

        $files = Storage::disk('books')->files($directoryPath);

        if (empty($files)) {
            abort(404, 'No files found for this book.');
        }

        $zipFileName = str_replace(' ', '_', $book['title']) . '.zip';  // Sanitize filename
        $zipPath = storage_path(
            'app/public/temp/' . $zipFileName
        );  // Temporary storage

        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Failed to create zip archive.');
        }

        foreach ($files as $file) {
            $zip->addFile(Storage::disk('books')->path($file), basename($file));
        }

        $zip->close();

        // Return the zip file as a download
        return response()
            ->download($zipPath, $zipFileName)
            ->deleteFileAfterSend(true); // Delete the temp zip file after sending.
    }

    public function googleBooks(Request $request)
    {
        $title = $request->query('title');
        $author = $request->query('author');
        $series = $request->query('series', '');
        $seriesNumber = $request->query('series_number', '');

        if (!$title || !$author) {
            return response()->json([
                'error' => 'Title and author are required.',
            ], 400);
        }

        // Use trait method for similarity
        [$matches, $closeMatch] = $this->searchGoogleBooksWithSimilarity(
            $title,
            $author,
            $series,
            $seriesNumber
        );

        $more = $request->query('more');
        $limit = min((int) $request->query('limit', 10), 40); // Default 10, max 40
        if ($closeMatch && !$more) {
            $info = $closeMatch['volumeInfo'];
            $autofill = [
                'title' => $info['title'] ?? '',
                'author' => isset($info['authors']) ? implode(', ', $info['authors']) : '',
                'publishedYear' => isset($info['publishedDate']) ? substr($info['publishedDate'], 0, 4) : '',
                'description' => $info['description'] ?? '',
                'coverImageUrl' => $info['imageLinks']['thumbnail'] ?? '',
                'id' => $closeMatch['item']['id'] ?? '',
                'series' => $info['series'] ?? '',
                'seriesNumber' => $info['seriesNumber'] ?? '',
                'score' => $closeMatch['score'],
                'matchType' => 'close',
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
                    'author' => isset($info['authors']) ? implode(', ', $info['authors']) : '',
                    'publishedYear' => isset($info['publishedDate']) ? substr($info['publishedDate'], 0, 4) : '',
                    'description' => $info['description'] ?? '',
                    'coverImageUrl' => $info['imageLinks']['thumbnail'] ?? '',
                    'id' => $m['item']['id'] ?? '',
                    'series' => $info['series'] ?? '',
                    'seriesNumber' => $info['seriesNumber'] ?? '',
                    'score' => $m['score'],
                ];
            }

            return response()->json([
                'error' => 'No close match found.',
                'matchType' => 'list',
                'matches' => $tableMatches,
                'maxed' => count($matches) <= $limit, // true if all results are shown
            ], 200);
        }
    }

    /**
     * Serve an image from BOOK_STORAGE_PATH for preview (secure).
     */
    public function previewImage($book, $filename)
    {
        $storagePath = env('BOOK_STORAGE_PATH');
        $dir = rtrim($storagePath, '/') . '/' . ltrim($book['directoryPath'], '/');
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
     * AJAX endpoint for Tom Select: returns series matching query string, or all if no query.
     */
    public function seriesAjax(Request $request)
    {
        $q = $request->input('q', '');
        $firestore = new FirestoreService();
        $series = $firestore->listSeries();
        if ($q) {
            $series = array_filter($series, function ($item) use ($q) {
                return stripos($item['name'], $q) !== false;
            });
        }
        usort($series, function ($a, $b) {
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
            // If it's a directory, update any Book records using this directoryPath
            if (is_dir($newPath)) {
                $oldRel = str_replace($this->storagePath, '', $path);
                $newRel = str_replace($this->storagePath, '', $newPath);
                Log::info("({$this->storagePath}) {$oldRel} -> {$newRel}");
                // Update Firestore books whose directoryPath matches $oldRel
                $firestore = new FirestoreService();
                $booksToUpdate = array_filter($firestore->listBooks(), function ($book) use ($oldRel) {
                    return isset($book['directoryPath']) && $book['directoryPath'] === $oldRel;
                });
                foreach ($booksToUpdate as $book) {
                    $firestore->updateBook($book['id'], ['directoryPath' => $newRel]);
                }
            }

            // Always return relative paths to the frontend!
            return response()->json([
                'success' => true,
                'newPath' => ltrim($newRel, '/'),
                'newName' => $newName,
            ]);
        } else {
            return response()->json([
                'error' => 'Rename failed. Check permissions.',
            ], 500);
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
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $path = $dir . '/' . $file;
                if (!is_file($path)) {
                    continue;
                }
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if ($showAll || in_array($ext, $audioExts)) {
                    $files[] = $file;
                }
            }
        }

        return response()->json(['files' => $files]);
    }

    /**
     * Provides autocomplete suggestions for author names.
     */
    public function autocompleteAuthors(Request $request): \Illuminate\Http\JsonResponse
    {
        $term = $request->input('term', '');
        if (empty($term)) {
            return response()->json([]);
        }
        $authors = $this->firestoreService->searchAuthorsByName($term);

        return response()->json($authors);
    }

    /**
     * Provides autocomplete suggestions for series titles.
     */
    public function autocompleteSeries(Request $request): \Illuminate\Http\JsonResponse
    {
        $term = $request->input('term', '');
        if (empty($term)) {
            return response()->json([]);
        }
        $series = $this->firestoreService->searchSeriesByName($term);

        return response()->json($series);
    }

    /**
     * Get the raw JSON data for a book from Firestore
     *
     * @param  string  $id  The book ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRawJson($id)
    {
        $firestore = new FirestoreService();
        $book = $firestore->getBook($id);
        if (!$book) {
            abort(404);
        }

        return response()->json($book, 200, ['Content-Type' => 'application/json'], JSON_PRETTY_PRINT);
    }
}
