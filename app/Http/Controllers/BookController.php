<?php

namespace App\Http\Controllers;

use App\Services\FirestoreService;
use App\Services\GoogleBooksApiService;
use Illuminate\Http\Request;

class BookController extends Controller
{
    protected $googleBooksApiService;

    public function __construct(GoogleBooksApiService $googleBooksApiService)
    {
        $this->googleBooksApiService = $googleBooksApiService;
    }

    // ... Other methods...

    public function index(Request $request)
    {
        $firestore = new FirestoreService();
        $books = $firestore->listBooks();

        // Extract unique genres from books
        $genres = [];
        foreach ($books as $book) {
            if (isset($book['genre']) && !empty($book['genre'])) {
                foreach ((array)$book['genre'] as $genre) {
                    if (!empty($genre)) {
                        $genreId = md5($genre); // Create a consistent ID from genre name
                        $genres[$genreId] = ['id' => $genreId, 'name' => $genre];
                    }
                }
            }
        }
        $genres = array_values($genres);
        usort($genres, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        // Extract unique authors from books
        $authors = [];
        foreach ($books as $book) {
            if (isset($book['author']) && !empty($book['author'])) {
                foreach ((array)$book['author'] as $author) {
                    if (!empty($author)) {
                        $authorId = md5($author); // Create a consistent ID from author name
                        $authors[$authorId] = ['id' => $authorId, 'name' => $author];
                    }
                }
            }
        }
        $authors = array_values($authors);
        usort($authors, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        // Apply filters if provided
        if ($request->has('search')) {
            $search = $request->input('search');
            $books = array_filter($books, function ($book) use ($search) {
                // Search in title
                if (isset($book['title']) && stripos($book['title'], $search) !== false) {
                    return true;
                }

                // Search in authors
                if (isset($book['author']) && is_array($book['author'])) {
                    foreach ($book['author'] as $author) {
                        if (stripos($author, $search) !== false) {
                            return true;
                        }
                    }
                }

                return false;
            });
        }

        if ($request->has('genre_id')) {
            $genreId = $request->input('genre_id');
            $books = array_filter($books, function ($book) use ($genreId, $genres) {
                if (!isset($book['genre']) || !is_array($book['genre'])) {
                    return false;
                }

                // Find the genre name from the ID
                $genreName = '';
                foreach ($genres as $genre) {
                    if ($genre['id'] === $genreId) {
                        $genreName = $genre['name'];
                        break;
                    }
                }

                // Check if the book has this genre
                return in_array($genreName, $book['genre']);
            });
        }

        if ($request->has('author_id')) {
            $authorId = $request->input('author_id');
            $books = array_filter($books, function ($book) use ($authorId, $authors) {
                if (!isset($book['author']) || !is_array($book['author'])) {
                    return false;
                }

                // Find the author name from the ID
                $authorName = '';
                foreach ($authors as $author) {
                    if ($author['id'] === $authorId) {
                        $authorName = $author['name'];
                        break;
                    }
                }

                // Check if the book has this author
                return in_array($authorName, $book['author']);
            });
        }

        if ($request->has('series')) {
            $seriesName = $request->input('series');
            $books = array_filter($books, function ($book) use ($seriesName) {
                return isset($book['series']) && stripos($book['series'], $seriesName) !== false;
            });
        }

        // Get recent books (only if not filtering)
        $recentBooks = [];
        if (!$request->has('search') && !$request->has('genre_id') && !$request->has('author_id') && !$request->has('series')) {
            $recentBooks = array_slice(array_reverse($books), 0, 5);
        }

        // Convert the filtered books array to a paginator
        $perPage = 12;
        $currentPage = $request->input('page', 1);
        $offset = ($currentPage - 1) * $perPage;
        $paginatedBooks = array_slice($books, $offset, $perPage, true);
        $totalBooks = count($books);
        $paginatedBooks = new \Illuminate\Pagination\LengthAwarePaginator(
            $paginatedBooks,
            $totalBooks,
            $perPage,
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('books.index', compact('paginatedBooks', 'genres', 'authors', 'recentBooks'));
    }

    public function show($id)
    {
        $firestore = new FirestoreService();
        $book = $firestore->getBook($id);
        if (!$book) {
            abort(404, 'Book not found');
        }

        $book = $this->ensureBookFields($book);

        // Get related books by the same author
        $relatedBooks = [];
        if (isset($book['author']) && is_array($book['author']) && !empty($book['author'])) {
            $allBooks = $firestore->listBooks();
            $authorBooks = array_filter($allBooks, function ($relatedBook) use ($book) {
                // Skip if this is the current book
                if ($relatedBook['id'] == $book['id']) {
                    return false;
                }

                // Skip if the related book has no authors
                if (!isset($relatedBook['author']) || !is_array($relatedBook['author']) || empty($relatedBook['author'])) {
                    return false;
                }

                // Check if any author from the current book matches any author from the related book
                foreach ($book['author'] as $currentAuthor) {
                    if (in_array($currentAuthor, $relatedBook['author'])) {
                        return true;
                    }
                }

                return false;
            });
            $relatedBooks = array_slice($authorBooks, 0, 3); // Limit to 3 related books
        }

        return view('books.show', compact('book', 'relatedBooks'));
    }

    /**
     * Ensure book has all required fields to prevent view errors
     *
     * @param array $book
     * @return array
     */
    protected function ensureBookFields(array $book): array
    {
        $defaults = [
            'title' => 'Unknown Title',
            'author' => [],
            'description' => 'No description available.',
            'coverImage' => null,
            'genre' => [],
            'series' => null,
            'series_number' => null,
            'reviews' => [],
        ];

        $result = array_merge($defaults, $book);

        // Ensure author is always an array
        if (!is_array($result['author'])) {
            $result['author'] = empty($result['author']) ? [] : [$result['author']];
        }

        // Ensure genre is always an array
        if (!is_array($result['genre'])) {
            $result['genre'] = empty($result['genre']) ? [] : [$result['genre']];
        }

        return $result;
    }

    public function download($id)
    {
        $firestore = new FirestoreService();
        $book = $firestore->getBook($id);
        if (!$book) {
            abort(404, 'Book not found');
        }

        $directoryPath = $book['directory_path'] ?? null;
        if (!$directoryPath || !\Storage::disk('books')->exists($directoryPath)) {
            abort(404, 'Book directory not found.');
        }

        // Filter for audio files only
        $files = \Storage::disk('books')->files($directoryPath);
        $audioFiles = array_filter($files, function ($file) {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            return in_array($extension, ['mp3', 'm4a', 'm4b', 'ogg', 'wav', 'aac', 'flac']);
        });

        if (empty($audioFiles)) {
            abort(404, 'No audio files found for this book.');
        }

        // Create a directory for temporary files if it doesn't exist
        $tempDir = storage_path('app/public/temp');
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // Create a sanitized filename for the zip
        $zipFileName = str_replace(' ', '_', $book['title']) . '.zip';
        $zipFileName = preg_replace('/[^A-Za-z0-9._-]/', '', $zipFileName);
        $zipPath = $tempDir . '/' . $zipFileName;

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Failed to create zip archive.');
        }

        foreach ($audioFiles as $file) {
            $zip->addFile(\Storage::disk('books')->path($file), basename($file));
        }

        // Add a metadata file with book information
        $metadataContent = "Title: {$book['title']}\n";
        $metadataContent .= "Author: " . (isset($book['author']) && !empty($book['author']) ? implode(', ', $book['author']) : 'Unknown') . "\n";
        if (isset($book['series']) && !empty($book['series'])) {
            $metadataContent .= "Series: {$book['series']}\n";
            if (isset($book['series_number'])) {
                $metadataContent .= "Series Number: {$book['series_number']}\n";
            }
        }
        if (isset($book['description']) && !empty($book['description'])) {
            $metadataContent .= "\nDescription:\n{$book['description']}\n";
        }

        $zip->addFromString('book_info.txt', $metadataContent);
        $zip->close();

        return response()->download($zipPath, $zipFileName)->deleteFileAfterSend(true);
    }
}
