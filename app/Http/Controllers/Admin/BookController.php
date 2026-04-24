<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Events\NewBookAdded;
use App\Http\Controllers\Controller;
use App\Services\AudioFileAnalyzer;
use App\Services\AudibleService;
use App\Services\AudiobookBayService;
use App\Services\BookDirectoryMoveService;
use App\Services\BookDirectoryParser;
use App\Services\ExternalCoverService;
use App\Services\GoogleBooksApiService;
use App\Services\HardcoverService;
use App\Traits\BookImportTrait;
use App\Traits\HandlesLibraryJson;
use App\Traits\ProcessesBookData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BookController extends Controller
{
    use BookImportTrait;
    use HandlesLibraryJson;
    use ProcessesBookData;

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
     * @var DocumentStoreServiceInterface
     */
    protected DocumentStoreServiceInterface $documentStoreService;

    protected ExternalCoverService $externalCoverService;

    protected AudioFileAnalyzer $audioFileAnalyzer;

    public function __construct(
        DocumentStoreServiceInterface $documentStoreService,
        ExternalCoverService $externalCoverService,
        AudioFileAnalyzer $audioFileAnalyzer
    ) {
        $this->documentStoreService = $documentStoreService;
        $this->externalCoverService = $externalCoverService;
        $this->audioFileAnalyzer = $audioFileAnalyzer;
    }

    public function index(Request $request)
    {
        if (config('library_profiles.active_source_mode') === 'librivox') {
            return redirect()->route('admin.librivox.index');
        }

        // Emergency: Increase memory limit aggressively
        try {
            // Get pagination and filter parameters from request
            $page = max(1, (int) $request->input('page', 1));
            $perPage = 20;

            // Get filters from request
            $filters = [];
            if ($request->filled('author')) {
                $filters['author'] = $request->input('author');
            }
            if ($request->filled('series')) {
                $filters['series'] = $request->input('series');
            }
            if ($request->filled('genre')) {
                $filters['genre'] = $request->input('genre');
            } elseif ($request->filled('genre_id')) {
                $filters['genre_id'] = $request->input('genre_id');
            }

            $tokens = $this->parseSearchTokens($request->input('search', ''));
            if ($tokens['author_id']) {
                $filters['author_id'] = $tokens['author_id'];
                unset($filters['author']);
            }
            if ($tokens['genre_id']) {
                $filters['genre_id'] = $tokens['genre_id'];
                unset($filters['genre']);
            }
            if ($tokens['series_id']) {
                $filters['series_id'] = $tokens['series_id'];
                unset($filters['series']);
            }
            if ($tokens['search'] !== '') {
                $filters['search'] = $tokens['search'];
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

        // Recalculate duration from audio files on disk
        $directoryPath = $validated['directoryPath'] ?? ($book['directoryPath'] ?? null);
        if ($directoryPath) {
            $bookRoot = rtrim((string) config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
            $absolutePath = $bookRoot . '/' . ltrim($directoryPath, '/');
            if (is_dir($absolutePath)) {
                $durationInfo = $this->audioFileAnalyzer->getDirectoryAudioDuration($absolutePath);
                if ($durationInfo['total_seconds'] > 0) {
                    $documentStore->updateBook($id, ['duration' => (int) $durationInfo['total_seconds']]);
                    Log::info("Recalculated duration for book {$id}: {$durationInfo['formatted']}");
                }
            }
        }

        // Redirect to returnUrl if present, else fallback to last list or main index
        $returnUrl = $request->input('returnUrl') ?? session('last_admin_list_url') ?? route('admin.books.index');
        return redirect($returnUrl)->with('success', 'Book updated successfully');
    }

    public function autofillFromPath(Request $request, string $book)
    {
        $returnUrl = $request->input('return_url') ?? session('last_admin_list_url') ?? route('admin.books.index');
        $bookData = $this->documentStoreService->getBook($book);

        if (!$bookData) {
            return redirect($returnUrl)->with('error', 'Book not found.');
        }

        $directoryPath = trim((string) ($bookData['directoryPath'] ?? ''));
        if ($directoryPath === '') {
            return redirect($returnUrl)->with('error', 'Cannot autofill: book has no directory path.');
        }

        $seed = $this->buildAutofillSearchSeed($directoryPath);
        if ($seed['title'] === '') {
            return redirect($returnUrl)->with('error', 'Could not derive a title from the directory path.');
        }

        $match = $this->findBestAutofillMatch($seed['title'], $seed['author']);
        if ($match === null) {
            return redirect($returnUrl)
                ->with('error', 'No metadata match found from path-derived search (Audible/Google/AudiobookBay/Hardcover).');
        }

        $updates = $this->buildAutofillUpdatesFromResult($match['result'], $match['source'], $bookData, $seed);
        if (empty($updates)) {
            return redirect($returnUrl)->with('error', 'Autofill match found, but no usable metadata was returned.');
        }

        $this->documentStoreService->updateBook($book, $updates);

        return redirect($returnUrl)->with(
            'success',
            'Autofilled metadata using ' . ucfirst($match['source']) . ' from directory-path search.'
        );
    }

    private function buildAutofillSearchSeed(string $directoryPath): array
    {
        $normalizedPath = trim(str_replace('\\', '/', $directoryPath), '/');
        $segments = array_values(array_filter(explode('/', $normalizedPath), fn ($part) => $part !== ''));
        $leaf = $segments[count($segments) - 1] ?? '';

        $title = trim($leaf);
        $author = '';
        $series = '';

        if (str_contains($leaf, ' - ')) {
            [$left, $right] = array_map('trim', explode(' - ', $leaf, 2));
            if ($right !== '') {
                $author = $left;
                $title = $right;
            }
        }

        if ($title !== '') {
            $title = preg_replace('/^\d+\s*[-._)]\s*/', '', $title) ?: $title;
            $title = preg_replace('/\s+\(Unabridged\)$/i', '', $title) ?: $title;
            $title = trim($title);
        }

        try {
            $parsedAuthor = app(BookDirectoryParser::class)->extractAuthorFromPath($normalizedPath);
            if ($parsedAuthor !== '' && strtolower($parsedAuthor) !== 'unknown author') {
                $author = $parsedAuthor;
            }
        } catch (\Throwable $e) {
            Log::debug('autofillFromPath: failed to parse author from path', [
                'directoryPath' => $directoryPath,
                'error' => $e->getMessage(),
            ]);
        }

        if (count($segments) >= 2) {
            $parent = trim($segments[count($segments) - 2]);
            if ($parent !== '' && !str_contains($parent, ' - ') && $parent !== $author) {
                $series = $parent;
            }
        }

        return [
            'title' => $title,
            'author' => trim($author),
            'series' => $series,
        ];
    }

    private function findBestAutofillMatch(string $title, string $author): ?array
    {
        $audibleService = app(AudibleService::class);
        $audibleResults = $audibleService->searchBooksWithFiltering($title, $author !== '' ? $author : null, ['limit' => 10]);
        if (!empty($audibleResults)) {
            return [
                'source' => 'audible',
                'result' => $audibleResults[0],
            ];
        }

        $fallbackCandidates = [];

        try {
            $googleService = app(GoogleBooksApiService::class);
            $authorQuery = $author !== '' ? ' inauthor:"' . $author . '"' : '';
            $query = trim('intitle:"' . $title . '"' . $authorQuery);
            $googleResults = $googleService->searchBooks($query, ['limit' => 10]);
            foreach ($googleResults as $result) {
                $fallbackCandidates[] = ['source' => 'googlebooks', 'result' => $result];
            }
        } catch (\Throwable $e) {
            Log::warning('autofillFromPath: Google Books fallback search failed', ['error' => $e->getMessage()]);
        }

        try {
            $audiobookBayService = app(AudiobookBayService::class);
            $searchQuery = trim($title . ' ' . $author);
            $audiobookBayResults = $audiobookBayService->searchBooks($searchQuery, ['limit' => 10]);
            foreach ($audiobookBayResults ?? [] as $result) {
                $fallbackCandidates[] = ['source' => 'audiobookbay', 'result' => $result];
            }
        } catch (\Throwable $e) {
            Log::warning('autofillFromPath: AudiobookBay fallback search failed', ['error' => $e->getMessage()]);
        }

        try {
            $hardcoverService = app(HardcoverService::class);
            if ($hardcoverService->isAvailable()) {
                $hardcoverResults = $hardcoverService->searchBooks($title, [
                    'author' => $author,
                    'limit' => 10,
                ]);
                foreach ($hardcoverResults ?? [] as $result) {
                    $fallbackCandidates[] = ['source' => 'hardcover', 'result' => $result];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('autofillFromPath: Hardcover fallback search failed', ['error' => $e->getMessage()]);
        }

        if (empty($fallbackCandidates)) {
            return null;
        }

        usort($fallbackCandidates, function (array $left, array $right) use ($title, $author): int {
            return $this->scoreAutofillCandidate($right['result'], $title, $author)
                <=> $this->scoreAutofillCandidate($left['result'], $title, $author);
        });

        return $fallbackCandidates[0];
    }

    private function scoreAutofillCandidate(array $result, string $expectedTitle, string $expectedAuthor): int
    {
        $score = 0;

        $candidateTitle = trim((string) ($result['title'] ?? ''));
        $candidateAuthorList = $this->extractAuthorsFromResult($result);
        $candidateAuthor = trim((string) ($candidateAuthorList[0] ?? ''));

        if ($candidateTitle !== '') {
            similar_text(
                $this->normalizeForSimilarity($candidateTitle),
                $this->normalizeForSimilarity($expectedTitle),
                $titleSimilarity
            );
            $score += (int) round($titleSimilarity);
        }

        if ($expectedAuthor !== '' && $candidateAuthor !== '') {
            $expected = $this->normalizeForSimilarity($expectedAuthor);
            $actual = $this->normalizeForSimilarity($candidateAuthor);
            if ($expected === $actual) {
                $score += 40;
            } elseif (str_contains($actual, $expected) || str_contains($expected, $actual)) {
                $score += 20;
            }
        }

        if ($this->extractCoverUrlFromResult($result) !== null) {
            $score += 5;
        }

        return $score;
    }

    private function normalizeForSimilarity(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9\s]/', ' ', $value) ?: $value;
        $value = preg_replace('/\s+/', ' ', $value) ?: $value;

        return trim($value);
    }

    private function buildAutofillUpdatesFromResult(array $result, string $source, array $bookData, array $seed): array
    {
        $updates = [];

        $resolvedTitle = trim((string) ($result['title'] ?? $seed['title'] ?? ''));
        if ($resolvedTitle !== '') {
            $updates['title'] = $resolvedTitle;
        }

        $authors = $this->extractAuthorsFromResult($result);
        if (empty($authors) && !empty($seed['author'])) {
            $authors = [$seed['author']];
        }

        if (!empty($authors)) {
            $updates['author'] = $authors;
            try {
                $updates['authors'] = $this->documentStoreService->findOrCreateMany('authors', $authors);
            } catch (\Throwable $e) {
                Log::warning('autofillFromPath: author ID resolution failed', ['error' => $e->getMessage()]);
            }
        }

        $narrators = $this->extractNarratorsFromResult($result);
        if (!empty($narrators)) {
            $updates['narrator'] = $narrators;
            try {
                $updates['narrators'] = $this->documentStoreService->findOrCreateMany('narrators', $narrators);
            } catch (\Throwable $e) {
                Log::warning('autofillFromPath: narrator ID resolution failed', ['error' => $e->getMessage()]);
            }
        }

        [$seriesName, $seriesNumber] = $this->extractSeriesFromResult($result);
        if ($seriesName === '' && !empty($seed['series'])) {
            $seriesName = trim((string) $seed['series']);
        }
        if ($seriesName !== '') {
            $existingSeries = $this->documentStoreService->getSeriesByName($seriesName);
            $seriesId = $existingSeries['id'] ?? null;
            if (!$seriesId) {
                $seriesId = $this->documentStoreService->createSeries($seriesName);
            }
            $updates['series'] = [[
                'id' => $seriesId,
                'seriesName' => $seriesName,
                'number' => $seriesNumber,
            ]];
        }

        $description = trim((string) ($result['description'] ?? ''));
        if ($description !== '') {
            $updates['description'] = $description;
        }

        $releaseDate = $this->extractReleaseDateFromResult($result);
        if ($releaseDate !== null) {
            $updates['release_date'] = $releaseDate;
        }

        if ($source === 'audible') {
            $audibleId = trim((string) ($result['id'] ?? $result['asin'] ?? ''));
            if ($audibleId !== '') {
                $updates['audibleId'] = $audibleId;
            }
        }
        if ($source === 'googlebooks') {
            $googleBooksId = trim((string) ($result['id'] ?? $result['googlebooksId'] ?? ''));
            if ($googleBooksId !== '') {
                $updates['googleBooksId'] = $googleBooksId;
            }
        }
        if ($source === 'hardcover') {
            $hardcoverId = trim((string) ($result['id'] ?? $result['hardcoverId'] ?? ''));
            if ($hardcoverId !== '') {
                $updates['hardcoverId'] = $hardcoverId;
            }
        }

        $coverUrl = $this->extractCoverUrlFromResult($result);
        $directoryPath = trim((string) ($bookData['directoryPath'] ?? ''));
        if ($coverUrl !== null && $directoryPath !== '') {
            $coverSource = $source === 'audible' ? 'audible' : 'googlebooks';
            $sourceId = trim((string) ($result['id'] ?? ''));

            $coverResult = $this->externalCoverService->downloadCoverImage(
                $coverUrl,
                $directoryPath,
                $coverSource,
                $sourceId !== '' ? $sourceId : null
            );

            if (($coverResult['success'] ?? false) === true && !empty($coverResult['path'])) {
                $updates['coverImage'] = basename((string) $coverResult['path']);
            }
        }

        return $updates;
    }

    private function extractAuthorsFromResult(array $result): array
    {
        $raw = $result['author'] ?? $result['authors'] ?? $result['audibleAuthors'] ?? [];
        $names = [];

        if (is_string($raw)) {
            $names[] = $raw;
        } elseif (is_array($raw)) {
            foreach ($raw as $value) {
                if (is_string($value)) {
                    $names[] = $value;
                    continue;
                }

                if (is_array($value)) {
                    $authorValue = $value['author'] ?? null;
                    if (is_string($authorValue)) {
                        $names[] = $authorValue;
                        continue;
                    }

                    if (is_array($authorValue) && isset($authorValue['name']) && is_string($authorValue['name'])) {
                        $names[] = $authorValue['name'];
                        continue;
                    }

                    if (isset($value['name']) && is_string($value['name'])) {
                        $names[] = $value['name'];
                    }
                }
            }
        }

        $names = array_values(array_unique(array_filter(array_map('trim', $names), fn ($name) => $name !== '')));

        return $names;
    }

    private function extractNarratorsFromResult(array $result): array
    {
        $raw = $result['narrator'] ?? $result['narrators'] ?? $result['audibleNarrators'] ?? [];
        $names = [];

        if (is_string($raw)) {
            $names[] = $raw;
        } elseif (is_array($raw)) {
            foreach ($raw as $value) {
                if (is_string($value)) {
                    $names[] = $value;
                    continue;
                }

                if (is_array($value)) {
                    $narratorValue = $value['narrator'] ?? null;
                    if (is_string($narratorValue)) {
                        $names[] = $narratorValue;
                        continue;
                    }

                    if (is_array($narratorValue) && isset($narratorValue['name']) && is_string($narratorValue['name'])) {
                        $names[] = $narratorValue['name'];
                        continue;
                    }

                    if (isset($value['name']) && is_string($value['name'])) {
                        $names[] = $value['name'];
                    }
                }
            }
        }

        $names = array_values(array_unique(array_filter(array_map('trim', $names), fn ($name) => $name !== '')));

        return $names;
    }

    private function extractSeriesFromResult(array $result): array
    {
        $seriesName = '';
        $seriesNumber = '';

        if (isset($result['series']) && is_string($result['series'])) {
            $seriesName = trim($result['series']);
        } elseif (isset($result['series']) && is_array($result['series'])) {
            if (isset($result['series']['name']) && is_string($result['series']['name'])) {
                $seriesName = trim($result['series']['name']);
                $seriesNumber = trim((string) ($result['series']['part'] ?? ''));
            } else {
                $firstKey = array_key_first($result['series']);
                if (is_string($firstKey)) {
                    $seriesName = trim($firstKey);
                    $seriesNumber = trim((string) ($result['series'][$firstKey] ?? ''));
                }
            }
        }

        if (isset($result['seriesName']) && is_string($result['seriesName']) && trim($result['seriesName']) !== '') {
            $seriesName = trim($result['seriesName']);
        }
        if (isset($result['seriesNumber']) && trim((string) $result['seriesNumber']) !== '') {
            $seriesNumber = trim((string) $result['seriesNumber']);
        }

        return [$seriesName, $seriesNumber];
    }

    private function extractReleaseDateFromResult(array $result): ?string
    {
        $rawDate = $result['release_date'] ?? $result['releaseDate'] ?? $result['published_date'] ?? null;
        if (is_string($rawDate) && trim($rawDate) !== '') {
            try {
                return \Carbon\Carbon::parse($rawDate)->format('Y-m-d');
            } catch (\Throwable $e) {
                Log::debug('autofillFromPath: could not parse release date', ['value' => $rawDate]);
            }
        }

        $rawYear = $result['publishedYear'] ?? $result['year'] ?? null;
        if ($rawYear !== null && preg_match('/^\d{4}$/', (string) $rawYear)) {
            return (string) $rawYear . '-01-01';
        }

        return null;
    }

    private function extractCoverUrlFromResult(array $result): ?string
    {
        $coverUrl = $result['audibleCoverImageUrl']
            ?? $result['coverImageUrl']
            ?? $result['cover_image_url']
            ?? $result['cover']
            ?? null;

        if (!is_string($coverUrl)) {
            return null;
        }

        $coverUrl = trim($coverUrl);

        return $coverUrl !== '' ? $coverUrl : null;
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
}
