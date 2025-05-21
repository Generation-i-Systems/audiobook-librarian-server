<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ZipArchive;

class BookApiController extends Controller
{
    public function index(Request $request)
    {
        $firestore = new FirestoreService();
        $books = $firestore->listBooks();
        $withCover = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);
        $perPage = $request->input('per_page', 100);
        $query = $request->input('query');
        $genre = $request->input('genre');
        $author = $request->input('author');
        $series = $request->input('series');
        $title = $request->input('title');
        $publication_date = $request->input('publication_date');
        $date_added = $request->input('date_added');
        // Filter
        $books = array_filter($books, function ($book) use ($query, $genre, $author, $series, $title, $publication_date, $date_added) {
            $match = true;
            if ($query) {
                $match = $match && (
                    stripos($book['title'] ?? '', $query) !== false ||
                    stripos($book['author_name'] ?? '', $query) !== false ||
                    stripos($book['series_name'] ?? '', $query) !== false
                );
            }
            if ($genre) {
                $match = $match && (strcasecmp($book['genre'] ?? '', $genre) === 0);
            }
            if ($author) {
                $match = $match && (stripos($book['author_name'] ?? '', $author) !== false);
            }
            if ($series) {
                $match = $match && (stripos($book['series_name'] ?? '', $series) !== false);
            }
            if ($title) {
                $match = $match && (stripos($book['title'] ?? '', $title) !== false);
            }
            if ($publication_date) {
                $match = $match && (($book['published_year'] ?? null) == $publication_date);
            }
            if ($date_added) {
                $match = $match && (isset($book['date_added']) && strpos($book['date_added'], $date_added) === 0);
            }
            return $match;
        });
        // Pagination
        $books = array_values($books);
        $total = count($books);
        $page = (int) $request->input('page', 1);
        $books = array_slice($books, ($page - 1) * $perPage, $perPage);
        // Transform
        $books = array_map(function ($book) use ($withCover, $inlineCovers) {
            return $this->getBookWithCover($book, $withCover, $inlineCovers);
        }, $books);
        return response()->json([
            'data' => $books,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page
        ]);
    }


    public function show($id, Request $request)
    {
        $firestore = new FirestoreService();
        $book = $firestore->getBook($id);
        if (!$book) {
            return response()->json(['error' => 'Book not found'], 404);
        }
        $withCover = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);
        return response()->json($this->getBookWithCover($book, $withCover, $inlineCovers));
    }

    public function browse(Request $request)
    {
        $type = $request->input('type'); // 'genre', 'author', 'series'
        $perPage = $request->input('per_page', 100);
        $search = $request->input('search');
        $firestore = new FirestoreService();
        $dataMap = [
            'genre' => $firestore->listGenres(),
            'author' => $firestore->listAuthors(),
            'series' => $firestore->listSeries(),
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
            'current_page' => $page
        ]);
    }

    public function cover($id)
    {
        $firestore = new FirestoreService();
        $book = $firestore->getBook($id);
        if (!$book) {
            return response()->json(['error' => 'Book not found.'], 404);
        }
        Log::info('Cover image requested for book: ' . ($book['title'] ?? '[unknown]') . ' (' . ($book['cover_image'] ?? '[none]') . ')');
        if (!empty($book['cover_image']) && Storage::disk('books')->exists($book['cover_image'])) {
            $mime = Storage::disk('books')->mimeType($book['cover_image']);
            return response(Storage::disk('books')->get($book['cover_image']), 200)->header('Content-Type', $mime);
        }
        return response()->json(['error' => 'Cover image not found.'], 404);
    }

    public function search(Request $request)
    {
        $firestore = new FirestoreService();
        $books = $firestore->listBooks();
        $withCover = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);
        $perPage = $request->input('per_page', 100);
        $title = $request->input('title');
        $author = $request->input('author');
        $series = $request->input('series');
        $publication_date = $request->input('publication_date');
        $date_added = $request->input('date_added');
        // Filter
        $books = array_filter($books, function ($book) use ($title, $author, $series, $publication_date, $date_added) {
            $match = true;
            if ($title) {
                $match = $match && (stripos($book['title'] ?? '', $title) !== false);
            }
            if ($author) {
                $match = $match && (stripos($book['author_name'] ?? '', $author) !== false);
            }
            if ($series) {
                $match = $match && (stripos($book['series_name'] ?? '', $series) !== false);
            }
            if ($publication_date) {
                $match = $match && (($book['published_year'] ?? null) == $publication_date);
            }
            if ($date_added) {
                $match = $match && (isset($book['date_added']) && strpos($book['date_added'], $date_added) === 0);
            }
            return $match;
        });
        // Pagination
        $books = array_values($books);
        $total = count($books);
        $page = (int) $request->input('page', 1);
        $books = array_slice($books, ($page - 1) * $perPage, $perPage);
        // Transform
        $books = array_map(function ($book) use ($withCover, $inlineCovers) {
            return $this->getBookWithCover($book, $withCover, $inlineCovers);
        }, $books);
        return response()->json([
            'data' => $books,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page
        ]);
    }


    public function download($id)
    {
        $firestore = new FirestoreService();
        $book = $firestore->getBook($id);
        if (!$book) {
            abort(404);
        }
        $directoryPath = $book['directory_path'] ?? null;
        if (!$directoryPath || !\Storage::disk('books')->exists($directoryPath)) {
            abort(404, 'Book directory not found.');
        }
        $files = \Storage::disk('books')->files($directoryPath);
        if (empty($files)) {
            abort(404, 'No files found for this book.');
        }
        $zipFileName = str_replace(' ', '_', $book['title']) . '.zip';
        $zipPath = storage_path('app/public/temp/' . $zipFileName);
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Failed to create zip archive.');
        }
        foreach ($files as $file) {
            $zip->addFile(\Storage::disk('books')->path($file), basename($file));
        }
        $zip->close();
        return response()->download($zipPath, $zipFileName)->deleteFileAfterSend(true);
    }

    public function queueDownload(Request $request)
    {
        $user = Auth::user();
        $firestore = new FirestoreService();
        $queue = $firestore->getBookQueue($user->id);
        if (empty($queue)) {
            $queue = BookQueue::where('user_id', $user->id)->with('book')->get();
            if ($queue->isEmpty()) {
                return response()->json(['error' => 'No books queued for download.'], 404);
            }
            $zipName = 'bookqueue_' . $user->id . '_' . Str::random(8) . '.zip';
            $zipPath = storage_path('app/public/' . $zipName);

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE) === true) {
                foreach ($queue as $item) {
                    $book = $item->book;
                    if ($book && $book->directory_path && Storage::exists($book->directory_path)) {
                        $files = Storage::files($book->directory_path);
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
        // If Firestore queue is not empty, handle that logic here (implement if needed)
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
        $firestore = new FirestoreService();
        $genres = $firestore->listGenres();
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
        $firestore = new FirestoreService();
        $perPage = $request->input('per_page', 20);
        $search = $request->input('search');
        $genre = $firestore->getGenre($genreId);
        if (!$genre) {
            return response()->json(['error' => 'Genre not found'], 404);
        }
        $books = array_filter($firestore->listBooks(), function ($book) use ($genreId) {
            return ($book['genre_id'] ?? null) == $genreId;
        });
        $authorIds = array_unique(array_column($books, 'author_id'));
        $authors = array_filter($firestore->listAuthors(), function ($author) use ($authorIds, $search) {
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
            'current_page' => $page
        ]);
    }

    /**
     * Get all authors with books in a given genre.
     */
    public function authorsByGenreSimple($genreId, Request $request)
    {
        $firestore = new FirestoreService();
        $genre = $firestore->getGenre($genreId);
        if (!$genre) {
            return response()->json(['error' => 'Genre not found'], 404);
        }
        $books = array_filter($firestore->listBooks(), function ($book) use ($genreId) {
            return ($book['genre_id'] ?? null) == $genreId;
        });
        $authorIds = array_unique(array_column($books, 'author_id'));
        $authors = array_filter($firestore->listAuthors(), function ($author) use ($authorIds) {
            return in_array($author['id'], $authorIds);
        });
        $authors = array_values($authors);
        usort($authors, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        $authors = array_map(function ($a) {
            return ['id' => $a['id'], 'name' => $a['name']];
        }, $authors);
        return response()->json([
            'genre' => ['id' => $genre['id'], 'name' => $genre['name']],
            'authors' => $authors
        ]);
    }

    /**
     * Get all books for a given series.
     */
    public function booksBySeries($seriesId, Request $request)
    {
        $firestore = new FirestoreService();
        $perPage = $request->input('per_page', 100);
        $withCover = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);
        $series = $firestore->getSeries($seriesId);
        if (!$series) {
            return response()->json(['error' => 'Series not found'], 404);
        }
        $books = array_filter($firestore->listBooks(), function ($book) use ($seriesId) {
            return ($book['series_id'] ?? null) == $seriesId;
        });
        $books = array_values($books);
        $total = count($books);
        $page = (int) $request->input('page', 1);
        $books = array_slice($books, ($page - 1) * $perPage, $perPage);
        $books = array_map(function ($book) use ($withCover, $inlineCovers) {
            return $this->getBookWithCover($book, $withCover, $inlineCovers);
        }, $books);
        return response()->json([
            'series' => ['id' => $series['id'], 'name' => $series['name']],
            'books' => [
                'data' => $books,
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page
            ]
        ]);
    }

    /**
     * Get all books for a given author.
     */
    public function booksByAuthor($authorId, Request $request)
    {
        $firestore = new FirestoreService();
        $perPage = $request->input('per_page', 100);
        $withCover = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);
        $author = $firestore->getAuthor($authorId);
        if (!$author) {
            return response()->json(['error' => 'Author not found'], 404);
        }
        $books = array_filter($firestore->listBooks(), function ($book) use ($authorId) {
            return ($book['author_id'] ?? null) == $authorId;
        });
        $books = array_values($books);
        $total = count($books);
        $page = (int) $request->input('page', 1);
        $books = array_slice($books, ($page - 1) * $perPage, $perPage);
        $books = array_map(function ($book) use ($withCover, $inlineCovers) {
            return $this->getBookWithCover($book, $withCover, $inlineCovers);
        }, $books);
        return response()->json([
            'author' => ['id' => $author['id'], 'name' => $author['name']],
            'books' => [
                'data' => $books,
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page
            ]
        ]);
    }

    /**
     * Get all series for a given author.
     */
    public function seriesByAuthor($authorId, Request $request)
    {
        $firestore = new FirestoreService();
        $author = $firestore->getAuthor($authorId);
        if (!$author) {
            return response()->json(['error' => 'Author not found'], 404);
        }
        $books = array_filter($firestore->listBooks(), function ($book) use ($authorId) {
            return ($book['author_id'] ?? null) == $authorId;
        });
        $seriesIds = array_unique(array_column($books, 'series_id'));
        $series = array_filter($firestore->listSeries(), function ($ser) use ($seriesIds) {
            return in_array($ser['id'], $seriesIds);
        });
        $series = array_values($series);
        usort($series, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        $series = array_map(function ($s) {
            return ['id' => $s['id'], 'name' => $s['name']];
        }, $series);
        return response()->json([
            'author' => ['id' => $author['id'], 'name' => $author['name']],
            'series' => $series
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


        $firestore = app(FirestoreService::class);
        $author = $firestore->getAuthor($authorId);
        $genre = $firestore->getGenre($genreId);
        $books = $firestore->getBooksByAuthorAndGenre($authorId, $genreId); // returns array of books
        if (!$author || !$genre) {
            return response()->json(['error' => 'Author or Genre not found.'], 404);
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
        $total = count($books);
        $page = max(1, (int)$request->input('page', 1));
        $offset = ($page - 1) * $perPage;
        $paginatedBooks = array_slice($books, $offset, $perPage);
        $paginatedBooks = array_map(function ($book) use ($withCover, $inlineCovers) {
            return $this->getBookWithCover($book, $withCover, $inlineCovers);
        }, $paginatedBooks);
        return response()->json([
            'data' => $paginatedBooks,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
        ]);
    }

    private function getBookWithCover($book, $withCover = false, $inlineCovers = false)
    {
        $arr = $book;
        if ($withCover && !empty($book['cover_image']) && \Storage::disk('books')->exists($book['cover_image'])) {
            if ($inlineCovers) {
                $coverPath = \Storage::disk('books')->path($book['cover_image']);
                $arr['cover'] = [
                    'type' => 'base64',
                    'path' => $coverPath,
                    'data' => base64_encode(\Storage::disk('books')->get($book['cover_image'])),
                ];
            } else {
                $arr['cover_url'] = url('/api/v1/books/' . ($book['id'] ?? '') . '/cover');
            }
        } else {
            $arr['cover_url'] = null;
        }
        unset($arr['cover_image_content']);
        return $arr;
    }
}
