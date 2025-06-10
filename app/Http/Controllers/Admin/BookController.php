<?php

namespace App\Http\Controllers\Admin;

use App\Events\NewBookAdded;
use App\Http\Controllers\Controller;
use App\Services\FirestoreService;
use App\Services\GoogleBooksApiService;
use App\Traits\BookImportTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class BookController extends Controller
{
    use BookImportTrait;

    protected $googleBooksApiService;

    protected $firestoreService;

    private $storagePath;

    public function __construct(GoogleBooksApiService $googleBooksApiService, FirestoreService $firestoreService)
    {
        $this->setGoogleBooksApiService($googleBooksApiService);
        $this->firestoreService = $firestoreService;
        $this->storagePath = env('BOOK_STORAGE_PATH');
    }

    public function index(Request $request)
    {
        $firestore = new FirestoreService;
        $books = $firestore->listBooks();

        // Filtering
        if ($request->filled('search')) {
            $search = strtolower($request->input('search'));
            $books = array_filter(
                $books,
                function ($book) use ($search) {
                    return (isset($book['title']) && stripos($book['title'], $search) !== false)
                        || (isset($book['author_name']) && stripos($book['author_name'], $search) !== false);
                }
            );
        }
        if ($request->filled('author')) {
            $books = array_filter(
                $books,
                function ($book) use ($request) {
                    return isset($book['author']) && $book['author'] == $request->input('author');
                }
            );
        }
        if ($request->filled('genre_id')) {
            $books = array_filter(
                $books,
                function ($book) use ($request) {
                    return isset($book['genre_id']) && $book['genre_id'] == $request->input('genre_id');
                }
            );
        }
        // Sorting
        $sort = $request->input('sort', 'recent_desc');
        $books = array_values($books);
        usort(
            $books,
            function ($a, $b) use ($sort) {
                switch ($sort) {
                    case 'recent_desc':
                        return strtotime($b['created_at'] ?? 0) <=> strtotime($a['created_at'] ?? 0);
                    case 'recent_asc':
                        return strtotime($a['created_at'] ?? 0) <=> strtotime($b['created_at'] ?? 0);
                    case 'author_asc':
                        return strcmp($a['author_name'] ?? '', $b['author_name'] ?? '');
                    case 'author_desc':
                        return strcmp($b['author_name'] ?? '', $a['author_name'] ?? '');
                    case 'title_asc':
                        return strcmp($a['title'] ?? '', $b['title'] ?? '');
                    case 'title_desc':
                        return strcmp($b['title'] ?? '', $a['title'] ?? '');
                    case 'year_asc':
                        return ($a['published_year'] ?? 0) <=> ($b['published_year'] ?? 0);
                    case 'year_desc':
                        return ($b['published_year'] ?? 0) <=> ($a['published_year'] ?? 0);
                    default:
                        return strtotime($b['created_at'] ?? 0) <=> strtotime($a['created_at'] ?? 0);
                }
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
        $firestore = new FirestoreService;
        $initial = [
            'directory_path' => $request->path,
        ];
        $coverCandidates = [];
        $coverAuto = null;
        $biggestCover = null;
        $biggestSize = 0;
        $directory_path = $request->old('directory_path') ?? $initial['directory_path'] ?? '';
        // Use processDirPath to extract initial values from the directory
        if ($directory_path) {
            $dirMeta = $this->processDirPath($directory_path);
            if (is_array($dirMeta)) {
                $initial = array_merge($initial, $dirMeta);
            }
            [$coverAuto, $coverCandidates] = $this->findCoverImageCandidate($directory_path);
            // If no cover and no images, try m4b extraction
            if (empty($coverAuto) && empty($coverCandidates)) {
                $dir = rtrim($this->storagePath, '/').'/'.ltrim($directory_path, '/');
                if (is_dir($dir)) {
                    $m4bs = array_values(array_filter(scandir($dir), function ($f) use ($dir) {
                        return is_file($dir.'/'.$f) && strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'm4b';
                    }));
                    if ($m4bs) {
                        $firstM4b = $dir.'/'.$m4bs[0];
                        $coverFile = $this->extractCoverFromM4B($firstM4b, $dir);
                        if ($coverFile) {
                            $coverAuto = $coverFile;
                        }
                        $tags = $this->extractTagData($firstM4b);
                        if (! empty($tags['description'])) {
                            $initial['description'] = $tags['description'];
                        }
                    }
                    // Also check metadata.abs for description and year
                    $meta = $this->extractMetadataAbs($dir);
                    if (! empty($meta['description']) && empty($initial['description'])) {
                        $initial['description'] = $meta['description'];
                    }
                    if (! empty($meta['year']) && empty($initial['published_year'])) {
                        $initial['published_year'] = $meta['year'];
                    }
                }
            }
        }
        if ($directory_path && Storage::disk('books')->exists($directory_path)) {
            $files = Storage::disk('books')->files($directory_path);
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
        $firestore = new FirestoreService;
        $genreList = $firestore->listGenres();
        $book = []; // Initialize empty book array

        if (! isset($initial['directory_path'])) {
            $initial['directory_path'] = '';
        }

        if ($request->ajax()) {
            return view(
                'admin.books.create_form',
                compact(
                    'genreList',
                    'initial',
                    'coverCandidates',
                    'coverAuto',
                    'biggestCover',
                    'directory_path'
                )
            )
                ->with('isModal', true)
                ->with('layout', 'layouts.modal');
        }

        return view(
            'admin.books.create_form',
            compact(
                'genreList',
                'initial',
                'coverCandidates',
                'coverAuto',
                'biggestCover',
                'directory_path'
            )
        );
    }

    public function import()
    {
        return view('admin.books.import_directory');
    }

    public function store(Request $request)
    {
        $firestore = new FirestoreService;

        $validated = $request->validate(
            [
                'title' => 'required|string|max:255',
                'author' => 'required|array|min:1',
                'author.*' => 'required|string|max:255',
                'series' => 'nullable|array',
                'series.*' => 'nullable|string|max:255',
                'series_number' => 'nullable|array',
                'series_number.*' => 'nullable|numeric',
                'genre' => 'required|array|min:1',
                'genre.*' => 'required|string|max:255',
                'cover_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                'description' => 'nullable|string',
                'directory_path' => 'nullable|string|max:255',
                'published_year' => 'nullable|digits:4',
            ]
        );

        // Build the book record in processDirPath format
        $book = [];
        $book['title'] = $validated['title'];
        $book['author'] = array_values(array_filter($validated['author']));
        $book['genre'] = array_values(array_filter($validated['genre']));
        $book['directory_path'] = $validated['directory_path'] ?? null;
        $book['description'] = $validated['description'] ?? '';
        $book['published_year'] = $validated['published_year'] ?? null;
        // Handle series as an associative array with name as key and number as value
        $book['series'] = [];
        if (! empty($validated['series'])) {
            foreach ($validated['series'] as $i => $seriesName) {
                $seriesName = trim($seriesName);
                if ($seriesName === '') {
                    continue;
                }

                $number = isset($validated['series_number'][$i]) ? $validated['series_number'][$i] : null;
                if ($number !== null && $number !== '') {
                    $book['series'][$seriesName] = (float) $number;
                } else {
                    $book['series'][$seriesName] = null;
                }
            }
        }

        // Remove any empty series entries
        $book['series'] = array_filter($book['series'], function ($name) {
            return ! empty($name);
        }, ARRAY_FILTER_USE_KEY);

        $dirPath = $validated['directory_path'] ?? null;
        if (empty($dirPath)) {
            if (! empty($validated['series'])) {
                $series = $validated['series'];
                $genreName = ! empty($validated['genre'][0]);
                // generate authorName from author array by implodeing with ' & ' and removing any empty values
                $authorName = implode(' & ', array_filter($validated['author']));
                // generate series name from the first series in the series array
                $seriesName = ! empty($validated['series'][0]);
                // generate the dir path from genre, author, series, and series number and title (author/series/[series number] title) or author/title
                if ($series) {
                    $dirPath = '/'.implode('/', [
                        $genreName,
                        $authorName,
                        $series,
                        ($validated['series_number'] ? $validated['series_number'].' ' : '').$validated['title'],
                    ]);
                } else {
                    $dirPath = '/'.implode('/', [
                        $genreName,
                        $authorName,
                        $validated['title'],
                    ]);
                }
            } else {
                $genreName = ! empty($validated['genre'][0]);
                // generate authorName from author array by implodeing with ' & ' and removing any empty values
                $authorName = implode(' & ', array_filter($validated['author']));
                $dirPath = '/'.implode('/', [
                    $genreName,
                    $authorName,
                    $validated['title'],
                ]);
            }
            if ($this->storagePath && $dirPath) {
                Storage::makeDirectory($this->storagePath.'/'.ltrim($dirPath, '/'));
            }
        }
        $book['directory_path'] = $dirPath;

        // Handle cover image upload or import from autofill/candidate
        $coverImagePath = null;
        if ($request->hasFile('cover_image')) {
            $ext = $request->file('cover_image')->getClientOriginalExtension();
            $coverImagePath = ($validated['directory_path'] ? trim($validated['directory_path'], '/').'/' : '').'cover.'.$ext;
            Storage::disk('books')->put(
                $coverImagePath,
                file_get_contents($$request->file('cover_image')->getRealPath())
            );
            $validated['cover_image'] = $coverImagePath;
        } elseif ($request->input('cover_image_url')) {
            $coverImagePath = $this->importCoverImageFromUrl(
                $request->input('cover_image_url'),
                $dirPath,
            );
            if ($coverImagePath) {
                $book['cover_image'] = $coverImagePath;
            }
        } elseif ($request->filled('cover_image_candidate')) {
            $candidate = $request->input('cover_image_candidate');
            $book['cover_image'] = ltrim($book['directory_path'], '/').'/'.
                $candidate;
        }

        // Generate dirPath if not provided
        // Store the book record in Firestore
        $bookId = $firestore->createBook($book);

        if ($bookId) {
            // Get the created book data from Firestore
            $createdBook = $firestore->getBook($bookId);
            if ($createdBook) {
                // Dispatch the NewBookAdded event with the Firestore document data
                event(new NewBookAdded($createdBook));
            }
        }

        // Handle Book File Uploads and directory creation
        if (! $this->storagePath) {
            Log::error('BOOK_STORAGE_PATH is not defined in the .env file.');

            return back()->withErrors(['error' => 'Configuration error: BOOK_STORAGE_PATH is not defined.']);
        }
        // Make sure the directory exists (create or rename if necessary)
        $bookDirectory = $book['directory_path'] ?? null;
        if ($bookDirectory) {
            $absPath = $this->storagePath.'/'.ltrim($bookDirectory, '/');
            if (! is_dir($absPath)) {
                Storage::makeDirectory($absPath);
            }
        }
        if ($request->hasFile('book_files')) {
            $files = $request->file('book_files');
            foreach ($files as $file) {
                $filename = $file->getClientOriginalName();
                $file->storeAs($this->storagePath.'/'.$bookDirectory, $filename);
            }
        }

        if ($request->ajax()) {
            // Render the row HTML for the new book
            $rowHtml = view('admin.books.partials.directory_row', [
                'item' => [
                    'type' => 'book',
                    'id' => $bookId,
                    'name' => basename($book['directory_path']),
                    'path' => $book['directory_path'],
                    'edit_url' => route('admin.books.edit', ['book' => $bookId]),
                    'created_at' => now(),
                    'size' => 0,
                    'mime_type' => 'directory',
                    'book' => $book,
                ],
            ])->render();

            return response()->json([
                'success' => true,
                'book_id' => $bookId,
                'edit_url' => route('admin.books.edit', ['book' => $bookId]),
                'row_html' => $rowHtml,
                'directory_path' => $book['directory_path'],
            ]);
        }

        return redirect()->route('admin.books.index')->with('success', 'Book created successfully!');
    }

    public function show($id)
    {
        $firestore = new FirestoreService;
        $book = $firestore->getBook($id);
        if (! $book) {
            abort(404);
        }

        return view('admin.books.show', compact('book'));
    }

    public function edit($id, Request $request)
    {
        $firestore = new FirestoreService;
        $book = $firestore->getBook($id);

        if (! $book) {
            abort(404);
        }

        // Ensure the ID is set in the book data
        if (! isset($book['id'])) {
            $book['id'] = $id;
        }

        $genreList = $firestore->listGenres();

        // If no cover image, find image candidates in directory
        $coverCandidates = [];
        $coverAuto = null;
        $biggestCover = null;
        $biggestSize = 0;
        if (empty($book['cover_image']) && ! empty($book['directory_path'])) {
            [$coverAuto, $coverCandidates] = $this->findCoverImageCandidate($book['directory_path']);
            // If no cover and no images, try m4b extraction
            if (empty($coverAuto) && empty($coverCandidates)) {
                $dir = rtrim($this->storagePath, '/').'/'.ltrim($book['directory_path'], '/');
                if (is_dir($dir)) {
                    $m4bs = array_values(array_filter(scandir($dir), function ($f) use ($dir) {
                        return is_file($dir.'/'.$f) && strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'm4b';
                    }));
                    if ($m4bs) {
                        $firstM4b = $dir.'/'.$m4bs[0];
                        $coverFile = $this->extractCoverFromM4B($firstM4b, $dir);
                        if ($coverFile) {
                            $coverAuto = $coverFile;
                            $book['cover_image'] = ltrim($book['directory_path'], '/').'/'.$coverFile;
                        }
                        if (empty($book['description'])) {
                            $tags = $this->extractTagData($firstM4b);
                            if (! empty($tags['description'])) {
                                $book['description'] = $tags['description'];
                            }
                        }
                    }
                    // Also check metadata.abs for description and year
                    $meta = $this->extractMetadataAbs($dir);
                    if (! empty($meta['description']) && empty($book['description'])) {
                        $book['description'] = $meta['description'];
                    }
                    if (! empty($meta['year']) && empty($book['published_year'])) {
                        $book['published_year'] = $meta['year'];
                    }
                }
            }
        }
        if ($book['directory_path'] && Storage::disk('books')->exists($book['directory_path'])) {
            $currentCover = ! empty($book['cover_image']) ? basename($book['cover_image']) : null;
            $files = Storage::disk('books')->files($book['directory_path']);
            foreach ($files as $file) {
                $filename = basename($file);
                // Skip the current cover image from being a candidate
                if ($filename === $currentCover) {
                    continue;
                }
                if (preg_match('/\.(jpe?g|png|gif|svg)$/i', $file)) {
                    $candidate = $filename;
                    $coverCandidates[] = $candidate;
                    $size = Storage::disk('books')->size($file);
                    if ($size > $biggestSize) {
                        $biggestSize = $size;
                        $biggestCover = $candidate;
                    }
                }
            }
        }
        $isModal = $request->ajax() || $request->get('modal') == 1;
        $layout = $isModal ? 'layouts.modal' : 'layouts.app';

        $coverCandidatesForDefault = [];
        if ($request->hasFile('cover_image')) {
            $ext = $request->file('cover_image')->getClientOriginalExtension();
            $coverPath = ($book['directory_path'] ? trim($book['directory_path'], '/').'/' : '').'cover.'.$ext;
            Storage::disk('books')->put(
                $coverPath,
                file_get_contents($request->file('cover_image')->getRealPath())
            );
            $book['cover_image'] = $coverPath;
            Log::info('Book Edit: cover_image uploaded via file to '.$coverPath);
            $coverCandidatesForDefault[] = [
                'path' => $coverPath,
                'size' => Storage::disk('books')->size($coverPath),
            ];
        } elseif ($request->input('cover_image_path')) {
            $book['cover_image'] = $request->input('cover_image_path');
            Log::info('Book Edit: cover_image_path = '.$request->input('cover_image_path'));
            $coverCandidatesForDefault[] = [
                'path' => $request->input('cover_image_path'),
                'size' => Storage::disk('books')->exists(
                    $request->input('cover_image_path')
                )
                    ? Storage::disk('books')->size(
                        $request->input('cover_image_path')
                    )
                    : 0,
            ];
        } elseif ($request->input('cover_image_url')) {
            Log::info('Book Edit: Importing cover image from URL: '.$request->input('cover_image_url'));
            // Ensure the book's directory exists before importing
            $dirPath = $book['directory_path'];
            if (! $dirPath) {
                $dirPath = $request->directory_path;
            }
            if ($this->storagePath && $dirPath) {
                Storage::makeDirectory($this->storagePath.'/'.ltrim($dirPath, '/'));
            }
            $coverPath = $this->importCoverImageFromUrl($request->input('cover_image_url'), $dirPath);
            Log::info('Book Edit: importCoverImageFromUrl returned: '.($coverPath ?: 'null'));
            if ($coverPath) {
                $book['cover_image'] = $coverPath;
                $coverCandidatesForDefault[] = [
                    'path' => $coverPath,
                    'size' => Storage::disk('books')->exists($coverPath) ? Storage::disk('books')->size($coverPath) : 0,
                ];
            }
        } elseif ($request->filled('cover_image_candidate')) {
            // Use selected candidate from directory
            $candidate = $request->input('cover_image_candidate');
            $candidatePath = ltrim($book['directory_path'], '/').'/'.$candidate;
            $book['cover_image'] = $candidatePath;
            $coverCandidatesForDefault[] = [
                'path' => $candidatePath,
                'size' => Storage::disk('books')->exists($candidatePath) ?
                    Storage::disk('books')->size($candidatePath) : 0,
            ];
        }
        // If there are multiple candidates (including Google Books), pick the largest as default
        if (count($coverCandidatesForDefault) > 1) {
            usort($coverCandidatesForDefault, callback: fn ($a, $b) => $b['size'] <=> $a['size']);
            $book['cover_image'] = $coverCandidatesForDefault[0]['path'];
        }

        return view(
            'admin.books.edit',
            compact('genreList', 'book', 'isModal', 'layout', 'coverCandidates', 'coverAuto', 'biggestCover')
        );
    }

    public function update(Request $request, $id)
    {
        $firestore = new FirestoreService;
        $book = $firestore->getBook($id);
        if (! $book) {
            abort(404);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'author' => 'required|array|min:1',
            'author.*' => 'required|string|max:255',
            'series' => 'nullable|array',
            'series.*' => 'nullable|string|max:255',
            'series_number' => 'nullable|array',
            'series_number.*' => 'nullable|numeric',
            'genre' => 'required|array|min:1',
            'genre.*' => 'required|string|max:255',
            'cover_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'description' => 'nullable|string',
            'directory_path' => 'nullable|string|max:255',
            'published_year' => 'nullable|digits:4',
        ]);

        // Build the book record in processDirPath format
        $newBook = [];
        $newBook['title'] = $validated['title'];
        $newBook['author'] = array_values(array_filter($validated['author']));
        $newBook['genre'] = array_values(array_filter($validated['genre']));
        $newBook['directory_path'] = $validated['directory_path'] ?? $book['directory_path'];
        $newBook['description'] = $validated['description'] ?? '';
        $newBook['published_year'] = $validated['published_year'] ?? null;
        // Handle series as a simple map of series name => number
        $newBook['series'] = [];
        if (! empty($validated['series']) && is_array($validated['series'])) {
            // Filter out empty series names and reindex the array to ensure proper alignment with series numbers
            $filteredSeries = array_filter(
                array_map('trim', $validated['series']),
                fn ($name) => $name !== ''
            );

            // Get the series numbers, ensuring we have the same number of entries as filtered series
            $seriesNumbers = $validated['series_number'] ?? [];

            // Combine the filtered series names with their corresponding numbers
            foreach ($filteredSeries as $i => $seriesName) {
                $number = $seriesNumbers[$i] ?? null;
                $newBook['series'][$seriesName] = $number !== null && $number !== ''
                    ? (is_numeric($number) ? (float) $number : $number)
                    : null;
            }
        }

        $oldDirectoryPath = $book['directory_path'];
        $newDirectoryPath = $validated['directory_path'] ?? $oldDirectoryPath;
        // If directory_path changed, rename the folder and update the record
        if ($oldDirectoryPath && $newDirectoryPath && $oldDirectoryPath !== $newDirectoryPath) {
            $oldAbs = $this->storagePath.'/'.ltrim($oldDirectoryPath, '/');
            $newAbs = $this->storagePath.'/'.ltrim($newDirectoryPath, '/');
            if (is_dir($oldAbs) && ! is_dir($newAbs)) {
                if (! @rename($oldAbs, $newAbs)) {
                    return back()->withErrors(
                        [
                            'directory_path' => 'Failed to rename directory from '.$oldDirectoryPath.
                                ' to '.$newDirectoryPath,
                        ]
                    );
                }
            }
            $newBook['directory_path'] = $newDirectoryPath; // Update the record to match the new directory
        }
        // Handle cover image upload or import from autofill/candidate (same as store)
        $coverImagePath = null;
        if ($request->hasFile('cover_image')) {
            $ext = $request->file('cover_image')->getClientOriginalExtension();
            $coverImagePath = ($newDirectoryPath ? trim($newDirectoryPath, '/').'/' : '').'cover.'.$ext;
            Storage::disk('books')->put(
                $coverImagePath,
                file_get_contents($request->file('cover_image')->getRealPath())
            );
            $validated['cover_image'] = $coverImagePath;
        } elseif ($request->input('cover_image_url')) {
            $dirPath = $newBook['directory_path'] ?? $book['directory_path'] ?? null;
            if ($this->storagePath && $dirPath) {
                Storage::makeDirectory($this->storagePath.'/'.ltrim($dirPath, '/'));
            }
            $coverImagePath = $this->importCoverImageFromUrl($request->input('cover_image_url'), $dirPath);
            if ($coverImagePath) {
                $newBook['cover_image'] = $coverImagePath;
            }
        } elseif ($request->filled('cover_image_candidate')) {
            $candidate = $request->input('cover_image_candidate');
            $dir = ltrim($newBook['directory_path'] ?? $book['directory_path'], '/');
            $newBook['cover_image'] = $dir ? ($dir.'/'.ltrim($candidate, '/')) : $candidate;
        }

        // Debug: Dump the data being sent to Firestore
        Log::debug('Updating book in Firestore', [
            'book_id' => $id,
            'data' => $newBook,
        ]);

        $firestore->updateBook($id, $newBook);

        return redirect()->route('admin.books.show', $id)->with('success', 'Book updated successfully!');
    }

    /**
     * Remove the specified book from storage.
     */
    public function destroy($id)
    {
        $firestore = new FirestoreService;
        $firestore->deleteBook($id);

        return redirect()->route('admin.books.index')->with('success', 'Book deleted successfully.');
    }

    public function download($id)
    {
        $firestore = new FirestoreService;
        $book = $firestore->getBook($id);
        $directoryPath = $book['directory_path'];

        if (! $directoryPath || ! Storage::disk('books')->exists($directoryPath)) {
            abort(404, 'Book directory not found.');
        }

        $files = Storage::disk('books')->files($directoryPath);

        if (empty($files)) {
            abort(404, 'No files found for this book.');
        }

        $zipFileName = str_replace(' ', '_', $book['title']).'.zip';  // Sanitize filename
        $zipPath = storage_path(
            'app/public/temp/'.$zipFileName
        );  // Temporary storage

        $zip = new ZipArchive;

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

    public function googleBooks(Request $request)
    {
        $title = $request->query('title');
        $author = $request->query('author');
        $series = $request->query('series', '');
        $seriesNumber = $request->query('series_number', '');

        if (! $title || ! $author) {
            return response()->json([
                'error' => 'Title and author are required.',
            ], 400);
        }

        // Use trait method for similarity
        [$matches, $closeMatch] = $this->searchGoogleBooksWithSimilarity(
            $title,
            $author,
            $series,
            $seriesNumber
        );

        $more = $request->query('more');
        $limit = min((int) $request->query('limit', 10), 40); // Default 10, max 40
        if ($closeMatch && ! $more) {
            $info = $closeMatch['volumeInfo'];
            $autofill = [
                'title' => $info['title'] ?? '',
                'authors' => isset($info['authors']) ? implode(', ', $info['authors']) : '',
                'published_year' => isset($info['publishedDate']) ? substr($info['publishedDate'], 0, 4) : '',
                'description' => $info['description'] ?? '',
                'cover_image_url' => $info['imageLinks']['thumbnail'] ?? '',
                'id' => $closeMatch['item']['id'] ?? '',
                'series' => $info['series'] ?? '',
                'seriesNumber' => $info['seriesNumber'] ?? '',
                'score' => $closeMatch['score'],
                'match_type' => 'close',
                'matches' => [],
            ];

            return response()->json($autofill);
        } else {
            // Prepare a list of up to $limit possible matches for user selection
            $tableMatches = [];
            foreach (array_slice($matches, 0, $limit) as $m) {
                $info = $m['item']['volumeInfo'];
                $tableMatches[] = [
                    'title' => $info['title'] ?? '',
                    'authors' => isset($info['authors']) ? implode(', ', $info['authors']) : '',
                    'published_year' => isset($info['publishedDate']) ? substr($info['publishedDate'], 0, 4) : '',
                    'description' => $info['description'] ?? '',
                    'cover_image_url' => $info['imageLinks']['thumbnail'] ?? '',
                    'id' => $m['item']['id'] ?? '',
                    'series' => $info['series'] ?? '',
                    'seriesNumber' => $info['seriesNumber'] ?? '',
                    'score' => $m['score'],
                ];
            }

            return response()->json([
                'error' => 'No close match found.',
                'match_type' => 'list',
                'matches' => $tableMatches,
                'maxed' => count($matches) <= $limit, // true if all results are shown
            ], 200);
        }
    }

    /**
     * Serve an image from BOOK_STORAGE_PATH for preview (secure).
     */
    public function previewImage($book, $filename)
    {
        $storagePath = env('BOOK_STORAGE_PATH');
        $dir = rtrim($storagePath, '/').'/'.ltrim($book['directory_path'], '/');
        $fullPath = $dir.'/'.$filename;
        if (! file_exists($fullPath)) {
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
        $firestore = new FirestoreService;
        $series = $firestore->listSeries();
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
        $path = $this->storagePath.'/'.$relPath;
        $newName = $request->input('new_name');

        $dir = dirname($path);
        $newPath = $dir.DIRECTORY_SEPARATOR.$newName;

        Log::info("{$path} -> {$newPath}");

        $oldRel = str_replace($this->storagePath, '', $path);
        $newRel = str_replace($this->storagePath, '', $newPath);
        Log::info("({$this->storagePath}) {$oldRel} -> {$newRel}");

        if (! file_exists($path)) {
            return response()->json(['error' => 'Original file/folder does not exist.'], 404);
        }
        if (file_exists($newPath)) {
            return response()->json(['error' => 'A file/folder with the new name already exists.'], 409);
        }
        Log::info("{$path} -> {$newPath}");
        // Try to rename
        $success = @rename($path, $newPath);
        if ($success) {
            // If it's a directory, update any Book records using this directory_path
            if (is_dir($newPath)) {
                $oldRel = str_replace($this->storagePath, '', $path);
                $newRel = str_replace($this->storagePath, '', $newPath);
                Log::info("({$this->storagePath}) {$oldRel} -> {$newRel}");
                // Update Firestore books whose directory_path matches $oldRel
                $firestore = new FirestoreService;
                $booksToUpdate = array_filter($firestore->listBooks(), function ($book) use ($oldRel) {
                    return isset($book['directory_path']) && $book['directory_path'] === $oldRel;
                });
                foreach ($booksToUpdate as $book) {
                    $firestore->updateBook($book['id'], ['directory_path' => $newRel]);
                }
            }

            // Always return relative paths to the frontend!
            return response()->json([
                'success' => true,
                'new_path' => ltrim($newRel, '/'),
                'new_name' => $newName,
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
        $dir = rtrim($storagePath, '/').'/'.ltrim($directory, '/');
        $files = [];
        if (is_dir($dir)) {
            $allFiles = scandir($dir);
            $audioExts = ['mp3', 'm4b', 'm4a', 'aac', 'flac', 'ogg', 'wav'];
            foreach ($allFiles as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $path = $dir.'/'.$file;
                if (! is_file($path)) {
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
        $authors = $this->firestoreService->searchAuthorsByName($term);

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
        $series = $this->firestoreService->searchSeriesByName($term);

        return response()->json($series);
    }
}
