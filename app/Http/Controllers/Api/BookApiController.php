<?php

namespace App\Http\Controllers\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\BookQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class BookApiController extends Controller
{
    /**
     * Autocomplete book series names using fuzzy search (MongoDB Atlas Search).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function autocompleteSeries(Request $request)
    {
        $query = $request->input('query', '');
        $limit = (int) $request->input('limit', 10);
        if (!$query) {
            return response()->json(['data' => []]);
        }
        $series = $this->documentStoreService->autocompleteSeries($query, $limit);

        return response()->json(['data' => $series]);
    }

    /**
     * Autocomplete author names using fuzzy search (MongoDB Atlas Search).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function autocompleteAuthors(Request $request)
    {
        $query = $request->input('query', '');
        $limit = (int) $request->input('limit', 10);
        if (!$query) {
            return response()->json(['data' => []]);
        }
        $authors = $this->documentStoreService->autocompleteAuthors($query, $limit);

        return response()->json(['data' => $authors]);
    }

    /**
     * Autocomplete narrator names using fuzzy search (MongoDB Atlas Search).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function autocompleteNarrators(Request $request)
    {
        $query = $request->input('query', '');
        $limit = (int) $request->input('limit', 10);
        if (!$query) {
            return response()->json(['data' => []]);
        }
        $narrators = $this->documentStoreService->autocompleteNarrators($query, $limit);

        return response()->json(['data' => $narrators]);
    }

    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }

    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $page = (int) $request->input('page', 1);
        $withCover = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);

        $filters = [
            'search' => $request->input('search'),
            'genre' => $request->input('genre'),
            'author' => $request->input('author'),
            'series' => $request->input('series'),
            'title' => $request->input('title'),
            'publication_date' => $request->input('publication_date'),
            'date_added' => $request->input('date_added'),
        ];

        $booksData = $this->documentStoreService->listBooks($page, $perPage, $filters);

        // Transform books to match OpenAPI spec
        $transformedBooks = [];
        if (isset($booksData['data']) && is_array($booksData['data'])) {
            $transformedBooks = array_map(function ($book) use ($withCover, $inlineCovers) {
                return $this->getBookWithCover($book, $withCover, $inlineCovers);
            }, array_filter($booksData['data'], 'is_array'));
        }

        return response()->json([
            'data' => $transformedBooks,
            'meta' => [
                'current_page' => $booksData['currentPage'] ?? $page,
                'from' => ($booksData['total'] > 0) ? (($page - 1) * $perPage) + 1 : null,
                'last_page' => $booksData['lastPage'] ?? max(1, ceil(($booksData['total'] ?? 0) / $perPage)),
                'per_page' => $perPage,
                'to' => ($booksData['total'] > 0) ? min($page * $perPage, $booksData['total']) : null,
                'total' => $booksData['total'] ?? 0,
            ],
        ]);
    }


    public function show($id, Request $request)
    {
        $book = $this->documentStoreService->getBook($id);

        if (!$book) {
            return response()->json([
                'error' => 'Book not found',
                'message' => 'The specified book could not be found',
            ], 404);
        }

        $withCover = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);

        // Transform book to match OpenAPI spec format
        return response()->json($this->getBookWithCover($book, $withCover, $inlineCovers));
    }

    public function cover($id)
    {
        $book = $this->documentStoreService->getBook($id);

        if (!$book) {
            return response()->json([
                'error' => 'Book not found',
                'message' => 'The specified book could not be found',
            ], 404);
        }

        if (empty($book['coverImage'])) {
            return response()->json([
                'error' => 'Cover not found',
                'message' => 'No cover image available for this book',
            ], 404);
        }

        $coverImage = $book['coverImage'];
        $directoryPath = $book['directoryPath'] ?? null;

        Log::info('Cover image requested for book: ' . ($book['title'] ?? '[unknown]') . ' (' . $coverImage . ')');

        // Handle both filename-only and full path formats
        $coverPath = $this->resolveCoverImagePath($coverImage, $directoryPath);

        if (!$coverPath) {
            Log::warning('Could not resolve cover image path', [
                'book_id' => $id,
                'coverImage' => $coverImage,
                'directoryPath' => $directoryPath,
            ]);

            return response()->json([
                'error' => 'Cover not found',
                'message' => 'Cover image file could not be found',
            ], 404);
        }

        // Check if the resolved path exists
        if (Storage::disk('books')->exists($coverPath)) {
            $content = Storage::disk('books')->get($coverPath);
            $mime = mime_content_type(Storage::disk('books')->path($coverPath));
            return response(
                $content,
                200
            )->header('Content-Type', $mime);
        }

        //split out context into multiple log entries
        Log::warning('Cover image file does not exist', [
            'book_id' => $id,
        ]);
        Log::warning('Cover image file does not exist', [
            'resolved_path' => $coverPath,
        ]);
        Log::warning('Cover image file does not exist', [
            'original_coverImage' => $coverImage,
        ]);
        Log::warning('Cover image file does not exist', [
            'directoryPath' => $directoryPath,
        ]);
        Log::warning('Cover image file does not exist', [
            'storage_path' => Storage::disk('books')->path($coverPath),
        ]);

        return response()->json([
            'error' => 'Cover not found',
            'message' => 'Cover image file could not be found',
        ], 404);
    }

    /**
     * Resolve cover image path, handling both filename-only and full path formats
     * Also handles filesystem corruption where directory names have stray quotes
     *
     * @param string $coverImage The cover image value from database
     * @param string|null $directoryPath The directory path for the book
     * @return string|null The resolved cover image path
     */
    private function resolveCoverImagePath(string $coverImage, ?string $directoryPath): ?string
    {
        // Clean up any corrupted paths (remove quotes, etc.)
        $coverImage = trim($coverImage, "'\"");
        $coverImage = str_replace("'/", "/", $coverImage);
        $coverImage = ltrim($coverImage, '/');

        // If it's a full path (contains slashes), use as-is
        if (str_contains($coverImage, '/')) {
            return $coverImage;
        }

        // It's just a filename - combine with directory path
        if ($directoryPath) {
            $cleanDirectoryPath = rtrim($directoryPath, '/');
            $primaryPath = $cleanDirectoryPath . '/' . $coverImage;

            // Check if the clean path exists first
            if (Storage::disk('books')->exists($primaryPath)) {
                return $primaryPath;
            }

            // Fallback: check if filesystem has corrupted directory names with trailing quotes
            // This handles cases where DB was cleaned but filesystem still has corruption
            $corruptedPath = $cleanDirectoryPath . "'/" . $coverImage;
            if (Storage::disk('books')->exists($corruptedPath)) {
                Log::info('Found cover image at corrupted filesystem path', [
                    'clean_path' => $primaryPath,
                    'corrupted_path' => $corruptedPath,
                ]);
                return $corruptedPath;
            }

            // // Try other common corruption patterns
            $patterns = [
                $cleanDirectoryPath . '"/' . $coverImage,  // Double quote
                $cleanDirectoryPath . ' /' . $coverImage,  // Space
                $cleanDirectoryPath . '\\' . $coverImage,  // Backslash
            ];

            foreach ($patterns as $pattern) {
                if (Storage::disk('books')->exists($pattern)) {
                    Log::info('Found cover image at alternative corrupted path', [
                        'clean_path' => $primaryPath,
                        'found_path' => $pattern,
                    ]);
                    return $pattern;
                }
            }

            return $primaryPath; // Return clean path even if not found (for error logging)
        }

        // No directory path available, try as-is (might be in root)
        return $coverImage;
    }


    public function browse(Request $request)
    {
        $type = $request->input('type'); // 'genre', 'author', 'series'
        $perPage = $request->input('per_page', 100);
        $search = $request->input('search');
        $dataMap = [
            'genre' => $this->documentStoreService->listGenres(),
            'author' => $this->documentStoreService->listAuthors(),
            'series' => $this->documentStoreService->listSeries(),
        ];
        if (!isset($dataMap[$type])) {
            return response()->json([
                'error' => 'Invalid browse type',
                'message' => 'The browse type must be one of: genre, author, series',
            ], 400);
        }
        $items = $dataMap[$type];
        if ($search) {
            $items = array_filter($items, function ($item) use ($search) {
                return stripos($item['name'], $search) !== false;
            });
        }
        $items = array_values($items);
        $total = count($items);
        $page = (int) $request->input('page', 1);
        $paginatedItems = array_slice($items, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'data' => $paginatedItems,
            'meta' => [
                'current_page' => $page,
                'from' => ($total > 0) ? (($page - 1) * $perPage) + 1 : null,
                'last_page' => max(1, ceil($total / $perPage)),
                'per_page' => $perPage,
                'to' => ($total > 0) ? min($page * $perPage, $total) : null,
                'total' => $total,
            ],
        ]);
    }


    public function search(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $page = (int) $request->input('page', 1);
        $withCover = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);

        $filters = [
            'search' => $request->input('search'),
            'title' => $request->input('title'),
            'author' => $request->input('author'),
            'series' => $request->input('series'),
            'publication_date' => $request->input('publication_date'),
            'date_added' => $request->input('date_added'),
        ];

        $booksData = $this->documentStoreService->listBooks($page, $perPage, $filters);
        $books = $booksData['data'];
        $total = $booksData['total'];

        // Transform books to match OpenAPI spec
        $books = array_filter($books, 'is_array');
        $books = array_map(function ($book) use ($withCover, $inlineCovers) {
            return $this->getBookWithCover($book, $withCover, $inlineCovers);
        }, $books);

        return response()->json([
            'data' => $books,
            'meta' => [
                'current_page' => $page,
                'from' => ($total > 0) ? (($page - 1) * $perPage) + 1 : null,
                'last_page' => max(1, ceil($total / $perPage)),
                'per_page' => $perPage,
                'to' => ($total > 0) ? min($page * $perPage, $total) : null,
                'total' => $total,
            ],
        ]);
    }

    public function download($id)
    {
        $book = $this->documentStoreService->getBook($id);
        if (!$book) {
            abort(404);
        }
        $directoryPath = $book['directoryPath'] ?? null;
        if (!$directoryPath || !Storage::disk('books')->exists($directoryPath)) {
            abort(404, 'Book directory not found.');
        }
        $files = Storage::disk('books')->files($directoryPath);
        if (empty($files)) {
            abort(404, 'No files found for this book.');
        }
        $zipFileName = str_replace(' ', '_', $book['title']) . '.zip';
        $zipPath = storage_path('app/public/temp/' . $zipFileName);
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Failed to create zip archive.');
        }
        foreach ($files as $file) {
            $zip->addFile(Storage::disk('books')->path($file), basename($file));
        }
        $zip->close();

        return response()->download($zipPath, $zipFileName)->deleteFileAfterSend(true);
    }

    public function queueDownload(Request $request)
    {
        $user = Auth::user();
        $queue = $this->documentStoreService->getBookQueue($user->id);
        if (empty($queue)) {
            $queue = $this->documentStoreService->getBookQueue($user->id);
            if (empty($queue) || (is_array($queue) && count($queue) === 0)) {
                return response()->json([
                    'error' => 'No books queued for download',
                    'message' => 'No books have been added to your download queue',
                ], 404);
            }
            $zipName = 'bookqueue_' . $user->id . '_' . Str::random(8) . '.zip';
            $zipPath = storage_path('app/public/' . $zipName);

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE) === true) {
                foreach ($queue as $item) {
                    $book = $item['book'];
                    if ($book && $book['directoryPath'] && Storage::exists($book['directoryPath'])) {
                        $files = Storage::files($book['directoryPath']);
                        foreach ($files as $file) {
                            $localPath = storage_path('app/' . $file);
                            if (file_exists($localPath)) {
                                $zip->addFile($localPath, basename($file));
                            }
                        }
                    }
                }
                $zip->close();
            } else {
                return response()->json([
                    'error' => 'Could not create zip file',
                    'message' => 'Failed to create download archive',
                ], 500);
            }

            // Optionally, store a record of the zip for later deletion/marking
            return response()->json(['zip_id' => $zipName, 'download_url' => url('storage/' . $zipName)]);
        }
        // If document store queue is not empty, handle that logic here (implement if needed)
    }

    public function downloadQueuedZip($zipId)
    {
        $zipPath = storage_path('app/public/' . $zipId);
        if (!file_exists($zipPath)) {
            return response()->json([
                'error' => 'Zip file not found',
                'message' => 'The requested download file could not be found',
            ], 404);
        }

        return response()->download($zipPath);
    }

    public function markZipDownloaded($zipId)
    {
        $zipPath = storage_path('app/public/' . $zipId);
        if (file_exists($zipPath)) {
            unlink($zipPath);

            return response()->json(['message' => 'Zip file deleted successfully']);
        }

        return response()->json([
            'error' => 'Zip file not found',
            'message' => 'The requested download file could not be found',
        ], 404);
    }

    /**
     * Get a list of all genres.
     */
    public function listGenres(Request $request)
    {
        $genres = $this->documentStoreService->listGenres();
        usort($genres, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        $genres = array_map(function ($g) {
            return ['id' => $g['id'], 'name' => $g['name']];
        }, $genres);

        return response()->json($genres);
    }

    /**
     * Get a paginated, filterable list of authors in a genre.
     */
    public function authorsByGenre(Request $request, $genreId)
    {
        $perPage = $request->input('per_page', 20);
        $search = $request->input('search');
        $genre = $this->documentStoreService->getGenre($genreId);
        if (!$genre) {
            return response()->json([
                'error' => 'Genre not found',
                'message' => 'The specified genre could not be found',
            ], 404);
        }
        $books = array_filter($this->documentStoreService->listBooks(), function ($book) use ($genreId) {
            return ($book['genre_id'] ?? null) == $genreId;
        });
        $authorIds = array_unique(array_column($books, 'author_id'));
        $authors = array_filter($this->documentStoreService->listAuthors(), function ($author) use ($authorIds, $search) {
            $match = in_array($author['id'], $authorIds);
            if ($search) {
                $match = $match && (stripos($author['name'], $search) !== false);
            }

            return $match;
        });
        $authors = array_values($authors);
        usort($authors, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        $total = count($authors);
        $page = (int) $request->input('page', 1);
        $offset = ($page - 1) * $perPage;
        $paginatedAuthors = array_slice($authors, $offset, $perPage);

        return response()->json([
            'data' => $paginatedAuthors,
            'meta' => [
                'current_page' => $page,
                'from' => ($total > 0) ? $offset + 1 : null,
                'last_page' => max(1, ceil($total / $perPage)),
                'per_page' => $perPage,
                'to' => ($total > 0) ? min($offset + $perPage, $total) : null,
                'total' => $total,
            ],
        ]);
    }

    /**
     * Get all authors with books in a given genre.
     */
    public function authorsByGenreSimple($genreId, Request $request)
    {
        $documentStore = $this->documentStoreService;
        $genre = $documentStore->getGenre($genreId);
        if (!$genre) {
            return response()->json([
                'error' => 'Genre not found',
                'message' => 'The specified genre could not be found',
            ], 404);
        }
        $books = array_filter($documentStore->listBooks(), function ($book) use ($genreId) {
            return ($book['genre_id'] ?? null) == $genreId;
        });
        $authorIds = array_unique(array_column($books, 'author_id'));
        $authors = array_filter($documentStore->listAuthors(), fn($author) => in_array($author['id'], $authorIds));
        $authors = array_values($authors);
        usort($authors, fn($a, $b) => strcmp($a['name'], $b['name']));
        $authors = array_map(fn($a) => ['id' => $a['id'], 'name' => $a['name']], $authors);

        return response()->json([
            'genre' => ['id' => $genre['id'], 'name' => $genre['name']],
            'authors' => $authors,
        ]);
    }

    /**
     * Get all books for a given series.
     */
    public function booksBySeries($seriesId, Request $request)
    {
        $documentStore = $this->documentStoreService;
        $perPage = $request->input('per_page', 100);
        $withCover = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);
        $series = $documentStore->getSeries($seriesId);
        if (!$series) {
            return response()->json([
                'error' => 'Series not found',
                'message' => 'The specified series could not be found',
            ], 404);
        }
        $books = array_filter($documentStore->listBooks(), fn($book) => ($book['series_id'] ?? null) == $seriesId);
        $books = array_values($books);
        // Filter out any non-array entries that may have gotten into the books array
        $books = array_filter($books, 'is_array');
        $total = count($books);
        $page = (int) $request->input('page', 1);
        $offset = ($page - 1) * $perPage;
        $paginatedBooks = array_slice($books, $offset, $perPage);
        $paginatedBooks = array_map(fn($book) => $this->getBookWithCover($book, $withCover, $inlineCovers), $paginatedBooks);

        return response()->json([
            'series' => ['id' => $series['id'], 'name' => $series['name']],
            'data' => $paginatedBooks,
            'meta' => [
                'current_page' => $page,
                'from' => ($total > 0) ? $offset + 1 : null,
                'last_page' => max(1, ceil($total / $perPage)),
                'per_page' => $perPage,
                'to' => ($total > 0) ? min($offset + $perPage, $total) : null,
                'total' => $total,
            ],
        ]);
    }

    /**
     * Get all books for a given author.
     */
    public function booksByAuthor($authorId, Request $request)
    {
        $documentStore = $this->documentStoreService;
        $perPage = $request->input('per_page', 100);
        $withCover = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);
        $author = $documentStore->getAuthor($authorId);
        if (!$author) {
            return response()->json([
                'error' => 'Author not found',
                'message' => 'The specified author could not be found',
            ], 404);
        }
        $books = array_filter($documentStore->listBooks(), fn($book) => ($book['author_id'] ?? null) == $authorId);
        $books = array_values($books);
        // Filter out any non-array entries that may have gotten into the books array
        $books = array_filter($books, 'is_array');
        $total = count($books);
        $page = (int) $request->input('page', 1);
        $offset = ($page - 1) * $perPage;
        $paginatedBooks = array_slice($books, $offset, $perPage);
        $paginatedBooks = array_map(fn($book) => $this->getBookWithCover($book, $withCover, $inlineCovers), $paginatedBooks);

        return response()->json([
            'author' => ['id' => $author['id'], 'name' => $author['name']],
            'data' => $paginatedBooks,
            'meta' => [
                'current_page' => $page,
                'from' => ($total > 0) ? $offset + 1 : null,
                'last_page' => max(1, ceil($total / $perPage)),
                'per_page' => $perPage,
                'to' => ($total > 0) ? min($offset + $perPage, $total) : null,
                'total' => $total,
            ],
        ]);
    }

    /**
     * Get all series for a given author.
     */
    public function seriesByAuthor($authorId, Request $request)
    {
        $documentStore = $this->documentStoreService;
        $author = $documentStore->getAuthor($authorId);
        if (!$author) {
            return response()->json(['error' => 'Author not found'], 404);
        }
        $books = array_filter($documentStore->listBooks(), fn($book) => ($book['author_id'] ?? null) == $authorId);
        $seriesIds = array_unique(array_column($books, 'series_id'));
        $series = array_filter($documentStore->listSeries(), fn($ser) => in_array($ser['id'], $seriesIds));
        $series = array_values($series);
        usort($series, fn($a, $b) => strcmp($a['name'], $b['name']));
        $series = array_map(fn($s) => ['id' => $s['id'], 'name' => $s['name']], $series);

        return response()->json([
            'author' => ['id' => $author['id'], 'name' => $author['name']],
            'series' => $series,
        ]);
    }

    /**
     * Get all books for a given author within a specific genre.
     */
    public function booksByAuthorAndGenre($authorId, $genreId, Request $request)
    {
        $perPage = $request->input('per_page', 100);
        $withCover = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);
        Log::info('booksByAuthorAndGenre called for author: ' . $authorId . ' and genre: ' . $genreId);
        Log::info('booksByAuthorAndGenre ' . json_encode($_POST));
        Log::info('booksByAuthorAndGenre ' . json_encode($_GET));

        $documentStore = $this->documentStoreService;
        $author = $documentStore->getAuthor($authorId);
        $genre = $documentStore->getGenre($genreId);
        $books = $documentStore->getBooksByAuthorAndGenre($authorId, $genreId);
        if (!$author || !$genre) {
            return response()->json([
                'error' => 'Author or Genre not found',
                'message' => 'The specified author or genre could not be found',
            ], 404);
        }
        // Sort books by series name, series number, and title
        usort($books, function ($a, $b) {
            $seriesA = $a['series']['name'] ?? '';
            $seriesB = $b['series']['name'] ?? '';
            if ($seriesA !== $seriesB) {
                return strcmp($seriesA, $seriesB);
            }
            $numA = $a['series_number'] ?? 0;
            $numB = $b['series_number'] ?? 0;
            if ($numA !== $numB) {
                return $numA <=> $numB;
            }

            return strcmp($a['title'] ?? '', $b['title'] ?? '');
        });
        // Paginate manually
        // Filter out any non-array entries that may have gotten into the books array
        $books = array_filter($books, 'is_array');
        $total = count($books);
        $page = max(1, (int) $request->input('page', 1));
        $offset = ($page - 1) * $perPage;
        $paginatedBooks = array_slice($books, $offset, $perPage);
        $paginatedBooks = array_map(fn($book) => $this->getBookWithCover($book, $withCover, $inlineCovers), $paginatedBooks);

        return response()->json([
            'data' => $paginatedBooks,
            'meta' => [
                'current_page' => $page,
                'from' => ($total > 0) ? $offset + 1 : null,
                'last_page' => max(1, ceil($total / $perPage)),
                'per_page' => $perPage,
                'to' => ($total > 0) ? min($offset + $perPage, $total) : null,
                'total' => $total,
            ],
        ]);
    }

    private function getBookWithCover($book, $withCover = false, $inlineCovers = false)
    {
        // Ensure $book is an array
        if (!is_array($book)) {
            Log::error('getBookWithCover received non-array book data', [
                'book_type' => gettype($book),
                'book_value' => $book,
                'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
            ]);
            return ['error' => 'Invalid book data'];
        }

        // Transform book data to match OpenAPI specification
        $transformedBook = [
            'id' => $book['id'] ?? null,
            'title' => $book['title'] ?? '',
            'author' => $this->normalizeArray($book['author'] ?? $book['author_name'] ?? []),
            'narrator' => $this->normalizeArray($book['narrator'] ?? $book['narrator_name'] ?? []),
            'series' => $this->formatSeriesData($book),
            'genre' => $this->normalizeGenre($book['genre'] ?? []),
            'year' => isset($book['published_year']) ? (int) $book['published_year'] : (isset($book['year']) ? (int) $book['year'] : null),
            'duration' => $book['duration'] ?? null,
            'description' => $book['description'] ?? null,
            'file_count' => isset($book['audio_file_count']) ? (int) $book['audio_file_count'] : (isset($book['file_count']) ? (int) $book['file_count'] : null),
            'total_size' => isset($book['total_size']) ? (int) $book['total_size'] : null,
            'created_at' => $book['created_at'] ?? $book['date_added'] ?? null,
            'updated_at' => $book['updated_at'] ?? null,
        ];

        // Handle cover image - always set cover_url if coverImage exists
        if (!empty($book['coverImage'])) {
            // Resolve the cover image path (handles both filename-only and full path formats)
            $coverPath = $this->resolveCoverImagePath($book['coverImage'], $book['directoryPath'] ?? null);

            if ($inlineCovers && $coverPath && Storage::disk('books')->exists($coverPath)) {
                $fullPath = Storage::disk('books')->path($coverPath);
                $transformedBook['cover'] = [
                    'type' => 'base64',
                    'path' => $fullPath,
                    'data' => base64_encode(Storage::disk('books')->get($coverPath)),
                ];
            }
            // Always provide cover_url for consistency with OpenAPI spec
            // Use the current request's hostname and protocol for the cover URL
            $request = request();
            $transformedBook['cover_url'] = $request->getSchemeAndHttpHost() . '/api/v1/books/' . ($book['id'] ?? '') . '/cover';
        } else {
            $transformedBook['cover_url'] = null;
        }

        return $transformedBook;
    }

    /**
     * Normalize array data - ensure it's always an array of strings
     */
    private function normalizeArray($data)
    {
        if (is_string($data)) {
            return [$data];
        }

        if (is_array($data)) {
            return array_values(array_filter(array_map('trim', $data)));
        }

        return [];
    }

    /**
     * Format series data as an array with name and series number
     */
    private function formatSeriesData($book): array
    {
        // Handle case where series is already loaded as a relationship
        if (isset($book['series']) && is_array($book['series']) && !empty($book['series'])) {
            $result = [];
            foreach ($book['series'] as $series) {
                if (is_array($series)) {
                    $result[] = [
                        'name' => $series['name'] ?? null,
                        'series_number' => $series['pivot']['series_number'] ?? null,
                    ];
                } elseif (is_object($series)) {
                    $result[] = [
                        'name' => $series->name ?? null,
                        'series_number' => $series->pivot->series_number ?? null,
                    ];
                }
            }
            return $result;
        }

        // Handle case where series info is directly in the book array
        $seriesName = $book['series_name'] ?? ($book['series']['name'] ?? null);
        $seriesNumber = $book['series_number'] ?? null;

        if (empty($seriesName)) {
            return [];
        }

        return [
            [
                'name' => $seriesName,
                'series_number' => $seriesNumber,
            ],
        ];
    }

    /**
     * Normalize genre data - ensure it's always an array of strings
     */
    private function normalizeGenre($data)
    {
        if (is_string($data)) {
            return [$data];
        }

        if (is_array($data)) {
            return array_values(array_filter(array_map('trim', $data)));
        }

        return [];
    }

    /**
     * Get the download manifest for a book
     *
     * Provides metadata about the contents of the book download zip without downloading the file
     *
     * @param  string  $id  Book ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function downloadManifest($id)
    {
        // Get the book details
        $book = $this->documentStoreService->getBook($id);

        if (!$book) {
            return response()->json(['error' => 'Book not found'], 404);
        }

        // Check if the book has audio files
        $audioPath = 'books/' . $id . '/audio';
        $hasAudio = Storage::disk('books')->exists($audioPath);

        // Get audio file metadata
        $chapters = [];
        $totalDuration = 0;

        if ($hasAudio) {
            $files = Storage::disk('books')->files($audioPath);
            sort($files); // Ensure files are in order

            foreach ($files as $index => $file) {
                // Only include audio files
                $extension = pathinfo($file, PATHINFO_EXTENSION);
                if (!in_array(strtolower($extension), ['mp3', 'm4a', 'wav', 'aac', 'ogg', 'flac'])) {
                    continue;
                }

                $chapterNum = $index + 1;
                $fileName = basename($file);

                // Extract duration if available in metadata (this is a placeholder - implement actual duration extraction)
                $duration = $book['chapters'][$index]['duration'] ?? 0;
                $totalDuration += $duration;

                $chapters[] = [
                    'chapter_number' => $chapterNum,
                    'file_name' => $fileName,
                    'format' => $extension,
                    'duration' => $duration,
                    'size_bytes' => Storage::disk('books')->size($file),
                ];
            }
        }

        // Build the manifest
        $manifest = [
            'book_id' => $id,
            'title' => $book['title'] ?? '',
            'author' => $book['author_name'] ?? '',
            'series' => $book['series_name'] ?? '',
            'series_number' => $book['series_number'] ?? null,
            'total_duration_seconds' => $totalDuration,
            'cover_included' => !empty($book['coverImage']) && Storage::disk('books')->exists($book['coverImage']),
            'format' => 'zip',
            'chapters' => $chapters,
            'has_audio' => $hasAudio,
            'total_chapters' => count($chapters),
            'total_files' => count($chapters) + ($book['coverImage'] ? 1 : 0),
        ];

        return response()->json($manifest);
    }
}
