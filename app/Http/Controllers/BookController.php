<?php

namespace App\Http\Controllers;

use App\Contracts\DocumentStoreServiceInterface;
use App\Services\GoogleBooksApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;

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
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        // Get pagination and filter parameters from request
        $page = max(1, (int) $request->get('page', 1));
        $perPage = (int) session('main_per_page', 24);

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

        // Get paginated and filtered books
        $result = $this->documentStoreService->listBooks($page, $perPage, $filters, true);
        $books = $result['data'];

        Log::debug(sprintf(
            'Fetched %d books (page %d of %d) with filters: %s',
            $books->count(),
            $page,
            $result['lastPage'],
            json_encode($filters)
        ));

        // Ensure all book fields are properly formatted
        $books = $books->map(function ($book) {
            return $this->ensureBookFields($book);
        });

        // Get filter options using optimized methods
        $genres = $this->documentStoreService->getUniqueValues('genre');
        $authors = $this->documentStoreService->getUniqueValues('author');
        $series = $this->documentStoreService->getUniqueValues('series', 'seriesName');

        // Get recently added books (last 30 days) - limited to 10
        $recentBooks = $this->getRecentBooks([], 10);

        // Get view preferences from session
        $mainViewType = session('main_view_type', 'grid');

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
                } else if (is_array($item) && isset($item['name'])) {
                    $value = $item['name'];
                } else if (is_string($item)) {
                    $value = $item;
                } else if (is_array($item) && count($item) === 1) {
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
    protected function getRecentBooks(array $books, int $limit = 10): array
    {
        try {
            // Use the document store service to get recent books
            $recentBooks = $this->documentStoreService->getRecentBooks($limit, 30);

            // Ensure all required fields are present in each book
            return collect($recentBooks)->map(function ($book) {
                return $this->ensureBookFields($book);
            })->all();
        } catch (\Exception $e) {
            // Log the error and return an empty array as fallback
            \Log::error('Error fetching recent books: ' . $e->getMessage());
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
        $result = $this->documentStoreService->listBooks();
        $books = $result['data']->all();
        Log::debug('JSON API: Books fetched from DocumentStoreService: ' . count($books));

        return $this->handleMainBooksAjaxRequest($request, $books);
    }

    /**
     * JSON API endpoint for recent books AJAX loading
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function jsonRecent(Request $request)
    {
        $result = $this->documentStoreService->listBooks();
        $books = $result['data']->all();
        Log::debug('JSON API: Recent books fetched from DocumentStoreService: ' . count($books));

        // Force sorting by date_added for recent books
        $request->merge([
            'sort' => 'date_added',
            'order' => 'desc',
        ]);

        return $this->handleMainBooksAjaxRequest($request, $books);
    }

    /**
     * Display a specific book
     *
     * @param  string|null  $id
     * @return \Illuminate\View\View
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
        $allBooks = $result['data']->all();

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
     * Handle AJAX request for main books listing with filtering, sorting, and pagination
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function handleMainBooksAjaxRequest(Request $request, array $books)
    {
        // Get view type from session or request
        $viewType = $request->input('view_type', session('main_view_type', 'grid'));
        session(['main_view_type' => $viewType]);

        // Apply search filter if provided
        if ($request->has('search') && !empty($request->input('search'))) {
            $search = strtolower($request->input('search'));
            $books = array_filter($books, function ($book) use ($search) {
                // Search in title
                if (isset($book['title']) && stripos($book['title'], $search) !== false) {
                    return true;
                }

                // Search in author
                if (isset($book['authors'])) {
                    foreach ($book['authors'] as $author) {
                        if (stripos($author, $search) !== false) {
                            return true;
                        }
                    }
                }

                // Search in series
                if (isset($book['series'])) {
                    foreach ($book['series'] as $series) {
                        if (isset($series['seriesName']) && stripos($series['seriesName'], $search) !== false) {
                            return true;
                        }
                    }
                }

                return false;
            });
        }

        // Apply genre filter if provided
        if ($request->has('genre_id') && !empty($request->input('genre_id'))) {
            $genreId = $request->input('genre_id');
            $books = array_filter($books, function ($book) use ($genreId) {
                if (!isset($book['genres']) || empty($book['genres'])) {
                    return false;
                }

                foreach ($book['genres'] as $genre) {
                    if (md5($genre) === $genreId) {
                        return true;
                    }
                }

                return false;
            });
        }

        // Apply author filter if provided
        if ($request->has('author_id') && !empty($request->input('author_id'))) {
            $authorId = $request->input('author_id');
            $books = array_filter($books, function ($book) use ($authorId) {
                if (!isset($book['authors']) || empty($book['authors'])) {
                    return false;
                }

                foreach ($book['authors'] as $author) {
                    if (md5($author) === $authorId) {
                        return true;
                    }
                }

                return false;
            });
        }

        // Apply series filter if provided
        if ($request->has('series_id') && !empty($request->input('series_id'))) {
            $seriesId = $request->input('series_id');
            $books = array_filter($books, function ($book) use ($seriesId) {
                if (!isset($book['series']) || empty($book['series'])) {
                    return false;
                }

                foreach ($book['series'] as $series) {
                    if (isset($series['seriesName']) && md5($series['seriesName']) === $seriesId) {
                        return true;
                    }
                }

                return false;
            });
        }

        // Apply sorting
        $sort = $request->input('sort', 'title');
        $order = $request->input('order', 'asc');

        usort($books, function ($a, $b) use ($sort, $order) {
            $result = 0;

            switch ($sort) {
                case 'title':
                    $result = strcasecmp($a['title'] ?? '', $b['title'] ?? '');
                    break;
                case 'author':
                    $authorA = $a['authors'][0] ?? '';
                    $authorB = $b['authors'][0] ?? '';
                    $result = strcasecmp($authorA, $authorB);
                    break;
                case 'date_added':
                    $dateA = isset($a['createdAt']) ? strtotime($a['createdAt']) : 0;
                    $dateB = isset($b['createdAt']) ? strtotime($b['createdAt']) : 0;
                    $result = $dateB - $dateA; // desc by default
                    break;
                default:
                    $result = strcasecmp($a['title'] ?? '', $b['title'] ?? '');
            }

            return $order === 'desc' ? -$result : $result;
        });

        // Apply pagination
        $page = max(1, (int) $request->input('page', 1));
        $perPage = (int) $request->input('per_page', session('main_per_page', 24));
        session(['main_per_page' => $perPage]);

        $total = count($books);
        $offset = ($page - 1) * $perPage;
        $paginatedBooks = array_slice($books, $offset, $perPage);

        // Ensure all required fields are present in each book and filter out non-array values
        $paginatedBooks = array_filter($paginatedBooks, 'is_array');
        $paginatedBooks = array_map([$this, 'ensureBookFields'], $paginatedBooks);

        // Return the books as JSON with pagination info
        return response()->json([
            'books' => $paginatedBooks,
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => ceil($total / $perPage),
            ],
            'view_type' => $viewType,
        ]);
    }

    /**
     * Load main books via AJAX for JavaScript-based pagination and view switching
     *
     * @deprecated Use the jsonIndex method instead
     *
     * @return \Illuminate\Http\JsonResponse
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
            'coverImage' => '/images/placeholder.png',
            'description' => 'No description available.',
            'createdAt' => date('Y-m-d H:i:s'),
            'duration' => '00:00:00',
            'narrators' => [],
            'series' => [],
        ];

        foreach ($defaults as $key => $value) {
            if (!isset($book[$key]) || empty($book[$key])) {
                $book[$key] = $value;
            }
        }

        // Ensure authors, genres, and narrators are simple arrays of names
        foreach (['authors', 'genres', 'narrators'] as $key) {
            Log::debug("Before ensureBookFields for $key: " . json_encode($book[$key] ?? 'null'));
            if (isset($book[$key]) && is_array($book[$key])) {
                $book[$key] = array_map(function ($item) {
                    return is_array($item) && isset($item['name']) ? $item['name'] : (string) $item;
                }, $book[$key]);
            } else {
                $book[$key] = [];
            }
            Log::debug("After ensureBookFields for $key: " . json_encode($book[$key] ?? 'null'));
        }

        // Ensure series is an array of objects with seriesName and number
        Log::debug("Before ensureBookFields for series: " . json_encode($book['series'] ?? 'null'));
        if (isset($book['series']) && is_array($book['series'])) {
            $book['series'] = collect($book['series'])->map(function ($seriesItem) {
                // If it's already an object with seriesName and number, return it
                if (isset($seriesItem['seriesName']) && isset($seriesItem['number'])) {
                    return $seriesItem;
                }
                // If it's an object with 'name' and 'pivot' (from MySQL relationship)
                if (isset($seriesItem['name']) && isset($seriesItem['pivot']['series_number'])) {
                    return [
                        'seriesName' => $seriesItem['name'],
                        'number' => (int) $seriesItem['pivot']['series_number'],
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
        Log::debug("After ensureBookFields for series: " . json_encode($book['series'] ?? 'null'));

        return $book;
    }

}
