<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Events\NewBookAdded;
use App\Http\Controllers\Controller;
use App\Traits\BookImportTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BookImportController extends Controller
{
    use BookImportTrait;

    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
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
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function processImport(Request $request)
    {
        Log::info('Book import processing started', ['request_data' => $request->except(['cover', 'coverImage'])]);

        try {
            Log::debug('BookImportController@processImport: starting validation', ['input_keys' => array_keys($request->all())]);
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
                'directoryPath' => 'nullable|string',
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

                // Use user-provided directoryPath if available, otherwise build from metadata
                if (!empty($validated['directoryPath'])) {
                    $directoryPath = $validated['directoryPath'];
                } else {
                    $directoryPath = $this->buildDirectoryPath($validated);
                }

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
                Log::warning('BookImportController@processImport: findOrCreateMany failed', ['error' => $e->getMessage()]);
            }

            // Create the book in the document store and capture returned ID
            Log::debug('BookImportController@processImport: calling createBook', [
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
            if (is_string($importPath) && is_string($importRoot) && is_string($directoryPath)) {
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
            Log::error('BookImportController@processImport: validation failed', [
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
}
