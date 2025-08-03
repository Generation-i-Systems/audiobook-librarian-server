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
        $perPage = $request->input('per_page', 20);
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
        $books = $booksData['data'];
        $total = $booksData['total'];

        // Transform books to include cover URLs and other necessary fields
        $books = array_map(function ($book) use ($withCover, $inlineCovers) {
            return $this->getBookWithCover($book, $withCover, $inlineCovers);
        }, $books);

        return response()->json([
            'data' => $books,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
        ]);
    }

    public function show($id, Request $request)
    {
        $book = $this->documentStoreService->getBook($id);
        if (!$book) {
            return response()->json([
                'error' => 'Book not found or not authorized.',
            ], 404);
        }
        $withCover = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);

        return response()->json(
            $this->getBookWithCover($book, $withCover, $inlineCovers)
        );
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
            return response()->json(['error' => 'Invalid browse type'], 400);
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
        $items = array_slice($items, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'data' => $items,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
        ]);
    }

    public function cover($id)
    {
        $book = $this->documentStoreService->getBook($id);
        if (!$book) {
            return response()->json([
                'error' => 'Book not found or not authorized.',
            ], 404);
        }
        Log::info(
            'Cover image requested for book: ' . ($book['title'] ?? '[unknown]') . ' (' .
            ($book['coverImage'] ?? '[none]') . ')'
        );
        if (
            !empty($book['coverImage']) &&
            Storage::disk('books')->exists($book['coverImage'])
        ) {
            $mime = Storage::disk('books')->mimeType($book['coverImage']);

            return response(
                Storage::disk('books')->get($book['coverImage']),
                200
            )->header('Content-Type', $mime);
        }

        return response()->json(['error' => 'Cover image not found.'], 404);
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

        // Transform
        $books = array_map(function ($book) use ($withCover, $inlineCovers) {
            return $this->getBookWithCover($book, $withCover, $inlineCovers);
        }, $books);

        return response()->json([
            'data' => $books,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
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
            if ($queue->isEmpty()) {
                return response()->json(['error' => 'No books queued for download.'], 404);
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
                return response()->json(['error' => 'Could not create zip file.'], 500);
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
            return response()->json(['error' => 'Zip file not found.'], 404);
        }

        return response()->download($zipPath);
    }

    public function markZipDownloaded($zipId)
    {
        $zipPath = storage_path('app/public/' . $zipId);
        if (file_exists($zipPath)) {
            unlink($zipPath);

            return response()->json(['message' => 'Zip file deleted.']);
        }

        return response()->json(['error' => 'Zip file not found.'], 404);
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
            return response()->json(['error' => 'Genre not found'], 404);
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
        $authors = array_slice($authors, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'data' => $authors,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
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
            return response()->json(['error' => 'Genre not found'], 404);
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
            return response()->json(['error' => 'Series not found'], 404);
        }
        $books = array_filter($documentStore->listBooks(), fn($book) => ($book['series_id'] ?? null) == $seriesId);
        $books = array_values($books);
        // Filter out any non-array entries that may have gotten into the books array
        $books = array_filter($books, 'is_array');
        $total = count($books);
        $page = (int) $request->input('page', 1);
        $books = array_slice($books, ($page - 1) * $perPage, $perPage);
        $books = array_map(fn($book) => $this->getBookWithCover($book, $withCover, $inlineCovers), $books);

        return response()->json([
            'series' => ['id' => $series['id'], 'name' => $series['name']],
            'books' => [
                'data' => $books,
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
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
            return response()->json(['error' => 'Author not found'], 404);
        }
        $books = array_filter($documentStore->listBooks(), fn($book) => ($book['author_id'] ?? null) == $authorId);
        $books = array_values($books);
        // Filter out any non-array entries that may have gotten into the books array
        $books = array_filter($books, 'is_array');
        $total = count($books);
        $page = (int) $request->input('page', 1);
        $books = array_slice($books, ($page - 1) * $perPage, $perPage);
        $books = array_map(fn($book) => $this->getBookWithCover($book, $withCover, $inlineCovers), $books);

        return response()->json([
            'author' => ['id' => $author['id'], 'name' => $author['name']],
            'books' => [
                'data' => $books,
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
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
                'error' => 'Author or Genre not found.',
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
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
        ]);
    }

    private function getBookWithCover($book, $withCover = false, $inlineCovers = false)
    {
        // Ensure $book is an array
        if (!is_array($book)) {
            \Log::error('getBookWithCover received non-array book data', [
                'book_type' => gettype($book),
                'book_value' => $book,
                'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5)
            ]);
            return ['error' => 'Invalid book data'];
        }

        $arr = $book;
        if ($withCover && !empty($book['coverImage']) && Storage::disk('books')->exists($book['coverImage'])) {
            if ($inlineCovers) {
                $coverPath = Storage::disk('books')->path($book['coverImage']);
                $arr['cover'] = [
                    'type' => 'base64',
                    'path' => $coverPath,
                    'data' => base64_encode(Storage::disk('books')->get($book['coverImage'])),
                ];
            } else {
                $arr['cover_url'] = url('/api/v1/books/' . ($book['id'] ?? '') . '/cover');
            }
        } else {
            $arr['cover_url'] = null;
        }
        unset($arr['coverImageContent']);

        return $arr;
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
