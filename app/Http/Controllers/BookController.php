<?php

namespace App\Http\Controllers;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;

class BookController extends Controller
{
    /**
     * @var DocumentStoreServiceInterface
     */
    protected DocumentStoreServiceInterface $documentStoreService;

    /**
     * @var GoogleBooksApiService
     */
    protected GoogleBooksApiService $googleBooksApiService;

    /**
     * BookController constructor.
     *
     * @param  DocumentStoreServiceInterface  $documentStoreService
     * @param  GoogleBooksApiService  $googleBooksApiService
     */
    public function __construct(DocumentStoreServiceInterface $documentStoreService, GoogleBooksApiService $googleBooksApiService)
    {
        $this->documentStoreService = $documentStoreService;
        $this->googleBooksApiService = $googleBooksApiService;
    }



    /**
     * Display the main books index page
     *
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        $books = $this->documentStoreService->listBooks();
        Log::debug('Books fetched from DocumentStoreService: ' . count($books));

        // Extract unique genres from books
        $genres = [];
        foreach ($books as $book) {
            if (isset($book['genre']) && !empty($book['genre'])) {
                foreach ((array) $book['genre'] as $genre) {
                    $genreId = md5($genre);
                    $genres[$genreId] = $genre;
                }
            }
        }
        asort($genres);

        // Extract unique authors from books
        $authors = [];
        foreach ($books as $book) {
            if (isset($book['author']) && !empty($book['author'])) {
                foreach ((array) $book['author'] as $author) {
                    $authorId = md5($author);
                    $authors[$authorId] = $author;
                }
            }
        }
        asort($authors);

        // Extract unique series from books
        $series = [];
        foreach ($books as $book) {
            if (isset($book['series']) && !empty($book['series'])) {
                if (is_array($book['series'])) {
                    foreach ($book['series'] as $seriesName => $seriesNumber) {
                        $series[$seriesName] = $seriesName;
                    }
                } else {
                    $series[$book['series']] = $book['series'];
                }
            }
        }
        asort($series);

        // Get recent books (sorted by date_added)
        $recentBooks = $books;
        usort($recentBooks, function ($a, $b) {
            $dateA = isset($a['dateAdded']) ? strtotime($a['dateAdded']) : 0;
            $dateB = isset($b['dateAdded']) ? strtotime($b['dateAdded']) : 0;

            return $dateB - $dateA; // Descending order
        });
        $recentBooks = array_slice($recentBooks, 0, 10);
        $recentBooks = array_map([$this, 'ensureBookFields'], $recentBooks);

        // Get view preferences from session
        $mainViewType = session('main_view_type', 'grid');
        $mainPerPage = session('main_per_page', 24);

        return view('books.index', compact(
            'books',
            'genres',
            'authors',
            'series',
            'recentBooks',
            'mainViewType',
            'mainPerPage'
        ));
    }

    /**
     * JSON API endpoint for main books AJAX loading
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function jsonIndex(Request $request)
    {
        $books = $this->documentStoreService->listBooks();
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
        $books = $this->documentStoreService->listBooks();
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
        $book = $this->ensureBookFields($book);

        // Get related books (same author or series)
        $relatedBooks = $this->documentStoreService->listBooks();
        $relatedBooks = array_filter($relatedBooks, function ($relatedBook) use ($book, $id) {
            // Skip the current book
            if ($relatedBook['id'] === $id) {
                return false;
            }

            // Check if same author
            if (isset($relatedBook['author']) && isset($book['author'])) {
                $authors = (array) $relatedBook['author'];
                $bookAuthors = (array) $book['author'];
                if (count(array_intersect($authors, $bookAuthors)) > 0) {
                    return true;
                }
            }

            // Check if same series
            if (isset($relatedBook['series']) && isset($book['series'])) {
                if (is_array($relatedBook['series']) && is_array($book['series'])) {
                    $seriesNames = array_keys($relatedBook['series']);
                    $bookSeriesNames = array_keys($book['series']);
                    if (count(array_intersect($seriesNames, $bookSeriesNames)) > 0) {
                        return true;
                    }
                } elseif (!is_array($relatedBook['series']) && !is_array($book['series'])) {
                    if ($relatedBook['series'] === $book['series']) {
                        return true;
                    }
                }
            }

            return false;
        });

        // Limit to 6 related books
        $relatedBooks = array_slice($relatedBooks, 0, 6);
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
        $firestore = new FirestoreService();
        $book = $firestore->getBook($id);

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
                if (isset($book['author'])) {
                    foreach ((array) $book['author'] as $author) {
                        if (stripos($author, $search) !== false) {
                            return true;
                        }
                    }
                }

                // Search in series
                if (isset($book['series'])) {
                    if (is_array($book['series'])) {
                        foreach (array_keys($book['series']) as $series) {
                            if (stripos($series, $search) !== false) {
                                return true;
                            }
                        }
                    } elseif (stripos($book['series'], $search) !== false) {
                        return true;
                    }
                }

                return false;
            });
        }

        // Apply genre filter if provided
        if ($request->has('genre_id') && !empty($request->input('genre_id'))) {
            $genreId = $request->input('genre_id');
            $books = array_filter($books, function ($book) use ($genreId) {
                if (!isset($book['genre']) || empty($book['genre'])) {
                    return false;
                }

                foreach ((array) $book['genre'] as $genre) {
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
                if (!isset($book['author']) || empty($book['author'])) {
                    return false;
                }

                foreach ((array) $book['author'] as $author) {
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

                if (is_array($book['series'])) {
                    foreach (array_keys($book['series']) as $series) {
                        if (md5($series) === $seriesId) {
                            return true;
                        }
                    }

                    return false;
                }

                return md5($book['series']) === $seriesId;
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
                    $authorA = isset($a['author']) && !empty($a['author']) ? (is_array($a['author'])
                        ? $a['author'][0] : $a['author'])
                        : '';
                    $authorB = isset($b['author']) && !empty($b['author']) ? (is_array($b['author'])
                        ? $b['author'][0] : $b['author'])
                        : '';
                    $result = strcasecmp($authorA, $authorB);
                    break;
                case 'date_added':
                    $dateA = isset($a['dateAdded']) ? strtotime($a['dateAdded']) : 0;
                    $dateB = isset($b['dateAdded']) ? strtotime($b['dateAdded']) : 0;
                    $result = $dateA - $dateB;
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

        // Ensure all required fields are present in each book
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
            'title' => 'Unknown Title',
            'author' => ['Unknown Author'],
            'genre' => [],
            'cover' => '/images/default-cover.jpg',
            'description' => 'No description available.',
            'dateAdded' => date('Y-m-d'),
            'duration' => '00:00:00',
            'narrator' => ['Unknown Narrator'],
            'series' => [],
        ];

        foreach ($defaults as $key => $value) {
            if (!isset($book[$key]) || empty($book[$key])) {
                $book[$key] = $value;
            }
        }

        // Ensure author is always an array
        if (!is_array($book['author'])) {
            $book['author'] = [$book['author']];
        }

        // Ensure genre is always an array
        if (!is_array($book['genre'])) {
            $book['genre'] = $book['genre'] ? [$book['genre']] : [];
        }

        // Ensure narrator is always an array
        if (!isset($book['narrator'])) {
            $book['narrator'] = ['Unknown Narrator'];
        } elseif (!is_array($book['narrator'])) {
            $book['narrator'] = [$book['narrator']];
        }

        return $book;
    }
}
