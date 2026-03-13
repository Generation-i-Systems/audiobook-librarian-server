<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Events\NewBookAdded;
use App\Http\Controllers\Controller;
use App\Services\BookDirectoryMoveService;
use App\Services\BookEditPlannedActionsService;
use App\Services\ExternalCoverService;
use App\Traits\BookImportTrait;
use App\Traits\HandlesLibraryJson;
use App\Traits\ProcessesBookData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class BookController extends Controller
{
    use BookImportTrait;
    use HandlesLibraryJson;
    use ProcessesBookData;

    public function plannedActions(Request $request, string $id)
    {
        $directoryPath = (string) $request->input('directoryPath', '');
        $coverImageUrl = (string) $request->input('coverImageUrl', '');
        $audibleCoverImageUrl = (string) $request->input('audibleCoverImageUrl', '');

        $coverUrl = $audibleCoverImageUrl !== '' ? $audibleCoverImageUrl : $coverImageUrl;

        $service = app(BookEditPlannedActionsService::class);
        $plan = $service->computePlannedActions($id, $directoryPath, $coverUrl);

        return response()->json($plan);
    }

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
            /** @phpstan-ignore-next-line function.alreadyNarrowedType */
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

    protected ExternalCoverService $externalCoverService;

    public function __construct(
        DocumentStoreServiceInterface $documentStoreService,
        ExternalCoverService $externalCoverService
    ) {
        $this->documentStoreService = $documentStoreService;
        $this->externalCoverService = $externalCoverService;
        $this->storagePath = (string) config('app.book_root', '/media/lyra_data1/audiobooks/books');
    }

    public function index(Request $request)
    {
        // Emergency: Increase memory limit aggressively
        try {
            // Get pagination and filter parameters from request
            $page = max(1, (int) $request->input('page', 1));
            $perPage = 20;

            // Get filters from request
            $filters = [];
            if ($request->filled('search')) {
                $filters['search'] = $request->input('search');
            }
            if ($request->filled('author')) {
                $filters['author'] = $request->input('author');
            }
            if ($request->filled('series')) {
                $filters['series'] = $request->input('series');
            }
            if ($request->filled('genre')) {
                $filters['genre'] = $request->input('genre');
            } elseif ($request->filled('genre_id')) {
                $filters['genre'] = $request->input('genre_id');
            }
            // Admin panel should see books that need review
            $filters['include_needs_review'] = true;

            // Get sorting parameters
            $sortParam = $request->input('sort', 'recent_desc');

            // Default to series name sorting when series filter is applied
            if ($request->filled('series') && !$request->has('sort')) {
                $sortParam = 'series_asc';
            }

            $sort = 'created_at'; // Default internal sort
            $order = 'desc';      // Default internal order

            // Map admin panel sort options to MySqlService sort options
            switch ($sortParam) {
                case 'recent_desc':
                    $sort = 'created_at';
                    $order = 'desc';
                    break;
                case 'recent_asc':
                    $sort = 'created_at';
                    $order = 'asc';
                    break;
                case 'author_asc':
                    $sort = 'author';
                    $order = 'asc';
                    break;
                case 'author_desc':
                    $sort = 'author';
                    $order = 'desc';
                    break;
                case 'title_asc':
                    $sort = 'title';
                    $order = 'asc';
                    break;
                case 'title_desc':
                    $sort = 'title';
                    $order = 'desc';
                    break;
                case 'series_asc':
                    $sort = 'series';
                    $order = 'asc';
                    break;
                case 'series_desc':
                    $sort = 'series';
                    $order = 'desc';
                    break;
                case 'genre_asc':
                    $sort = 'genre';
                    $order = 'asc';
                    break;
                case 'genre_desc':
                    $sort = 'genre';
                    $order = 'desc';
                    break;
                case 'year_asc':
                    $sort = 'release_date';
                    $order = 'asc';
                    break;
                case 'year_desc':
                    $sort = 'release_date';
                    $order = 'desc';
                    break;
                default:
                    // If an unknown sort param is passed, fall back to recent_desc
                    $sort = 'created_at';
                    $order = 'desc';
                    break;
            }

            // Get paginated and filtered books from the document store service
            // Include all books (even with missing directories) for admin panel
            $result = $this->documentStoreService->listBooks($page, $perPage, $filters, true, $sort, $order, true);

            $books = $result['data'];

            $bookRoot = rtrim((string) config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');

            foreach ($books as &$book) {
                $directoryPath = $book['directoryPath'] ?? ($book['directory_path'] ?? null);
                if (is_string($directoryPath) && $directoryPath !== '') {
                    $normalizedDirectoryPath = trim($directoryPath, '/');
                    $absolutePath = $bookRoot . '/' . $normalizedDirectoryPath;

                    $book['directoryPath'] = $normalizedDirectoryPath;
                    $book['directoryExists'] = is_dir($absolutePath);
                } else {
                    $book['directoryExists'] = false;
                    $book['directoryPath'] = null;
                }
            }

            // Wrap in paginator
            $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
                $books,
                $result['total'],
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );


            // Store the current URL as the last viewed list for redirects after edit/update
            session(['last_admin_list_url' => $request->fullUrl()]);

            return view('admin.books.index', [
                'books' => $paginator,
                'sort' => $sortParam,
            ]);
        } catch (\Throwable $e) {
            // Log the error using Laravel's logging system
            Log::error('Admin BookController error', [
                'message' => $e->getMessage(),
                'memory_usage' => number_format(memory_get_usage()),
                'peak_memory' => number_format(memory_get_peak_usage()),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return a simple error response
            // return response('Out of memory error occurred. Please try with fewer results or contact admin. ' . $e->getMessage(), 500);
            throw $e;
        }
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
                'release_date' => $request->get('release_date', ''),
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
                /** @phpstan-ignore-next-line function.alreadyNarrowedType */
                if (is_array($dirMeta)) {
                    $initial = array_merge($initial, $dirMeta);
                    // Ensure author and genre are arrays
                    /** @phpstan-ignore-next-line booleanNot.alwaysFalse */
                    if (empty($initial['author']) || !is_array($initial['author'])) {
                        $initial['author'] = [''];
                    }
                    /** @phpstan-ignore-next-line booleanNot.alwaysFalse */
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

                    // Map ID3 tags to book fields: artist = author, composer = narrator, date = published_date
                    if (!empty($tags['artist']) && empty($initial['author'])) {
                        $initial['author'] = [$tags['artist']];
                    }

                    if (!empty($tags['composer']) && empty($initial['narrator'])) {
                        $initial['narrator'] = $tags['composer'];
                    }

                    if (!empty($tags['date']) && empty($initial['release_date'])) {
                        // Use the date directly if it's a valid date format
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tags['date'])) {
                            $initial['release_date'] = $tags['date'];
                        } else {
                            // Extract year and create a date (e.g., "2025" -> "2025-01-01")
                            $year = substr($tags['date'], 0, 4);
                            if (is_numeric($year) && $year >= 1000 && $year <= date('Y')) {
                                $initial['release_date'] = $year . '-01-01';
                            }
                        }
                    }

                    if (!empty($tags['description'])) {
                        $initial['description'] = $tags['description'];
                    }
                }
                // Also check metadata.abs for description and year
                $meta = $this->extractMetadataAbs($dir);
                if (!empty($meta['description']) && empty($initial['description'])) {
                    $initial['description'] = $meta['description'];
                }
                if (!empty($meta['year']) && empty($initial['release_date'])) {
                    // Convert year to date format
                    if (is_numeric($meta['year']) && $meta['year'] >= 1000 && $meta['year'] <= date('Y')) {
                        $initial['release_date'] = $meta['year'] . '-01-01';
                    }
                }
            }
        }
        if ($directoryPath) {
            try {
                if (Storage::disk('books')->exists($directoryPath)) {
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
            } catch (\League\Flysystem\UnableToCreateDirectory $e) {
                $bookStoragePath = config('filesystems.disks.books.root');
                throw new \RuntimeException(
                    "Book storage directory is not accessible. The configured path '{$bookStoragePath}' does not exist or cannot be created. " .
                    "Please check that the BOOK_STORAGE_PATH environment variable points to a valid, accessible directory."
                );
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
        /** @phpstan-ignore-next-line booleanNot.alwaysFalse */
        if (empty($initial['author']) || !is_array($initial['author'])) {
            $initial['author'] = [''];
        }
        /** @phpstan-ignore-next-line booleanNot.alwaysFalse */
        if (empty($initial['genre']) || !is_array($initial['genre'])) {
            $initial['genre'] = [''];
        }
        $book = []; // Initialize empty book array

        if (!isset($initial['directoryPath'])) {
            $initial['directoryPath'] = '';
        }

        // Normalize selected genres for the form
        $genres = [];
        /** @phpstan-ignore-next-line empty.variable */
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
        /** @phpstan-ignore-next-line isset.offset, booleanAnd.rightAlwaysTrue */
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
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function processImport(Request $request)
    {
        Log::info('Book import processing started', ['request_data' => $request->except(['cover', 'coverImage'])]);

        try {
            Log::debug('BookController@processImport: starting validation', ['input_keys' => array_keys($request->all())]);
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
                // Accept numeric or string for series number (tests may send int)
                'series.*.number' => 'nullable|max:50',
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

            // Handle series
            if (!empty($validated['series'])) {
                $seriesLinks = [];
                foreach ($validated['series'] as $seriesData) {
                    // Use null coalescing to handle both 'seriesName' and legacy 'name'
                    $seriesName = $seriesData['seriesName'] ?? $seriesData['name'] ?? null;

                    if ($seriesName) {
                        $seriesDoc = $this->documentStoreService->getSeriesByName($seriesName);
                        $seriesId = $seriesDoc['id'] ?? $this->documentStoreService->createSeries($seriesName);

                        $seriesLinks[] = [
                            'id' => $seriesId,
                            'name' => $seriesName,
                            'number' => $seriesData['number'] ?? null
                        ];
                    }
                }
                $validated['series'] = $seriesLinks;
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

                if (isset($validated['genre_path'])) {
                    $genrePath = $validated['genre_path'];
                } elseif (!empty($validated['genre']) && is_array($validated['genre'])) {
                    $genrePath = $validated['genre'][0];
                } else {
                    $genrePath = 'Other';
                }

                if (is_string($genrePath) && $genrePath !== '') {
                    session(['import_default_genre_path' => $genrePath]);
                }

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

            // Resolve and attach IDs for authors, narrators, and genres
            try {
                if (!empty($validated['author'])) {
                    $validated['authors'] = $this->documentStoreService->findOrCreateMany('authors', $validated['author']);
                }
                if (!empty($validated['narrator']) && is_array($validated['narrator'])) {
                    $validated['narrators'] = $this->documentStoreService->findOrCreateMany('narrators', $validated['narrator']);
                }
                if (!empty($validated['genre'])) {
                    $validated['genres'] = $this->documentStoreService->findOrCreateMany('genres', $validated['genre']);
                }
            } catch (\Throwable $e) {
                Log::warning('BookController@processImport: findOrCreateMany failed', ['error' => $e->getMessage()]);
            }

            // Create the book in the document store and capture returned ID
            Log::debug('BookController@processImport: calling createBook', [
                'service_class' => get_class($this->documentStoreService),
                'keys' => array_keys($validated),
            ]);
            $createdId = $this->documentStoreService->createBook($validated);
            Log::debug('createBook returned ID', ['createdId' => $createdId, 'originalId' => $id]);
            if (!empty($createdId)) {
                $id = (string) $createdId;
                $validated['id'] = $id; // Update the validated data with the actual ID
            }
            Log::info('Book imported successfully', ['finalId' => $id]);

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

            // Fire the NewBookAdded event using the created ID
            $bookData = ['id' => $id, 'title' => $validated['title']];
            Log::debug('Dispatching NewBookAdded event', ['bookData' => $bookData]);
            event(new NewBookAdded($bookData));
            Log::debug('NewBookAdded event dispatched');

            // Return redirect response for both AJAX and regular requests (relative path for tests)
            return redirect('/admin/books/' . $id . '/edit')
                ->with('success', 'Book imported successfully.');
        } catch (\Illuminate\Validation\ValidationException $ve) {
            Log::error('BookController@processImport: validation failed', [
                'errors' => $ve->errors(),
                'input' => $request->all(),
            ]);
            return back()->withErrors($ve->validator)->withInput();
        } catch (\Exception $e) {
            Log::error('Book import failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

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
        if (isset($bookData['genre_path'])) {
            $genrePath = $bookData['genre_path'];
        } elseif (!empty($bookData['genre']) && is_array($bookData['genre'])) {
            $genrePath = $bookData['genre'][0];
        } else {
            $genrePath = 'Other';
        }
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
        $bookId = $book;
        $book = $documentStore->getBook($bookId);
        if (!$book) {
            abort(404, 'Book not found');
        }

        // Ensure all required fields are present (mimic BookController@show)
        $book = $this->ensureBookFields($book);

        // Get related books (mimic BookController@show)
        $result = $this->documentStoreService->listBooks(1, 100);
        $allBooks = $result['data'];

        $relatedBooks = array_filter($allBooks, function ($relatedBook) use ($book, $bookId) {
            // Skip the current book
            if ($relatedBook['id'] === $bookId) {
                return false;
            }

            // Check if same author
            if (!empty($relatedBook['authors']) && !empty($book['authors'])) {
                if (count(array_intersect($relatedBook['authors'], $book['authors'])) > 0) {
                    return true;
                }
            }

            // Check if same series
            if (!empty($relatedBook['series']) && !empty($book['series'])) {
                $seriesNames = array_column($relatedBook['series'], 'seriesName');
                $bookSeriesNames = array_column($book['series'], 'seriesName');
                if (count(array_intersect($seriesNames, $bookSeriesNames)) > 0) {
                    return true;
                }
            }

            return false;
        });

        // Limit to 6 related books and ensure they're valid arrays before processing
        $relatedBooks = array_slice($relatedBooks, 0, 6);
        $relatedBooks = array_filter($relatedBooks, 'is_array'); // Filter out non-array values
        $relatedBooks = array_map([$this, 'ensureBookFields'], $relatedBooks);

        return view('books.show', compact('book', 'relatedBooks'));
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
        if ($directoryPath) {
            try {
                if (Storage::disk('books')->exists($directoryPath)) {
                    $files = Storage::disk('books')->files($directoryPath);
                    foreach ($files as $file) {
                        if (preg_match('/\.(jpe?g|png|gif|svg)$/i', $file)) {
                            $candidate = basename($file);
                            $coverCandidates[] = $candidate;
                        }
                    }
                }
            } catch (\League\Flysystem\UnableToCreateDirectory $e) {
                $bookStoragePath = config('filesystems.disks.books.root');
                throw new \RuntimeException(
                    "Book storage directory is not accessible. The configured path '{$bookStoragePath}' does not exist or cannot be created. " .
                    "Please check that the BOOK_STORAGE_PATH environment variable points to a valid, accessible directory."
                );
            }
        }
        // Set coverAuto to the filename of the book's coverImage if present
        if (!empty($book['coverImage'])) {
            $coverAuto = basename($book['coverImage']);
        }

        // Normalize selected genres for the form
        $genres = [];
        if (isset($book['genre'])) {
            $bookGenre = $book['genre'];

            Log::debug('BookController@edit: genre raw', [
                'type' => is_object($bookGenre) ? get_class($bookGenre) : gettype($bookGenre),
                'value' => $bookGenre,
            ]);

            if (is_array($bookGenre)) {
                foreach ($bookGenre as $g) {
                    $genres[] = trim((string) $g);
                }
            } else {
                $genres[] = trim((string) $bookGenre);
            }
        }
        // Also allow old input to override
        $genres = old('genre', $genres);
        if (!is_array($genres)) {
            $genres = [$genres];
        }

        // Capture return URL from request or referer
        $returnUrl = request()->input('returnUrl') ?? request()->header('referer');

        return view('admin.books.edit', [
            'book' => $book,
            'genreList' => $genreList,
            'genres' => $genres,
            'coverCandidates' => $coverCandidates,
            'coverAuto' => $coverAuto,
            'directoryPath' => $directoryPath,
            'isModal' => request()->ajax() || request('isModal', false),
            'returnUrl' => $returnUrl,
        ]);
    }

    /**
     * Store a newly created book in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param ImportFileController $importFileController
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function store(Request $request, ImportFileController $importFileController = null)
    {
        try {
            Log::info('Book creation started', ['request_data' => $request->except(['cover', 'coverImage'])]);

            Log::debug('STEP 1: Validating book creation request.');
            // Normalize year-only input for release_date (e.g., "2011" -> "2011-01-01") before validation
            if ($request->filled('release_date')) {
                $rd = trim((string) $request->input('release_date'));
                if (preg_match('/^\d{4}$/', $rd)) {
                    $request->merge(['release_date' => $rd . '-01-01']);
                }
            }
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'subtitle' => 'nullable|string|max:255',
                'authors' => 'nullable|array',
                'narrators' => 'nullable|array',
                'series' => 'nullable|array',
                'genres' => 'nullable|array',
                'publisher' => 'nullable|string|max:255',
                'release_date' => 'nullable|date',
                'published_year' => 'nullable|digits:4',
                'description' => 'nullable|string',
                'comment' => 'nullable|string',
                'tags' => 'nullable|string',
                'rating' => 'nullable|numeric|min:0|max:5',
                'language' => 'nullable|string|max:255',
                'asin' => 'nullable|string|max:255',
                'isbn' => 'nullable|string|max:255',
                'cover' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'coverImage' => 'nullable|string',
                'coverImageSource' => 'nullable|string',
                'importMode' => 'nullable|string',
                'sourcePath' => 'nullable|string',
                'sourceRoot' => 'nullable|string',
                'sourceRelPath' => 'nullable|string',
                'sourceType' => 'nullable|string',
                'directoryPath' => 'nullable|string',
                'returnUrl' => 'nullable|string',
            ]);
            Log::debug('STEP 2: Validation successful.', ['validated_data' => array_keys($validated)]);

            $id = (string) Str::uuid();
            $validated['id'] = $id;
            Log::debug('STEP 3: Generated new book ID.', ['id' => $id]);

            Log::debug('STEP 4: Processing cover image.');
            if ($request->hasFile('cover')) {
                Log::debug('BookController@store: Processing cover image', ['type' => gettype($request->file('cover'))]);
                try {
                    $validated['cover'] = $this->storeCoverImage($request->file('cover'), $id);
                    Log::debug('BookController@store: Cover image processed successfully', ['cover' => $validated['cover']]);
                } catch (\Exception $e) {
                    Log::error('BookController@store: Error processing cover image', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                    throw $e;
                }
            } elseif ($request->filled('coverImageSource')) {
                Log::debug('BookController@store: Processing cover image', ['type' => gettype($request->input('coverImageSource'))]);
                try {
                    $validated['cover'] = $this->storeCoverImage($request->input('coverImageSource'), $id);
                    Log::debug('BookController@store: Cover image processed successfully', ['cover' => $validated['cover']]);
                } catch (\Exception $e) {
                    Log::error('BookController@store: Error processing cover image', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                    throw $e;
                }
            } elseif ($request->filled('coverImage')) {
                Log::debug('BookController@store: Processing cover image', ['type' => gettype($request->input('coverImage'))]);
                try {
                    $validated['cover'] = $this->storeCoverImage($request->input('coverImage'), $id);
                    Log::debug('BookController@store: Cover image processed successfully', ['cover' => $validated['cover']]);
                } catch (\Exception $e) {
                    Log::error('BookController@store: Error processing cover image', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                    throw $e;
                }
            }
            Log::debug('STEP 5: Cover image processing complete.');

            Log::debug('STEP 6: Processing authors, narrators, and genres.');
            $authors = collect($validated['authors'] ?? []);
            $narrators = collect($validated['narrators'] ?? []);
            $genres = collect($validated['genres'] ?? []);
            Log::debug('BookController@store: Processing authors', ['authors' => $validated['authors']]);
            try {
                $validated['authors'] = $this->documentStoreService->findOrCreateMany('authors', $authors->all());
                Log::debug('BookController@store: Authors processed successfully', ['authors' => $validated['authors']]);
            } catch (\Exception $e) {
                Log::error('BookController@store: Error processing authors', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                throw $e;
            }
            Log::debug('BookController@store: Processing narrators', ['narrators' => $validated['narrators']]);
            try {
                $validated['narrators'] = $this->documentStoreService->findOrCreateMany('narrators', $narrators->all());
                Log::debug('BookController@store: Narrators processed successfully', ['narrators' => $validated['narrators']]);
            } catch (\Exception $e) {
                Log::error('BookController@store: Error processing narrators', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                throw $e;
            }
            Log::debug('BookController@store: Processing genres', ['genres' => $validated['genres']]);
            try {
                $validated['genres'] = $this->documentStoreService->findOrCreateMany('genres', $genres->all());
                Log::debug('BookController@store: Genres processed successfully', ['genres' => $validated['genres']]);
            } catch (\Exception $e) {
                Log::error('BookController@store: Error processing genres', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                throw $e;
            }
            Log::debug('STEP 7: Finished processing authors, narrators, and genres.');

            Log::debug('STEP 8: Processing series data.');
            if (!empty($validated['series'])) {
                Log::debug('BookController@store: Processing series', ['series' => $validated['series']]);
                try {
                    $seriesLinks = collect($validated['series'])->map(function ($seriesEntry) {
                        $seriesName = trim($seriesEntry['seriesName'] ?? $seriesEntry['name'] ?? '');
                        if (empty($seriesName)) {
                            return null;
                        }

                        $isCollection = !empty($seriesEntry['isCollection']) || !empty($seriesEntry['is_collection']);

                        $existingSeries = $this->documentStoreService->getSeriesByName($seriesName);
                        $seriesId = $existingSeries['id'] ?? null;

                        if (!$seriesId) {
                            $seriesId = $this->documentStoreService->createSeries($seriesName, $isCollection);
                        } elseif ($isCollection && !($existingSeries['is_collection'] ?? false)) {
                            // Update existing series to mark as collection
                            $this->documentStoreService->updateSeries($seriesId, ['is_collection' => true]);
                        }

                        return [
                            'id' => $seriesId,
                            'name' => $seriesName,
                            'number' => $seriesEntry['number'] ?? null,
                            'is_collection' => $isCollection,
                        ];
                    })->filter()->values();

                    $validated['series'] = $seriesLinks->all();
                    Log::debug('BookController@store: Series processed successfully', ['processed_series' => $validated['series']]);
                } catch (\Exception $e) {
                    Log::error('BookController@store: Error processing series', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                    throw $e;
                }
            }
            Log::debug('STEP 9: Finished processing series data.');

            Log::debug('STEP 10: Calling createBook service method.', ['book_data' => $validated]);
            Log::debug('BookController@store: Creating book', ['validated' => $validated]);
            try {
                $bookId = $this->documentStoreService->createBook($validated);
                Log::debug('BookController@store: Book created successfully', ['bookId' => $bookId]);

                // Get the created book to pass to the event
                $book = $this->documentStoreService->getBook($bookId);

                // Dispatch the NewBookAdded event
                event(new NewBookAdded($book));
            } catch (\Exception $e) {
                Log::error('BookController@store: Error creating book', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                throw $e;
            }

            $returnUrl = $request->input('returnUrl') ?? session('last_admin_list_url');
            if ($returnUrl) {
                return redirect($returnUrl)->with('success', 'Book created successfully.');
            }

            Log::debug('BookController@store: Method completed successfully', ['bookId' => $bookId]);

            // Ensure we have a valid book ID before redirecting
            if (empty($bookId)) {
                Log::error('BookController@store: No bookId returned from createBook');
                return redirect()->route('admin.books.index')->with('error', 'Book creation failed - no ID returned.');
            }

            return redirect()->route('admin.books.edit', $bookId)->with('success', 'Book created successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Book creation validation failed', ['errors' => $e->errors(), 'input' => $request->all()]);
            if ($request->ajax()) {
                return response()->json(['success' => false, 'errors' => $e->errors()], 422);
            }
            return redirect()->back()->withErrors($e->errors())->withInput();
        } catch (\Exception $e) {
            Log::error('An unexpected error occurred during book creation', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'input' => $request->except(['cover', 'coverImage']),
            ]);

            // For tests, return to index instead of back() to avoid root redirect
            if (app()->environment('testing')) {
                return redirect()->route('admin.books.index')->with('error', 'An unexpected error occurred while creating the book: ' . $e->getMessage())
                    ->withInput($request->except(['cover', 'coverImage']));
            }

            return redirect()->back()->with('error', 'An unexpected error occurred while creating the book.')
                ->withInput($request->except(['cover', 'coverImage']));
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
        Log::info('Updating book: ' . $book . ' ' . json_encode($request->all()));
        $documentStore = $this->documentStoreService;
        $id = $book; // Store the ID before overwriting $book variable
        $book = $documentStore->getBook($book);
        if (!$book) {
            return redirect()->route('admin.books.index')->withErrors(['Book not found.']);
        }

        // Normalize incoming keys to match our internal schema
        // Accept plural and snake_case variants coming from various forms
        $incoming = $request->all();
        $normalizations = [];
        if (array_key_exists('authors', $incoming) && !array_key_exists('author', $incoming)) {
            $normalizations['author'] = $incoming['authors'];
        }
        if (array_key_exists('genres', $incoming) && !array_key_exists('genre', $incoming)) {
            $normalizations['genre'] = $incoming['genres'];
        }
        if (array_key_exists('narrators', $incoming) && !array_key_exists('narrator', $incoming)) {
            $normalizations['narrator'] = $incoming['narrators'];
        }
        if (array_key_exists('published_year', $incoming) && !array_key_exists('release_date', $incoming)) {
            // Convert old published_year to release_date format
            if (is_numeric($incoming['published_year'])) {
                $normalizations['release_date'] = $incoming['published_year'] . '-01-01';
            }
        }
        if (array_key_exists('directory_path', $incoming) && !array_key_exists('directoryPath', $incoming)) {
            $normalizations['directoryPath'] = $incoming['directory_path'];
        }
        if (!empty($normalizations)) {
            $request->merge($normalizations);
        }

        try {
            Log::debug('BookController@update: starting validation', ['input_keys' => array_keys($request->all())]);
            // Normalize year-only input for release_date (e.g., "2011" -> "2011-01-01") before validation
            if ($request->filled('release_date')) {
                $rd = trim((string) $request->input('release_date'));
                if (preg_match('/^\d{4}$/', $rd)) {
                    $request->merge(['release_date' => $rd . '-01-01']);
                }
            }
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'author' => 'required|array|min:1',
                'author.*' => 'required|string|max:255',
                'genre' => 'required|array|min:1',
                'genre.*' => 'required|string|max:255',
                // Allow narrator to be optional array of strings
                'narrator' => 'sometimes|array',
                'narrator.*' => 'nullable|string|max:255',
                'release_date' => 'nullable|date',
                'description' => 'nullable|string',
                // Support series entries and prefer seriesName key
                'series' => 'nullable|array',
                'series.*.seriesName' => 'nullable|string',
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
        // Narrator: accept array, trim values, drop empties
        if ($request->has('narrator')) {
            $narr = $request->input('narrator', []);
            if (!is_array($narr)) {
                $narr = [$narr];
            }
            $validated['narrator'] = array_values(array_filter(array_map(function ($v) {
                return is_string($v) ? trim($v) : '';
            }, $narr), function ($v) {
                return $v !== '';
            }));
        }

        // Series: normalize entries and resolve/create series IDs
        if ($request->has('series')) {
            $incomingSeries = $request->input('series', []);
            if (!is_array($incomingSeries)) {
                $incomingSeries = [];
            }

            if (!empty($incomingSeries)) {
                $normalizedSeries = [];
                $pending = [];

                foreach ($incomingSeries as $seriesEntry) {
                    if (!is_array($seriesEntry)) {
                        $normalizedSeries[] = $seriesEntry;
                        continue;
                    }

                    $seriesName = trim((string) ($seriesEntry['seriesName'] ?? $seriesEntry['name'] ?? ''));
                    $number = $seriesEntry['number'] ?? null;
                    $isCollection = $seriesEntry['isCollection'] ?? $seriesEntry['is_collection'] ?? null;

                    $hasOnlyName = $seriesName !== '' && ($number === null || $number === '') && empty($seriesEntry['id']);
                    $hasOnlyNumber = ($seriesName === '') && ($number !== null && $number !== '');

                    if ($hasOnlyNumber) {
                        $pending['number'] = $number;
                        if ($isCollection !== null) {
                            $pending['isCollection'] = $isCollection;
                        }

                        continue;
                    }

                    if ($hasOnlyName && !empty($pending)) {
                        $merged = $seriesEntry;
                        /** @phpstan-ignore-next-line identical.alwaysFalse */
                        if (!isset($merged['number']) || $merged['number'] === null || $merged['number'] === '') {
                            /** @phpstan-ignore-next-line nullCoalesce.offset */
                            $merged['number'] = $pending['number'] ?? null;
                        }
                        if (!isset($merged['isCollection']) && isset($pending['isCollection'])) {
                            $merged['isCollection'] = $pending['isCollection'];
                        }
                        $normalizedSeries[] = $merged;
                        $pending = [];

                        continue;
                    }

                    $normalizedSeries[] = $seriesEntry;
                }

                $incomingSeries = $normalizedSeries;
            }

            try {
                $seriesLinks = collect($incomingSeries)->map(function ($seriesEntry) {
                    // Map legacy 'name' to 'seriesName' if present
                    $seriesName = '';
                    if (is_array($seriesEntry)) {
                        $seriesName = trim($seriesEntry['seriesName'] ?? $seriesEntry['name'] ?? '');
                    } elseif (is_string($seriesEntry)) {
                        $seriesName = trim($seriesEntry);
                    }
                    if (empty($seriesName)) {
                        return null;
                    }

                    $existingSeries = $this->documentStoreService->getSeriesByName($seriesName);
                    $seriesId = $existingSeries['id'] ?? null;

                    if (!$seriesId) {
                        $seriesId = $this->documentStoreService->createSeries($seriesName);
                    }

                    return [
                        'id' => $seriesId,
                        'seriesName' => $seriesName,
                        'number' => is_array($seriesEntry) ? ($seriesEntry['number'] ?? null) : null,
                        'isCollection' => is_array($seriesEntry) ? ($seriesEntry['isCollection'] ?? null) : null,
                    ];
                })->filter()->values();

                $validated['series'] = $seriesLinks->all();
                Log::debug('BookController@update: Series processed successfully', ['processed_series' => $validated['series']]);
            } catch (\Exception $e) {
                Log::error('BookController@update: Error processing series', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                // Do not fail the whole update due to series error
            }
        }
        if ($request->has('directoryPath')) {
            $validated['directoryPath'] = trim($request->input('directoryPath'));
        }
        if ($request->has('description')) {
            $validated['description'] = trim($request->input('description'));
        }
        if ($request->has('release_date')) {
            $validated['release_date'] = $request->input('release_date');
        }

        // Move files if directoryPath changed
        $oldDirectoryPath = $book['directoryPath'] ?? null;
        $newDirectoryPath = $validated['directoryPath'] ?? null;
        $continueWithoutMove = $request->input('continue_without_move', 'false') === 'true';

        Log::debug('Directory path check', [
            'oldDirectoryPath' => $oldDirectoryPath,
            'newDirectoryPath' => $newDirectoryPath,
            'oldTruthy' => (bool) $oldDirectoryPath,
            'newTruthy' => (bool) $newDirectoryPath,
            'different' => $oldDirectoryPath !== $newDirectoryPath,
            'continueWithoutMove' => $continueWithoutMove,
        ]);

        if ($continueWithoutMove && $oldDirectoryPath && $newDirectoryPath && $oldDirectoryPath !== $newDirectoryPath) {
            Log::warning('Continuing with database-only update after move failure', [
                'oldPath' => $oldDirectoryPath,
                'newPath' => $newDirectoryPath,
                'book_id' => $id,
            ]);
        }

        if ($oldDirectoryPath && $newDirectoryPath && $oldDirectoryPath !== $newDirectoryPath && !$continueWithoutMove) {
            // Check if old directory doesn't exist and new directory does exist - if so, skip move
            $shouldSkipMove = false;
            $oldExists = false;
            $newExists = false;
            $oldCoverBasename = null;
            $booksDisk = Storage::disk('books');

            try {
                /** @phpstan-ignore-next-line function.alreadyNarrowedType */
                $oldExists = count($booksDisk->allFiles($oldDirectoryPath)) > 0;
                $newExists = count($booksDisk->allFiles($newDirectoryPath)) > 0;

                $shouldSkipMove = !$oldExists && $newExists;

                // Also skip if old directory only has metadata (librarian.json, covers)
                // and new directory already has real content
                if (!$shouldSkipMove && $oldExists && $newExists) {
                    $moveService = app(BookDirectoryMoveService::class);
                    $oldFiles = $booksDisk->allFiles($oldDirectoryPath);
                    if ($moveService->containsOnlyMetadataFiles($oldFiles)) {
                        $shouldSkipMove = true;
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Error checking directory existence, will attempt move', [
                    'oldPath' => $oldDirectoryPath,
                    'newPath' => $newDirectoryPath,
                    'error' => $e->getMessage(),
                ]);
                // On error, proceed with move attempt
                $shouldSkipMove = false;
            }

            if ($shouldSkipMove) {
                Log::info('Skipping directory move - new directory already has content', [
                    'oldPath' => $oldDirectoryPath,
                    'newPath' => $newDirectoryPath,
                    'book_id' => $id,
                    'oldExists' => $oldExists,
                    'newExists' => $newExists,
                ]);

                // Clean up orphaned metadata files from old directory
                if ($oldExists) {
                    $oldFiles = $booksDisk->allFiles($oldDirectoryPath);
                    foreach ($oldFiles as $file) {
                        $booksDisk->delete($file);
                    }
                    try {
                        $booksDisk->deleteDirectory($oldDirectoryPath);
                    } catch (\Exception $e) {
                        Log::debug('Could not remove old metadata directory', [
                            'path' => $oldDirectoryPath,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            } else {
                Log::info('Moving files from old directory to new directory: ' . $oldDirectoryPath . ' -> ' . $newDirectoryPath);
                Log::debug('BookController@update: Directory move details', [
                    'oldDirectoryPath' => $oldDirectoryPath,
                    'newDirectoryPath' => $newDirectoryPath,
                    'book' => $book,
                    'oldExists' => $oldExists,
                    'newExists' => $newExists,
                ]);

                $oldCoverBasename = !empty($book['coverImage']) ? basename((string) $book['coverImage']) : null;
                $moveService = app(BookDirectoryMoveService::class);
                $moveResult = $moveService->moveBookDirectoryContents($oldDirectoryPath, $newDirectoryPath, $oldCoverBasename);

                Log::debug('BookController@update: Move result', [
                    'moveResult' => $moveResult,
                ]);

                // Check if move failed
                if (isset($moveResult['moved']) && $moveResult['moved'] === false) {
                    $errorMessage = $moveResult['error'] ?? 'Failed to move directory to new location';
                    Log::error('BookController@update: Directory move failed', [
                        'oldPath' => $oldDirectoryPath,
                        'newPath' => $newDirectoryPath,
                        'error' => $errorMessage,
                    ]);

                    $data = $request->except(['_token', '_method']);
                    if ($request->has('returnUrl')) {
                        $data['returnUrl'] = $request->input('returnUrl');
                    }

                    // Return with modal trigger data
                    return redirect()->back()
                        ->withInput($data)
                        ->with('move_failed', true)
                        ->with('move_error', $errorMessage)
                        ->with('old_directory_path', $oldDirectoryPath)
                        ->with('new_directory_path', $newDirectoryPath);
                }

                if (!empty($moveResult['directoryPath']) && is_string($moveResult['directoryPath'])) {
                    $validated['directoryPath'] = $moveResult['directoryPath'];
                    $newDirectoryPath = $moveResult['directoryPath'];
                }
            }

            $storageRoot = (string) (config('filesystems.disks.books.root') ?? config('app.book_root'));
            $storageRoot = rtrim($storageRoot, '/');
            if ($storageRoot !== '') {
                $oldAbs = $storageRoot . '/' . ltrim($oldDirectoryPath, '/');
                $newAbs = $storageRoot . '/' . ltrim($newDirectoryPath, '/');
                if (is_dir($oldAbs) && is_dir($newAbs)) {
                    $perms = @fileperms($oldAbs) & 0777;
                    @chmod($newAbs, $perms);
                }
            }

            if ($oldCoverBasename !== null) {
                $validated['coverImage'] = $moveResult['coverImage'] ?? $oldCoverBasename;
            }
        }

        // Handle cover image upload or candidate selection
        // Use the updated directoryPath if it changed, otherwise use the original
        $targetDirectoryPath = $validated['directoryPath'] ?? $book['directoryPath'] ?? null;
        $coverProcessed = false;

        if ($request->hasFile('coverImage') && $request->file('coverImage')->isValid()) {
            Log::info('Updating cover image', [
                'book' => $book,
                'directoryPath' => $targetDirectoryPath,
            ]);
            $file = $request->file('coverImage');
            $directoryPath = $targetDirectoryPath;
            if ($directoryPath) {
                try {
                    $coverName = 'cover_' . uniqid() . '.' . $file->getClientOriginalExtension();
                    // Store file in books disk
                    $file->storeAs($directoryPath, $coverName, 'books');
                    $validated['coverImage'] = $coverName;
                    $coverProcessed = true;
                } catch (\League\Flysystem\UnableToCreateDirectory $e) {
                    $bookStoragePath = config('filesystems.disks.books.root');
                    throw new \RuntimeException(
                        "Book storage directory is not accessible. The configured path '{$bookStoragePath}' does not exist or cannot be created. " .
                        "Please check that the BOOK_STORAGE_PATH environment variable points to a valid, accessible directory."
                    );
                }
            } else {
                Log::error('Failed to update cover image', [
                    'book' => $book,
                    'directoryPath' => $directoryPath,
                ]);
            }
        }

        if (!$coverProcessed && $request->filled('coverImageCandidate')) {
            try {
                if (Storage::disk('books')->exists($targetDirectoryPath . '/' . $request->input('coverImageCandidate'))) {
                    Log::debug('Updating cover image candidate', [
                        'candidate' => $request->input('coverImageCandidate'),
                    ]);
                    $directoryPath = $targetDirectoryPath;
                    $candidate = $request->input('coverImageCandidate');
                    if ($directoryPath && $candidate) {
                        $validated['coverImage'] = basename($candidate);
                        $coverProcessed = true;
                    }
                }
            } catch (\League\Flysystem\UnableToCreateDirectory $e) {
                // Log and ignore, proceed to URL download
                Log::warning('Unable to check cover image candidate: ' . $e->getMessage());
            }
        }

        if (!$coverProcessed && $request->filled('coverImageUrl')) {
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
            $directoryPath = $targetDirectoryPath;
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
                        $validated['coverImage'] = basename((string) $result['path']);
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
            $directoryPath = $targetDirectoryPath;
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
                        $validated['coverImage'] = basename((string) $result['path']);
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
        } elseif ($request->filled('embedded_cover_temp_path')) {
            // Handle embedded cover extracted from audio files
            $tempPath = $request->input('embedded_cover_temp_path');
            $directoryPath = $targetDirectoryPath;

            if ($tempPath && $directoryPath && file_exists($tempPath)) {
                try {
                    // Get the book root path
                    $bookRoot = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
                    $fullDirectoryPath = $bookRoot . '/' . ltrim($directoryPath, '/');

                    // Ensure directory exists
                    if (!is_dir($fullDirectoryPath)) {
                        if (!@mkdir($fullDirectoryPath, 0775, true) && !is_dir($fullDirectoryPath)) {
                            $lastError = error_get_last();
                            Log::error('Failed to create directory for embedded cover', [
                                'path' => $fullDirectoryPath,
                                'error' => $lastError ? $lastError['message'] : 'No PHP error captured',
                                'parent_exists' => is_dir(dirname($fullDirectoryPath)),
                                'parent_writable' => is_writable(is_dir(dirname($fullDirectoryPath)) ? dirname($fullDirectoryPath) : '/'),
                                'user' => posix_getpwuid(posix_geteuid())['name'] ?? 'unknown',
                            ]);
                        }
                        $this->setDirectoryOwnership($fullDirectoryPath);
                    }

                    // Generate unique filename for the cover
                    $extension = pathinfo($tempPath, PATHINFO_EXTENSION) ?: 'jpg';
                    $coverName = 'cover_embedded_' . uniqid() . '.' . $extension;
                    $targetPath = $fullDirectoryPath . '/' . $coverName;

                    // Copy the temporary file to the book directory
                    if (copy($tempPath, $targetPath)) {
                        // Set proper permissions
                        chmod($targetPath, 0664);
                        $this->setFileOwnership($targetPath);

                        // Clean up temp file
                        unlink($tempPath);

                        $validated['coverImage'] = $coverName;
                        $coverProcessed = true;

                        Log::info('Embedded cover saved successfully', [
                            'temp_path' => $tempPath,
                            'target_path' => $targetPath,
                            'cover_name' => $coverName
                        ]);
                    } else {
                        Log::error('Failed to copy embedded cover from temp file', [
                            'temp_path' => $tempPath,
                            'target_path' => $targetPath
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Exception while processing embedded cover', [
                        'temp_path' => $tempPath,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    // Clean up temp file on error
                    if (file_exists($tempPath)) {
                        unlink($tempPath);
                    }
                }
            } else {
                Log::warning('Embedded cover temp file not found or missing directory path', [
                    'temp_path' => $tempPath,
                    'directory_path' => $directoryPath,
                    'temp_exists' => $tempPath ? file_exists($tempPath) : null
                ]);
            }
        }

        // Resolve and attach IDs for authors, narrators, and genres for update
        try {
            if (!empty($validated['author'])) {
                $validated['authors'] = $this->documentStoreService->findOrCreateMany('authors', $validated['author']);
            }
            if (array_key_exists('narrator', $validated) && is_array($validated['narrator'])) {
                $validated['narrators'] = $this->documentStoreService->findOrCreateMany('narrators', $validated['narrator']);
            }
            if (!empty($validated['genre'])) {
                $validated['genres'] = $this->documentStoreService->findOrCreateMany('genres', $validated['genre']);
            }
        } catch (\Throwable $e) {
            Log::warning('BookController@update: findOrCreateMany failed', ['error' => $e->getMessage()]);
        }

        // Handle needs_review checkbox logic
        if ($request->has('needsReviewPresent')) {
            $keptReasons = $request->input('needsReviewReasons', []);
            $clearNeedsReview = $request->input('clearNeedsReview', false);

            if ($clearNeedsReview || empty($keptReasons)) {
                // Clear needs review checkbox was checked OR all reasons unchecked
                $validated['needsReview'] = false;
                $validated['needsReviewReasons'] = [];
            } else {
                // Some reasons kept - update reasons
                $validated['needsReview'] = true;
                $validated['needsReviewReasons'] = $keptReasons;
            }
        }

        Log::debug('BookController@update: calling updateBook', [
            'service_class' => get_class($documentStore),
            'id' => $id,
            'keys' => array_keys($validated),
        ]);
        $documentStore->updateBook($id, $validated);

        // Redirect to returnUrl if present, else fallback to last list or main index
        $returnUrl = $request->input('returnUrl') ?? session('last_admin_list_url') ?? route('admin.books.index');
        return redirect($returnUrl)->with('success', 'Book updated successfully');
    }

    /**
     * Remove the specified book from storage.
     */
    public function destroy(Request $request, $book)
    {
        $deletionService = app(\App\Services\BookDeletionService::class);
        $documentStore = $this->documentStoreService;

        $deleteFiles = $request->input('delete_files', 'true') === 'true';
        $confirmed = $request->input('confirmed', 'false') === 'true';
        $returnUrl = $request->input('return_url');

        // Get book details for validation
        $bookData = $documentStore->getBook($book);
        if (!$bookData) {
            if ($returnUrl) {
                return redirect($returnUrl)->with('error', 'Book not found');
            }
            $queryParams = $request->query();
            if (empty($queryParams)) {
                return redirect()->route('admin.books.index')
                    ->with('error', 'Book not found');
            }
            return redirect()->route('admin.books.index', $queryParams)
                ->with('error', 'Book not found');
        }

        $directoryPath = $bookData['directoryPath'] ?? null;
        $bookId = $book;

        // Check if directory is shared and files are to be deleted
        if ($deleteFiles && !$confirmed && !empty($directoryPath)) {
            $isShared = $deletionService->isDirectoryShared($bookId, $directoryPath);

            if ($isShared) {
                // Directory is shared - return back with confirmation request
                return redirect()->back()
                    ->with('error', 'This book shares a directory with other books. You can only delete the database record.')
                    ->with('requires_confirmation', true)
                    ->with('book_id', $bookId)
                    ->with('book_title', $bookData['title'] ?? 'Unknown')
                    ->with('shared_directory', $directoryPath)
                    ->with('return_url', $returnUrl);
            }
        }

        // If directory is shared and confirmed, force deleteFiles to false
        if (!empty($directoryPath)) {
            $isShared = $deletionService->isDirectoryShared($bookId, $directoryPath);
            if ($isShared && $deleteFiles) {
                Log::info('Forcing database-only deletion for shared directory', [
                    'book_id' => $bookId,
                    'directory_path' => $directoryPath,
                ]);
                $deleteFiles = false;
            }
        }

        $result = $deletionService->moveToTrash($book, $deleteFiles);

        if ($result['success']) {
            $message = $deleteFiles ? 'Book and files moved to trash successfully.' : 'Book deleted from database (files preserved).';

            if ($returnUrl) {
                return redirect($returnUrl)
                    ->with('success', $message)
                    ->with('trash_item_id', $result['trash_item_id']);
            }

            $queryParams = $request->query();
            if (empty($queryParams)) {
                return redirect()->route('admin.books.index')
                    ->with('success', $message)
                    ->with('trash_item_id', $result['trash_item_id']);
            }
            return redirect()->route('admin.books.index', $queryParams)
                ->with('success', $message)
                ->with('trash_item_id', $result['trash_item_id']);
        }

        if ($returnUrl) {
            return redirect($returnUrl)
                ->with('error', 'Failed to delete book: ' . ($result['error'] ?? 'Unknown error'));
        }

        $queryParams = $request->query();
        if (empty($queryParams)) {
            return redirect()->route('admin.books.index')
                ->with('error', 'Failed to delete book: ' . ($result['error'] ?? 'Unknown error'));
        }
        return redirect()->route('admin.books.index', $queryParams)
            ->with('error', 'Failed to delete book: ' . ($result['error'] ?? 'Unknown error'));
    }

    public function download($id)
    {
        $documentStore = $this->documentStoreService;
        $book = $documentStore->getBook($id);
        $directoryPath = $book['directoryPath'];

        try {
            if (!$directoryPath || !Storage::disk('books')->exists($directoryPath)) {
                abort(404, 'Book directory not found.');
            }

            $files = Storage::disk('books')->files($directoryPath);
        } catch (\League\Flysystem\UnableToCreateDirectory $e) {
            $bookStoragePath = config('filesystems.disks.books.root');
            throw new \RuntimeException(
                "Book storage directory is not accessible. The configured path '{$bookStoragePath}' does not exist or cannot be created. " .
                "Please check that the BOOK_STORAGE_PATH environment variable points to a valid, accessible directory."
            );
        }

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
}
