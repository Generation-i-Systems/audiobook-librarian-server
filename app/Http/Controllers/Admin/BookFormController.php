<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use App\Services\BookEditPlannedActionsService;
use App\Traits\BookImportTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BookFormController extends Controller
{
    use BookImportTrait;

    private string $storagePath;

    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
        $this->storagePath = (string) config('app.book_root', '/media/lyra_data1/audiobooks/books');
    }

    public function plannedActions(Request $request, string $id)
    {
        $directoryPath = (string) $request->input('directoryPath', '');
        $coverImageUrl = (string) $request->input('coverImageUrl', '');
        $audibleCoverImageUrl = (string) $request->input('audibleCoverImageUrl', '');

        $coverUrl = $audibleCoverImageUrl !== '' ? $audibleCoverImageUrl : $coverImageUrl;

        $service = new BookEditPlannedActionsService($this->documentStoreService);
        $plan = $service->computePlannedActions($id, $directoryPath, $coverUrl);

        return response()->json($plan);
    }

    /**
     * AJAX: Resync title, author, and series from a directory path.
     * POST /admin/books/resync-from-path
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function resyncFromPath(Request $request)
    {
        $request->validate([
            'directoryPath' => 'required|string',
        ]);
        $directoryPath = $request->input('directoryPath');
        try {
            $parser = new \App\Services\BookDirectoryParser();
            $absPath = $parser->resolveStoragePath($directoryPath);
            if (!is_dir($absPath)) {
                return response()->json(['success' => false, 'message' => 'Directory does not exist . '], 404);
            }
            $parsed = $parser->parseDirectory($absPath);
            /** @phpstan-ignore-next-line function.alreadyNarrowedType */
            $book = is_array($parsed) && count($parsed) > 0 ? $parsed[0] : null;
            if (!$book) {
                return response()->json(['success' => false, 'message' => 'Could not parse directory . ']);
            }
            // Normalize output for JS
            $authors = [];
            if (!empty($book['author'])) {
                $authors = is_array($book['author']) ? $book['author'] : [$book['author']];
            }
            $series = [];
            if (!empty($book['series']) && is_array($book['series'])) {
                foreach ($book['series'] as $name => $number) {
                    $series[] = ['name' => $name, 'number' => $number];
                }
            } elseif (!empty($book['series'])) {
                $series[] = ['name' => $book['series'], 'number' => $book['seriesNumber'] ?? ''];
            }

            return response()->json([
                'success' => true,
                'title' => $book['title'] ?? '',
                'authors' => $authors,
                'series' => $series,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function create(Request $request)
    {
        $documentStore = $this->documentStoreService;
        // Always initialize author and genre as arrays for the form
        $initial = [
            'directoryPath' => $request->path,
            'author' => [''],
            'genre' => [''],
        ];
        $coverCandidates = [];
        $coverAuto = null;
        $biggestCover = null;
        $biggestSize = 0;
        $directoryPath = $request->old('directoryPath') ?? $initial['directoryPath'] ?? '';

        // Check if this is an import request with pre-extracted metadata
        $isImportMode = $request->has('importMode') && $request->get('importMode');

        if ($isImportMode) {
            // Use the pre-extracted metadata from import process instead of re-processing
            $initial = [
                'directoryPath' => $request->get('directoryPath', ''),
                'author' => $request->get('author', ['']),
                'genre' => $request->get('genre', ['']),
                'narrator' => $request->get('narrator', ['']),
                'series' => $request->get('series', []),
                'title' => $request->get('title', ''),
                'description' => $request->get('description', ''),
                'release_date' => $request->get('release_date', ''),
                'language' => $request->get('language', 'en'),
                'isbn' => $request->get('isbn', ''),
                'asin' => $request->get('asin', ''),
                'sourcePath' => $request->get('sourcePath', ''),
                'sourceRoot' => $request->get('sourceRoot', ''),
                'sourceRelPath' => $request->get('sourceRelPath', ''),
                'sourceType' => $request->get('sourceType', ''),
                'importMode' => $request->get('importMode', false),
            ];

            // Get cover image from session if available
            if ($request->has('hasCoverImage') && session()->has('import_cover_image')) {
                $initial['coverImage'] = session('import_cover_image');
                session()->forget('import_cover_image'); // Clean up session
            }


            // Ensure arrays are properly formatted
            if (!is_array($initial['author'])) {
                $initial['author'] = empty($initial['author']) ? [''] : [$initial['author']];
            }
            if (!is_array($initial['genre'])) {
                $initial['genre'] = empty($initial['genre']) ? [''] : [$initial['genre']];
            }
            if (!is_array($initial['narrator'])) {
                $initial['narrator'] = empty($initial['narrator']) ? [''] : [$initial['narrator']];
            }
            if (!is_array($initial['series'])) {
                $initial['series'] = empty($initial['series']) ? [] : $initial['series'];
            }

            $directoryPath = $initial['directoryPath'];
        } else {
            // Use processDirPath to extract initial values from the directory
            if ($directoryPath) {
                $dirMeta = $this->processDirPath($directoryPath);
                /** @phpstan-ignore-next-line function.alreadyNarrowedType */
                if (is_array($dirMeta)) {
                    $initial = array_merge($initial, $dirMeta);
                    // Ensure author and genre are arrays
                    /** @phpstan-ignore-next-line booleanNot.alwaysFalse */
                    if (empty($initial['author']) || !is_array($initial['author'])) {
                        $initial['author'] = [''];
                    }
                    /** @phpstan-ignore-next-line booleanNot.alwaysFalse */
                    if (empty($initial['genre']) || !is_array($initial['genre'])) {
                        $initial['genre'] = [''];
                    }
                }
            }
        }

        [$coverAuto, $coverCandidates] = $this->findCoverImageCandidate($directoryPath);
        // If no cover and no images, try m4b extraction
        if (empty($coverAuto) && empty($coverCandidates)) {
            $dir = rtrim($this->storagePath, '/') . '/' . ltrim($directoryPath, '/');
            if (is_dir($dir)) {
                $m4bs = array_values(array_filter(scandir($dir), function ($f) use ($dir) {
                    return is_file($dir . '/' . $f) && strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'm4b';
                }));
                if ($m4bs) {
                    $firstM4b = $dir . '/' . $m4bs[0];
                    $coverFile = $this->extractCoverFromM4B($firstM4b, $dir);
                    if ($coverFile) {
                        $coverAuto = $coverFile;
                    }
                    $tags = $this->extractTagData($firstM4b);

                    // Map ID3 tags to book fields: artist = author, composer = narrator, date = published_date
                    if (!empty($tags['artist']) && empty($initial['author'])) {
                        $initial['author'] = [$tags['artist']];
                    }

                    if (!empty($tags['composer']) && empty($initial['narrator'])) {
                        $initial['narrator'] = $tags['composer'];
                    }

                    if (!empty($tags['date']) && empty($initial['release_date'])) {
                        // Use the date directly if it's a valid date format
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tags['date'])) {
                            $initial['release_date'] = $tags['date'];
                        } else {
                            // Extract year and create a date (e.g., "2025" -> "2025-01-01")
                            $year = substr($tags['date'], 0, 4);
                            if (is_numeric($year) && $year >= 1000 && $year <= date('Y')) {
                                $initial['release_date'] = $year . '-01-01';
                            }
                        }
                    }

                    if (!empty($tags['description'])) {
                        $initial['description'] = $tags['description'];
                    }
                }
                // Also check metadata.abs for description and year
                $meta = $this->extractMetadataAbs($dir);
                if (!empty($meta['description']) && empty($initial['description'])) {
                    $initial['description'] = $meta['description'];
                }
                if (!empty($meta['year']) && empty($initial['release_date'])) {
                    // Convert year to date format
                    if (is_numeric($meta['year']) && $meta['year'] >= 1000 && $meta['year'] <= date('Y')) {
                        $initial['release_date'] = $meta['year'] . '-01-01';
                    }
                }
            }
        }
        if ($directoryPath) {
            try {
                if (Storage::disk('books')->exists($directoryPath)) {
                    $files = Storage::disk('books')->files($directoryPath);
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
            } catch (\League\Flysystem\UnableToCreateDirectory $e) {
                $bookStoragePath = config('filesystems.disks.books.root');
                throw new \RuntimeException(
                    "Book storage directory is not accessible. The configured path '{$bookStoragePath}' does not exist or cannot be created. " .
                    "Please check that the BOOK_STORAGE_PATH environment variable points to a valid, accessible directory."
                );
            }
        }
        // Always fetch genreList as array for the form
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

        // If in import mode, ensure the requested genre exists in the genre list
        if ($isImportMode) {
            $requestedGenres = $request->get('genre', []);
            if (!is_array($requestedGenres)) {
                $requestedGenres = [$requestedGenres];
            }

            foreach ($requestedGenres as $requestedGenre) {
                if (!empty($requestedGenre) && !in_array($requestedGenre, $genreList)) {
                    // Add the new genre to the list so it appears in the dropdown
                    $genreList[] = $requestedGenre;

                    // Also add it to the database for future use
                    try {
                        $documentStore->createGenre(['name' => $requestedGenre]);
                        Log::info('Auto-created genre during import', ['genre' => $requestedGenre]);
                    } catch (\Exception $e) {
                        Log::warning('Failed to auto-create genre', [
                            'genre' => $requestedGenre,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        // Debug logging for genre list
        Log::debug('Genre list for form', [
            'genreList' => $genreList,
            'requested_genre' => $isImportMode ? ($request->get('genre') ?? 'none') : 'not_import'
        ]);
        // Guarantee initial['author'] and initial['genre'] are arrays for JS and Blade
        /** @phpstan-ignore-next-line booleanNot.alwaysFalse */
        if (empty($initial['author']) || !is_array($initial['author'])) {
            $initial['author'] = [''];
        }
        /** @phpstan-ignore-next-line booleanNot.alwaysFalse */
        if (empty($initial['genre']) || !is_array($initial['genre'])) {
            $initial['genre'] = [''];
        }
        $book = []; // Initialize empty book array

        if (!isset($initial['directoryPath'])) {
            $initial['directoryPath'] = '';
        }

        // Normalize selected genres for the form
        $genres = [];
        /** @phpstan-ignore-next-line empty.variable */
        if (!empty($initial['genre'])) {
            foreach ($initial['genre'] as $g) {
                $genres[] = trim((string) $g);
            }
        }
        // Also allow old input to override
        $genres = old('genre', $genres);
        if (!is_array($genres)) {
            $genres = [$genres];
        }

        if ($request->ajax()) {
            return view(
                'admin.books.create_form',
                compact(
                    'genreList',
                    'genres',
                    'initial',
                    'coverCandidates',
                    'coverAuto',
                    'biggestCover',
                    'directoryPath'
                )
            )
                ->with('isModal', true)
                ->with('layout', 'layouts.modal');
        }

        // Ensure initial['author'] is a string for the form field if it's an array
        /** @phpstan-ignore-next-line isset.offset, booleanAnd.rightAlwaysTrue */
        if (isset($initial['author']) && is_array($initial['author'])) {
            $initial['author'] = array_map(function ($a) {
                return is_array($a) ? implode(', ', $a) : (string) $a;
            }, $initial['author']);
        }

        return view(
            'admin.books.create_form',
            compact(
                'genreList',
                'genres',
                'initial',
                'coverCandidates',
                'coverAuto',
                'biggestCover',
                'directoryPath'
            )
        );
    }
}
