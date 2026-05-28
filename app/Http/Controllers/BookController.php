<?php

namespace App\Http\Controllers;

use App\Contracts\DocumentStoreServiceInterface;
use App\Services\GoogleBooksApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BookController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    protected GoogleBooksApiService $googleBooksApiService;

    /**
     * BookController constructor.
     */
    public function __construct(DocumentStoreServiceInterface $documentStoreService, GoogleBooksApiService $googleBooksApiService)
    {
        $this->documentStoreService = $documentStoreService;
        $this->googleBooksApiService = $googleBooksApiService;
    }

    /**
     * Display the main books index page with pagination and filtering
     *
     * @param Request $request
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function index(Request $request)
    {
        $isLibrivoxMode = (string) config('library_profiles.active_source_mode', 'local') === 'librivox';

        // Memory monitoring
        $memoryStart = memory_get_usage();
        Log::debug('BookController index start', ['memory_mb' => round($memoryStart / 1024 / 1024, 2)]);

        // Get pagination and filter parameters from request
        $page = max(1, (int) $request->get('page', 1));
        $perPage = min((int) session('main_per_page', 12), 12); // Reduce from 24 to 12 max

        // Get filters from request
        $filters = [];
        if ($request->has('author') && !empty($request->author)) {
            $filters['author'] = $request->author;
        }
        if ($request->has('genre') && !empty($request->genre)) {
            $filters['genre'] = $request->genre;
        }
        if ($request->has('series') && !empty($request->series)) {
            $filters['series'] = $request->series;
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

        // Get paginated and filtered books with minimal relations
        $result = $this->documentStoreService->listBooks($page, $perPage, $filters, true);
        $books = $result['data'];

        Log::debug(sprintf(
            'Fetched %d books (page %d of %d) with filters: %s',
            count($books),
            $page,
            $result['lastPage'] ?? 1,
            json_encode($filters)
        ));

        // Minimal book field formatting to reduce memory usage
        $processedBooks = [];
        foreach ($books as $book) {
            $processedBooks[] = $this->ensureBookFieldsMinimal($book);
        }
        $books = collect($processedBooks);

        // Log::debug('Books data passed to books.index view: ' . json_encode($books->toArray(), JSON_PRETTY_PRINT));

        // Get filter options using optimized methods
        $genres = $this->documentStoreService->getUniqueValues('genre');
        $authors = $this->documentStoreService->getUniqueValues('author');
        $series = $this->documentStoreService->getUniqueValues('series', 'seriesName');

        // Get recently added books (last 30 days) - limited to 5 for memory conservation
        $recentBooks = $this->getRecentBooks([], 5);

        // Get view preferences from session
        $mainViewType = $isLibrivoxMode ? 'list' : session('main_view_type', 'grid');

        // Pass pagination data to the view
        $pagination = new \Illuminate\Pagination\LengthAwarePaginator(
            $books,
            $result['total'],
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );


        return view('books.index', [
            'books' => $pagination,
            'genres' => $genres,
            'authors' => $authors,
            'series' => $series,
            'recentBooks' => $recentBooks,
            'isLibrivoxMode' => $isLibrivoxMode,
            'mainViewType' => $mainViewType,
            'mainPerPage' => $perPage,
            'currentFilters' => $filters,
        ]);
    }

    /**
     * Extract unique values from a field in a collection of books
     *
     * @param array $books
     * @param string $field
     * @param string|null $subField If the field contains objects, specify the subfield to extract
     * @return array
     */
    protected function extractUniqueValues(array $books, string $field, ?string $subField = null): array
    {
        $values = [];

        foreach ($books as $book) {
            if (!isset($book[$field]) || empty($book[$field])) {
                continue;
            }

            $items = is_array($book[$field]) ? $book[$field] : [$book[$field]];

            foreach ($items as $item) {
                $value = '';

                if ($subField && is_array($item) && isset($item[$subField])) {
                    $value = $item[$subField];
                } elseif (is_array($item) && isset($item['name'])) {
                    $value = $item['name'];
                } elseif (is_string($item)) {
                    $value = $item;
                } elseif (is_array($item) && count($item) === 1) {
                    $value = reset($item);
                }

                if (!empty($value) && !in_array($value, $values, true)) {
                    $values[] = $value;
                }
            }
        }

        sort($values);
        return $values;
    }

    /**
     * Get recently added books
     *
     * @param array $books Unused parameter, kept for backward compatibility
     * @param int $limit Number of recent books to return
     * @return array
     */
    protected function getRecentBooks(array $books, int $limit = 5): array
    {
        try {
            // Use the document store service to get recent books with minimal processing
            $recentBooks = $this->documentStoreService->getRecentBooks($limit, 7); // Only 7 days back

            // Minimal processing to reduce memory usage
            $processedBooks = [];
            foreach ($recentBooks as $book) {
                $processedBooks[] = $this->ensureBookFieldsMinimal($book);
            }

            return $processedBooks;
        } catch (\Exception $e) {
            // Log the error and return an empty array as fallback
            Log::error('Error fetching recent books: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * JSON API endpoint for main books AJAX loading
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function jsonIndex(Request $request)
    {
        $isLibrivoxMode = (string) config('library_profiles.active_source_mode', 'local') === 'librivox';

        // Get pagination and filter parameters from request
        $page = max(1, (int) $request->input('page', 1));
        $perPage = (int) $request->input('per_page', session('main_per_page', 24));
        session(['main_per_page' => $perPage]);

        // Get filters from request
        $filters = [];

        // Support both xxx and xxx_id for author, genre, and series
        if ($request->has('author') && !empty($request->input('author'))) {
            $filters['author'] = $request->input('author');
        } elseif ($request->has('author_id') && !empty($request->input('author_id'))) {
            $filters['author_id'] = $request->input('author_id');
        }

        if ($request->has('genre') && !empty($request->input('genre'))) {
            $filters['genre'] = $request->input('genre');
        } elseif ($request->has('genre_id') && !empty($request->input('genre_id'))) {
            $filters['genre_id'] = $request->input('genre_id');
        }

        if ($request->has('series') && !empty($request->input('series'))) {
            $filters['series'] = $request->input('series');
        } elseif ($request->has('series_id') && !empty($request->input('series_id'))) {
            $filters['series_id'] = $request->input('series_id');
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

        // Get sorting parameters
        $sort = $request->input('sort', 'title');
        $order = $request->input('order', 'asc');

        // Get paginated and filtered books from the document store service
        $result = $this->documentStoreService->listBooks($page, $perPage, $filters, true, $sort, $order);
        $books = $result['data'];

        // Ensure all book fields are properly formatted
        $paginatedBooks = collect($books)->map(function ($book) {
            return $this->ensureBookFields($book);
        });

        // Return the books as JSON with pagination info
        $response = [
            'books' => $paginatedBooks,
            'pagination' => [
                'total' => $result['total'] ?? 0,
                'per_page' => $result['perPage'] ?? $result['per_page'] ?? $perPage,
                'current_page' => $result['currentPage'] ?? $result['current_page'] ?? $page,
                'last_page' => $result['lastPage'] ?? $result['last_page'] ?? 1,
            ],
            'view_type' => $isLibrivoxMode ? 'list' : $request->input('view_type', session('main_view_type', 'grid')),
        ];

        // Log::debug('JSON API Response for books.json: ' . json_encode($response, JSON_PRETTY_PRINT));

        return response()->json($response);
    }

    /**
     * JSON API endpoint for recent books AJAX loading
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function jsonRecent(Request $request)
    {
        $result = $this->documentStoreService->listBooks();
        $books = is_array($result['data']) ? $result['data'] : $result['data']->all();
        Log::debug('JSON API: Recent books fetched from DocumentStoreService: ' . count($books));

        // Force sorting by date_added for recent books
        $request->merge([
            'sort' => 'date_added',
            'order' => 'desc',
        ]);

        if (method_exists($this, 'handleMainBooksAjaxRequest')) {
            return $this->handleMainBooksAjaxRequest($request, $books);
        }

        // Fallback or legacy behavior if needed, otherwise this path shouldn't be reached
        // based on previous code structure, but to satisfy PHPStan:
        return response()->json(['data' => $books]);
    }

    /**
     * Display a specific book
     *
     * @param  string|null  $id
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function show(Request $request, $id = null)
    {
        if (!$id) {
            return redirect()->route('books.index');
        }

        $book = $this->documentStoreService->getBook($id);

        if (!$book) {
            return redirect()->route('books.index')->with('error', 'Book not found');
        }

        // Ensure all required fields are present
        if (!is_array($book)) {
            Log::error('Expected array from getBook but got: ' . gettype($book), ['id' => $id]);
            return redirect()->route('books.index')->with('error', 'Invalid book data');
        }

        $book = $this->ensureBookFields($book);

        // Get related books (same author or series)
        $result = $this->documentStoreService->listBooks(1, 100);
        $allBooks = $result['data'];

        $relatedBooks = array_filter($allBooks, function ($relatedBook) use ($book, $id) {
            // Skip the current book
            if ($relatedBook['id'] === $id) {
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
     * Download a book file
     *
     * @param  string  $id
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\RedirectResponse
     */
    public function download(Request $request, $id)
    {
        $documentStoreService = app(DocumentStoreServiceInterface::class);
        $book = $documentStoreService->getBook($id);

        if (!$book || !isset($book['file_path'])) {
            return redirect()->route('books.index')->with('error', 'Book file not found');
        }

        $filePath = $book['file_path'];
        $fileName = basename($filePath);

        // Check if file exists in storage
        if (!Storage::exists($filePath)) {
            return redirect()->route('books.show', $id)->with('error', 'Book file not found on server');
        }

        // Log download
        Log::info('Book downloaded', [
            'book_id' => $id,
            'title' => $book['title'] ?? 'Unknown',
            'user_id' => auth()->id() ?? 'guest',
            'ip' => $request->ip(),
        ]);

        // Return file download response
        return Response::download(storage_path('app/' . $filePath), $fileName);
    }

    /**
     * Set user preference for view type or items per page
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function setPreference(Request $request)
    {
        $type = $request->input('type');
        $value = $request->input('value');

        if ($type === 'view_type') {
            session(['main_view_type' => $value]);
        } elseif ($type === 'per_page') {
            session(['main_per_page' => (int) $value]);
        }

        return response()->json(['success' => true]);
    }



    /**
     * Load main books via AJAX for JavaScript-based pagination and view switching
     *
     * @deprecated Use the jsonIndex method instead
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function loadMainBooks(Request $request)
    {
        Log::warning('Deprecated endpoint loadMainBooks called. Use jsonIndex instead.');

        return redirect()->route('api.books.json', $request->all());
    }

    /**
     * Ensure all required fields are present in a book array
     */
    protected function ensureBookFields(array $book): array
    {
        $defaults = [
            'id' => '',
            'title' => (string) ($book['title'] ?? 'Unknown Title'),
            'authors' => [],
            'genres' => [],
            'coverImage' => null,
            'description' => 'No description available.',
            'createdAt' => date('Y-m-d H:i:s'),
            'duration' => '00:00:00',
            'narrators' => [],
            'series' => [],
            'source' => null,
        ];

        foreach ($defaults as $key => $value) {
            if (!isset($book[$key]) || empty($book[$key])) {
                $book[$key] = $value;
            }
        }

        // Ensure authors, genres, and narrators are simple arrays of names
        foreach (['authors', 'genres', 'narrators'] as $key) {
            if (isset($book[$key]) && is_array($book[$key])) {
                $book[$key] = array_map(function ($item) {
                    return is_array($item) && isset($item['name']) ? $item['name'] : (string) $item;
                }, $book[$key]);
            } else {
                $book[$key] = [];
            }
        }

        // Ensure series is an array of objects with seriesName and number
        if (is_array($book['series'])) {
            $book['series'] = collect($book['series'])->map(function ($seriesItem) {
                // If it's already an object with seriesName and number, return it
                if (isset($seriesItem['seriesName']) && isset($seriesItem['number'])) {
                    return $seriesItem;
                }
                // If it's an object with 'name' and 'pivot' (from MySQL relationship)
                if (isset($seriesItem['name']) && isset($seriesItem['pivot']['series_number'])) {
                    $sn = $seriesItem['pivot']['series_number'];
                    return [
                        'seriesName' => $seriesItem['name'],
                        'number' => is_numeric($sn) && str_contains((string) $sn, '.') ? (float) $sn : (int) $sn,
                    ];
                }
                // If it's a simple string, assume it's the series name and number is 1
                if (is_string($seriesItem)) {
                    return [
                        'seriesName' => $seriesItem,
                        'number' => 1, // Default to 1 if not specified
                    ];
                }
                return null; // Or handle other unexpected formats
            })->filter()->values()->all();
        } else {
            $book['series'] = [];
        }

        $book['coverImage'] = $this->processCoverImage($book['coverImage'] ?? null, $book['directoryPath'] ?? null);
        $book['hasCoverImage'] = $book['coverImage'] !== null;

        return $book;
    }

    /**
     * Minimal book field processing for memory conservation
     */
    protected function ensureBookFieldsMinimal(array $book): array
    {
        // Handle authors
        $authors = ['Unknown'];
        if (isset($book['authors']) && is_array($book['authors'])) {
            $authors = array_map(function ($author) {
                return is_array($author) && isset($author['name']) ? $author['name'] : (string) $author;
            }, array_slice($book['authors'], 0, 2));
        }

        // Handle genres
        $genres = ['Unknown'];
        if (isset($book['genres']) && is_array($book['genres'])) {
            $genres = array_map(function ($genre) {
                return is_array($genre) && isset($genre['name']) ? $genre['name'] : (string) $genre;
            }, array_slice($book['genres'], 0, 1));
        }

        // Handle series
        $series = [];
        if (isset($book['series']) && is_array($book['series'])) {
            $series = array_slice($book['series'], 0, 1);
        }

        return [
            'id' => $book['id'] ?? '',
            'title' => (string) ($book['title'] ?? 'Unknown Title'),
            'authors' => $authors,
            'genres' => $genres,
            'coverImage' => $this->processCoverImage($book['coverImage'] ?? null, $book['directoryPath'] ?? null),
            'hasCoverImage' => !empty($book['coverImage']),
            'description' => substr($book['description'] ?? 'No description available.', 0, 200),
            'series' => $series,
            'source' => $book['source'] ?? null,
        ];
    }

    /**
     * Process cover image URL efficiently with directory path handling
     */
    protected function processCoverImage(?string $coverImage, ?string $directoryPath = null): ?string
    {
        if (empty($coverImage)) {
            return null;
        }

        // If it's already a full URL, return as-is (avoid double processing)
        if (Str::startsWith($coverImage, ['http://', 'https://'])) {
            return $this->normalizeCoverImageUrl($coverImage);
        }

        // If it's an absolute path (like /images/placeholder.png), convert to asset URL
        if (Str::startsWith($coverImage, '/')) {
            return $this->normalizeCoverImageUrl(url($coverImage));
        }

        // Handle relative paths: always prefer directoryPath + basename when available.
        $coverFilename = basename($coverImage);

        $finalCoverPath = $coverFilename;
        if (!empty($directoryPath)) {
            $directoryPath = trim((string) $directoryPath, '/');

            if ($directoryPath !== '') {
                if (Str::contains($coverImage, $directoryPath . '/')) {
                    $finalCoverPath = trim($coverImage, '/');
                } else {
                    $finalCoverPath = $directoryPath . '/' . $coverFilename;
                }
            }
        }

        $encodedPath = str_replace(['%2F'], ['/'], rawurlencode($finalCoverPath));

        return $this->normalizeCoverImageUrl(url('/cover/' . $encodedPath));
    }

    protected function normalizeCoverImageUrl(string $url): string
    {
        if (Str::startsWith($url, 'http://')) {
            return 'https://' . substr($url, 7);
        }

        return $url;
    }
}
