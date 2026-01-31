<?php

namespace App\Http\Controllers\Api;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BookApiController extends Controller
{
    /**
     * Autocomplete book series names using fuzzy search.
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
     * Autocomplete author names using fuzzy search.
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
     * Autocomplete narrator names using fuzzy search.
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

    /**
     * Batch fetch book metadata
     */
    public function batch(Request $request)
    {
        $validated = $request->validate([
            'bookIds' => 'nullable|array',
            'bookIds.*' => 'integer',
            'queries' => 'nullable|array',
            'queries.*.title' => 'required_with:queries|string',
            'queries.*.author' => 'nullable|string',
        ]);

        $bookIds = $validated['bookIds'] ?? [];
        $queries = $validated['queries'] ?? [];

        $results = [];
        $processedIds = [];

        // 1. Fetch by IDs
        if (!empty($bookIds)) {
            $books = \App\Models\Book::whereIn('id', $bookIds)->get();
            foreach ($books as $book) {
                // @phpstan-ignore-next-line
                $results[] = $this->transformBookForBatch($book);
                $processedIds[] = $book->id;
            }

            // Find missing IDs in ClientBooks
            $missingIds = array_diff($bookIds, $processedIds);
            if (!empty($missingIds)) {
                $clientBooks = \App\Models\ClientBook::whereIn('id', $missingIds)->get();
                foreach ($clientBooks as $clientBook) {
                    $results[] = $this->transformClientBookForBatch($clientBook);
                }
            }
        }

        // 2. Process Queries
        foreach ($queries as $query) {
            $title = $query['title'];
            $author = $query['author'] ?? null;

            // Try main books
            $q = \App\Models\Book::where('title', 'LIKE', $title);
            if ($author) {
                $q->whereHas('authors', function ($sq) use ($author) {
                    $sq->where('name', 'LIKE', "%{$author}%");
                });
            }
            $book = $q->first();

            if ($book) {
                // Avoid duplicates if we already found it by ID
                if (!in_array($book->id, $processedIds)) {
                    // @phpstan-ignore-next-line
                    $results[] = $this->transformBookForBatch($book);
                    $processedIds[] = $book->id;
                }
                continue;
            }

            // Try Client Books
            $q = \App\Models\ClientBook::where('title', $title);
            if ($author) {
                $q->where('author', $author);
            }
            $clientBook = $q->first();

            if ($clientBook) {
                $results[] = $this->transformClientBookForBatch($clientBook);
            }
        }

        return response()->json($results);
    }

    private function transformBookForBatch($book)
    {
        return $this->getBookWithCover($book->toArray(), true, false);
    }

    private function transformClientBookForBatch($clientBook)
    {
        return [
            'id' => $clientBook->id,
            'title' => $clientBook->title,
            'author' => $clientBook->author,
            'coverImage' => $clientBook->cover_url, // Map to expected field
            'is_client_book' => true,
            'source' => 'client',
        ];
    }

    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $page = (int) $request->input('page', 1);
        $withCover = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);
        $includeNeedsReview = $request->boolean('includeNeedsReview', $request->boolean('include_needs_review', false));

        $filters = [
            'search' => $request->input('search'),
            'genre' => $request->input('genre'),
            'author' => $request->input('author'),
            'series' => $request->input('series'),
            'title' => $request->input('title'),
            'publication_date' => $request->input('publication_date'),
            'date_added' => $request->input('date_added'),
            'status' => $request->input('status'),
            'is_recommended' => $request->has('is_recommended') ? $request->boolean('is_recommended') : null,
            'is_completed' => $request->has('is_completed') ? $request->boolean('is_completed') : null,
            'device_id' => $request->input('device_id'),
        ];

        $sort = $request->input('sort', 'title');
        $order = $request->input('order', 'asc');

        // Respect includeNeedsReview override
        $filters['include_needs_review'] = $includeNeedsReview;
        $userId = Auth::id();

        $booksData = $this->documentStoreService->listBooks(
            $page,
            $perPage,
            $filters,
            true,
            $sort,
            $order,
            false,
            $userId
        );

        // Transform books to match OpenAPI spec
        $transformedBooks = [];
        if (isset($booksData['data']) && is_array($booksData['data'])) {
            $booksArray = array_filter($booksData['data'], 'is_array');
            // If not including needs_review, filter them out here as a safety net
            if (!$includeNeedsReview) {
                $booksArray = array_filter($booksArray, function ($book) {
                    return empty($book['needs_review']);
                });
            }

            $transformedBooks = array_map(function ($book) use ($withCover, $inlineCovers) {
                return $this->getBookWithCover($book, $withCover, $inlineCovers);
            }, $booksArray);
        }

        if ($booksData['total'] > 0) {
            $from = (($page - 1) * $perPage) + 1;
        } else {
            $from = null;
        }

        // @phpstan-ignore-next-line
        $total = (int) $booksData['total'] ?? 0;
        $to = ($total > 0) ? min($page * $perPage, $total) : null;
        $lastPage = $booksData['lastPage'] ?? max(1, ceil($total / $perPage));

        return response()->json([
            'data' => $transformedBooks,
            'meta' => [
                'current_page' => $booksData['currentPage'] ?? $page,
                'from' => $from,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'to' => $to,
                'total' => $total,
            ],
        ]);
    }


    public function show($id, Request $request)
    {
        $userId = Auth::id();
        $book = $this->documentStoreService->getBook($id, $userId);

        if (!$book) {
            return response()->json([
                'error' => 'Book not found',
                'message' => 'The specified book could not be found',
            ], 404);
        }

        // Hide needs_review books unless explicitly requested
        $includeNeedsReview = $request->boolean('includeNeedsReview', $request->boolean('include_needs_review', false));
        $isNeedsReview = !empty($book['needs_review']) || !empty($book['needsReview']);
        if ($isNeedsReview && !$includeNeedsReview) {
            return response()->json([
                'error' => 'Book not available',
                'message' => 'This book is pending review',
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

        // Hide needs_review books unless explicitly requested
        $includeNeedsReview = request()->boolean('includeNeedsReview', request()->boolean('include_needs_review', false));
        $isNeedsReview = !empty($book['needs_review']) || !empty($book['needsReview']);
        if ($isNeedsReview && !$includeNeedsReview) {
            return response()->json([
                'error' => 'Cover not found',
                'message' => 'Cover image not available for a book pending review',
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

        // Check if coverImage is a remote URL (starts with http:// or https://)
        if ($this->isRemoteUrl($coverImage)) {
            return $this->proxyRemoteCoverImage($coverImage);
        }

        // Handle local files (both filename-only and full path formats)
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
            )->header('Content-Type', $mime)
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization')
                ->header('Cache-Control', 'public, max-age=3600');
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

        return $this->coverNotFoundResponse();
    }

    /**
     * Resolve cover image path, handling both filename-only and full path formats
     * Also handles filesystem corruption where directory names have stray quotes
     *
     * @param string $coverImage The cover image value from database
     * @param string|null $directoryPath The directory path for the book
     * @return string The resolved cover image path
     */
    private function resolveCoverImagePath(string $coverImage, ?string $directoryPath): string
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

    /**
     * Check if a URL is remote (starts with http:// or https://)
     *
     * @param string $url
     * @return bool
     */
    private function isRemoteUrl(string $url): bool
    {
        return (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0);
    }

    /**
     * Proxy a remote cover image URL with caching and error handling
     *
     * @param string $url The remote URL to proxy
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    private function proxyRemoteCoverImage(string $url): \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
    {
        try {
            Log::info('Proxying remote cover image', ['url' => $url]);

            // Use Laravel's HTTP client with a reasonable timeout
            /** @var \Illuminate\Http\Client\Response $response */
            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; BooksCoverProxy/1.0)',
                    'Accept' => 'image/*,*/*;q=0.8',
                ])
                ->get($url);

            if ($response->successful()) {
                $content = $response->body();
                $contentType = $response->header('Content-Type');

                // Default to image/jpeg if no content type or invalid content type
                if (!$contentType || !str_starts_with($contentType, 'image/')) {
                    $contentType = 'image/jpeg';
                }

                // Validate that we actually got image content
                if (empty($content)) {
                    Log::warning('Remote cover image returned empty content', ['url' => $url]);
                    return $this->coverNotFoundResponse();
                }

                // Optional: Validate image content using finfo
                if (function_exists('finfo_buffer')) {
                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                    $detectedMime = $finfo->buffer($content);
                    if ($detectedMime && str_starts_with($detectedMime, 'image/')) {
                        $contentType = $detectedMime;
                    }
                }

                return response($content, 200)
                    ->header('Content-Type', $contentType)
                    ->header('Access-Control-Allow-Origin', '*')
                    ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
                    ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization')
                    ->header('Cache-Control', 'public, max-age=3600')
                    ->header('X-Proxied-From', parse_url($url, PHP_URL_HOST));
            } else {
                Log::warning('Remote cover image request failed', [
                    'url' => $url,
                    'status' => $response->status(),
                    'response' => \Illuminate\Support\Str::limit($response->body(), 200),
                ]);
                return $this->coverNotFoundResponse();
            }
        } catch (\Exception $e) {
            Log::error('Exception while proxying remote cover image', [
                'url' => $url,
                'message' => $e->getMessage(),
                'trace' => \Illuminate\Support\Str::limit($e->getTraceAsString(), 500),
            ]);
            return $this->coverNotFoundResponse();
        }
    }

    /**
     * Return a standardized "cover not found" response
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function coverNotFoundResponse(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'error' => 'Cover not found',
            'message' => 'Cover image file could not be found',
        ], 404);
    }


    public function browse(Request $request)
    {
        $type = $request->input('type'); // 'genre', 'author', 'series'
        $perPage = $request->input('per_page', 100);
        $search = $request->input('search');
        $since = $request->input('since') ? (int) $request->input('since') : null;

        $items = match ($type) {
            'genre' => $this->documentStoreService->listGenres($since),
            'author' => $this->documentStoreService->listAuthors($since),
            'series' => $this->documentStoreService->listSeries($since),
            default => null,
        };

        if ($items === null) {
            return response()->json([
                'error' => 'Invalid browse type',
                'message' => 'The browse type must be one of: genre, author, series',
            ], 400);
        }

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
                'since' => $since, // Echo back the since parameter
                'timestamp' => time(), // Current server timestamp for client to use next time
            ],
        ]);
    }


    public function search(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $page = (int) $request->input('page', 1);
        $withCover = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);
        $includeNeedsReview = $request->boolean('includeNeedsReview', $request->boolean('include_needs_review', false));

        $filters = [
            'search' => $request->input('search'),
            'title' => $request->input('title'),
            'author' => $request->input('author'),
            'series' => $request->input('series'),
            'publication_date' => $request->input('publication_date'),
            'date_added' => $request->input('date_added'),
            'status' => $request->input('status'),
            'is_recommended' => $request->has('is_recommended') ? $request->boolean('is_recommended') : null,
            'is_completed' => $request->has('is_completed') ? $request->boolean('is_completed') : null,
            'device_id' => $request->input('device_id'),
        ];

        $filters['include_needs_review'] = $includeNeedsReview;
        $userId = Auth::id();

        $booksData = $this->documentStoreService->listBooks($page, $perPage, $filters, true, 'title', 'asc', false, $userId);
        $books = $booksData['data'];
        $total = $booksData['total'];

        // Transform books to match OpenAPI spec
        $transformedBooks = array_map(function ($book) use ($withCover, $inlineCovers) {
            return $this->getBookWithCover($book, $withCover, $inlineCovers);
        }, $books);

        return response()->json([
            'data' => $transformedBooks,
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
        // Log download request with token preview for comparison
        $authHeader = request()->header('Authorization');
        $tokenPreview = 'none';
        if ($authHeader && preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
            $token = $matches[1];
            $tokenPreview = substr($token, 0, 8) . '...' . substr($token, -4);
        }

        Log::info('Book download requested', [
            'book_id' => $id,
            'ip' => request()->ip(),
            'user_agent' => request()->header('User-Agent'),
            'token_preview' => $tokenPreview,
            'user_id' => Auth::id(),
            'user_email' => Auth::user()->email ?? null
        ]);

        $book = $this->documentStoreService->getBook($id);
        if (!$book) {
            return response()->json([
                'error' => 'Book not found',
                'message' => 'The specified book could not be found',
            ], 404);
        }

        // Hide needs_review books unless explicitly requested
        $includeNeedsReview = request()->boolean('includeNeedsReview', request()->boolean('include_needs_review', false));
        $isNeedsReview = !empty($book['needs_review']) || !empty($book['needsReview']);
        if ($isNeedsReview && !$includeNeedsReview) {
            return response()->json([
                'error' => 'File not found',
                'message' => 'Files not available for a book pending review',
            ], 404);
        }

        $directoryPath = $book['directoryPath'] ?? null;
        if (!$directoryPath || !Storage::disk('books')->exists($directoryPath)) {
            return response()->json([
                'error' => 'Book directory not found',
                'message' => 'The book files could not be located',
            ], 404);
        }

        $files = Storage::disk('books')->files($directoryPath);
        if (empty($files)) {
            return response()->json([
                'error' => 'No files found',
                'message' => 'No audio files found for this book',
            ], 404);
        }

        // Filter and categorize files
        $audioFiles = [];
        $coverFile = null;
        $otherFiles = [];

        foreach ($files as $file) {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $fileName = basename($file);

            if (in_array($extension, ['mp3', 'm4a', 'wav', 'aac', 'ogg', 'flac', 'm4b'])) {
                $audioFiles[] = [
                    'filename' => $fileName,
                    'path' => $file,
                    'size' => Storage::disk('books')->size($file),
                    'type' => 'audio',
                    'download_url' => route('api.books.downloadFile', ['book' => $id, 'file' => urlencode($fileName)]),
                ];
            } elseif (
                in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']) &&
                (strpos(strtolower($fileName), 'cover') !== false || strpos(strtolower($fileName), 'folder') !== false)
            ) {
                $coverFile = [
                    'filename' => $fileName,
                    'path' => $file,
                    'size' => Storage::disk('books')->size($file),
                    'type' => 'cover',
                    'download_url' => route('api.books.downloadFile', ['book' => $id, 'file' => urlencode($fileName)]),
                ];
            } else {
                $otherFiles[] = [
                    'filename' => $fileName,
                    'path' => $file,
                    'size' => Storage::disk('books')->size($file),
                    'type' => 'other',
                    'download_url' => route('api.books.downloadFile', ['book' => $id, 'file' => urlencode($fileName)]),
                ];
            }
        }

        // Sort audio files alphanumerically
        usort($audioFiles, function ($a, $b) {
            return strnatcmp($a['filename'], $b['filename']);
        });

        // Calculate total size
        $totalSize = array_sum(array_column($audioFiles, 'size'));
        if ($coverFile) {
            $totalSize += $coverFile['size'];
        }
        $totalSize += array_sum(array_column($otherFiles, 'size'));

        // Build ordered file list (cover first, then audio files alphabetically, then other files)
        $orderedFiles = [];
        if ($coverFile) {
            $orderedFiles[] = $coverFile;
        }
        $orderedFiles = array_merge($orderedFiles, $audioFiles, $otherFiles);

        $manifest = [
            'book_id' => (int) $id,
            'title' => $book['title'] ?? '',
            'total_files' => count($orderedFiles),
            'total_size' => $totalSize,
            'files' => $orderedFiles,
            'download_instructions' => [
                'order' => 'Files should be downloaded in the provided order',
                'recommended_start' => 'Start with cover image, then first audio file',
                'resume' => 'Use Range headers to resume interrupted downloads',
            ],
            'generated_at' => now()->toISOString(),
        ];

        // Ensure temp directory exists
        if (!file_exists(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0755, true);
        }

        // Create ZIP file
        $zipFileName = 'book_' . $id . '_' . Str::slug($book['title'] ?? 'unknown') . '.zip';
        $zipPath = storage_path('app/temp/' . $zipFileName);

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
            // Add metadata.json
            $zip->addFromString('metadata.json', json_encode($orderedFiles, JSON_PRETTY_PRINT));

            // Add cover image
            if ($coverFile) {
                $zip->addFile(Storage::disk('books')->path($coverFile['path']), 'cover.' . pathinfo($coverFile['path'], PATHINFO_EXTENSION));
            }

            // Add audio files
            foreach ($audioFiles as $file) {
                $zip->addFile(Storage::disk('books')->path($file['path']), $file['filename']);
            }

            // Add other files
            foreach ($otherFiles as $file) {
                if ($coverFile && $file['path'] === $coverFile['path']) {
                    continue;
                } // Skip if already added as cover
                $zip->addFile(Storage::disk('books')->path($file['path']), $file['filename']);
            }

            $zip->close();
        } else {
            return response()->json([
               'error' => 'Zip creation failed',
               'message' => 'Could not create zip file',
            ], 500);
        }

        return response()->download($zipPath, $zipFileName)->deleteFileAfterSend(true);
    }

    /**
     * Download individual file from a book
     */
    public function downloadFile($id, $fileName)
    {
        // Log file download request with token preview for comparison
        $authHeader = request()->header('Authorization');
        $tokenPreview = 'none';
        if ($authHeader && preg_match('/Bearer\s(.*)/', $authHeader, $matches)) {
            $token = $matches[1];
            $tokenPreview = substr($token, 0, 8) . '...' . substr($token, -4);
        }

        Log::info('Book file download requested', [
            'book_id' => $id,
            'file_name' => $fileName,
            'ip' => request()->ip(),
            'user_agent' => request()->header('User-Agent'),
            'token_preview' => $tokenPreview,
            'user_id' => Auth::id(),
            'user_email' => Auth::user()->email ?? null
        ]);

        $book = $this->documentStoreService->getBook($id);
        if (!$book) {
            return response()->json([
                'error' => 'Book not found',
                'message' => 'The specified book could not be found',
            ], 404);
        }

        $directoryPath = $book['directoryPath'] ?? null;
        if (!$directoryPath || !Storage::disk('books')->exists($directoryPath)) {
            return response()->json([
                'error' => 'Book directory not found',
                'message' => 'The book files could not be located',
            ], 404);
        }

        // Decode the filename and find the file
        $fileName = urldecode($fileName);
        $filePath = null;
        $files = Storage::disk('books')->files($directoryPath);

        foreach ($files as $file) {
            if (basename($file) === $fileName) {
                $filePath = $file;
                break;
            }
        }

        if (!$filePath || !Storage::disk('books')->exists($filePath)) {
            return response()->json([
                'error' => 'File not found',
                'message' => 'The requested file could not be found',
            ], 404);
        }

        // Determine content type based on file extension
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $contentType = $this->getContentTypeByExtension($extension);

        // Get file size for Range header support
        $fileSize = Storage::disk('books')->size($filePath);
        $fullPath = Storage::disk('books')->path($filePath);

        // Log download start with file details
        Log::info('File download starting', [
            'book_id' => $id,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'file_size_mb' => round($fileSize / 1024 / 1024, 2),
            'full_path' => $fullPath,
            'user_id' => Auth::id(),
            'ip' => request()->ip(),
        ]);

        // Handle Range requests for resumable downloads
        $headers = [
            'Content-Type' => $contentType,
            'Content-Length' => $fileSize,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'public, max-age=3600',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, Range',
            'Access-Control-Expose-Headers' => 'Content-Range, Accept-Ranges, Content-Length',
        ];

        $request = request();
        $rangeHeader = $request->header('Range');

        if ($rangeHeader) {
            return $this->handleRangeRequest($fullPath, $fileSize, $rangeHeader, $contentType);
        }

        // Regular download
        return response()->stream(function () use ($fullPath, $fileSize, $book, $fileName, $id) {
            $startTime = microtime(true);
            $bytesSent = 0;
            $chunkCount = 0;
            $user = Auth::user();

            Log::info('Starting file download stream', [
                'book_id' => $book['id'] ?? $id,
                'book_title' => $book['title'] ?? 'Unknown',
                'file_name' => $fileName,
                'file_path' => $fullPath,
                'file_size_bytes' => $fileSize,
                'file_size_mb' => round($fileSize / (1024 * 1024), 2),
                'user_id' => $user->id,
                'user_email' => $user->email,
                'client_ip' => request()->ip(),
                'user_agent' => request()->header('User-Agent'),
                'start_time' => date('Y-m-d H:i:s'),
                'start_timestamp' => $startTime,
            ]);

            $handle = fopen($fullPath, 'rb');
            if ($handle === false) {
                Log::error('Failed to open file for streaming', [
                    'book_id' => $book['id'] ?? $id,
                    'file_name' => $fileName,
                    'file_path' => $fullPath,
                    'user_id' => $user->id,
                    'error' => 'fopen_failed',
                ]);
                return;
            }

            $lastProgressLog = 0;
            $progressLogInterval = 10 * 1024 * 1024; // Log every 10MB

            try {
                while (!feof($handle)) {
                    $chunk = fread($handle, 8192);
                    if ($chunk !== false && strlen($chunk) > 0) {
                        echo $chunk;
                        $bytesSent += strlen($chunk);
                        $chunkCount++;

                        if (ob_get_level()) {
                            ob_flush();
                        }
                        flush();

                        // Log progress every 10MB or at significant milestones
                        if (
                            $bytesSent - $lastProgressLog >= $progressLogInterval ||
                            ($fileSize > 0 && $bytesSent >= $fileSize)
                        ) {
                            $currentTime = microtime(true);
                            $elapsedSeconds = $currentTime - $startTime;
                            $progressPercent = $fileSize > 0 ? round(($bytesSent / $fileSize) * 100, 2) : 0;
                            $avgSpeedMBps = $elapsedSeconds > 0 ? round(($bytesSent / (1024 * 1024)) / $elapsedSeconds, 2) : 0;

                            Log::info('File download progress', [
                                'book_id' => $book['id'] ?? $id,
                                'file_name' => $fileName,
                                'user_id' => $user->id,
                                'bytes_sent' => $bytesSent,
                                'bytes_sent_mb' => round($bytesSent / (1024 * 1024), 2),
                                'total_size_bytes' => $fileSize,
                                'total_size_mb' => round($fileSize / (1024 * 1024), 2),
                                'progress_percent' => $progressPercent,
                                'elapsed_seconds' => round($elapsedSeconds, 2),
                                'avg_speed_mbps' => $avgSpeedMBps,
                                'chunks_sent' => $chunkCount,
                                'is_complete' => $bytesSent >= $fileSize
                            ]);

                            $lastProgressLog = $bytesSent;
                        }
                    }

                    if (connection_aborted()) {
                        $endTime = microtime(true);
                        $elapsedSeconds = $endTime - $startTime;

                        Log::warning('File download connection aborted by client', [
                            'book_id' => $book['id'] ?? $id,
                            'file_name' => $fileName,
                            'user_id' => $user->id,
                            'bytes_sent' => $bytesSent,
                            'bytes_sent_mb' => round($bytesSent / (1024 * 1024), 2),
                            'total_size_bytes' => $fileSize,
                            'progress_percent' => $fileSize > 0 ? round(($bytesSent / $fileSize) * 100, 2) : 0,
                            'elapsed_seconds' => round($elapsedSeconds, 2),
                            'chunks_sent' => $chunkCount,
                            'reason' => 'connection_aborted',
                        ]);
                        break;
                    }
                }

                $endTime = microtime(true);
                $elapsedSeconds = $endTime - $startTime;
                $isComplete = $bytesSent >= $fileSize;

                if ($isComplete) {
                    Log::info('File download completed successfully', [
                        'book_id' => $book['id'] ?? $id,
                        'file_name' => $fileName,
                        'user_id' => $user->id,
                        'bytes_sent' => $bytesSent,
                        'bytes_sent_mb' => round($bytesSent / (1024 * 1024), 2),
                        'total_size_bytes' => $fileSize,
                        'elapsed_seconds' => round($elapsedSeconds, 2),
                        'avg_speed_mbps' => $elapsedSeconds > 0 ? round(($bytesSent / (1024 * 1024)) / $elapsedSeconds, 2) : 0,
                        'chunks_sent' => $chunkCount,
                        'status' => 'completed',
                    ]);
                } else {
                    Log::warning('File download ended incomplete', [
                        'book_id' => $book['id'] ?? $id,
                        'file_name' => $fileName,
                        'user_id' => $user->id,
                        'bytes_sent' => $bytesSent,
                        'bytes_sent_mb' => round($bytesSent / (1024 * 1024), 2),
                        'total_size_bytes' => $fileSize,
                        'progress_percent' => $fileSize > 0 ? round(($bytesSent / $fileSize) * 100, 2) : 0,
                        'elapsed_seconds' => round($elapsedSeconds, 2),
                        'chunks_sent' => $chunkCount,
                        'status' => 'incomplete',
                        'reason' => 'stream_ended_early',
                    ]);
                }
            } catch (\Exception $e) {
                $endTime = microtime(true);
                $elapsedSeconds = $endTime - $startTime;

                Log::error('File download failed with exception', [
                    'book_id' => $book['id'] ?? $id,
                    'file_name' => $fileName,
                    'user_id' => $user->id,
                    'bytes_sent' => $bytesSent,
                    'bytes_sent_mb' => round($bytesSent / (1024 * 1024), 2),
                    'elapsed_seconds' => round($elapsedSeconds, 2),
                    'chunks_sent' => $chunkCount,
                    'exception_message' => $e->getMessage(),
                    'exception_class' => get_class($e),
                    'status' => 'error',
                ]);
                throw $e;
            }

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Handle HTTP Range requests for resumable downloads
     */
    private function handleRangeRequest($filePath, $fileSize, $rangeHeader, $contentType)
    {
        if (!preg_match('/bytes=(\d+)-(\d+)?/', $rangeHeader, $matches)) {
            return response('Invalid range', 416);
        }

        $start = (int) $matches[1];
        $end = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : $fileSize - 1;

        if ($start > $end || $start >= $fileSize || $end >= $fileSize) {
            return response('Range not satisfiable', 416, [
                'Content-Range' => "bytes */{$fileSize}",
            ]);
        }

        $length = $end - $start + 1;

        $headers = [
            'Content-Type' => $contentType,
            'Content-Length' => $length,
            'Accept-Ranges' => 'bytes',
            'Content-Range' => "bytes {$start}-{$end}/{$fileSize}",
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, Range',
            'Access-Control-Expose-Headers' => 'Content-Range, Accept-Ranges, Content-Length',
        ];

        return response()->stream(function () use ($filePath, $start, $length, $fileSize) {
            $startTime = microtime(true);
            $bytesSent = 0;
            $chunkCount = 0;
            $user = Auth::user();

            Log::info('Starting range download stream', [
                'file_path' => basename($filePath),
                'range_start' => $start,
                'range_length' => $length,
                'range_end' => $start + $length - 1,
                'total_file_size' => $fileSize,
                'user_id' => $user->id,
                'user_email' => $user->email,
                'client_ip' => request()->ip(),
                'user_agent' => request()->header('User-Agent'),
                'start_time' => date('Y-m-d H:i:s'),
                'start_timestamp' => $startTime,
            ]);

            $handle = fopen($filePath, 'rb');
            if ($handle === false) {
                Log::error('Failed to open file for range streaming', [
                    'file_path' => basename($filePath),
                    'range_start' => $start,
                    'range_length' => $length,
                    'user_id' => $user->id,
                    'error' => 'fopen_failed',
                ]);
                return;
            }

            fseek($handle, $start);
            $remaining = $length;
            $progressLogInterval = max(1048576, $length / 10); // Log every 1MB or 10% of range
            $lastProgressLog = 0;

            try {
                while ($remaining > 0 && !feof($handle)) {
                    $chunkSize = min(8192, $remaining);
                    $chunk = fread($handle, $chunkSize);

                    if ($chunk === false || strlen($chunk) === 0) {
                        break;
                    }

                    echo $chunk;
                    $bytesSent += strlen($chunk);
                    $chunkCount++;
                    $remaining -= strlen($chunk);

                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();

                    // Log progress for range downloads
                    if ($bytesSent - $lastProgressLog >= $progressLogInterval || $remaining <= 0) {
                        $currentTime = microtime(true);
                        $elapsedSeconds = $currentTime - $startTime;
                        $progressPercent = $length > 0 ? round(($bytesSent / $length) * 100, 2) : 100;
                        $avgSpeedMBps = $elapsedSeconds > 0 ? round(($bytesSent / (1024 * 1024)) / $elapsedSeconds, 2) : 0;

                        Log::info('Range download progress', [
                            'file_path' => basename($filePath),
                            'user_id' => $user->id,
                            'range_start' => $start,
                            'bytes_sent' => $bytesSent,
                            'bytes_sent_mb' => round($bytesSent / (1024 * 1024), 2),
                            'range_length' => $length,
                            'range_length_mb' => round($length / (1024 * 1024), 2),
                            'progress_percent' => $progressPercent,
                            'elapsed_seconds' => round($elapsedSeconds, 2),
                            'avg_speed_mbps' => $avgSpeedMBps,
                            'chunks_sent' => $chunkCount,
                            'remaining_bytes' => $remaining,
                            'is_complete' => $remaining <= 0
                        ]);

                        $lastProgressLog = $bytesSent;
                    }

                    if (connection_aborted()) {
                        $endTime = microtime(true);
                        $elapsedSeconds = $endTime - $startTime;

                        Log::warning('Range download connection aborted by client', [
                            'file_path' => basename($filePath),
                            'user_id' => $user->id,
                            'range_start' => $start,
                            'bytes_sent' => $bytesSent,
                            'range_length' => $length,
                            'progress_percent' => $length > 0 ? round(($bytesSent / $length) * 100, 2) : 0,
                            'elapsed_seconds' => round($elapsedSeconds, 2),
                            'chunks_sent' => $chunkCount,
                            'remaining_bytes' => $remaining,
                            'reason' => 'connection_aborted',
                        ]);
                        break;
                    }
                }

                $endTime = microtime(true);
                $elapsedSeconds = $endTime - $startTime;
                $isComplete = $remaining <= 0;

                if ($isComplete) {
                    Log::info('Range download completed successfully', [
                        'file_path' => basename($filePath),
                        'user_id' => $user->id,
                        'range_start' => $start,
                        'bytes_sent' => $bytesSent,
                        'bytes_sent_mb' => round($bytesSent / (1024 * 1024), 2),
                        'range_length' => $length,
                        'elapsed_seconds' => round($elapsedSeconds, 2),
                        'avg_speed_mbps' => $elapsedSeconds > 0 ? round(($bytesSent / (1024 * 1024)) / $elapsedSeconds, 2) : 0,
                        'chunks_sent' => $chunkCount,
                        'status' => 'completed',
                    ]);
                } else {
                    Log::warning('Range download ended incomplete', [
                        'file_path' => basename($filePath),
                        'user_id' => $user->id,
                        'range_start' => $start,
                        'bytes_sent' => $bytesSent,
                        'range_length' => $length,
                        'progress_percent' => $length > 0 ? round(($bytesSent / $length) * 100, 2) : 0,
                        'elapsed_seconds' => round($elapsedSeconds, 2),
                        'chunks_sent' => $chunkCount,
                        'remaining_bytes' => $remaining,
                        'status' => 'incomplete',
                        'reason' => 'stream_ended_early',
                    ]);
                }
            } catch (\Exception $e) {
                $endTime = microtime(true);
                $elapsedSeconds = $endTime - $startTime;

                Log::error('Range download failed with exception', [
                    'file_path' => basename($filePath),
                    'user_id' => $user->id,
                    'range_start' => $start,
                    'bytes_sent' => $bytesSent,
                    'elapsed_seconds' => round($elapsedSeconds, 2),
                    'chunks_sent' => $chunkCount,
                    'remaining_bytes' => $remaining,
                    'exception_message' => $e->getMessage(),
                    'exception_class' => get_class($e),
                    'status' => 'error',
                ]);
                throw $e;
            }

            fclose($handle);
        }, 206, $headers);
    }

    /**
     * Get MIME content type by file extension
     */
    private function getContentTypeByExtension($extension)
    {
        $mimeTypes = [
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'm4b' => 'audio/mp4',
            'wav' => 'audio/wav',
            'aac' => 'audio/aac',
            'ogg' => 'audio/ogg',
            'flac' => 'audio/flac',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'txt' => 'text/plain',
            'nfo' => 'text/plain',
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    /**
     * Get a temporary download URL for a book
     */
    public function downloadUrl($id)
    {
        $book = $this->documentStoreService->getBook($id);
        if (!$book) {
            return response()->json([
                'error' => 'Book not found',
                'message' => 'The specified book could not be found',
            ], 404);
        }

        $directoryPath = $book['directoryPath'] ?? null;
        if (!$directoryPath || !Storage::disk('books')->exists($directoryPath)) {
            return response()->json([
                'error' => 'Book directory not found',
                'message' => 'The book files could not be located',
            ], 404);
        }

        $files = Storage::disk('books')->files($directoryPath);
        if (empty($files)) {
            return response()->json([
                'error' => 'No files found',
                'message' => 'No audio files found for this book',
            ], 404);
        }

        // Generate signed URLs for individual files with 1 hour expiration
        $expiresAt = now()->addHour();
        $signature = hash('sha256', $id . $expiresAt->timestamp . config('app.key'));

        $fileUrls = [];
        $totalSize = 0;

        foreach ($files as $file) {
            $fileName = basename($file);
            $fileSize = Storage::disk('books')->size($file);
            $totalSize += $fileSize;

            $fileUrls[] = [
                'filename' => $fileName,
                'size' => $fileSize,
                'download_url' => route('api.books.downloadFile', [
                    'book' => $id,
                    'file' => urlencode($fileName),
                ]) . "?expires={$expiresAt->timestamp}&signature={$signature}",
            ];
        }

        // Sort files to prioritize cover first, then audio files alphabetically
        usort($fileUrls, function ($a, $b) {
            $extensionA = strtolower(pathinfo($a['filename'], PATHINFO_EXTENSION));
            $extensionB = strtolower(pathinfo($b['filename'], PATHINFO_EXTENSION));

            // Cover images first
            $isCoverA = in_array($extensionA, ['jpg', 'jpeg', 'png', 'gif', 'webp']) &&
                (strpos(strtolower($a['filename']), 'cover') !== false || strpos(strtolower($a['filename']), 'folder') !== false);
            $isCoverB = in_array($extensionB, ['jpg', 'jpeg', 'png', 'gif', 'webp']) &&
                (strpos(strtolower($b['filename']), 'cover') !== false || strpos(strtolower($b['filename']), 'folder') !== false);

            if ($isCoverA && !$isCoverB) {
                return -1;
            }
            if (!$isCoverA && $isCoverB) {
                return 1;
            }

            // Then alphabetical
            return strnatcmp($a['filename'], $b['filename']);
        });

        return response()->json([
            'book_id' => (int) $id,
            'title' => $book['title'] ?? '',
            'expires_at' => $expiresAt->toISOString(),
            'total_size' => $totalSize,
            'total_files' => count($fileUrls),
            'files' => $fileUrls,
            'download_instructions' => [
                'order' => 'Download files in the provided order for best experience',
                'resume' => 'All URLs support HTTP Range headers for resumable downloads',
                'authentication' => 'URLs are signed and will expire at the specified time',
            ],
        ]);
    }

    public function queueDownload(Request $request)
    {
        // Validate incoming request for book IDs (manual to ensure JSON 422)
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'book_ids' => ['required', 'array', 'min:1'],
            'book_ids.*' => ['integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'The given data was invalid.', 'errors' => $validator->errors()], 422);
        }

        $bookIds = $validator->validated()['book_ids'] ?? [];

        // Ensure all books exist
        $missing = [];
        foreach ($bookIds as $bid) {
            if (!\App\Models\Book::query()->whereKey($bid)->exists()) {
                $missing[] = $bid;
            }
        }
        if (count($missing) > 0) {
            return response()->json([
                'message' => 'One or more book IDs are invalid',
                'errors' => [
                    'book_ids' => ['Some provided book IDs do not exist.'],
                ],
            ], 422);
        }

        // Simulate creating a queued zip and return an identifier
        $zipId = 'zip_' . Str::random(16);
        \Illuminate\Support\Facades\Cache::put('download_zip:' . $zipId, [
            'book_ids' => $bookIds,
            'status' => 'ready',
            'created_at' => now()->toISOString(),
        ], now()->addMinutes(30));

        return response()->json([
            'zipId' => $zipId,
            'message' => 'Download queued successfully',
            'book_count' => count($bookIds),
        ]);
    }

    public function downloadQueuedZip($zipId)
    {
        $state = \Illuminate\Support\Facades\Cache::get('download_zip:' . $zipId);
        if (!$state) {
            return response()->json([
                'error' => 'Zip file not found',
                'message' => 'The requested download is not available',
            ], 404);
        }

        // For testing purposes, return JSON indicating readiness
        return response()->json([
            'zipId' => $zipId,
            'status' => $state['status'] ?? 'processing',
        ], 200);
    }

    public function markZipDownloaded($zipId)
    {
        $key = 'download_zip:' . $zipId;
        $state = \Illuminate\Support\Facades\Cache::get($key);
        if (!$state) {
            return response()->json([
                'error' => 'Zip file not found',
                'message' => 'The requested download is not available',
            ], 404);
        }

        if (($state['status'] ?? null) === 'downloaded') {
            return response()->json([
                'message' => 'Already marked as downloaded',
            ], 409);
        }

        $state['status'] = 'downloaded';
        \Illuminate\Support\Facades\Cache::put($key, $state, now()->addMinutes(5));

        return response()->json(['message' => 'Zip file marked as downloaded']);
    }

    /**
     * Get a list of all genres.
     */
    public function listGenres(Request $request)
    {
        $since = $request->input('since') ? (int) $request->input('since') : null;
        $genres = $this->documentStoreService->listGenresWithStats($since);

        // Filter out genres that have no books associated with them
        $genres = array_filter($genres, function (array $genre) {
            return ($genre['bookCount'] ?? 0) > 0;
        });

        usort($genres, function (array $a, array $b) {
            return strcmp($a['name'] ?? '', $b['name'] ?? '');
        });

        $genres = array_map(function (array $genre) {
            return [
                'id' => $genre['id'],
                'name' => $genre['name'],
            ];
        }, $genres);

        return response()->json(array_values($genres));
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
        $authors = array_filter($documentStore->listAuthors(), fn ($author) => in_array($author['id'], $authorIds));
        $authors = array_values($authors);
        usort($authors, fn ($a, $b) => strcmp($a['name'], $b['name']));
        $authors = array_map(fn ($a) => ['id' => $a['id'], 'name' => $a['name']], $authors);

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

        $userId = Auth::id();
        $booksData = $documentStore->listBooks(1, $perPage, ['series_id' => $seriesId], true, 'title', 'asc', false, $userId);
        $books = $booksData['data'];

        $transformedBooks = array_map(fn ($book) => $this->getBookWithCover($book, $withCover, $inlineCovers), $books);

        return response()->json([
            'series' => ['id' => $series['id'], 'name' => $series['name']],
            'data' => $transformedBooks,
            'meta' => [
                'current_page' => 1,
                'from' => ($booksData['total'] > 0) ? 1 : null,
                'last_page' => $booksData['lastPage'],
                'per_page' => $perPage,
                'to' => count($transformedBooks),
                'total' => $booksData['total'],
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

        $userId = Auth::id();
        $booksData = $documentStore->listBooks(1, $perPage, ['author_id' => $authorId], true, 'title', 'asc', false, $userId);
        $books = $booksData['data'];

        $transformedBooks = array_map(fn ($book) => $this->getBookWithCover($book, $withCover, $inlineCovers), $books);

        return response()->json([
            'author' => ['id' => $author['id'], 'name' => $author['name']],
            'data' => $transformedBooks,
            'meta' => [
                'current_page' => 1,
                'from' => ($booksData['total'] > 0) ? 1 : null,
                'last_page' => $booksData['lastPage'],
                'per_page' => $perPage,
                'to' => count($transformedBooks),
                'total' => $booksData['total'],
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
        $books = array_filter($documentStore->listBooks(), fn ($book) => ($book['author_id'] ?? null) == $authorId);
        $seriesIds = array_unique(array_column($books, 'series_id'));
        $series = array_filter($documentStore->listSeries(), fn ($ser) => in_array($ser['id'], $seriesIds));
        $series = array_values($series);
        usort($series, fn ($a, $b) => strcmp($a['name'], $b['name']));
        $series = array_map(fn ($s) => ['id' => $s['id'], 'name' => $s['name']], $series);

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
        $page = max(1, (int) $request->input('page', 1));
        $withCover = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);

        $documentStore = $this->documentStoreService;
        $author = $documentStore->getAuthor($authorId);
        $genre = $documentStore->getGenre($genreId);

        if (!$author || !$genre) {
            return response()->json([
                'error' => 'Author or Genre not found',
                'message' => 'The specified author or genre could not be found',
            ], 404);
        }

        $userId = Auth::id();
        $booksData = $documentStore->listBooks(
            $page,
            $perPage,
            ['author_id' => $authorId, 'genre_id' => $genreId],
            true,
            'title',
            'asc',
            false,
            $userId
        );

        $books = $booksData['data'];
        $transformedBooks = array_map(fn ($book) => $this->getBookWithCover($book, $withCover, $inlineCovers), $books);

        if ($booksData['total'] > 0) {
            $from = (($page - 1) * $perPage) + 1;
        } else {
            $from = null;
        }

        // @phpstan-ignore-next-line
        $total = (int) $booksData['total'] ?? 0;
        $to = ($total > 0) ? min($page * $perPage, $total) : null;
        $lastPage = $booksData['lastPage'] ?? max(1, ceil($total / $perPage));

        return response()->json([
            'data' => $transformedBooks,
            'meta' => [
                'current_page' => $booksData['currentPage'] ?? $page,
                'from' => $from,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'to' => $to,
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

        // Include user-specific data if present
        if (isset($book['progress'])) {
            $transformedBook['progress'] = $book['progress'];
        }
        if (isset($book['status'])) {
            $transformedBook['status'] = $book['status'];
        }
        if (isset($book['recommendation'])) {
            $transformedBook['recommendation'] = $book['recommendation'];
        }

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
     * Returns only entries with valid (non-null, non-empty) series names
     */
    private function formatSeriesData($book): array
    {
        // Handle case where series is already loaded as a relationship
        if (isset($book['series']) && is_array($book['series']) && !empty($book['series'])) {
            $result = [];
            foreach ($book['series'] as $series) {
                $name = null;
                $seriesNumber = null;

                if (is_array($series)) {
                    $name = $series['name'] ?? $series['seriesName'] ?? null;
                    $seriesNumber = $series['pivot']['series_number'] ?? $series['series_number'] ?? $series['number'] ?? null;
                } elseif (is_object($series)) {
                    $name = $series->name ?? $series->seriesName ?? null;
                    $seriesNumber = $series->pivot->series_number ?? $series->series_number ?? $series->number ?? null;
                }

                // Only include entries with valid series names (not null or empty)
                if (!empty($name)) {
                    $result[] = [
                        'name' => $name,
                        'series_number' => $seriesNumber !== null ? (string)$seriesNumber : null,
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

        $resolvedCoverPath = null;
        if (!empty($book['coverImage'])) {
            $resolvedCoverPath = $this->resolveCoverImagePath($book['coverImage'], $book['directoryPath'] ?? null);
        }

        // Build the manifest
        $manifest = [
            'book_id' => $id,
            'title' => $book['title'] ?? '',
            'author' => $book['author_name'] ?? '',
            'series' => $book['series_name'] ?? '',
            'series_number' => $book['series_number'] ?? null,
            'total_duration_seconds' => $totalDuration,
            'cover_included' => !empty($resolvedCoverPath) && Storage::disk('books')->exists($resolvedCoverPath),
            'format' => 'zip',
            'chapters' => $chapters,
            'has_audio' => $hasAudio,
            'total_chapters' => count($chapters),
            'total_files' => count($chapters) + (!empty($book['coverImage']) ? 1 : 0),
        ];

        return response()->json($manifest);
    }

    /**
     * Get all authors with optional genre filtering, pagination, and sorting
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function authors(Request $request)
    {
        $genreId = $request->input('genre_id');
        $genreName = $request->input('genre_name');
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 50)));
        $sort = $request->input('sort', 'name_asc');
        $search = $request->input('search');
        $since = $request->input('since') ? (int) $request->input('since') : null;
        $includeNeedsReview = $request->boolean('includeNeedsReview', $request->boolean('include_needs_review', false));

        // Validate sort parameter
        $allowedSorts = ['name_asc', 'name_desc', 'book_count_asc', 'book_count_desc'];
        if (!in_array($sort, $allowedSorts)) {
            $sort = 'name_asc';
        }

        $isFavorite = $request->boolean('favorites', false);
        $userId = Auth::id();

        // Build the base query
        $query = \App\Models\Author::query()
            ->select([
                'authors.id',
                'authors.name',
            ])
            ->selectRaw('MAX(authors.updated_at) as updated_at')
            ->selectSub(function ($q) use ($includeNeedsReview) {
                $q->from('author_book')
                    ->join('books', 'author_book.book_id', '=', 'books.id')
                    ->whereColumn('author_book.author_id', 'authors.id');
                if (!$includeNeedsReview) {
                    $q->where('books.needs_review', false);
                }
                $q->selectRaw('COUNT(DISTINCT books.id)');
            }, 'book_count')
            ->selectRaw('EXISTS(SELECT 1 FROM user_author_favorites WHERE user_id = ? AND author_id = authors.id) as isFavorite', [$userId])
            ->join('author_book', 'authors.id', '=', 'author_book.author_id')
            ->join('books', 'author_book.book_id', '=', 'books.id');

        if ($isFavorite && $userId) {
            $query->join('user_author_favorites', function ($join) use ($userId) {
                $join->on('authors.id', '=', 'user_author_favorites.author_id')
                    ->where('user_author_favorites.user_id', '=', $userId);
            });
        }


        if ($since) {
            $query->where('authors.updated_at', '>=', date('Y-m-d H:i:s', $since));
        }

        // Exclude needs_review books unless explicitly included
        if (!$includeNeedsReview) {
            $query->where('books.needs_review', false);
        }

        // Add genre filtering if specified
        if ($genreId || $genreName) {
            $query->join('book_genre', 'books.id', '=', 'book_genre.book_id')
                ->join('genres', 'book_genre.genre_id', '=', 'genres.id');

            if ($genreId) {
                $query->where('genres.id', $genreId);
            } elseif ($genreName) {
                $query->where('genres.name', $genreName);
            }

            // Add book count in specific genre
            $query->selectRaw(
                'COUNT(DISTINCT CASE WHEN genres.id = ? OR genres.name = ? THEN books.id END) as book_count_in_genre',
                [$genreId, $genreName]
            );
        } else {
            // No genre filter, so book_count_in_genre equals total book_count
            $query->selectRaw('COUNT(DISTINCT books.id) as book_count_in_genre');
        }

        // Add search functionality
        if ($search) {
            $query->where('authors.name', 'LIKE', '%' . $search . '%');
        }

        // Group by author to avoid duplicates
        $query->groupBy('authors.id', 'authors.name');

        // Add sorting
        switch ($sort) {
            case 'name_desc':
                $query->orderBy('authors.name', 'desc');
                break;
            case 'book_count_asc':
                $query->orderBy('book_count', 'asc');
                break;
            case 'book_count_desc':
                $query->orderBy('book_count', 'desc');
                break;
            case 'name_asc':
            default:
                $query->orderBy('authors.name', 'asc');
                break;
        }

        // Get total count before pagination - need to remove GROUP BY for accurate count
        $countQuery = \App\Models\Author::query()
            ->join('author_book', 'authors.id', '=', 'author_book.author_id')
            ->join('books', 'author_book.book_id', '=', 'books.id');

        if (!$includeNeedsReview) {
            $countQuery->where('books.needs_review', false);
        }

        if ($isFavorite && $userId) {
            $countQuery->join('user_author_favorites', function ($join) use ($userId) {
                $join->on('authors.id', '=', 'user_author_favorites.author_id')
                    ->where('user_author_favorites.user_id', '=', $userId);
            });
        }


        // Add same genre filtering as main query if present
        if ($genreId || $genreName) {
            $countQuery->join('book_genre', 'books.id', '=', 'book_genre.book_id')
                ->join('genres', 'book_genre.genre_id', '=', 'genres.id');
            if ($genreId) {
                $countQuery->where('genres.id', $genreId);
            } elseif ($genreName) {
                $countQuery->where('genres.name', $genreName);
            }
        }

        // Add search functionality if present
        if ($search) {
            $countQuery->where('authors.name', 'LIKE', '%' . $search . '%');
        }

        if ($since) {
            $countQuery->where('authors.updated_at', '>=', date('Y-m-d H:i:s', $since));
        }

        $total = $countQuery->distinct()->count('authors.id');

        // Execute query with pagination
        $offset = ($page - 1) * $perPage;
        $authors = $query->offset($offset)->limit($perPage)->get();

        // Get genres and series for each author
        $authorsWithDetails = $authors->map(function ($author) use ($includeNeedsReview) {
            // Get genres for this author
            $authorBooksQuery = $author->books();
            if (!$includeNeedsReview) {
                $authorBooksQuery->where('books.needs_review', false);
            }
            $author->genres = $authorBooksQuery
                ->join('book_genre', 'books.id', '=', 'book_genre.book_id')
                ->join('genres', 'book_genre.genre_id', '=', 'genres.id')
                ->select('genres.name')
                ->distinct()
                ->pluck('name')
                ->toArray();

            // Compute total book count for this author independent of outer joins
            $totalBookCount = \App\Models\Book::query()
                ->join('author_book', 'books.id', '=', 'author_book.book_id')
                ->where('author_book.author_id', $author->id)
                ->when(!$includeNeedsReview, function ($q) {
                    $q->where('books.needs_review', false);
                })
                ->distinct('books.id')
                ->count('books.id');

            // Get series for this author with book counts
            $authorSeries = \App\Models\Series::query()
                ->select('series.id', 'series.name')
                ->selectRaw('COUNT(DISTINCT books.id) as books_in_series')
                ->join('book_series', 'series.id', '=', 'book_series.series_id')
                ->join('books', 'book_series.book_id', '=', 'books.id')
                ->join('author_book', 'books.id', '=', 'author_book.book_id')
                ->where('author_book.author_id', $author->id)
                ->when(!$includeNeedsReview, function ($q) {
                    $q->where('books.needs_review', false);
                })
                ->groupBy('series.id', 'series.name')
                ->get()
                ->map(function ($series) {
                    return [
                        'id' => $series->id,
                        'name' => $series->name,
                        'books_in_series' => $series->books_in_series,
                    ];
                });

            return [
                'id' => $author->id,
                'name' => $author->name,
                'biography' => null, // Column doesn't exist in database
                'book_count' => $totalBookCount,
                'book_count_in_genre' => $author->book_count_in_genre ?? $author->book_count,
                'image_url' => null, // Column doesn't exist in database
                'genres' => $author->genres,
                'series' => $authorSeries->toArray(),
                'isFavorite' => (bool) $author->isFavorite,
            ];
        });

        // Calculate pagination info
        $totalPages = ceil($total / $perPage);
        $hasNext = $page < $totalPages;
        $hasPrev = $page > 1;

        return response()->json([
            'authors' => $authorsWithDetails,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_next' => $hasNext,
                'has_prev' => $hasPrev,
            ],
        ]);
    }

    /**
     * Get all series with optional author filtering, pagination, and sorting
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function series(Request $request)
    {
        $authorId = $request->input('author_id');
        $authorName = $request->input('author_name');
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 50)));
        $sort = $request->input('sort', 'name_asc');
        $search = $request->input('search');
        $since = $request->input('since') ? (int) $request->input('since') : null;
        $includeNeedsReview = $request->boolean('includeNeedsReview', $request->boolean('include_needs_review', false));
        $isFavorite = $request->boolean('favorites', false);
        $userId = Auth::id();

        // Validate sort parameter
        $allowedSorts = ['name_asc', 'name_desc', 'book_count_asc', 'book_count_desc'];
        if (!in_array($sort, $allowedSorts)) {
            $sort = 'name_asc';
        }

        // Build the base query
        $query = \App\Models\Series::query()
            ->select([
                'series.id',
                'series.name',
            ])
            ->selectRaw('MAX(series.updated_at) as updated_at')
            ->withCount('books as book_count')
            ->selectRaw('EXISTS(SELECT 1 FROM user_series_favorites WHERE user_id = ? AND series_id = series.id) as isFavorite', [$userId])
            ->join('book_series', 'series.id', '=', 'book_series.series_id')
            ->join('books', 'book_series.book_id', '=', 'books.id');

        if ($isFavorite && $userId) {
            $query->join('user_series_favorites', function ($join) use ($userId) {
                $join->on('series.id', '=', 'user_series_favorites.series_id')
                    ->where('user_series_favorites.user_id', '=', $userId);
            });
        }


        if ($since) {
            $query->where('series.updated_at', '>=', date('Y-m-d H:i:s', $since));
        }

        if (!$includeNeedsReview) {
            $query->where('books.needs_review', false);
        }

        // Add author filtering if specified
        if ($authorId || $authorName) {
            $query->join('author_book', 'books.id', '=', 'author_book.book_id')
                ->join('authors', 'author_book.author_id', '=', 'authors.id');

            if ($authorId) {
                $query->where('authors.id', $authorId);
            } elseif ($authorName) {
                $query->where('authors.name', $authorName);
            }

            // Add book count by specific author
            $query->selectRaw(
                'COUNT(DISTINCT CASE WHEN (authors.id = ? OR authors.name = ?) THEN books.id END) as book_count_by_author',
                [$authorId, $authorName]
            );
        } else {
            // No author filter, so book_count_by_author equals total book_count
            $query->selectRaw('COUNT(DISTINCT books.id) as book_count_by_author');
        }

        // Add search functionality
        if ($search) {
            $query->where('series.name', 'LIKE', '%' . $search . '%');
        }

        // Group by series to avoid duplicates
        $query->groupBy('series.id', 'series.name');

        // Add sorting
        switch ($sort) {
            case 'name_desc':
                $query->orderBy('series.name', 'desc');
                break;
            case 'book_count_asc':
                $query->orderByRaw('COUNT(DISTINCT books.id) ASC');
                break;
            case 'book_count_desc':
                $query->orderByRaw('COUNT(DISTINCT books.id) DESC');
                break;
            case 'name_asc':
            default:
                $query->orderBy('series.name', 'asc');
                break;
        }

        // Get total count before pagination - need to remove GROUP BY for accurate count
        $countQuery = \App\Models\Series::query()
            ->join('book_series', 'series.id', '=', 'book_series.series_id')
            ->join('books', 'book_series.book_id', '=', 'books.id');

        if (!$includeNeedsReview) {
            $countQuery->where('books.needs_review', false);
        }

        if ($isFavorite && $userId) {
            $countQuery->join('user_series_favorites', function ($join) use ($userId) {
                $join->on('series.id', '=', 'user_series_favorites.series_id')
                    ->where('user_series_favorites.user_id', '=', $userId);
            });
        }


        // Add same author filtering as main query if present
        if ($authorId || $authorName) {
            $countQuery->join('author_book', 'books.id', '=', 'author_book.book_id')
                ->join('authors', 'author_book.author_id', '=', 'authors.id');
            if ($authorId) {
                $countQuery->where('authors.id', $authorId);
            } elseif ($authorName) {
                $countQuery->where('authors.name', $authorName);
            }
        }

        // Add search functionality if present
        if ($search) {
            $countQuery->where('series.name', 'LIKE', '%' . $search . '%');
        }

        if ($since) {
            $countQuery->where('series.updated_at', '>=', date('Y-m-d H:i:s', $since));
        }

        $total = $countQuery->distinct()->count('series.id');

        // Execute query with pagination
        $offset = ($page - 1) * $perPage;
        $series = $query->offset($offset)->limit($perPage)->get();

        // Get authors for each series if needed for response
        $seriesWithAuthors = $series->map(function ($series) use ($includeNeedsReview) {
            $series->authors = $series->books()
                ->join('author_book', 'books.id', '=', 'author_book.book_id')
                ->join('authors', 'author_book.author_id', '=', 'authors.id')
                ->select('authors.name')
                ->distinct()
                ->when(!$includeNeedsReview, function ($q) {
                    $q->where('books.needs_review', false);
                })
                ->pluck('name')
                ->toArray();

            // Compute total book count for this series respecting needs_review filter
            $totalBookCount = \App\Models\Book::query()
                ->join('book_series', 'books.id', '=', 'book_series.book_id')
                ->where('book_series.series_id', $series->id)
                ->when(!$includeNeedsReview, function ($q) {
                    $q->where('books.needs_review', false);
                })
                ->distinct('books.id')
                ->count('books.id');

            return [
                'id' => $series->id,
                'name' => $series->name,
                'description' => null, // Column doesn't exist in database
                'book_count' => $totalBookCount,
                'book_count_by_author' => $series->book_count_by_author ?? $series->book_count,
                'authors' => $series->authors,
                'isFavorite' => (bool) $series->isFavorite,
            ];
        });

        // Calculate pagination info
        $totalPages = ceil($total / $perPage);
        $hasNext = $page < $totalPages;
        $hasPrev = $page > 1;

        return response()->json([
            'series' => $seriesWithAuthors,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_next' => $hasNext,
                'has_prev' => $hasPrev,
            ],
        ]);
    }

    /**
     * Enhanced books endpoint with proper SQL-based filtering
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function booksEnhanced(Request $request)
    {
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('per_page', 15)));
        $withCover = $request->boolean('with_cover', true);
        $inlineCovers = $request->boolean('inlineCovers', false);

        // Filtering parameters
        $filters = [
            'genre_id' => $request->input('genre_id'),
            'genre' => $request->input('genre_name'),
            'author_id' => $request->input('author_id'),
            'author' => $request->input('author_name'),
            'series_id' => $request->input('series_id'),
            'series' => $request->input('series_name'),
            'search' => $request->input('search'),
            'status' => $request->input('status'),
            'is_recommended' => $request->has('is_recommended') ? $request->boolean('is_recommended') : null,
            'is_completed' => $request->has('is_completed') ? $request->boolean('is_completed') : null,
            'device_id' => $request->input('device_id'),
        ];

        // Map enhanced sort names to internal sort names
        $sortMap = [
            'title_asc' => ['title', 'asc'],
            'title_desc' => ['title', 'desc'],
            'created_at_asc' => ['created_at', 'asc'],
            'created_at_desc' => ['created_at', 'desc'],
            'author_asc' => ['author', 'asc'],
            'author_desc' => ['author', 'desc'],
            'progress_asc' => ['progress', 'asc'],
            'progress_desc' => ['progress', 'desc'],
            'last_listened_asc' => ['last_listened', 'asc'],
            'last_listened_desc' => ['last_listened', 'desc'],
            'queue_order_asc' => ['queue_order', 'asc'],
            'queue_order_desc' => ['queue_order', 'desc'],
        ];

        $sortParam = $request->input('sort', 'title_asc');
        $sortConfig = $sortMap[$sortParam] ?? ['title', 'asc'];

        $sort = $sortConfig[0];
        $order = $sortConfig[1];
        $userId = Auth::id();

        $booksData = $this->documentStoreService->listBooks(
            $page,
            $perPage,
            $filters,
            true,
            $sort,
            $order,
            false,
            $userId
        );

        $total = $booksData['total'];
        $books = $booksData['data'];

        // Transform books to match API spec
        $transformedBooks = array_map(function ($book) use ($withCover, $inlineCovers) {
            $result = $this->getBookWithCover($book, $withCover, $inlineCovers);

            // Add full relationship objects for the enhanced endpoint
            // listBooks returns arrays with specific keys for these relationships
            if (isset($book['authors_data'])) {
                $result['authors'] = $book['authors_data'];
            } elseif (isset($book['authors'])) {
                $result['authors'] = $book['authors'];
            }

            if (isset($book['genres_data'])) {
                $result['genres'] = $book['genres_data'];
            } elseif (isset($book['genres'])) {
                $result['genres'] = $book['genres'];
            }

            if (isset($book['series_data'])) {
                $result['series'] = $book['series_data'];
            } elseif (isset($book['series'])) {
                $result['series'] = $book['series'];
            }

            if (isset($book['narrators_data'])) {
                $result['narrators'] = $book['narrators_data'];
            } elseif (isset($book['narrators'])) {
                $result['narrators'] = $book['narrators'];
            }

            return $result;
        }, $books);

        // Calculate pagination info
        $totalPages = ceil($total / $perPage);
        $hasNext = $page < $totalPages;
        $hasPrev = $page > 1;

        return response()->json([
            'books' => $transformedBooks,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_next' => $hasNext,
                'has_prev' => $hasPrev,
            ],
        ]);
    }
}
