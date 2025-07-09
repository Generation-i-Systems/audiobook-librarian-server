<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Events\NewBookAdded;
use App\Http\Controllers\Controller;
use App\Services\AudibleService;
use App\Services\ExternalCoverService;
use App\Services\GoogleBooksApiService;
use App\Traits\BookImportTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class BookController extends Controller
{
    use BookImportTrait;

    /**
     * Set the document store service (for testing)
     *
     * @param  \App\Contracts\DocumentStoreServiceInterface  $service
     * @return void
     */
    public function setDocumentStoreService($service)
    {
        $this->documentStoreService = $service;
    }

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

    private $storagePath;

    /**
     * @var DocumentStoreServiceInterface
     */
    protected DocumentStoreServiceInterface $documentStoreService;

    protected AudibleService $audibleService;

    protected ExternalCoverService $externalCoverService;

    public function __construct(
        DocumentStoreServiceInterface $documentStoreService,
        GoogleBooksApiService $googleBooksApiService,
        AudibleService $audibleService,
        ExternalCoverService $externalCoverService
    ) {
        $this->documentStoreService = $documentStoreService;
        $this->setGoogleBooksApiService($googleBooksApiService);
        $this->audibleService = $audibleService;
        $this->externalCoverService = $externalCoverService;
        $this->storagePath = env('BOOK_STORAGE_PATH');
    }

    public function index(Request $request)
    {
        $books = $this->documentStoreService->listBooks();

        // Filtering
        if ($request->filled('search')) {
            $search = strtolower($request->input('search'));
            $books = array_filter(
                $books,
                fn($book) => (
                    isset($book['title']) && stripos($book['title'], $search) !== false
                ) || (
                    isset($book['author']) && (
                        (is_array($book['author']) ? stripos(implode(', ', $book['author']), $search) !== false : stripos($book['author'], $search) !== false)
                    )
                )
            );
        }
        if ($request->filled('author')) {
            $books = array_filter(
                $books,
                fn($book) => isset($book['author']) && $book['author'] == $request->input('author')
            );
        }
        if ($request->filled('genre_id')) {
            $books = array_filter(
                $books,
                fn($book) => isset($book['genre_id']) && $book['genre_id'] == $request->input('genre_id')
            );
        }
        // Sorting
        $sort = $request->input('sort', 'recent_desc');
        $books = array_values($books);
        usort(
            $books,
            fn($a, $b) => match ($sort) {
                'recent_desc' => strtotime($b['created_at'] ?? 0) <=> strtotime($a['created_at'] ?? 0),
                'recent_asc' => strtotime($a['created_at'] ?? 0) <=> strtotime($b['created_at'] ?? 0),
                'author_asc' => strcmp($a['author_name'] ?? '', $b['author_name'] ?? ''),
                'author_desc' => strcmp($b['author_name'] ?? '', $a['author_name'] ?? ''),
                'title_asc' => strcmp($a['title'] ?? '', $b['title'] ?? ''),
                'title_desc' => strcmp($b['title'] ?? '', $a['title'] ?? ''),
                'year_asc' => ($a['published_year'] ?? 0) <=> ($b['published_year'] ?? 0),
                'year_desc' => ($b['published_year'] ?? 0) <=> ($a['published_year'] ?? 0),
                default => strtotime($b['created_at'] ?? 0) <=> strtotime($a['created_at'] ?? 0)
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
        $documentStore = $this->documentStoreService;
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

        // Check if this is an import request with pre-extracted metadata
        $isImportMode = $request->has('importMode') && $request->get('importMode');

        if ($isImportMode) {
            // Use the pre-extracted metadata from import process instead of re-processing
            $initial = [
                'directoryPath' => $request->get('directoryPath', ''),
                'author' => $request->get('author', ['']),
                'genre' => $request->get('genre', ['']),
                'narrator' => $request->get('narrator', ['']),
                'series' => $request->get('series', []),
                'title' => $request->get('title', ''),
                'description' => $request->get('description', ''),
                'publishedYear' => $request->get('publishedYear', ''),
                'language' => $request->get('language', 'en'),
                'isbn' => $request->get('isbn', ''),
                'asin' => $request->get('asin', ''),
                'sourcePath' => $request->get('sourcePath', ''),
                'sourceRoot' => $request->get('sourceRoot', ''),
                'sourceRelPath' => $request->get('sourceRelPath', ''),
                'sourceType' => $request->get('sourceType', ''),
                'importMode' => $request->get('importMode', false),
            ];

            // Get cover image from session if available
            if ($request->has('hasCoverImage') && session()->has('import_cover_image')) {
                $initial['coverImage'] = session('import_cover_image');
                session()->forget('import_cover_image'); // Clean up session
            }


            // Ensure arrays are properly formatted
            if (!is_array($initial['author'])) {
                $initial['author'] = empty($initial['author']) ? [''] : [$initial['author']];
            }
            if (!is_array($initial['genre'])) {
                $initial['genre'] = empty($initial['genre']) ? [''] : [$initial['genre']];
            }
            if (!is_array($initial['narrator'])) {
                $initial['narrator'] = empty($initial['narrator']) ? [''] : [$initial['narrator']];
            }
            if (!is_array($initial['series'])) {
                $initial['series'] = empty($initial['series']) ? [] : $initial['series'];
            }

            $directoryPath = $initial['directoryPath'];
        } else {
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
        // Normalize genreList to flat array of strings
        $genreListRaw = $documentStore->listGenres();
        $genreList = [];
        foreach ($genreListRaw as $g) {
            if (is_array($g) && isset($g['name'])) {
                $genreList[] = (string) $g['name'];
            } elseif (is_string($g)) {
                $genreList[] = $g;
            }
        }

        // If in import mode, ensure the requested genre exists in the genre list
        if ($isImportMode) {
            $requestedGenres = $request->get('genre', []);
            if (!is_array($requestedGenres)) {
                $requestedGenres = [$requestedGenres];
            }

            foreach ($requestedGenres as $requestedGenre) {
                if (!empty($requestedGenre) && !in_array($requestedGenre, $genreList)) {
                    // Add the new genre to the list so it appears in the dropdown
                    $genreList[] = $requestedGenre;

                    // Also add it to the database for future use
                    try {
                        $documentStore->createGenre(['name' => $requestedGenre]);
                        Log::info('Auto-created genre during import', ['genre' => $requestedGenre]);
                    } catch (\Exception $e) {
                        Log::warning('Failed to auto-create genre', [
                            'genre' => $requestedGenre,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        // Debug logging for genre list
        Log::debug('Genre list for form', [
            'genreList' => $genreList,
            'requested_genre' => $isImportMode ? ($request->get('genre') ?? 'none') : 'not_import'
        ]);
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

        // Normalize selected genres for the form
        $genres = [];
        if (!empty($initial['genre'])) {
            foreach ($initial['genre'] as $g) {
                $genres[] = trim((string) $g);
            }
        }
        // Also allow old input to override
        $genres = old('genre', $genres);
        if (!is_array($genres)) {
            $genres = [$genres];
        }

        if ($request->ajax()) {
            return view(
                'admin.books.create_form',
                compact(
                    'genreList',
                    'genres',
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

        // Ensure initial['author'] is a string for the form field if it's an array
        if (isset($initial['author']) && is_array($initial['author'])) {
            $initial['author'] = array_map(function ($a) {
                return is_array($a) ? implode(', ', $a) : (string) $a;
            }, $initial['author']);
        }

        return view(
            'admin.books.create_form',
            compact(
                'genreList',
                'genres',
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
     * Show the file/audio import workflow for books.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function importFile()
    {
        return view('admin.books.import_file');
    }

    /**
     * Process the import of a book from file/audio.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|\
     */
    public function processImport(Request $request)
    {
        Log::info('Book import processing started', ['request_data' => $request->except(['cover', 'coverImage'])]);

        try {
            // Validate the request data
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'author' => 'required|array',
                'author.*' => 'required|string|max:255',
                'genre' => 'required|array',
                'genre.*' => 'required|string|max:255',
                'narrator' => 'nullable|array',
                'narrator.*' => 'nullable|string|max:255',
                'series' => 'nullable|array',
                'series.*.seriesName' => 'nullable|string|max:255',
                'series.*.name' => 'nullable|string|max:255', // For backward compatibility
                'series.*.number' => 'nullable|string|max:50',
                'import_path' => 'nullable|string',
                'import_root' => 'nullable|string',
                'import_type' => 'nullable|string',
                'cover_url' => 'nullable|url',
                'description' => 'nullable|string',
                'year' => 'nullable|string|max:4',
                'publisher' => 'nullable|string|max:255',
                'isbn' => 'nullable|string|max:20',
                'language' => 'nullable|string|max:50',
                'pages' => 'nullable|integer',
                'rating' => 'nullable|numeric|min:0|max:5',
            ]);

            $id = (string) Str::uuid();
            $validated['id'] = $id;

            // Handle empty arrays
            if (empty($validated['author'])) {
                $validated['author'] = ['Unknown'];
            }
            if (empty($validated['genre'])) {
                $validated['genre'] = ['Uncategorized'];
            }

            // Handle cover image from URL if provided
            if (!empty($validated['cover_url'])) {
                Log::debug('Processing cover image from URL', ['url' => $validated['cover_url']]);
                try {
                    $coverPath = $this->importCoverImageFromUrl($validated['cover_url']);
                    if ($coverPath) {
                        $validated['cover'] = $coverPath;
                        Log::debug('Cover image imported successfully', ['path' => $coverPath]);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to import cover image from URL', [
                        'url' => $validated['cover_url'],
                        'error' => $e->getMessage(),
                    ]);
                }
                // Remove the cover_url from validated data as it's not stored in the document
                unset($validated['cover_url']);
            }

            // Process series data - ensure we use seriesName instead of name
            if (!empty($validated['series'])) {
                Log::debug('Processing series data', ['series' => $validated['series']]);
                $seriesData = [];
                foreach ($validated['series'] as $series) {
                    $seriesName = $series['seriesName'] ?? $series['name'] ?? null;
                    $seriesNumber = $series['number'] ?? '';
                    if (!empty($seriesName)) {
                        // Normalize the series name
                        $seriesName = $this->normalizeSeriesName($seriesName);
                        $seriesData[] = [
                            'seriesName' => $seriesName,
                            'number' => $seriesNumber,
                        ];
                    }
                }
                $validated['series'] = $seriesData;
                Log::debug('Processed series data', ['processed_series' => $validated['series']]);
            }

            // Add import metadata if available
            $importPath = null;
            $importRoot = null;
            $importType = null;
            $genrePath = null;
            $directoryPath = null;

            if (!empty($validated['import_path']) && !empty($validated['import_root'])) {
                $importPath = $validated['import_path'];
                $importRoot = $validated['import_root'];
                $importType = $validated['import_type'] ?? 'dir';
                $genrePath = $validated['genre_path'] ?? $validated['genre'][0] ?? 'Other';
                $directoryPath = $this->buildDirectoryPath($validated);

                $validated['import_metadata'] = [
                    'path' => $importPath,
                    'root' => $importRoot,
                    'type' => $importType,
                    'imported_at' => now()->toISOString(),
                    'genre_path' => $genrePath,
                    'directory_path' => $directoryPath,
                ];

                // Remove these fields as they're not stored directly in the document
                unset($validated['import_path'], $validated['import_root'], $validated['import_type'], $validated['genre_path']);
            }

            // Create the book in the document store
            $this->documentStoreService->createBook($validated);
            Log::info('Book imported successfully', ['id' => $id]);

            // If we have import path information, attempt to move the files to the library
            if ($importPath && $importRoot && $directoryPath) {
                try {
                    // Use the ImportFileController to move the files
                    $importFileController = app()->make('App\Http\Controllers\Admin\ImportFileController');

                    $moveRequest = new Request([
                        'path' => $importPath,
                        'root' => $importRoot,
                        'genrePath' => $genrePath,
                        'directoryPath' => $directoryPath,
                        'type' => $importType,
                    ]);

                    Log::info('Attempting to move imported files to library', [
                        'path' => $importPath,
                        'root' => $importRoot,
                        'genrePath' => $genrePath,
                        'directoryPath' => $directoryPath,
                        'type' => $importType,
                    ]);

                    $moveResult = $importFileController->moveSelected($moveRequest);
                    $moveData = json_decode($moveResult->getContent(), true);

                    if (isset($moveData['success']) && $moveData['success']) {
                        Log::info('Successfully moved imported files to library', [
                            'newPath' => $moveData['newPath'] ?? 'Unknown'
                        ]);
                    } else {
                        Log::warning('Failed to move imported files to library', [
                            'message' => $moveData['message'] ?? 'Unknown error',
                            'details' => $moveData['details'] ?? ''
                        ]);
                    }
                } catch (\Exception $moveException) {
                    Log::error('Exception while moving imported files to library', [
                        'error' => $moveException->getMessage(),
                        'trace' => $moveException->getTraceAsString(),
                    ]);
                    // Don't throw the exception - we still want to return the book import success
                }
            }

            // Fire the NewBookAdded event
            event(new NewBookAdded(['id' => $id, 'title' => $validated['title']]));

            if ($request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Book imported successfully',
                    'id' => $id,
                    'redirect' => route('admin.books.edit', ['book' => $id]),
                ]);
            }

            return redirect()->route('admin.books.edit', ['book' => $id])
                ->with('success', 'Book imported successfully.');
        } catch (\Exception $e) {
            Log::error('Book import failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Import failed: ' . $e->getMessage(),
                ], 422);
            }

            return back()->withErrors(['error' => 'Import failed: ' . $e->getMessage()])->withInput();
        }
    }

    /**
     * Build a directory path from book metadata
     *
     * @param array $bookData Book metadata
     * @return string Formatted directory path
     */
    private function buildDirectoryPath(array $bookData): string
    {
        $parts = [];

        // Use the first genre as the genre path if not explicitly provided
        $genrePath = $bookData['genre_path'] ?? ($bookData['genre'][0] ?? 'Other');
        $parts[] = $genrePath;

        // Add author (use first author if multiple)
        if (!empty($bookData['author'])) {
            $parts[] = is_array($bookData['author']) ? $bookData['author'][0] : $bookData['author'];
        }

        // Add series if available
        if (!empty($bookData['series'])) {
            $series = $bookData['series'][0] ?? null;
            if (is_array($series) && !empty($series['seriesName'])) {
                $parts[] = $series['seriesName'];
            } elseif (is_string($series)) {
                $parts[] = $series;
            }
        }

        // Add title
        if (!empty($bookData['title'])) {
            $parts[] = $bookData['title'];
        }

        // Join parts with directory separator
        $path = implode('/', array_filter($parts));

        return $path ?: 'Unknown';
    }

    /**
     * Display the specified book.
     *
     * @param  string  $book
     * @return \Illuminate\Contracts\View\View
     */
    public function show($book)
    {
        $documentStore = $this->documentStoreService;
        $book = $documentStore->getBook($book);
        if (!$book) {
            abort(404, 'Book not found');
        }

        return view('admin.books.show', ['book' => $book]);
    }

    /**
     * Show the form for editing the specified book.
     *
     * @param  string  $book
     * @return \Illuminate\Contracts\View\View
     */
    public function edit($book)
    {
        $documentStore = $this->documentStoreService;
        $book = $documentStore->getBook($book);
        if (!$book) {
            abort(404, 'Book not found');
        }
        // Normalize genreList to flat array of strings
        $genreListRaw = $documentStore->listGenres();
        $genreList = [];
        foreach ($genreListRaw as $g) {
            if (is_array($g) && isset($g['name'])) {
                $genreList[] = (string) $g['name'];
            } elseif (is_string($g)) {
                $genreList[] = $g;
            }
        }
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
        // Set coverAuto to the filename of the book's coverImage if present
        if (!empty($book['coverImage'])) {
            $coverAuto = basename($book['coverImage']);
        }

        // DEBUG: Log type and value of book['genre']
        Log::debug('BookController@edit: genre raw', [
            'type' => is_object($book['genre']) ? get_class($book['genre']) : gettype($book['genre']),
            'value' => $book['genre'],
        ]);
        // Hotfix: forcibly cast BSONArray to array if still present
        if ($book['genre'] instanceof \MongoDB\Model\BSONArray) {
            $book['genre'] = (array) $book['genre'];
        }
        // Normalize selected genres for the form
        $genres = [];
        if (!empty($book['genre'])) {
            if (is_array($book['genre'])) {
                foreach ($book['genre'] as $g) {
                    $genres[] = trim((string) $g);
                }
            } else {
                $genres[] = trim((string) $book['genre']);
            }
        }
        // Also allow old input to override
        $genres = old('genre', $genres);
        if (!is_array($genres)) {
            $genres = [$genres];
        }

        return view('admin.books.edit', [
            'book' => $book,
            'genreList' => $genreList,
            'genres' => $genres,
            'coverCandidates' => $coverCandidates,
            'coverAuto' => $coverAuto,
            'directoryPath' => $directoryPath,
            'isModal' => request()->ajax() || request('isModal', false),
        ]);
    }

    /**
     * Store a newly created book in storage.
     *
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        Log::info('Book creation started', ['request_data' => $request->except(['cover', 'coverImage'])]);

        try {
            Log::debug('Validating book creation request', ['request_data' => $request->all()]);

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'author' => 'required|array',
                'author.*' => 'required|string|max:255',
                'genre' => 'required|array',
                'genre.*' => 'required|string|max:255',
                'narrator' => 'nullable|array',
                'narrator.*' => 'nullable|string|max:255',
                'series' => 'nullable|array',
                'series.*.seriesName' => 'nullable|string|max:255',
                'series.*.name' => 'nullable|string|max:255', // For backward compatibility
                'series.*.number' => 'nullable|string|max:50',
                'description' => 'nullable|string',
                'publishedYear' => 'nullable|integer|min:1000|max:' . (date('Y') + 10),
                'directoryPath' => 'nullable|string',
                'duration' => 'nullable|string|max:50',
                'coverImage' => 'nullable|string',
                'cover' => 'nullable|image|max:5120',
                'language' => 'nullable|string|max:50',
                'sourcePath' => 'nullable|string',
                'sourceRoot' => 'nullable|string',
                'sourceRelPath' => 'nullable|string',
                'sourceType' => 'nullable|string',
                'importMode' => 'nullable|boolean',
                'publisher' => 'nullable|string|max:255',
                'isbn' => 'nullable|string|max:50',
                'asin' => 'nullable|string|max:50',
                'googleBooksId' => 'nullable|string|max:50',
                'goodreadsId' => 'nullable|string|max:50',
                // Import-specific fields
                'genrePath' => 'nullable|string',
            ]);

            // Generate a unique ID for the new book
            $id = (string) Str::uuid();
            $validated['id'] = $id;

            // Handle empty arrays
            if (empty($validated['author'])) {
                $validated['author'] = ['Unknown'];
            }

            if (empty($validated['genre'])) {
                $validated['genre'] = ['Uncategorized'];
            }

            // Handle cover image upload
            if ($request->hasFile('cover')) {
                Log::info('Processing cover image upload');
                $file = $request->file('cover');
                $directoryPath = $validated['directoryPath'] ?? 'uploads/' . date('Y-m-d');

                // Get storage path from environment or use default
                $storageBasePath = env('BOOK_STORAGE_PATH');

                if (!empty($storageBasePath)) {
                    // Ensure directory exists if we have a storage path
                    $fullDirectoryPath = rtrim($storageBasePath, '/') . '/' . ltrim($directoryPath, '/');
                    if (!is_dir($fullDirectoryPath)) {
                        mkdir($fullDirectoryPath, 0755, true);
                    }
                } else {
                    Log::warning('BOOK_STORAGE_PATH environment variable not set, using default storage');
                }

                $coverName = 'cover.' . $file->getClientOriginalExtension();
                $file->storeAs($directoryPath, $coverName, 'books');
                $validated['coverImage'] = $coverName;
                Log::info('Cover image saved', ['path' => $directoryPath . '/' . $coverName]);
            } elseif ($request->filled('coverImageUrl')) {
                // Handle external cover image URL from Google Books
                $coverUrl = $request->input('coverImageUrl');
                $directoryPath = $validated['directoryPath'] ?? '';
                $googleBooksId = $request->input('googleBooksId') ?? $validated['googleBooksId'] ?? null;

                if (!empty($directoryPath)) {
                    try {
                        // Validate URL before attempting to download
                        if (!filter_var($coverUrl, FILTER_VALIDATE_URL)) {
                            Log::error('Invalid Google Books cover image URL format', ['url' => $coverUrl]);
                            return back()
                                ->withInput()
                                ->withErrors(['coverImageUrl' => 'Invalid image URL format']);
                        }

                        $result = $this->externalCoverService->downloadCoverImage(
                            $coverUrl,
                            $directoryPath,
                            'googlebooks',
                            $googleBooksId
                        );

                        if ($result['success']) {
                            $validated['coverImage'] = basename($result['path']);
                            Log::info('Google Books cover image downloaded successfully', [
                                'path' => $result['path'],
                            ]);
                        } else {
                            // Return with error if download fails
                            Log::error('Failed to download Google Books cover image', [
                                'url' => $coverUrl,
                                'error' => $result['error'] ?? 'Unknown error'
                            ]);

                            return back()
                                ->withInput()
                                ->withErrors(['coverImageUrl' => 'Failed to download cover image: ' . ($result['error'] ?? 'Unknown error')]);
                        }
                    } catch (\Exception $e) {
                        Log::error('Exception while downloading Google Books cover image', [
                            'url' => $coverUrl,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);

                        return back()
                            ->withInput()
                            ->withErrors(['coverImageUrl' => 'Error downloading cover image: ' . $e->getMessage()]);
                    }
                } else {
                    // No directory path, can't download
                    Log::error('No directory path for Google Books cover download', ['url' => $coverUrl]);
                    return back()
                        ->withInput()
                        ->withErrors(['directoryPath' => 'Directory path is required to download cover image']);
                }
            } elseif ($request->filled('audibleCoverImageUrl')) {
                // Handle Audible cover image URL
                $coverUrl = $request->input('audibleCoverImageUrl');
                $directoryPath = $validated['directoryPath'] ?? '';
                $asin = $request->input('audibleId') ?? $validated['asin'] ?? null;

                if (!empty($directoryPath)) {
                    try {
                        // Validate URL before attempting to download
                        if (!filter_var($coverUrl, FILTER_VALIDATE_URL)) {
                            Log::error('Invalid Audible cover image URL format', ['url' => $coverUrl]);
                            return back()
                                ->withInput()
                                ->withErrors(['audibleCoverImageUrl' => 'Invalid image URL format']);
                        }

                        $result = $this->externalCoverService->downloadCoverImage(
                            $coverUrl,
                            $directoryPath,
                            'audible',
                            $asin
                        );

                        if ($result['success']) {
                            $validated['coverImage'] = basename($result['path']);
                            Log::info('Audible cover image downloaded successfully', [
                                'path' => $result['path'],
                            ]);
                        } else {
                            // Return with error if download fails
                            Log::error('Failed to download Audible cover image', [
                                'url' => $coverUrl,
                                'error' => $result['error'] ?? 'Unknown error'
                            ]);

                            return back()
                                ->withInput()
                                ->withErrors(['audibleCoverImageUrl' => 'Failed to download cover image: ' . ($result['error'] ?? 'Unknown error')]);
                        }
                    } catch (\Exception $e) {
                        Log::error('Exception while downloading Audible cover image', [
                            'url' => $coverUrl,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);

                        return back()
                            ->withInput()
                            ->withErrors(['audibleCoverImageUrl' => 'Error downloading cover image: ' . $e->getMessage()]);
                    }
                } else {
                    // No directory path, can't download
                    Log::error('No directory path for Audible cover download', ['url' => $coverUrl]);
                    return back()
                        ->withInput()
                        ->withErrors(['directoryPath' => 'Directory path is required to download cover image']);
                }
            } elseif (!empty($validated['coverImage'])) {
                // Handle coverImage from import metadata (could be URL or embedded data)
                $coverImage = $validated['coverImage'];
                $directoryPath = $validated['directoryPath'] ?? '';

                // Check if user selected embedded cover from import
                if ($coverImage === 'embedded_from_import' && session()->has('import_cover_image')) {
                    $coverImage = session('import_cover_image');
                    session()->forget('import_cover_image');
                }

                if (!empty($directoryPath)) {
                    // Check if it's a URL
                    if (filter_var($coverImage, FILTER_VALIDATE_URL)) {
                        try {
                            $localCoverPath = $this->importCoverImageFromUrl($coverImage, $directoryPath);
                            if ($localCoverPath) {
                                $validated['coverImage'] = basename($localCoverPath);
                                Log::info('Cover image downloaded from import URL', [
                                    'url' => $coverImage,
                                    'path' => $localCoverPath,
                                ]);
                            }
                        } catch (\Exception $e) {
                            Log::warning('Failed to download cover from import URL', [
                                'url' => $coverImage,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    } elseif (is_array($coverImage) && isset($coverImage['data'])) {
                        // Handle embedded image data from audio metadata
                        try {
                            $imageData = $coverImage['data'];
                            $mimeType = $coverImage['mime'] ?? 'image/jpeg';

                            // Determine file extension from MIME type
                            $ext = 'jpg';
                            if (strpos($mimeType, 'png') !== false) {
                                $ext = 'png';
                            } elseif (strpos($mimeType, 'gif') !== false) {
                                $ext = 'gif';
                            }

                            // Save embedded image to directory
                            $storagePath = env('BOOK_STORAGE_PATH');
                            $fullDir = rtrim($storagePath, '/') . '/' . ltrim($directoryPath, '/');

                            if (!is_dir($fullDir)) {
                                mkdir($fullDir, 0775, true);
                            }

                            $filename = 'cover.' . $ext;
                            $fullPath = $fullDir . '/' . $filename;

                            if (file_put_contents($fullPath, $imageData) !== false) {
                                $validated['coverImage'] = $filename;
                                Log::info('Embedded cover image saved', ['path' => $fullPath]);
                            }
                        } catch (\Exception $e) {
                            Log::warning('Failed to save embedded cover image', [
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }

            // Process series data - ensure we use seriesName instead of name
            if (!empty($validated['series'])) {
                Log::debug('Processing series data', ['series' => $validated['series']]);
                $seriesData = [];
                foreach ($validated['series'] as $series) {
                    // Check if we have seriesName (new format) or name (old format)
                    $seriesName = $series['seriesName'] ?? $series['name'] ?? null;
                    $seriesNumber = $series['number'] ?? '';

                    if (!empty($seriesName)) {
                        // Create properly structured series entry with seriesName field
                        $seriesData[] = [
                            'seriesName' => $seriesName,
                            'number' => $seriesNumber,
                        ];
                    }
                }
                $validated['series'] = $seriesData;
                Log::debug('Processed series data', ['processed_series' => $validated['series']]);
            }

            // Create the book
            $documentStore = $this->documentStoreService;
            $actualId = $documentStore->createBook($validated);

            if (!$actualId) {
                throw new \Exception('Failed to create book in document store');
            }

            // Use the actual document ID for all subsequent operations
            $id = $actualId;
            Log::info('Book created successfully', ['id' => $id]);

            // Handle import file moving if this is from import
            if (
                !empty($validated['importMode']) &&
                !empty($validated['sourcePath']) &&
                !empty($validated['sourceRoot']) &&
                !empty($validated['directoryPath'])
            ) {

                try {
                    $importFileController = app()->make('App\Http\Controllers\Admin\ImportFileController');

                    $moveSuccess = $importFileController->moveImportedFiles(
                        $validated['sourcePath'],
                        $validated['sourceRoot'],
                        $validated['sourceRelPath'] ?? '',
                        $validated['sourceType'] ?? 'dir',
                        $validated['directoryPath']
                    );

                    if ($moveSuccess) {
                        Log::info('Import files moved successfully', [
                            'bookId' => $id,
                            'from' => $validated['sourcePath'],
                            'to' => $validated['directoryPath'],
                        ]);
                    } else {
                        Log::warning('Failed to move import files', [
                            'bookId' => $id,
                            'from' => $validated['sourcePath'],
                            'to' => $validated['directoryPath'],
                        ]);
                    }
                } catch (\Exception $moveException) {
                    // Log but don't fail the book creation
                    Log::error('Exception during import file move', [
                        'bookId' => $id,
                        'error' => $moveException->getMessage(),
                        'sourcePath' => $validated['sourcePath'],
                    ]);
                }
            }

            // Fire event for any listeners
            event(new NewBookAdded(['id' => $id, 'title' => $validated['title']]));

            if ($request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Book created successfully',
                    'id' => $id,
                    'redirect_url' => route('admin.books.edit', ['book' => $id]),
                ]);
            }

            return redirect()->route('admin.books.edit', ['book' => $id])
                ->with('success', 'Book created successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Book creation validation failed', [
                'errors' => $e->errors(),
            ]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('Book creation failed', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create book: ' . $e->getMessage(),
                ], 500);
            }

            return redirect()->back()
                ->withInput()
                ->withErrors(['error' => 'Failed to create book: ' . $e->getMessage()]);
        }
    }

    /**
     * Update the specified book in storage.
     *
     * @param  string  $book
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, $book)
    {
        Log::info('Updating book: ' . $book . ' ' . print_r($request->all(), true));
        $documentStore = $this->documentStoreService;
        $id = $book; // Store the ID before overwriting $book variable
        $book = $documentStore->getBook($book);
        if (!$book) {
            return redirect()->route('admin.books.index')->withErrors(['Book not found.']);
        }

        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'author' => 'required|array|min:1',
                'author.*' => 'required|string|max:255',
                'genre' => 'required|array|min:1',
                'genre.*' => 'required|string|max:255',
                'publishedYear' => 'nullable|integer',
                'description' => 'nullable|string',
                'series' => 'nullable|array',
                'series.*.name' => 'nullable|string',
                'series.*.number' => 'nullable|string',
                'directoryPath' => 'nullable|string',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $data = $request->except(['_token', '_method']);
            if ($request->has('returnUrl')) {
                $data['returnUrl'] = $request->input('returnUrl');
            }
            return redirect()->back()->withInput($data)->withErrors($e->validator);
        }

        // Flatten and clean genres/authors
        $validated['genre'] = array_map('trim', $validated['genre']);
        $validated['author'] = array_map('trim', $validated['author']);

        // Optional fields
        if ($request->has('series')) {
            $validated['series'] = $request->input('series');
        }
        if ($request->has('directoryPath')) {
            $validated['directoryPath'] = trim($request->input('directoryPath'));
        }
        if ($request->has('description')) {
            $validated['description'] = trim($request->input('description'));
        }
        if ($request->has('publishedYear')) {
            $validated['publishedYear'] = $request->input('publishedYear');
        }

        // Move files if directoryPath changed
        $oldDirectoryPath = $book['directoryPath'] ?? null;
        $newDirectoryPath = $validated['directoryPath'] ?? null;
        if ($oldDirectoryPath && $newDirectoryPath && $oldDirectoryPath !== $newDirectoryPath) {
            Log::info('Moving files from old directory to new directory' . $oldDirectoryPath . '' . $newDirectoryPath . '' . $oldDirectoryPath . '');

            $disk = \Illuminate\Support\Facades\Storage::disk('books');
            if ($disk->exists($oldDirectoryPath)) {
                $files = $disk->allFiles($oldDirectoryPath);
                foreach ($files as $file) {
                    $filename = basename($file);
                    $disk->makeDirectory($newDirectoryPath);
                    try {
                        $disk->move($file, $newDirectoryPath . '/' . $filename);
                    } catch (\Exception $e) {
                        Log::error('Failed to move file during directory update', [
                            'oldPath' => $oldDirectoryPath,
                            'newPath' => $newDirectoryPath,
                            'file' => $file,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                // Set permissions/ownership on new directory to match old
                $storageRoot = rtrim(env('BOOK_STORAGE_PATH'), '/');
                $oldAbs = $storageRoot . '/' . ltrim($oldDirectoryPath, '/');
                $newAbs = $storageRoot . '/' . ltrim($newDirectoryPath, '/');
                if (is_dir($oldAbs) && is_dir($newAbs)) {
                    $perms = @fileperms($oldAbs) & 0777;
                    @chmod($newAbs, $perms);
                    if (function_exists('fileowner') && function_exists('filegroup') && function_exists('chown') && function_exists('chgrp')) {
                        $owner = @fileowner($oldAbs);
                        $group = @filegroup($oldAbs);
                        if ($owner !== false) {
                            @chown($newAbs, $owner);
                        }
                        if ($group !== false) {
                            @chgrp($newAbs, $group);
                        }
                    }
                }
                // Remove old (now empty) directory
                try {
                    $disk->deleteDirectory($oldDirectoryPath);
                } catch (\Exception $e) {
                    Log::error('Failed to delete old directory during directory update', [
                        'oldPath' => $oldDirectoryPath,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            // Update file references (coverImage, etc)
            if (!empty($book['coverImage'])) {
                Log::debug('Updating cover image reference', [
                    'book' => $book,
                    'newDirectoryPath' => $newDirectoryPath,
                ]);
                $oldCover = $book['coverImage'];
                $coverName = basename($oldCover);
                $validated['coverImage'] = $newDirectoryPath . '/' . $coverName;
            }
        }

        // Handle cover image upload or candidate selection
        if ($request->hasFile('coverImage') && $request->file('coverImage')->isValid()) {
            Log::info('Updating cover image', [
                'book' => $book,
                'directoryPath' => $book['directoryPath'],
            ]);
            $file = $request->file('coverImage');
            $directoryPath = $book['directoryPath'] ?? null;
            if ($directoryPath) {
                $coverName = 'cover_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $storagePath = $directoryPath . '/' . $coverName;
                // Store file in books disk
                $file->storeAs($directoryPath, $coverName, 'books');
                $validated['coverImage'] = $storagePath;
            } else {
                Log::error('Failed to update cover image', [
                    'book' => $book,
                    'directoryPath' => $directoryPath,
                ]);
            }
        } elseif (
            $request->filled('coverImageCandidate') &&
            Storage::disk('books')->exists($book['directoryPath'] . '/' . $request->input('coverImageCandidate'))
        ) {
            Log::debug('Updating cover image candidate', [
                'candidate' => $request->input('coverImageCandidate'),
            ]);
            $directoryPath = $book['directoryPath'] ?? null;
            $candidate = $request->input('coverImageCandidate');
            if ($directoryPath && $candidate) {
                $validated['coverImage'] = $directoryPath . '/' . $candidate;
            }
        } elseif ($request->filled('coverImageUrl')) {
            Log::debug('Updating cover image URL', [
                'coverImageUrl' => $request->input('coverImageUrl'),
                'coverImageSource' => $request->input('coverImageSource'),
                'coverImage' => $request->input('coverImage'),
                'coverImageCandidate' => $request->input('coverImageCandidate'),
                'audibleId' => $request->input('audibleId'),
                'googleBooksId' => $request->input('googleBooksId'),
            ]);
            // Handle external cover image URL
            $coverUrl = $request->input('coverImageUrl');
            $directoryPath = $book['directoryPath'] ?? null;
            $googleBooksId = $request->input('googleBooksId') ?? $book['googleBooksId'] ?? null;

            // Get the cover image source from the form
            $coverImageSource = $request->input('coverImageSource') ?? 'googlebooks';
            Log::info('Cover image source from form', ['source' => $coverImageSource]);

            if ($coverUrl && $directoryPath) {
                try {
                    // Validate URL before attempting to download
                    if (!filter_var($coverUrl, FILTER_VALIDATE_URL)) {
                        Log::error('Invalid Google Books cover image URL format', ['url' => $coverUrl]);
                        return back()
                            ->withInput()
                            ->withErrors(['coverImageUrl' => 'Invalid image URL format']);
                    }

                    $result = $this->externalCoverService->downloadCoverImage(
                        $coverUrl,
                        $directoryPath,
                        $coverImageSource,
                        $googleBooksId
                    );

                    if ($result['success']) {
                        $validated['coverImage'] = $result['path'];
                    } else {
                        // Log the error but continue with the update
                        Log::error('Failed to download Google Books cover image', [
                            'url' => $coverUrl,
                            'error' => $result['error'] ?? 'Unknown error'
                        ]);

                        // Return with error if download fails
                        $errorMsg = 'Failed to download cover image: ' . ($result['error'] ?? 'Unknown error');
                        return back()
                            ->withInput()
                            ->withErrors(['coverImageUrl' => $errorMsg]);
                    }
                } catch (\Exception $e) {
                    Log::error('Exception while downloading Google Books cover image', [
                        'url' => $coverUrl,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    return back()
                        ->withInput()
                        ->withErrors(['coverImageUrl' => 'Error downloading cover image: ' . $e->getMessage()]);
                }
            }
        } elseif ($request->filled('audibleCoverImageUrl')) {
            // Handle Audible cover image URL
            $coverUrl = $request->input('audibleCoverImageUrl');
            $directoryPath = $book['directoryPath'] ?? null;
            $asin = $request->input('audibleId') ?? $book['audibleId'] ?? null;

            if ($coverUrl && $directoryPath) {
                try {
                    // Validate URL before attempting to download
                    if (!filter_var($coverUrl, FILTER_VALIDATE_URL)) {
                        Log::error('Invalid Audible cover image URL format', ['url' => $coverUrl]);
                        return back()
                            ->withInput()
                            ->withErrors(['audibleCoverImageUrl' => 'Invalid image URL format']);
                    }

                    $result = $this->externalCoverService->downloadCoverImage(
                        $coverUrl,
                        $directoryPath,
                        'audible',
                        $asin
                    );

                    if ($result['success']) {
                        $validated['coverImage'] = $result['path'];
                    } else {
                        // Log the error but continue with the update
                        Log::error('Failed to download Audible cover image', [
                            'url' => $coverUrl,
                            'error' => $result['error'] ?? 'Unknown error'
                        ]);

                        // Return with error if download fails
                        $errorMsg = 'Failed to download cover image: ' . ($result['error'] ?? 'Unknown error');
                        return back()
                            ->withInput()
                            ->withErrors(['audibleCoverImageUrl' => $errorMsg]);
                    }
                } catch (\Exception $e) {
                    Log::error('Exception while downloading Audible cover image', [
                        'url' => $coverUrl,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    return back()
                        ->withInput()
                        ->withErrors(['audibleCoverImageUrl' => 'Error downloading cover image: ' . $e->getMessage()]);
                }
            }
        }

        $documentStore->updateBook($id, $validated);

        // Redirect to returnUrl if present, else fallback
        $returnUrl = $request->input('returnUrl');
        if ($returnUrl) {
            return redirect($returnUrl)->with('success', 'Book updated successfully.');
        }
        // Preserve original query params (search, filters, page, etc) on redirect
        $query = $request->query();
        return redirect()->route('admin.books.index', $query)->with('success', 'Book updated successfully.');
    }

    /**
     * Remove the specified book from storage.
     */
    public function destroy($book)
    {
        $documentStore = $this->documentStoreService;
        $documentStore->deleteBook($book);

        return redirect()->route('admin.books.index')->with('success', 'Book deleted successfully.');
    }

    public function download($id)
    {
        $documentStore = $this->documentStoreService;
        $book = $documentStore->getBook($id);
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

    /**
     * Search Google Books for books matching the given criteria.
     *
     * @deprecated Use searchBooks with source=googlebooks instead
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function googleBooks(Request $request)
    {
        $title = $request->query('title');
        $author = $request->query('author', '');
        $series = $request->query('series', '');
        $seriesNumber = $request->query('seriesNumber', '');
        if (!$title) {
            return response()->json([
                'error' => 'Title is required.',
            ], 400);
        }

        $limit = min((int) $request->query('limit', 10), 40); // Default 10, max 40

        Log::info('googleBooks search called', [
            'title' => $title,
            'author' => $author,
            'series' => $series,
            'seriesNumber' => $seriesNumber,
        ]);

        try {
            // Build the search query
            // Ensure we're properly formatting the author query parameter
            $authorQuery = '';
            if (!empty($author)) {
                // Properly format author name for the API query
                $authorQuery = " inauthor:\"{$author}\"";
                Log::debug('Adding author to Google Books query', ['author' => $author, 'authorQuery' => $authorQuery]);
            }
            $query = trim("intitle:\"{$title}\"" . $authorQuery);
            Log::debug('Google Books search query', ['query' => $query]);

            // Search Google Books API
            $results = $this->googleBooksApiService->searchBooks($query, ['limit' => $limit]);

            Log::info('googleBooks search results', ['count' => count($results)]);

            return response()->json($results);
        } catch (\Exception $e) {
            Log::error('googleBooks search failed', [
                'error' => $e->getMessage(),
                'title' => $title,
                'author' => $author,
            ]);

            return response()->json([
                'error' => 'Google Books search failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    // authorArrayContains method moved to AudibleService

    /**
     * Unified search endpoint for all book APIs (Audible, Google Books, etc.)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function searchBooks(Request $request)
    {
        $title = $request->query('title');
        $author = $request->query('author', '');
        $apiId = $request->query('api_id', '');
        $source = $request->query('source', 'audible'); // Default to audible if not specified
        $series = $request->query('series', '');
        $seriesNumber = $request->query('seriesNumber', '');
        $limit = min((int) $request->query('limit', 10), 40); // Default 10, max 40

        // Debug all request parameters
        Log::debug('Book search request parameters', [
            'all_params' => $request->all(),
            'query_params' => $request->query(),
            'title' => $title,
            'author' => $author,
            'author_type' => gettype($author),
            'author_empty' => empty($author),
            'api_id' => $apiId,
            'source' => $source,
        ]);

        // Validate required parameters
        if (!$title && !$apiId) {
            return response()->json([
                'error' => 'Title or API ID is required.',
            ], 400);
        }

        Log::info('book search called', [
            'source' => $source,
            'title' => $title,
            'author' => $author,
            'api_id' => $apiId,
            'series' => $series,
            'seriesNumber' => $seriesNumber,
        ]);

        try {
            $results = [];

            switch (strtolower($source)) {
                case 'audible':
                    if ($apiId) {
                        $bookDetails = $this->audibleService->getBookDetails($apiId);
                        if ($bookDetails) {
                            $results[] = $bookDetails; // Already transformed by the service
                        }
                    } else {
                        // Debug Audible search parameters
                        Log::debug('Audible search parameters', [
                            'title' => $title,
                            'author' => $author,
                            'author_empty' => empty($author),
                            'limit' => $limit,
                        ]);

                        // Make sure author is properly handled
                        $authorParam = !empty($author) ? $author : null;

                        $results = $this->audibleService->searchBooksWithFiltering($title, $authorParam, [
                            'limit' => $limit,
                        ]);
                    }
                    break;

                case 'googlebooks':
                    // Build the search query for Google Books
                    // Ensure we're properly formatting the author query parameter
                    $authorQuery = '';
                    if (!empty($author)) {
                        // Properly format author name for the API query with quotes
                        $authorQuery = " inauthor:\"{$author}\"";
                        Log::debug('Adding author to Google Books query', [
                            'author' => $author,
                            'authorQuery' => $authorQuery,
                            'author_empty' => empty($author),
                        ]);
                    }

                    // Ensure title is properly quoted
                    $titleQuery = !empty($title) ? "intitle:\"{$title}\"" : '';
                    $query = trim($titleQuery . $authorQuery);

                    Log::debug('Google Books search query', ['query' => $query]);
                    $results = $this->googleBooksApiService->searchBooks($query, ['limit' => $limit]);
                    break;

                default:
                    return response()->json([
                        'error' => 'Invalid source specified. Supported sources: audible, googlebooks',
                    ], 400);
            }

            Log::info($source . ' search results: ' . count($results) . ' items');

            return response()->json($results);
        } catch (\Exception $e) {
            Log::error($source . ' search failed: ' . $e->getMessage(), [
                'exception' => $e,
                'source' => $source,
                'title' => $title,
                'author' => $author,
                'api_id' => $apiId,
            ]);

            return response()->json([
                'error' => ucfirst($source) . ' search failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search Audible for books matching the given criteria.
     *
     * @deprecated Use searchBooks with source=audible instead
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function audible(Request $request)
    {
        $title = $request->query('title');
        $author = $request->query('author', '');
        $apiId = $request->query('api_id', '');

        if (!$title && !$apiId) {
            return response()->json([
                'error' => 'Title or ASIN is required.',
            ], 400);
        }

        $limit = min((int) $request->query('limit', 10), 40); // Default 10, max 40

        Log::info('audible search called', [
            'title' => $title,
            'author' => $author,
            'api_id' => $apiId,
        ]);

        try {
            $results = [];

            // If ASIN is provided, get specific book details
            if ($apiId) {
                $bookDetails = $this->audibleService->getBookDetails($apiId);
                if ($bookDetails) {
                    $results[] = $bookDetails; // Already transformed by the service
                }
            } else {
                // Otherwise search by title/author using the service method that handles filtering and fallback
                $results = $this->audibleService->searchBooksWithFiltering($title, $author, [
                    'limit' => $limit,
                ]);
            }

            Log::info('audible search results: ' . count($results) . ' items');

            return response()->json($results);
        } catch (\Exception $e) {
            Log::error('audible search failed: ' . $e->getMessage(), [
                'exception' => $e,
                'title' => $title,
                'author' => $author,
                'api_id' => $apiId,
            ]);

            return response()->json([
                'error' => 'Audible search failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Format Audible API result for the frontend.
     *
     * @param  array  $book  The book data from Audible API
     * @return array Formatted book data
     */
    protected function formatAudibleResult(array $book): array
    {
        // Start with the original data
        $result = $book;

        // Ensure we have the source field
        $result['source'] = 'Audible';

        // Keep the ID as 'id' instead of renaming to 'audibleId'
        if (isset($book['asin'])) {
            $result['id'] = $book['asin'];
            // Remove the original asin field to avoid duplication
            unset($result['asin']);
        }

        // Ensure we have a properly named cover image URL
        if (isset($book['image_url'])) {
            $result['audibleCoverImageUrl'] = $book['image_url'];
            unset($result['image_url']);
        }

        // Ensure we have a properly formatted published year

        // Use audibleAuthors field for author if available
        if (isset($book['audibleAuthors'])) {
            if (is_string($book['audibleAuthors'])) {
                $result['author'] = [$book['audibleAuthors']];
            } elseif (is_array($book['audibleAuthors'])) {
                $result['author'] = $book['audibleAuthors'];
            } else {
                $result['author'] = [];
            }
        } elseif (isset($book['author'])) {
            // Fall back to author field if audibleAuthors is not available
            if (is_string($book['author'])) {
                $result['author'] = [$book['author']];
            } elseif (is_array($book['author'])) {
                $result['author'] = $book['author'];
            } else {
                $result['author'] = [];
            }
        } else {
            $result['author'] = [];
        }

        // Ensure narrator is properly formatted
        if (isset($book['narrator'])) {
            if (is_array($book['narrator'])) {
                $result['narratorList'] = $book['narrator'];
            } elseif (is_string($book['narrator'])) {
                $result['narratorList'] = [$book['narrator']];
            } elseif (is_string($book['narrator'])) {
                $result['narratorList'] = [$book['narrator']];
            } else {
                $result['narratorList'] = [];
            }
        } else {
            $result['narratorList'] = [];
        }

        // Ensure series is properly formatted
        if (isset($book['series']) && is_array($book['series'])) {
            $seriesNames = array_keys($book['series']);
            if (!empty($seriesNames)) {
                $seriesName = $seriesNames[0];
                $seriesNumber = $book['series'][$seriesName] ?? '';
                $result['seriesName'] = $seriesName;
                $result['seriesNumber'] = $seriesNumber;
                $result['series'] = $seriesName; // For compatibility with Google Books format
            }
        } else {
            $result['seriesName'] = '';
            $result['seriesNumber'] = '';
            $result['series'] = '';
        }

        // Add category field if missing
        if (!isset($result['category'])) {
            $result['category'] = isset($book['categories']) ? $book['categories'] : [];
        }

        // Format publisher as an array
        if (isset($book['publisher'])) {
            if (is_string($book['publisher'])) {
                $result['publisher'] = [$book['publisher']];
            } elseif (is_array($book['publisher'])) {
                $result['publisher'] = $book['publisher'];
            } else {
                $result['publisher'] = [];
            }
        } else {
            $result['publisher'] = [];
        }

        // Add any other missing fields that Google Books has
        if (!isset($result['description'])) {
            $result['description'] = $book['summary'] ?? '';
        }

        // Convert all keys to camelCase for consistency with Google Books
        $camelCaseResult = [];
        foreach ($result as $key => $value) {
            // Convert snake_case to camelCase
            $camelKey = preg_replace_callback('/_([a-z])/', function ($matches) {
                return strtoupper($matches[1]);
            }, $key);

            $camelCaseResult[$camelKey] = $value;
        }

        return $camelCaseResult;
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
        $documentStore = $this->documentStoreService;
        $series = $documentStore->listSeries();
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
                // Update Documentstore books whose directoryPath matches $oldRel
                $documentStore = $this->documentStoreService;
                $booksToUpdate = array_filter($this->documentStoreService->listBooks(), function ($book) use ($oldRel) {
                    return isset($book['directoryPath']) && $book['directoryPath'] === $oldRel;
                });
                foreach ($booksToUpdate as $book) {
                    $this->documentStoreService->updateBook($book['id'], ['directoryPath' => $newRel]);
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
        $authors = $this->documentStoreService->searchAuthorsByName($term);

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
        $series = $this->documentStoreService->searchSeriesByName($term);

        return response()->json($series);
    }

    /**
     * Provides autocomplete suggestions for narrator names.
     */
    public function autocompleteNarrators(Request $request): \Illuminate\Http\JsonResponse
    {
        $term = $request->input('term', '');
        if (empty($term)) {
            return response()->json([]);
        }
        $narrators = $this->documentStoreService->searchNarratorsByName($term);

        return response()->json($narrators);
    }

    /**
     * Get the raw JSON data for a book from $documentStore
     *
     * @param  string  $book  The book ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRawJson($id)
    {
        $documentStore = $this->documentStoreService;
        $book = $documentStore->getBook($id);
        if (!$book) {
            abort(404);
        }

        return response()->json($book, 200, ['Content-Type' => 'application/json'], JSON_PRETTY_PRINT);
    }

    /**
     * Save raw JSON for a book (admin only).
     *
     * @param  string  $book
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveRawJson($id, Request $request)
    {
        $json = $request->input('json');
        if (empty($json)) {
            return response()->json(['message' => 'No JSON provided.'], 400);
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return response()->json(['message' => 'Invalid JSON: ' . $e->getMessage()], 422);
        }
        // Remove _id if present, always use string id
        unset($data['_id']);
        $data['id'] = $id;
        $documentStore = $this->documentStoreService;
        $book = $documentStore->getBook($id);
        if (!$book) {
            return response()->json(['message' => 'Book not found.'], 404);
        }
        $documentStore->updateBook($id, $data);
        return response()->json(['success' => true]);
    }
}
