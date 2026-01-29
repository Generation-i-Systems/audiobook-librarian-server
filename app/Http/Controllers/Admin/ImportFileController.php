<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use App\Services\AIBookProcessor;
use App\Services\BookImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use App\Traits\IsolatesErrorHandlers;
use Illuminate\Support\Str;

class ImportFileController extends Controller
{
    use IsolatesErrorHandlers;
    protected DocumentStoreServiceInterface $documentStoreService;
    protected BookImportService $bookImportService;

    public function __construct(
        DocumentStoreServiceInterface $documentStoreService,
        BookImportService $bookImportService
    ) {
        $this->documentStoreService = $documentStoreService;
        $this->bookImportService = $bookImportService;
    }

    /**
     * List configured import roots.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function roots()
    {
        try {
            $rawRoots = Config::get('import.roots', []);
            Log::debug('[ImportFile] Found ' . count($rawRoots) . ' configured roots in config');

            if (empty($rawRoots)) {
                Log::warning('[ImportFile] No import roots configured in config/import.php');
                // Create default import directory if it doesn't exist
                $defaultRoot = storage_path('import');
                if (!is_dir($defaultRoot)) {
                    Log::info('[ImportFile] Creating default import directory: ' . $defaultRoot);
                    if (!File::makeDirectory($defaultRoot, 0775, true)) {
                        Log::error('[ImportFile] Failed to create default import directory: ' . $defaultRoot);
                    }
                }
            }

            $roots = collect($rawRoots)
                ->flatMap(function ($path) {
                    try {
                        if (strpos($path, '*') !== false) {
                            $matches = glob($path, GLOB_ONLYDIR) ?: [];
                            Log::debug('[ImportFile] Glob pattern ' . $path . ' matched ' . count($matches) . ' directories');

                            return collect($matches)->map(function ($dir) {
                                $parent = basename(dirname($dir));
                                $label = $parent . '/' . basename($dir);

                                return [
                                    'value' => $dir,
                                    'label' => $label,
                                ];
                            });
                        } elseif (is_dir($path)) {
                            Log::debug('[ImportFile] Found valid directory: ' . $path);

                            return [
                                [
                                    'value' => $path,
                                    'label' => basename($path) ?: $path,
                                ],
                            ];
                        } else {
                            // Check if directory exists but isn't accessible due to permissions
                            if (file_exists($path) && !is_readable($path)) {
                                Log::warning('[ImportFile] Directory exists but is not readable due to permissions: ' . $path);

                                return [
                                    [
                                        'value' => $path,
                                        'label' => basename($path) ?: $path,
                                        'error' => 'Permission denied: Directory exists but is not readable',
                                        'permissions' => substr(sprintf('%o', fileperms($path)), -4),
                                    ],
                                ];
                            } else {
                                Log::warning('[ImportFile] Directory not found or not accessible: ' . $path);

                                return [
                                    [
                                        'value' => $path,
                                        'label' => basename($path) ?: $path,
                                        'error' => 'Directory not found or not accessible',
                                    ],
                                ];
                            }
                        }
                    } catch (\Exception $e) {
                        Log::error('[ImportFile] Error processing import root path: ' . $path . ' - ' .
                            $e->getMessage());

                        return [];
                    }
                })
                ->values();

            Log::info('[ImportFile] Returning ' . $roots->count() . ' valid import roots');

            return response()->json($roots);
        } catch (\Exception $e) {
            Log::error('[ImportFile] Error listing import roots: ' . $e->getMessage());

            return response()->json([
                'error' => 'Failed to list import roots: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List files and directories in the given root/path.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function list(Request $request)
    {
        try {
            $root = $request->input('root');
            $path = $request->input('path', '');

            Log::debug("[ImportFile] Listing directory: root={$root}, path={$path}");

            // Check if root directory exists and is accessible
            if (!file_exists($root)) {
                Log::warning("[ImportFile] Root directory does not exist: {$root}");

                return response()->json([
                    'error' => 'Root directory does not exist',
                    'details' => "The directory '{$root}' could not be found on the server.",
                ], 404);
            }

            if (!is_dir($root)) {
                Log::warning("[ImportFile] Root path is not a directory: {$root}");

                return response()->json([
                    'error' => 'Invalid root path',
                    'details' => "The path '{$root}' exists but is not a directory.",
                ], 400);
            }

            if (!is_readable($root)) {
                Log::warning("[ImportFile] Root directory is not readable due to permissions: {$root}");
                $perms = substr(sprintf('%o', fileperms($root)), -4);

                return response()->json([
                    'error' => 'Permission denied',
                    'details' => "The directory '{$root}' exists but cannot be read due to insufficient permissions.",
                    'permissions' => $perms,
                    'user' => function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown') : (getenv('USER') ?: getenv('USERNAME') ?: 'unknown'),
                ], 403);
            }

            $absRoot = realpath($root);
            if (!$absRoot) {
                Log::warning("[ImportFile] Could not resolve real path for root: {$root}");

                return response()->json([
                    'error' => 'Path resolution failed',
                    'details' => "Could not resolve the real path for '{$root}'. " .
                        'This might be due to symlink issues or filesystem restrictions.',
                ], 400);
            }

            $absPath = $absRoot . ($path ? DIRECTORY_SEPARATOR . $path : '');
            $absPath = realpath($absPath) ?: $absPath;

            // Check for path traversal attempts (security check)
            if (strpos($absPath, $absRoot) !== 0) {
                Log::warning("[ImportFile] Path traversal detected: {$absPath}");

                return response()->json([
                    'error' => 'Security violation: Path traversal detected',
                    'details' => 'The requested path is outside the allowed root directory. This may be an attempt to access unauthorized files.',
                ], 403);
            }

            // Check if path exists
            if (!file_exists($absPath)) {
                Log::warning("[ImportFile] Path does not exist: {$absPath}");

                return response()->json([
                    'error' => 'Path not found',
                    'details' => "The requested path '{$path}' does not exist in the selected root directory.",
                ], 404);
            }

            // Check if path is readable
            if (!is_readable($absPath)) {
                Log::warning("[ImportFile] Path is not readable due to permissions: {$absPath}");
                $perms = substr(sprintf('%o', fileperms($absPath)), -4);

                return response()->json([
                    'error' => 'Permission denied',
                    'details' => "The path '{$path}' exists but cannot be read due to insufficient permissions.",
                    'permissions' => $perms,
                    'user' => function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown') : (getenv('USER') ?: getenv('USERNAME') ?: 'unknown'),
                ], 403);
            }

            // Get directories
            $allowedExtensions = Config::get('import.allowed_extensions', []);
            $directories = collect(File::directories($absPath))
                ->filter(function ($dir) use ($allowedExtensions) {
                    $dirFiles = File::files($dir);
                    foreach ($dirFiles as $file) {
                        if (in_array(strtolower($file->getExtension()), $allowedExtensions, true)) {
                            return true;
                        }
                    }

                    return false;
                })
                ->map(function ($dir) {
                    return [
                        'type' => 'dir',
                        'name' => basename($dir),
                    ];
                });

            Log::debug('[ImportFile] Found ' . count($directories) . " directories with matching files in {$absPath}");

            // Get files with allowed extensions
            $files = collect(File::files($absPath))
                ->filter(function ($f) use ($allowedExtensions) {
                    return in_array(strtolower($f->getExtension()), $allowedExtensions);
                })
                ->map(function ($f) {
                    return [
                        'type' => 'file',
                        'name' => $f->getFilename(),
                    ];
                });

            Log::debug('[ImportFile] Found ' . count($files) . " files with allowed extensions in {$absPath}");

            // Merge and sort items
            $items = $directories->merge($files)->values();

            // Determine if we should show parent directory option
            $parent = $absPath !== $absRoot;

            Log::debug('[ImportFile] Returning ' . count($items) . " items, parent={$parent}");

            return response()->json([
                'parent' => $parent,
                'items' => $items,
                'path' => $path,
                'root' => $root,
            ]);
        } catch (\Exception $e) {
            Log::error('[ImportFile] Error listing directory: ' . $e->getMessage());

            return response()->json(['error' => 'Failed to list directory: ' . $e->getMessage()], 500);
        }
    }


    /**
     * Extract metadata from a file or directory and redirect to import form.
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function extract(Request $request)
    {
        try {
            $root = $request->input('root');
            $relPath = $request->input('path');
            $type = $request->input('type');
            $redirectToForm = $request->input('redirectToForm', false);

            $logMessage = "[ImportFile] Extracting metadata: root={$root}, path={$relPath}, ";
            $logMessage .= "type={$type}, redirectToForm={$redirectToForm}";
            Log::debug($logMessage);

            // Check for required parameters
            if (empty($root)) {
                Log::warning('[ImportFile] Missing required parameter: root');

                return response()->json(['success' => false, 'message' => 'Missing required parameter: root'], 400);
            }

            if (empty($type)) {
                Log::warning('[ImportFile] Missing required parameter: type');

                return response()->json(['success' => false, 'message' => 'Missing required parameter: type'], 400);
            }

            $absRoot = realpath($root);
            if (!$absRoot || !is_dir($absRoot)) {
                Log::warning('[ImportFile] Invalid root: ' . $root);

                return response()->json(['success' => false, 'message' => 'Invalid root'], 400);
            }

            $absPath = $absRoot . ($relPath ? DIRECTORY_SEPARATOR . $relPath : '');
            $absPath = realpath($absPath) ?: $absPath;

            if (strpos($absPath, $absRoot) !== 0) {
                Log::warning('[ImportFile] Path traversal detected: ' . $absPath);

                return response()->json(['success' => false, 'message' => 'Path traversal detected'], 400);
            }

            if (!file_exists($absPath)) {
                Log::warning('[ImportFile] File or directory does not exist: ' . $absPath);

                return response()->json(['success' => false, 'message' => 'File or directory does not exist'], 404);
            }

            $meta = [
                'title' => null,
                'author' => null,
                'series' => null,
                'genre' => null,
                'files' => [],
            ];

            // Validate type parameter
            if ($type !== 'file' && $type !== 'dir') {
                Log::warning("[ImportFile] Invalid type parameter: {$type}");

                return response()->json([
                    'success' => false,
                    'message' => "Invalid type parameter: must be 'file' or 'dir'",
                ], 400);
            }

            // Check if getID3 is available
            $getID3Available = class_exists('getID3');
            Log::debug('[ImportFile] getID3 library available: ' . ($getID3Available ? 'yes' : 'no'));

            if ($type === 'file') {
                // Process single file
                $this->extractFileMetadata($absPath, $meta);
            } elseif ($type === 'dir') {
                // Process directory
                $this->extractDirectoryMetadata($absPath, $meta);
            }

            $directoryPath = $this->composeDirectoryPathForImport($meta);

            Log::debug("[ImportFile] Metadata extracted successfully: title={$meta['title']}");

            // Determine genre based on other books by the same author or series
            if (!empty($meta['author']) || !empty($meta['series'])) {
                $this->suggestGenreFromExistingBooks($meta);
            }

            // Determine the appropriate genre path based on existing genres
            $genrePath = $this->determineGenrePath($meta);
            if ($genrePath === null) {
                $defaultGenrePath = session('import_default_genre_path');
                if (is_string($defaultGenrePath) && $defaultGenrePath !== '') {
                    $genrePath = $defaultGenrePath;
                    Log::debug('[ImportFile] Using session default genre path', ['genrePath' => $genrePath]);
                } else {
                    Log::debug('[ImportFile] Genre could not be determined automatically; user input required');
                }
            } else {
                Log::debug("[ImportFile] Determined genre path: {$genrePath}");
            }

            $meta['needsGenreSelection'] = $genrePath === null;

            // Store source info for later use
            $meta['sourcePath'] = $absPath;
            $meta['sourceRoot'] = $root;
            $meta['sourceRelPath'] = $relPath;
            $meta['sourceType'] = $type;

            // If redirectToForm is true, redirect to the import form with prefilled data
            if ($redirectToForm) {
                $formData = $this->prepareFormDataForRedirect($meta, $directoryPath, $genrePath);

                return redirect()->route('admin.books.create', $formData);
            }

            // Prepare form data if requested for JSON response
            $formData = $this->prepareFormData($meta);

            // Sanitize all string values in the response data to prevent UTF-8 encoding issues
            $responseData = array_merge([
                'success' => true,
                'directoryPath' => $directoryPath,
                'genrePath' => $genrePath,
                'sourcePath' => $absPath,
                'relPath' => $relPath,
                'root' => $root,
                'editable' => true, // Allow editing of metadata before moving
                'formData' => $formData,
                'needsGenreSelection' => $meta['needsGenreSelection'],
            ], $meta);

            $sanitizedData = $this->sanitizeArrayRecursive($responseData);

            return response()->json($sanitizedData);
        } catch (\Exception $e) {
            Log::error('[ImportFile] Metadata extraction failed: ' . $e->getMessage() . ' at line ' . $e->getLine());
            Log::error('[ImportFile] Stack trace: ' . $e->getTraceAsString());

            $userMessage = 'Metadata extraction error';
            $details = $e->getMessage();

            // Check for specific error types to provide better user messages
            if (strpos($e->getMessage(), 'getID3') !== false) {
                $userMessage = 'Audio metadata library error';
                $details = 'The audio metadata extraction library encountered an error. '
                    . 'Basic file information will still be available.';
            } elseif (strpos($e->getMessage(), 'permission') !== false) {
                $userMessage = 'Permission denied';
                $details = 'The system does not have permission to read the selected file or directory.';
            }

            if ($request->input('redirectToForm', false)) {
                return redirect()->back()->with('error', $userMessage . ': ' . $details);
            }

            return response()->json([
                'success' => false,
                'message' => $userMessage,
                'details' => $details,
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Extract metadata from a file or directory using AI enhancement and redirect to import form.
     */
    public function extractWithAI(Request $request)
    {
        try {
            $root = $request->input('root');
            $relPath = $request->input('path');
            $type = $request->input('type');
            $aiModel = $request->input('aiModel', 'gemini-2.5-flash-lite');
            $redirectToForm = $request->input('redirectToForm', false);

            Log::debug("[ImportFile] AI extraction: root={$root}, path={$relPath}, type={$type}, model={$aiModel}");

            // First, do the regular extraction
            $regularExtractionRequest = new Request([
                'root' => $root,
                'path' => $relPath,
                'type' => $type,
                'redirectToForm' => false,
            ]);

            $regularResponse = $this->extract($regularExtractionRequest);
            $regularData = $regularResponse->getData(true);

            if (!$regularData['success']) {
                return $regularResponse; // Return the error from regular extraction
            }

            // Check if AI processing is available for the selected model
            $provider = $this->determineAIProvider($aiModel);
            $apiKey = $this->getAPIKey($provider);

            if (empty($apiKey)) {
                Log::warning("[ImportFile] AI model {$aiModel} requested but API key not configured");
                // Fall back to regular extraction with a warning
                $regularData['aiWarning'] = "AI enhancement unavailable: {$provider} API key not configured. Using basic extraction.";
                return response()->json($regularData);
            }

            // Enhance with AI processing
            try {
                $aiProcessor = new AIBookProcessor($aiModel, true); // Use paid tier for better results

                // Prepare data for AI processing
                $directoryPath = $regularData['directoryPath'];
                $audioFiles = [];
                $fileTags = [];

                // Extract audio file information
                if (isset($regularData['files']) && is_array($regularData['files'])) {
                    foreach ($regularData['files'] as $file) {
                        $audioFiles[] = $file['name'];

                        // Extract tags from the first few files
                        if (count($fileTags) < 3 && isset($file['path'])) {
                            $tags = $aiProcessor->extractFileTags($file['path']);
                            if (!empty($tags)) {
                                $fileTags[basename($file['path'])] = $tags;
                            }
                        }
                    }
                }

                // Process with AI
                $aiMetadata = $aiProcessor->processBookDirectory($directoryPath, $audioFiles, $fileTags);

                // Merge AI results with regular extraction, preferring AI when available and confident
                if ($aiMetadata['confidence'] >= 70) {
                    $enhancedData = $this->mergeAIWithRegularExtraction($regularData, $aiMetadata);
                    $enhancedData['aiEnhanced'] = true;
                    $enhancedData['aiConfidence'] = $aiMetadata['confidence'];
                    $enhancedData['aiModel'] = $aiModel;

                    Log::info("[ImportFile] AI enhancement successful", [
                        'model' => $aiModel,
                        'confidence' => $aiMetadata['confidence'],
                        'title' => $aiMetadata['title'] ?? 'N/A'
                    ]);

                    // If redirectToForm is true, redirect to the import form with AI-enhanced data
                    if ($redirectToForm) {
                        $formData = $this->prepareFormDataForRedirect($enhancedData, $enhancedData['directoryPath'], $enhancedData['genrePath']);
                        return redirect()->route('admin.books.create', $formData);
                    }

                    return response()->json($enhancedData);
                } else {
                    // Low confidence AI result, use regular extraction but include AI suggestions
                    $regularData['aiSuggestions'] = $aiMetadata;
                    $regularData['aiModel'] = $aiModel;
                    $regularData['aiWarning'] = "AI confidence too low ({$aiMetadata['confidence']}%). Using basic extraction with AI suggestions for review.";

                    Log::info("[ImportFile] AI enhancement low confidence", [
                        'model' => $aiModel,
                        'confidence' => $aiMetadata['confidence'],
                    ]);
                }
            } catch (\Exception $e) {
                Log::error("[ImportFile] AI processing failed: " . $e->getMessage());
                $regularData['aiWarning'] = "AI processing failed: " . $e->getMessage() . ". Using basic extraction.";
            }

            // If redirectToForm is true and we're using regular data, redirect with that
            if ($redirectToForm) {
                $formData = $this->prepareFormDataForRedirect($regularData, $regularData['directoryPath'], $regularData['genrePath']);
                return redirect()->route('admin.books.create', $formData);
            }

            return response()->json($regularData);
        } catch (\Exception $e) {
            Log::error('[ImportFile] AI-enhanced extraction failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'AI-enhanced extraction failed',
                'details' => $e->getMessage(),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Determine AI provider from model name
     */
    private function determineAIProvider(string $model): string
    {
        if (str_starts_with($model, 'gemini-')) {
            return 'gemini';
        } elseif (str_starts_with($model, 'claude-')) {
            return 'claude';
        } elseif (str_starts_with($model, 'gpt-')) {
            return 'openai';
        }

        return 'gemini'; // Default fallback
    }

    /**
     * Get API key for the specified provider
     */
    private function getAPIKey(string $provider): ?string
    {
        switch ($provider) {
            case 'gemini':
                return config('services.gemini.api_key');
            case 'claude':
                return config('services.claude.api_key');
            case 'openai':
                return config('services.openai.api_key');
            default:
                return null;
        }
    }

    /**
     * Merge AI metadata with regular extraction results
     */
    private function mergeAIWithRegularExtraction(array $regularData, array $aiMetadata): array
    {
        $merged = $regularData;

        // Prefer AI results for core metadata when available and confident
        if (!empty($aiMetadata['title'])) {
            $merged['title'] = $aiMetadata['title'];
        }

        if (!empty($aiMetadata['author']) && is_array($aiMetadata['author'])) {
            $merged['author'] = implode(', ', $aiMetadata['author']);
        }

        if (!empty($aiMetadata['narrator']) && is_array($aiMetadata['narrator'])) {
            $merged['narrator'] = implode(', ', $aiMetadata['narrator']);
        }

        if (!empty($aiMetadata['series'])) {
            $merged['series'] = $aiMetadata['series'];
            $merged['seriesName'] = $aiMetadata['series'];
        }

        if (!empty($aiMetadata['series_number'])) {
            $merged['seriesNumber'] = $aiMetadata['series_number'];
        }

        if (!empty($aiMetadata['genre']) && is_array($aiMetadata['genre'])) {
            $merged['genre'] = implode(', ', $aiMetadata['genre']);
        }

        if (!empty($aiMetadata['year'])) {
            $merged['year'] = $aiMetadata['year'];
            $merged['publishedYear'] = $aiMetadata['year'];
        }

        if (!empty($aiMetadata['description'])) {
            $merged['description'] = $aiMetadata['description'];
        }

        if (!empty($aiMetadata['publisher'])) {
            $merged['publisher'] = $aiMetadata['publisher'];
        }

        if (!empty($aiMetadata['language'])) {
            $merged['language'] = $aiMetadata['language'];
        }

        if (!empty($aiMetadata['isbn'])) {
            $merged['isbn'] = $aiMetadata['isbn'];
        }

        return $merged;
    }


    /**
     * Compose a directory path from genre, author, series, seriesNumber, and title (skipping empty values).
     *
     * @return string
     */
    protected function composeDirectoryPath(array $meta)
    {
        $parts = [];
        foreach (['genre', 'author', 'series', 'seriesNumber', 'title'] as $key) {
            if (!empty($meta[$key])) {
                $parts[] = preg_replace('~[\\\\/]~', '-', trim($meta[$key]));
            }
        }

        return implode(DIRECTORY_SEPARATOR, $parts);
    }


    /**
     * Compose a directory path for the imported files based on metadata.
     */
    private function composeDirectoryPathForImport(array $meta): string
    {
        // Get the appropriate genre path first
        $genrePath = $this->determineGenrePath($meta);

        $parts = [];
        // Add the genre as the first part of the path
        if ($genrePath !== null) {
            $parts[] = $genrePath;
        }

        if ($meta['author']) {
            // Normalize author for directory (no spaces between initials)
            $parts[] = $this->bookImportService->normalizeAuthorNameForDirectory($meta['author']);
        }
        if ($meta['seriesName'] ?? $meta['series'] ?? null) {
            $parts[] = $meta['seriesName'] ?? $meta['series'];
        }
        if ($meta['title']) {
            $parts[] = $meta['title'];
        }

        $path = implode('/', array_filter($parts)) ?: 'Unknown';

        Log::info('[ImportFile] Composed directory path', [
            'path' => $path,
            'genre' => $genrePath,
            'author' => $meta['author'] ?? null,
            'series' => $meta['seriesName'] ?? $meta['series'] ?? null,
            'title' => $meta['title'] ?? null,
        ]);

        return $path;
    }


    /**
     * Determine the appropriate genre path based on existing genres and author/series data.
     *
     * Logic:
     * 1. If the genre matches an existing one, use that
     * 2. Look in all genres for author directories and if one is found that has a subdirectory
     *    that matches the series, use that genre
     * 3. If the author directory is found in multiple genres, use the one with the most books
     * 4. If no other rules match and the author is in a genre, use that genre
     * 5. Use the "Other" genre as fallback
     *
     * @param  array  $meta  Metadata containing author, series, and genre information
     * @return string The appropriate genre path to use
     */
    private function determineGenrePath(array $meta): ?string
    {
        $libraryRoot = rtrim((string) config('app.book_root', ''), '/');
        if ($libraryRoot === '') {
            $libraryRoot = rtrim((string) (config('filesystems.disks.books.root') ?? config('app.book_root')), '/');
        }
        if (!is_dir($libraryRoot)) {
            Log::warning('[ImportFile] Library root directory not found', [
                'path' => $libraryRoot,
            ]);

            return null;
        }

        // Get all existing genre directories
        $existingGenres = [];
        foreach (new \DirectoryIterator($libraryRoot) as $fileInfo) {
            if ($fileInfo->isDir() && !$fileInfo->isDot()) {
                $existingGenres[] = $fileInfo->getFilename();
            }
        }

        // Extract metadata
        $genre = $meta['genre'] ?? null;
        $author = $meta['author'] ?? null;
        $series = $meta['seriesName'] ?? $meta['series'] ?? null;

        // Rule 1: If the genre matches an existing one, use that
        if ($genre && in_array($genre, $existingGenres)) {
            Log::info('[ImportFile] Using exact genre match', [
                'genre' => $genre,
            ]);

            return $genre;
        }

        // If no author, we can't apply the other rules
        if (!$author) {
            Log::info('[ImportFile] No author provided, using default genre', [
                'reason' => 'missing_author',
            ]);

            return null;
        }

        // Track genre matches and book counts
        $genreMatches = [];
        $genreBookCounts = [];

        // Scan all genres for author and series matches
        foreach ($existingGenres as $existingGenre) {
            $genrePath = $libraryRoot . DIRECTORY_SEPARATOR . $existingGenre;
            $authorPath = $genrePath . DIRECTORY_SEPARATOR . $author;

            // Check if this genre contains the author
            if (is_dir($authorPath)) {
                // Count books in this genre for this author
                $bookCount = 0;
                $seriesFound = false;

                // Check all subdirectories in the author directory
                foreach (new \DirectoryIterator($authorPath) as $subDir) {
                    if ($subDir->isDir() && !$subDir->isDot()) {
                        // Rule 2: If we find a directory matching the series, use this genre
                        if ($series && $subDir->getFilename() === $series) {
                            $seriesFound = true;
                        }

                        // Count books or series directories
                        $bookCount++;
                    }
                }

                // If series was found, this is a strong match
                if ($seriesFound) {
                    $genreMatches[$existingGenre] = 2; // Higher priority
                } else {
                    $genreMatches[$existingGenre] = 1; // Lower priority
                }

                // Store book count for Rule 3
                $genreBookCounts[$existingGenre] = $bookCount;
            }
        }

        // If we found any matches
        if (!empty($genreMatches)) {
            // Sort by match strength (series match > author match)
            arsort($genreMatches);
            $maxScore = max($genreMatches);
            $topGenres = array_keys(array_filter($genreMatches, function ($score) use ($maxScore) {
                return $score === $maxScore;
            }));

            // If we have multiple genres with the same match strength
            if (count($topGenres) > 1) {
                // Rule 3: Use the one with the most books
                $mostBooks = 0;
                $bestGenre = $topGenres[0];

                foreach ($topGenres as $genre) {
                    if ($genreBookCounts[$genre] > $mostBooks) {
                        $mostBooks = $genreBookCounts[$genre];
                        $bestGenre = $genre;
                    }
                }

                Log::info('[ImportFile] Selected genre based on book count', [
                    'author' => $author,
                    'series' => $series,
                    'selectedGenre' => $bestGenre,
                    'bookCount' => $mostBooks,
                    'matchingGenres' => $topGenres,
                ]);

                return $bestGenre;
            } else {
                // Rule 4: Use the matched genre
                $selectedGenre = reset($topGenres);

                Log::info('[ImportFile] Selected genre based on author/series match', [
                    'author' => $author,
                    'series' => $series,
                    'selectedGenre' => $selectedGenre,
                    'matchStrength' => $genreMatches[$selectedGenre],
                ]);

                return $selectedGenre;
            }
        }

        // Rule 5: Fallback to default
        Log::info('[ImportFile] No matching genres found, using default', [
            'author' => $author,
            'series' => $series,
            'reason' => 'no_matches',
        ]);

        return null;
    }


    /**
     * Extract metadata from a single audio file.
     */
    private function extractFileMetadata(string $filePath, array &$meta): void
    {
        if (class_exists('getID3')) {
            try {
                $info = $this->withHandlerIsolation(fn () => (new \getID3())->analyze($filePath));

                Log::debug("[ImportFile] Analyzing file: {$filePath}");

                // Extract and normalize tags from different sources
                $tags = $this->normalizeAudioTags($info);

                // Log the normalized tags for debugging
                // Log::debug('[ImportFile] Normalized audio tags: ', $tags);

                // Extract title
                if (empty($meta['title'])) {
                    if (!empty($tags['title']) && !$this->isGenericFilenameTitle($tags['title'])) {
                        $meta['title'] = $tags['title'];
                    }

                    // If title contains book/series info in parentheses, extract it
                    if (!empty($meta['title']) && preg_match('/^(.*?)(?:\s*[:\-]*\s*(.*))?$/', $meta['title'], $matches)) {
                        $meta['title'] = trim($matches[1]);
                        if (!empty($matches[2])) {
                            $meta['subtitle'] = trim($matches[2]);
                        }
                    }

                    // Check for "Book X" pattern in title
                    if (!empty($meta['title']) && preg_match('/^(.*?)(?:,\s*Book\s*(\d+))?$/', $meta['title'], $matches)) {
                        $meta['title'] = trim($matches[1]);
                        if (!empty($matches[2])) {
                            $meta['seriesNumber'] = trim($matches[2]);
                        }
                    }
                }

                // Extract author
                if (empty($meta['author'])) {
                    $meta['author'] = $tags['artist'] ?? null;

                    // Handle author with series in parentheses: "Author Name (Series Name)"
                    if (!empty($meta['author']) && preg_match('/^(.*?)\s*\(([^)]+)\)$/', $meta['author'], $matches)) {
                        $meta['author'] = trim($matches[1]);

                        // If we don't already have series info, use this
                        if (empty($meta['seriesName'])) {
                            $meta['seriesName'] = trim($matches[2]);
                        }
                    }
                }

                // Extract series information
                if (empty($meta['seriesName']) && !empty($tags['grouping'])) {
                    $meta['seriesName'] = $tags['grouping'];
                }

                // Extract album/series info
                if (!empty($tags['album'])) {
                    // Check for series number in album: "00.1 The Mad Lancers" or "Book 01 - Series Name"
                    if (preg_match('/^(?:Book\s*)?(\d+(?:\.\d+)?)\s*(?:-\s*)?(.*)$/', $tags['album'], $matches)) {
                        // Only set series number if not already set
                        if (empty($meta['seriesNumber'])) {
                            $meta['seriesNumber'] = trim($matches[1]);
                        }

                        // Only set series name if not already set
                        if (empty($meta['seriesName'])) {
                            $meta['seriesName'] = trim($matches[2]);
                        }
                    } elseif (empty($meta['seriesName'])) {
                        $meta['seriesName'] = $tags['album'];
                    }
                }

                // Extract other metadata
                $meta['genre'] = $tags['genre'] ?? null;

                // Extract narrator from multiple possible fields
                $meta['narrator'] = $tags['composer'] ?? $tags['narrator'] ?? $tags['performer'] ?? null;

                $meta['year'] = $tags['year'] ?? null;

                // Extract description if available
                if (!empty($tags['description'])) {
                    $meta['description'] = $tags['description'];
                } elseif (!empty($tags['comment']) && strlen($tags['comment']) > 20) {
                    $meta['description'] = $tags['comment'];
                }

                // Extract cover image if available
                if (!empty($tags['picture'])) {
                    $meta['coverImage'] = $tags['picture'];
                    Log::debug('[ImportFile] Cover image extracted from audio file');
                }

                // Extract chapters if available
                if (!empty($info['quicktime']['chapters'])) {
                    $meta['chapters'] = [];
                    foreach ($info['quicktime']['chapters'] as $chapter) {
                        $meta['chapters'][] = [
                            'title' => $chapter['title'],
                            'start' => $chapter['timestamp'],
                            'duration' => isset($chapter['duration_seconds']) ? $chapter['duration_seconds'] : null,
                        ];
                    }
                }

                // Add file to the list
                $meta['files'][] = [
                    'path' => $filePath,
                    'name' => basename($filePath),
                    'size' => filesize($filePath),
                    'extension' => pathinfo($filePath, PATHINFO_EXTENSION),
                ];
            } catch (\Exception $e) {
                Log::warning("[ImportFile] getID3 analysis failed for {$filePath}: " . $e->getMessage());
                // Fall back to basic metadata extraction
                $base = pathinfo($filePath, PATHINFO_FILENAME);
                $meta['title'] = $base;
                $meta['files'][] = [
                    'path' => $filePath,
                    'name' => basename($filePath),
                    'size' => filesize($filePath),
                    'extension' => pathinfo($filePath, PATHINFO_EXTENSION),
                ];
            }
        } else {
            Log::info('[ImportFile] getID3 library not available, using basic metadata extraction');
            $base = pathinfo($filePath, PATHINFO_FILENAME);
            $meta['title'] = $base;

            // Add file to the list
            $meta['files'][] = [
                'path' => $filePath,
                'name' => basename($filePath),
                'size' => filesize($filePath),
                'extension' => pathinfo($filePath, PATHINFO_EXTENSION),
            ];
        }
    }


    /**
     * Sanitize a string to ensure it's valid UTF-8.
     *
     * @param  mixed  $value  The string to sanitize
     * @return string Sanitized string
     */
    private function sanitizeString($value)
    {
        // Ensure the string is valid UTF-8
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        // Trim whitespace
        return trim($value);
    }

    /**
     * Check if a title is a generic filename (chapter, part, section, etc.)
     */
    private function isGenericFilenameTitle(string $title): bool
    {
        $genericPatterns = [
            '/^chapter$/i',
            '/^part$/i',
            '/^section$/i',
            '/^\d+$/i',
            '/^track$/i',
            '/^cd$/i',
        ];

        foreach ($genericPatterns as $pattern) {
            if (preg_match($pattern, $title)) {
                return true;
            }
        }

        return false;
    }

    /**
      * Recursively sanitize all string values in an array to ensure valid UTF-8 encoding.
     *
     * @param  mixed  $data  The data to sanitize (array or scalar value)
     * @return mixed Sanitized data with the same structure
     */
    private function sanitizeArrayRecursive($data)
    {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                // Sanitize the key if it's a string
                $sanitizedKey = is_string($key) ? $this->sanitizeString($key) : $key;

                // Recursively sanitize the value
                $result[$sanitizedKey] = $this->sanitizeArrayRecursive($value);
            }

            return $result;
        } elseif (is_string($data)) {
            return $this->sanitizeString($data);
        } else {
            // For non-string scalar values or objects, return as is
            return $data;
        }
    }


    /**
     * Normalize audio tags from different sources (ID3v1, ID3v2, QuickTime) into a unified format.
     * Handles encoding issues by sanitizing all string values.
     *
     * @param  array  $info  The getID3 analysis result
     * @return array Normalized tags
     */
    private function normalizeAudioTags(array $info): array
    {
        $tags = [];

        // Process QuickTime tags (m4a/m4b files)
        if (!empty($info['tags']['quicktime'])) {
            $qt = $info['tags']['quicktime'];

            // Title - could be a string or array
            if (!empty($qt['title'])) {
                if (is_array($qt['title'])) {
                    // If it's an array of chapter titles, use the first non-chapter title
                    foreach ($qt['title'] as $title) {
                        if (!preg_match('/^(Chapter|Part|Section|\d+)\s*\d*$/i', $title)) {
                            $tags['title'] = $title;
                            break;
                        }
                    }
                    // If we didn't find a good title, don't use filename as title (let directory name take precedence)
                } else {
                    $tags['title'] = $this->sanitizeString($qt['title']);
                }
            }

            // Artist
            if (!empty($qt['artist'])) {
                $tags['artist'] = $this->sanitizeString(is_array($qt['artist']) ? $qt['artist'][0] : $qt['artist']);
            } elseif (!empty($qt['album_artist'])) {
                $tags['artist'] = $this->sanitizeString(is_array($qt['album_artist']) ? $qt['album_artist'][0] : $qt['album_artist']);
            }

            // Album
            if (!empty($qt['album'])) {
                $tags['album'] = $this->sanitizeString(is_array($qt['album']) ? $qt['album'][0] : $qt['album']);
            }

            // Genre
            if (!empty($qt['genre'])) {
                $tags['genre'] = $this->sanitizeString(is_array($qt['genre']) ? $qt['genre'][0] : $qt['genre']);
            }

            // Composer (often used for narrator in audiobooks)
            if (!empty($qt['composer'])) {
                $tags['composer'] = $this->sanitizeString(is_array($qt['composer']) ? $qt['composer'][0] : $qt['composer']);
            }

            // Narrator field (explicit narrator tag)
            if (!empty($qt['narrator'])) {
                $tags['narrator'] = $this->sanitizeString(is_array($qt['narrator']) ? $qt['narrator'][0] : $qt['narrator']);
            }

            // Performer field (sometimes used for narrator)
            if (!empty($qt['performer'])) {
                $tags['performer'] = $this->sanitizeString(is_array($qt['performer']) ? $qt['performer'][0] : $qt['performer']);
            }

            // Year/date
            if (!empty($qt['creation_date'])) {
                $tags['year'] = $this->sanitizeString(is_array($qt['creation_date']) ? $qt['creation_date'][0] : $qt['creation_date']);
            }

            // Description
            if (!empty($qt['description'])) {
                $tags['description'] = $this->sanitizeString(is_array($qt['description']) ? $qt['description'][0] : $qt['description']);
            }

            // Comment
            if (!empty($qt['comment'])) {
                $tags['comment'] = $this->sanitizeString(is_array($qt['comment']) ? $qt['comment'][0] : $qt['comment']);
            }

            // Grouping (often used for series name)
            if (!empty($qt['grouping'])) {
                $tags['grouping'] = $this->sanitizeString(is_array($qt['grouping']) ? $qt['grouping'][0] : $qt['grouping']);
            }

            // Extract cover image from QuickTime tags if available
            if (!empty($info['comments']['picture'][0])) {
                $tags['picture'] = [
                    'data' => $info['comments']['picture'][0]['data'],
                    'mime' => $info['comments']['picture'][0]['image_mime'],
                    'type' => 'front_cover',
                ];
            }
        }

        // Process ID3v2 tags (mp3 files)
        if (!empty($info['tags']['id3v2'])) {
            $id3 = $info['tags']['id3v2'];

            // Only set these if not already set from QuickTime tags
            if (empty($tags['title']) && !empty($id3['title'][0])) {
                $tags['title'] = $this->sanitizeString($id3['title'][0]);
            }

            if (empty($tags['artist']) && !empty($id3['artist'][0])) {
                $tags['artist'] = $this->sanitizeString($id3['artist'][0]);
            }

            if (empty($tags['album']) && !empty($id3['album'][0])) {
                $tags['album'] = $this->sanitizeString($id3['album'][0]);
            }

            if (empty($tags['genre']) && !empty($id3['genre'][0])) {
                $tags['genre'] = $this->sanitizeString($id3['genre'][0]);
            }

            if (empty($tags['composer']) && !empty($id3['composer'][0])) {
                $tags['composer'] = $this->sanitizeString($id3['composer'][0]);
            }

            if (empty($tags['narrator']) && !empty($id3['narrator'][0])) {
                $tags['narrator'] = $this->sanitizeString($id3['narrator'][0]);
            }

            if (empty($tags['performer']) && !empty($id3['performer'][0])) {
                $tags['performer'] = $this->sanitizeString($id3['performer'][0]);
            }

            if (empty($tags['year']) && !empty($id3['year'][0])) {
                $tags['year'] = $this->sanitizeString($id3['year'][0]);
            }

            if (empty($tags['comment']) && !empty($id3['comment'][0])) {
                $tags['comment'] = $this->sanitizeString($id3['comment'][0]);
            }

            // Extract picture/cover image from ID3v2 tags
            if (!empty($info['id3v2']['APIC'])) {
                // There might be multiple pictures, try to find the front cover
                foreach ($info['id3v2']['APIC'] as $pic) {
                    if (isset($pic['picturetypeid']) && $pic['picturetypeid'] == 3) { // 3 = front cover
                        $tags['picture'] = [
                            'data' => $pic['data'],
                            'mime' => $pic['mime'],
                            'type' => 'front_cover',
                        ];
                        break;
                    }
                }

                // If no front cover was found, use the first picture
                if (empty($tags['picture']) && !empty($info['id3v2']['APIC'][0]['data'])) {
                    $pic = $info['id3v2']['APIC'][0];
                    $tags['picture'] = [
                        'data' => $pic['data'],
                        'mime' => $pic['mime'] ?? 'image/jpeg',
                        'type' => 'unknown',
                    ];
                }
            }
        }

        // Process ID3v1 tags as fallback
        if (!empty($info['tags']['id3v1'])) {
            $id3 = $info['tags']['id3v1'];

            // Only set these if not already set from other tag sources
            if (empty($tags['title']) && !empty($id3['title'][0])) {
                $tags['title'] = $this->sanitizeString($id3['title'][0]);
            }

            if (empty($tags['artist']) && !empty($id3['artist'][0])) {
                $tags['artist'] = $this->sanitizeString($id3['artist'][0]);
            }

            if (empty($tags['album']) && !empty($id3['album'][0])) {
                $tags['album'] = $this->sanitizeString($id3['album'][0]);
            }

            if (empty($tags['genre']) && !empty($id3['genre'][0])) {
                $tags['genre'] = $this->sanitizeString($id3['genre'][0]);
            }

            if (empty($tags['year']) && !empty($id3['year'][0])) {
                $tags['year'] = $this->sanitizeString($id3['year'][0]);
            }

            if (empty($tags['comment']) && !empty($id3['comment'][0])) {
                $tags['comment'] = $this->sanitizeString($id3['comment'][0]);
            }
        }

        // Use filename as fallback for title
        if (empty($tags['title']) && !empty($info['filename'])) {
            $filename = pathinfo($info['filename'], PATHINFO_FILENAME);
            // Remove leading numbers and separators often found in filenames
            $tags['title'] = $this->sanitizeString(preg_replace('/^\d+[\s\._\-]+/', '', $filename));
        }

        return $tags;
    }


    /**
     * Extract metadata from a directory containing audio files.
     */
    private function extractDirectoryMetadata(string $dirPath, array &$meta): void
    {
        // Set basic metadata from directory name
        $base = basename($dirPath);
        $meta['title'] = $base;

        Log::debug("[ImportFile] Analyzing directory: {$dirPath}");

        // Try to extract metadata from directory name patterns
        $this->extractMetadataFromDirectoryName($base, $meta);

        // Get all audio files in the directory
        $allowedExtensions = Config::get('import.allowed_extensions', []);
        $files = collect(File::files($dirPath))
            ->filter(function ($file) use ($allowedExtensions) {
                return in_array(strtolower($file->getExtension()), $allowedExtensions);
            });

        Log::debug('[ImportFile] Found ' . count($files) . ' audio files in directory');

        // If we have audio files, try to extract metadata from the first one
        if ($files->count() > 0) {
            $firstFile = $files->first()->getPathname();

            // Store directory-parsed metadata to preserve it
            $directoryGenre = $meta['genre'] ?? null;
            $directoryTitle = $meta['title'] ?? null;
            $directoryAuthor = $meta['author'] ?? null;
            $directorySeries = $meta['series'] ?? null;
            $directorySeriesNumber = $meta['seriesNumber'] ?? null;

            $this->extractFileMetadata($firstFile, $meta);

            // Preserve directory-parsed metadata if it was successfully extracted
            if (!empty($directoryGenre)) {
                $meta['genre'] = $directoryGenre;
            }
            if (!empty($directoryTitle) && $directoryTitle !== $base) {
                $meta['title'] = $directoryTitle; // Use parsed title instead of full directory name
            }
            if (!empty($directoryAuthor)) {
                $meta['author'] = $directoryAuthor; // Prefer directory-parsed author
            }
            if (!empty($directorySeries)) {
                $meta['series'] = $directorySeries;
            }
            if (!empty($directorySeriesNumber)) {
                $meta['seriesNumber'] = $directorySeriesNumber;
            }

            // Add all files to the list
            $meta['files'] = [];
            foreach ($files as $file) {
                $filePath = $file->getPathname();
                $meta['files'][] = [
                    'path' => $filePath,
                    'name' => $file->getFilename(),
                    'size' => $file->getSize(),
                    'extension' => $file->getExtension(),
                ];
            }
        }
    }


    /**
     * Extract metadata from directory name patterns.
     */
    private function extractMetadataFromDirectoryName(string $dirName, array &$meta): void
    {
        // Common patterns for audiobook directory names:
        // Genre_Author_Name_Series_Book_Number_Title (e.g., Fantasy_Brandon_Sanderson_Mistborn_01_The_Final_Empire)
        // Author_Name_Series_Book_Number_Title
        // Author_Name_Title

        // Split by underscores and analyze parts
        $parts = explode('_', $dirName);

        if (count($parts) >= 3) {
            // Check if first part looks like a genre
            $commonGenres = ['Fantasy', 'Science', 'Fiction', 'Mystery', 'Romance', 'Thriller', 'Horror', 'Biography', 'History', 'Self', 'Business'];
            $firstPartIsGenre = in_array($parts[0], $commonGenres) || in_array(ucfirst(strtolower($parts[0])), $commonGenres);

            if ($firstPartIsGenre && count($parts) >= 4) {
                // Pattern: Genre_Author_Name_Series_Number_Title
                $meta['genre'] = $parts[0];

                // Find where the author name ends by looking for series indicators
                // For Fantasy_Brandon_Sanderson_Mistborn_01_The_Final_Empire:
                // parts[0]=Fantasy, parts[1]=Brandon, parts[2]=Sanderson, parts[3]=Mistborn, parts[4]=01, parts[5]=The, etc.
                $authorEndIndex = 1; // Default to just parts[1] (Brandon)

                for ($i = 2; $i < count($parts) - 1; $i++) {
                    // Check if next part looks like a series number - this means current part is series name
                    if ($i + 1 < count($parts) && (is_numeric($parts[$i + 1]) || preg_match('/^\d+/', $parts[$i + 1]))) {
                        $authorEndIndex = $i - 1; // Author ends before this series name part
                        break;
                    }
                    // Look for direct series indicators (numbers, common series words)
                    if (
                        is_numeric($parts[$i]) || preg_match('/^\d+/', $parts[$i]) ||
                        in_array(ucfirst(strtolower($parts[$i])), ['Book', 'Vol', 'Volume', 'Part'])
                    ) {
                        $authorEndIndex = $i - 1; // Author ends just before this part
                        break;
                    }
                }

                // If we didn't find clear series indicators, assume 2 parts for author name (common pattern)
                if ($authorEndIndex === 1 && count($parts) >= 6) {
                    $authorEndIndex = 2; // Include parts[1] and parts[2] for author
                }

                // Extract author (from parts[1] to parts[authorEndIndex] inclusive)
                // array_slice($parts, 1, $length) where length = authorEndIndex - 1 + 1 = authorEndIndex
                $authorLength = $authorEndIndex; // This is the number of parts to include starting from index 1
                $authorParts = array_slice($parts, 1, $authorLength);
                $meta['author'] = implode(' ', $authorParts);

                // Extract series and title from remaining parts
                $remainingParts = array_slice($parts, $authorEndIndex + 1);
                if (count($remainingParts) >= 3) {
                    // Check if we have a clear series pattern (series + number + title)
                    if (is_numeric($remainingParts[1]) || preg_match('/^\d+/', $remainingParts[1])) {
                        // Pattern: Series_Number_Title
                        $meta['series'] = $remainingParts[0];
                        $meta['seriesNumber'] = $remainingParts[1];
                        $meta['title'] = implode(' ', array_slice($remainingParts, 2));
                    } else {
                        // Check if first part looks like a series name (not an article)
                        $firstPart = $remainingParts[0];
                        $isArticle = in_array(strtolower($firstPart), ['the', 'a', 'an']);

                        if (!$isArticle && strlen($firstPart) > 2) {
                            // Likely series name
                            $meta['series'] = $firstPart;
                            $meta['title'] = implode(' ', array_slice($remainingParts, 1));
                        } else {
                            // Treat all as title (includes articles)
                            $meta['title'] = implode(' ', $remainingParts);
                        }
                    }
                } elseif (count($remainingParts) >= 2) {
                    // Check if first part looks like series vs title
                    $firstPart = $remainingParts[0];
                    $isArticle = in_array(strtolower($firstPart), ['the', 'a', 'an']);

                    if (!$isArticle && strlen($firstPart) > 2) {
                        // Likely series name
                        $meta['series'] = $firstPart;
                        $meta['title'] = implode(' ', array_slice($remainingParts, 1));
                    } else {
                        // Treat all as title
                        $meta['title'] = implode(' ', $remainingParts);
                    }
                } else {
                    // Just title
                    $meta['title'] = implode(' ', $remainingParts);
                }
            } elseif (count($parts) >= 4) {
                // Pattern: Author_Name_Series_Number_Title (no genre prefix)
                // Find where the author name ends
                $authorEndIndex = 1;
                for ($i = 2; $i < count($parts) - 1; $i++) {
                    if (is_numeric($parts[$i]) || preg_match('/^\d+/', $parts[$i])) {
                        $authorEndIndex = $i - 1;
                        break;
                    }
                }

                // If no clear series number found, assume 2 parts for author
                if ($authorEndIndex === 1 && count($parts) >= 5) {
                    $authorEndIndex = 2;
                }

                // Extract author
                $authorParts = array_slice($parts, 0, $authorEndIndex + 1);
                $meta['author'] = implode(' ', $authorParts);

                // Extract remaining parts
                $remainingParts = array_slice($parts, $authorEndIndex + 1);
                if (count($remainingParts) >= 2) {
                    if (is_numeric($remainingParts[0]) || preg_match('/^\d+/', $remainingParts[0])) {
                        // Has series number
                        $meta['seriesNumber'] = $remainingParts[0];
                        $meta['title'] = implode(' ', array_slice($remainingParts, 1));
                    } else {
                        $meta['series'] = $remainingParts[0];
                        if (count($remainingParts) >= 3 && (is_numeric($remainingParts[1]) || preg_match('/^\d+/', $remainingParts[1]))) {
                            $meta['seriesNumber'] = $remainingParts[1];
                            $meta['title'] = implode(' ', array_slice($remainingParts, 2));
                        } else {
                            $meta['title'] = implode(' ', array_slice($remainingParts, 1));
                        }
                    }
                } else {
                    $meta['title'] = implode(' ', $remainingParts);
                }
            } else {
                // Simple pattern: Author_Series_Title
                $meta['author'] = $parts[0];
                $meta['series'] = $parts[1];
                $meta['title'] = implode(' ', array_slice($parts, 2));
            }
        }

        Log::debug('[ImportFile] Extracted from directory name', [
            'dirName' => $dirName,
            'parts' => $parts,
            'firstPartIsGenre' => $firstPartIsGenre ?? false,
            'authorEndIndex' => $authorEndIndex ?? null,
            'author' => $meta['author'] ?? null,
            'series' => $meta['series'] ?? null,
            'genre' => $meta['genre'] ?? null,
            'title' => $meta['title'] ?? null,
            'seriesNumber' => $meta['seriesNumber'] ?? null,
        ]);
    }


    /**
     * Move imported files from source to final destination during book creation.
     *
     * @param  string  $sourcePath  Source file/directory path
     * @param  string  $sourceRoot  Source root directory
     * @param  string  $sourceRelPath  Source relative path
     * @param  string  $sourceType  Source type (file/dir)
     * @param  string  $destinationPath  Final destination path
     * @return bool Success status
     */
    public function moveImportedFiles(string $sourcePath, string $sourceRoot, string $sourceRelPath, string $sourceType, string $destinationPath): bool
    {
        try {
            // Get the book storage root
            $bookStorageRoot = rtrim((string) config('app.book_root', ''), '/');
            if ($bookStorageRoot === '') {
                $bookStorageRoot = rtrim((string) (config('filesystems.disks.books.root') ?? config('app.book_root')), '/');
            }
            if (!$bookStorageRoot || !is_dir($bookStorageRoot)) {
                Log::error('[ImportFile] Book storage path not found or invalid', [
                    'path' => $bookStorageRoot,
                ]);

                return false;
            }

            // Build full destination path
            $fullDestinationPath = $bookStorageRoot . '/' . trim($destinationPath, '/');

            // Create destination directory if it doesn't exist
            if (!is_dir($fullDestinationPath)) {
                $mkdirError = null;
                set_error_handler(function ($errno, $errstr) use (&$mkdirError) {
                    $mkdirError = $errstr;
                    return true;
                });
                $success = File::makeDirectory($fullDestinationPath, 0775, true);
                restore_error_handler();

                if (!$success && !is_dir($fullDestinationPath)) {
                    Log::error('[ImportFile] Failed to create destination directory', [
                        'path' => $fullDestinationPath,
                        'error' => $mkdirError ?? 'No PHP error captured',
                        'parent_exists' => is_dir(dirname($fullDestinationPath)),
                        'parent_writable' => is_writable(is_dir(dirname($fullDestinationPath)) ? dirname($fullDestinationPath) : '/'),
                    ]);

                    return false;
                }
            }

            // Verify source exists and is within allowed root
            if (!file_exists($sourcePath)) {
                Log::error('[ImportFile] Source path does not exist', [
                    'path' => $sourcePath,
                ]);

                return false;
            }

            $absSourceRoot = realpath($sourceRoot);
            $absSourcePath = realpath($sourcePath);

            if (!$absSourceRoot || !$absSourcePath || strpos($absSourcePath, $absSourceRoot) !== 0) {
                Log::error('[ImportFile] Source path security violation', [
                    'sourceRoot' => $absSourceRoot,
                    'sourcePath' => $absSourcePath,
                ]);

                return false;
            }

            // The fullDestinationPath is already the complete destination path where files should go
            // Do NOT append basename($sourcePath) as that would create a nested directory
            $targetPath = $fullDestinationPath;

            Log::debug('[ImportFile] Determining target path', [
                'sourcePath' => $sourcePath,
                'sourceName' => basename($sourcePath),
                'destinationPath' => $destinationPath,
                'fullDestinationPath' => $fullDestinationPath,
                'targetPath' => $targetPath,
                'sourceType' => $sourceType,
            ]);

            // Check if target already exists
            if (file_exists($targetPath)) {
                Log::warning('[ImportFile] Target already exists, skipping move', [
                    'target' => $targetPath,
                ]);

                return true; // Consider this success since files are already in place
            }

            // Move the files
            if ($sourceType === 'file') {
                // When importing a file, move it directly to the destination directory
                // without preserving the source directory structure
                $filename = basename($sourcePath);
                $targetPath = $fullDestinationPath . '/' . $filename;
                $success = File::move($sourcePath, $targetPath);
            } else {
                // Use moveDirectoryContentsToTarget instead of moveDirectory to avoid nested directories
                $this->moveDirectoryContentsToTarget($sourcePath, $fullDestinationPath);
                $success = true;
            }

            if ($success) {
                Log::info('[ImportFile] Successfully moved files', [
                    'from' => $sourcePath,
                    'to' => $targetPath,
                    'type' => $sourceType,
                ]);

                return true;
            } else {
                Log::error('[ImportFile] Failed to move files', [
                    'from' => $sourcePath,
                    'to' => $targetPath,
                    'type' => $sourceType,
                ]);

                return false;
            }
        } catch (\Exception $e) {
            Log::error('[ImportFile] Exception during file move', [
                'error' => $e->getMessage(),
                'sourcePath' => $sourcePath,
                'destinationPath' => $destinationPath,
            ]);

            return false;
        }
    }


    /**
     * Move selected files/directories to a target directory.
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function moveSelected(Request $request)
    {
        $root = $request->input('root');
        $relPath = $request->input('path');
        $type = $request->input('type');
        $directoryPath = $request->input('directoryPath', '');

        if (empty($directoryPath)) {
            $directoryPath = 'imports/' . date('Y-m-d');
        }

        $directoryPath = trim($directoryPath, '/');
        $directoryPath = str_replace('..', '', $directoryPath);

        $absRoot = realpath($root);
        if (!$absRoot || !is_dir($absRoot)) {
            return response()->json(['success' => false, 'message' => 'Invalid root'], 400);
        }

        $absPath = $absRoot . ($relPath ? DIRECTORY_SEPARATOR . $relPath : '');
        $absPath = realpath($absPath) ?: $absPath;

        if (strpos($absPath, $absRoot) !== 0) {
            return response()->json(['success' => false, 'message' => 'Path traversal detected'], 400);
        }

        if (!file_exists($absPath)) {
            return response()->json(['success' => false, 'message' => 'File or directory does not exist'], 404);
        }

        $destRoot = (string) config('app.book_root', '');
        if ($destRoot === '') {
            $destRoot = (string) (config('filesystems.disks.books.root') ?? config('app.book_root'));
        }
        if ($destRoot === '') {
            $destRoot = (string) Config::get('audiobooks.root', '');
        }
        if ($destRoot === '') {
            $destRoot = (string) Config::get('import.dest_root', '');
        }
        if ($destRoot === '') {
            $destRoot = storage_path('audiobooks');
        }

        $genrePath = $request->input('genrePath', $request->input('genre'));

        $directoryPathParts = explode('/', $directoryPath);
        $firstPart = reset($directoryPathParts);

        if (!empty($genrePath) && $firstPart !== $genrePath) {
            $destDir = $destRoot . DIRECTORY_SEPARATOR . $genrePath . DIRECTORY_SEPARATOR . $directoryPath;
        } else {
            $destDir = $destRoot . DIRECTORY_SEPARATOR . $directoryPath;
        }

        if (!file_exists($destDir)) {
            if (!File::makeDirectory($destDir, 0775, true)) {
                return response()->json(['success' => false, 'message' => 'Failed to create destination directory'], 500);
            }
        }

        if ($type === 'file') {
            $sourceDir = dirname($absPath);
            $targetFile = $destDir . DIRECTORY_SEPARATOR . basename($absPath);
            if (realpath($absPath) !== realpath($targetFile)) {
                File::move($absPath, $targetFile);
            }
        } else {
            $sourceDir = $absPath;
            $this->moveDirectoryContentsToTarget($sourceDir, $destDir);
        }

        $meta = $this->extract($request)->getData(true);

        // Check if the request is coming from within the import page
        $referer = $request->header('referer');
        $isInternalNavigation = $referer && (
            strpos($referer, '/admin/import/') !== false ||
            strpos($referer, '/admin/books/import-file') !== false
        );

        // Only include URL parameters if navigating within the import page
        if ($isInternalNavigation) {
            return redirect()->route('admin.books.create', $meta);
        } else {
            // When coming from main site, don't include URL parameters
            return redirect()->route('admin.books.create');
        }
    }

    protected function normalizeSourcePathForMoveSelected(string $sourcePath): string
    {
        $normalizedPath = rtrim($sourcePath, '/');

        while (File::isDirectory($normalizedPath)) {
            $files = File::files($normalizedPath);
            $dirs = File::directories($normalizedPath);

            if (count($files) > 0 || count($dirs) !== 1) {
                break;
            }

            $normalizedPath = rtrim($dirs[0], '/');
        }

        return $normalizedPath;
    }

    protected function moveDirectoryContentsToTarget(string $sourceDir, string $destDir): void
    {
        Log::debug('[ImportFile] moveDirectoryContentsToTarget called', [
            'sourceDir' => $sourceDir,
            'destDir' => $destDir,
        ]);

        if (!File::isDirectory($destDir)) {
            File::makeDirectory($destDir, 0775, true);
            Log::debug('[ImportFile] Created destination directory', ['destDir' => $destDir]);
        }

        $files = File::allFiles($sourceDir);
        Log::debug('[ImportFile] Found files to move', [
            'fileCount' => count($files),
            'files' => array_map(fn ($f) => $f->getPathname(), $files),
        ]);

        $normalizedSourceDir = rtrim($sourceDir, '/');
        foreach ($files as $file) {
            $sourceFilePath = $file->getPathname();
            $relativePath = Str::after($sourceFilePath, $normalizedSourceDir . '/');
            if ($relativePath === $sourceFilePath) {
                $relativePath = basename($sourceFilePath);
            }
            $relativePath = ltrim($relativePath, '/');
            if ($relativePath === '') {
                $relativePath = $file->getFilename();
            }
            $targetFile = rtrim($destDir, '/') . '/' . $relativePath;
            $targetDirPath = dirname($targetFile);
            if (!File::isDirectory($targetDirPath)) {
                File::makeDirectory($targetDirPath, 0775, true);
            }

            Log::debug('[ImportFile] Moving file', [
                'from' => $sourceFilePath,
                'to' => $targetFile,
                'filename' => $relativePath,
            ]);

            if (File::exists($targetFile)) {
                $pathInfo = pathinfo($targetFile);
                $counter = 1;
                while (true) {
                    $suffix = '_' . str_pad((string) $counter, 2, '0', STR_PAD_LEFT);
                    $candidate = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . $suffix;
                    if (!empty($pathInfo['extension'])) {
                        $candidate .= '.' . $pathInfo['extension'];
                    }
                    if (!File::exists($candidate)) {
                        $targetFile = $candidate;
                        break;
                    }
                    $counter++;
                }
                Log::debug('[ImportFile] File exists, using alternate name', ['targetFile' => $targetFile]);
            }

            File::move($sourceFilePath, $targetFile);
        }

        if (count(File::allFiles($sourceDir)) === 0) {
            File::deleteDirectory($sourceDir);
            Log::debug('[ImportFile] Deleted empty source directory', ['sourceDir' => $sourceDir]);
        }
    }


    /**
     * Suggest genre based on other books by the same author or in the same series.
     *
     * @param  array  &$meta  Metadata to update with genre suggestion
     */
    protected function suggestGenreFromExistingBooks(array &$meta): void
    {
        // Skip if genre is already set
        if (!empty($meta['genre'])) {
            return;
        }

        $author = $meta['author'] ?? null;
        $series = $meta['series'] ?? null;
        $genreCounts = [];

        try {
            // First try to find books in the same series if available
            if ($series) {
                $seriesBooks = $this->documentStoreService->searchSeriesByName($series);
                if (!empty($seriesBooks)) {
                    foreach ($seriesBooks as $book) {
                        if (!empty($book['genre'])) {
                            // Give series matches higher weight
                            $genreCounts[$book['genre']] = ($genreCounts[$book['genre']] ?? 0) + 2;
                        }
                    }
                }
            }

            // Then try to find books by the same author
            if ($author) {
                $authorBooks = $this->documentStoreService->searchAuthorsByName($author);
                if (!empty($authorBooks)) {
                    foreach ($authorBooks as $book) {
                        if (!empty($book['genre'])) {
                            $genreCounts[$book['genre']] = ($genreCounts[$book['genre']] ?? 0) + 1;
                        }
                    }
                }
            }

            // Determine the most common genre
            if (!empty($genreCounts)) {
                arsort($genreCounts);
                $meta['genre'] = key($genreCounts);
                $meta['genreSuggested'] = true;
                Log::info('[ImportFile] Suggested genre based on author/series', [
                    'author' => $author,
                    'series' => $series,
                    'suggestedGenre' => $meta['genre'],
                ]);
            }
        } catch (\Exception $e) {
            Log::error('[ImportFile] Error suggesting genre', [
                'error' => $e->getMessage(),
                'author' => $author,
                'series' => $series,
            ]);
        }
    }


    /**
     * Prepare data for the book form.
     *
     * @param  array  $meta  Extracted metadata
     * @return array Form data
     */
    protected function prepareFormData(array $meta): array
    {
        $formData = [
            'title' => $meta['title'] ?? '',
            'author' => $meta['author'] ?? '',
            'narrator' => $meta['narrator'] ?? '',
            'series' => $meta['series'] ?? '',
            'seriesNumber' => $meta['seriesNumber'] ?? '',
            'genre' => $meta['genre'] ?? '',
            'description' => $meta['description'] ?? '',
            'publishedYear' => $meta['publishedYear'] ?? '',
            'duration' => $meta['duration'] ?? '',
            'language' => $meta['language'] ?? 'en',
            'isbn' => $meta['isbn'] ?? '',
            'asin' => $meta['asin'] ?? '',
            'coverImage' => $meta['coverImage'] ?? '',
            'tags' => $meta['tags'] ?? [],
            'files' => $meta['files'] ?? [],
        ];

        // Get available genres for dropdown
        try {
            $genres = $this->documentStoreService->listGenres();
            $formData['availableGenres'] = $genres;
        } catch (\Exception $e) {
            Log::error('[ImportFile] Error fetching genres', ['error' => $e->getMessage()]);
            $formData['availableGenres'] = [];
        }

        return $formData;
    }


    /**
     * Prepare data for redirecting to the book form with prefilled data.
     *
     * @param  array  $meta  Extracted metadata
     * @param  string  $directoryPath  Composed directory path
     * @param  string|null  $genrePath  Determined genre path
     * @return array Form data for URL parameters
     */
    protected function prepareFormDataForRedirect(array $meta, string $directoryPath, ?string $genrePath): array
    {
        $resolvedGenrePath = $genrePath ?? 'Other';

        $formData = [
            'title' => $meta['title'] ?? '',
            'description' => $meta['description'] ?? '',
            'publishedYear' => $meta['year'] ?? '',
            'language' => $meta['language'] ?? 'en',
            'isbn' => $meta['isbn'] ?? '',
            'asin' => $meta['asin'] ?? '',
            'directoryPath' => $directoryPath,
            'genrePath' => $resolvedGenrePath,
            'sourcePath' => $meta['sourcePath'] ?? '',
            'sourceRoot' => $meta['sourceRoot'] ?? '',
            'sourceRelPath' => $meta['sourceRelPath'] ?? '',
            'sourceType' => $meta['sourceType'] ?? '',
            'importMode' => true, // Flag to indicate this is from import
        ];

        // Store cover image data in session to avoid URL parameter size limits
        if (!empty($meta['coverImage'])) {
            session(['import_cover_image' => $meta['coverImage']]);
            $formData['hasCoverImage'] = true;
        }

        // Handle authors as array
        if (!empty($meta['author'])) {
            $authors = is_array($meta['author']) ? $meta['author'] : [$meta['author']];
            foreach ($authors as $index => $author) {
                // Normalize for DB (ensure spaces between initials)
                $normalizedAuthor = $this->bookImportService->normalizeAuthorName($author);
                $formData["author[{$index}]"] = trim($normalizedAuthor);
            }
        }

        // Handle narrators as array - ensure we have narrator data
        if (!empty($meta['narrator'])) {
            $narrators = is_array($meta['narrator']) ? $meta['narrator'] : [$meta['narrator']];
            foreach ($narrators as $index => $narrator) {
                $formData["narrator[{$index}]"] = trim($narrator);
            }
        }

        // Handle genres as array - prefer genrePath over generic audio metadata genres
        $audioGenre = $meta['genre'] ?? '';
        $intelligentGenre = $resolvedGenrePath;

        // Use genrePath if audio genre is generic/unhelpful, otherwise use audio metadata
        $genericAudioGenres = ['Audiobook', 'Audio', 'Spoken Word', 'Book', 'Literature', 'Other'];
        if (
            !empty($intelligentGenre) && $intelligentGenre !== 'Other' &&
            (empty($audioGenre) || in_array($audioGenre, $genericAudioGenres, true))
        ) {
            $genre = $intelligentGenre;
        } else {
            $genre = $audioGenre ?: $intelligentGenre;
        }

        if (!empty($genre)) {
            $genres = is_array($genre) ? $genre : [$genre];
            foreach ($genres as $index => $genreItem) {
                $formData["genre[{$index}]"] = trim($genreItem);
            }
        }

        // Handle series as array with objects - check both series and seriesName
        $seriesName = $meta['seriesName'] ?? $meta['series'] ?? null;
        if (!empty($seriesName)) {
            $seriesNumber = $meta['seriesNumber'] ?? '';
            $formData['series[0][name]'] = trim($seriesName);
            $formData['series[0][number]'] = trim($seriesNumber);
        }

        Log::debug('[ImportFile] Prepared form data for redirect', [
            'directoryPath' => $directoryPath,
            'genrePath' => $genrePath,
            'hasAuthor' => !empty($formData['author[0]']),
            'hasNarrator' => !empty($formData['narrator[0]']),
            'hasGenre' => !empty($formData['genre[0]']),
            'hasSeries' => !empty($formData['series[0][name]']),
        ]);

        return $formData;
    }
}
