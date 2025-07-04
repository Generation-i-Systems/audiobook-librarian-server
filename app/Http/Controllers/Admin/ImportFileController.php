<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ImportFileController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
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
                if (! is_dir($defaultRoot)) {
                    Log::info('[ImportFile] Creating default import directory: ' . $defaultRoot);
                    if (! File::makeDirectory($defaultRoot, 0775, true)) {
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
                            if (file_exists($path) && ! is_readable($path)) {
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
            if (! file_exists($root)) {
                Log::warning("[ImportFile] Root directory does not exist: {$root}");

                return response()->json([
                    'error' => 'Root directory does not exist',
                    'details' => "The directory '{$root}' could not be found on the server.",
                ], 404);
            }

            if (! is_dir($root)) {
                Log::warning("[ImportFile] Root path is not a directory: {$root}");

                return response()->json([
                    'error' => 'Invalid root path',
                    'details' => "The path '{$root}' exists but is not a directory.",
                ], 400);
            }

            if (! is_readable($root)) {
                Log::warning("[ImportFile] Root directory is not readable due to permissions: {$root}");
                $perms = substr(sprintf('%o', fileperms($root)), -4);

                return response()->json([
                    'error' => 'Permission denied',
                    'details' => "The directory '{$root}' exists but cannot be read due to insufficient permissions.",
                    'permissions' => $perms,
                    'user' => function_exists('posix_getpwuid') && function_exists('posix_geteuid') ?
                        (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown') :
                        (getenv('USER') ?: getenv('USERNAME') ?: 'unknown'),
                ], 403);
            }

            $absRoot = realpath($root);
            if (! $absRoot) {
                Log::warning("[ImportFile] Could not resolve real path for root: {$root}");

                return response()->json([
                    'error' => 'Path resolution failed',
                    'details' => "Could not resolve the real path for '{$root}'. " .
                    "This might be due to symlink issues or filesystem restrictions.",
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
            if (! file_exists($absPath)) {
                Log::warning("[ImportFile] Path does not exist: {$absPath}");

                return response()->json([
                    'error' => 'Path not found',
                    'details' => "The requested path '{$path}' does not exist in the selected root directory.",
                ], 404);
            }

            // Check if path is readable
            if (! is_readable($absPath)) {
                Log::warning("[ImportFile] Path is not readable due to permissions: {$absPath}");
                $perms = substr(sprintf('%o', fileperms($absPath)), -4);

                return response()->json([
                    'error' => 'Permission denied',
                    'details' => "The path '{$path}' exists but cannot be read due to insufficient permissions.",
                    'permissions' => $perms,
                    'user' => function_exists('posix_getpwuid') && function_exists('posix_geteuid') ?
                        (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown') :
                        (getenv('USER') ?: getenv('USERNAME') ?: 'unknown'),
                ], 403);
            }

            // Get directories
            $directories = collect(File::directories($absPath))
                ->map(function ($d) {
                    return [
                        'type' => 'dir',
                        'name' => basename($d),
                    ];
                });

            Log::debug('[ImportFile] Found ' . count($directories) . " directories in {$absPath}");

            // Get files with allowed extensions
            $allowedExtensions = Config::get('import.allowed_extensions', []);
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
     * Extract metadata from a file or directory for import.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function extract(Request $request)
    {
        try {
            $root = $request->input('root');
            $relPath = $request->input('path');
            $type = $request->input('type');
            $prefillForm = $request->input('prefillForm', false);

            $logMessage = "[ImportFile] Extracting metadata: root={$root}, path={$relPath}, ";
            $logMessage .= "type={$type}, prefillForm={$prefillForm}";
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
            if (! $absRoot || ! is_dir($absRoot)) {
                Log::warning('[ImportFile] Invalid root: ' . $root);

                return response()->json(['success' => false, 'message' => 'Invalid root'], 400);
            }

            $absPath = $absRoot . ($relPath ? DIRECTORY_SEPARATOR . $relPath : '');
            $absPath = realpath($absPath) ?: $absPath;

            if (strpos($absPath, $absRoot) !== 0) {
                Log::warning('[ImportFile] Path traversal detected: ' . $absPath);

                return response()->json(['success' => false, 'message' => 'Path traversal detected'], 400);
            }

            if (! file_exists($absPath)) {
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
            if (! empty($meta['author']) || ! empty($meta['series'])) {
                $this->suggestGenreFromExistingBooks($meta);
            }

            // Determine the appropriate genre path based on existing genres
            $genrePath = $this->determineGenrePath($meta);
            Log::debug("[ImportFile] Determined genre path: {$genrePath}");

            // Prepare form data if requested
            $formData = null;
            if ($prefillForm) {
                $formData = $this->prepareFormData($meta);
            }

            // Sanitize all string values in the response data to prevent UTF-8 encoding issues
            $responseData = array_merge([
                'success' => true,
                'directoryPath' => $directoryPath,
                'genrePath' => $genrePath,
                'sourcePath' => $absPath,
                'relPath' => $relPath,
                'root' => $root,
                'editable' => true, // Allow editing of metadata before moving
            ], $meta);

            if ($formData) {
                $responseData['formData'] = $formData;
            }

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

            return response()->json([
                'success' => false,
                'message' => $userMessage,
                'details' => $details,
                'error' => $e->getMessage(),
            ], 500);
        }
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
            if (! empty($meta[$key])) {
                $parts[] = preg_replace('/[\\\/]/', '-', trim($meta[$key]));
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
        $parts[] = $genrePath;

        if ($meta['author']) {
            $parts[] = $meta['author'];
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
            'title' => $meta['title'] ?? null
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
     * @param array $meta Metadata containing author, series, and genre information
     * @return string The appropriate genre path to use
     */
    private function determineGenrePath(array $meta): string
    {
        // Default fallback genre
        $defaultGenre = 'Other';

        // Get the library root directory from environment variable
        $libraryRoot = rtrim(env('BOOK_STORAGE_PATH'), '/');
        if (!is_dir($libraryRoot)) {
            Log::warning('[ImportFile] Library root directory not found', [
                'path' => $libraryRoot
            ]);
            return $defaultGenre;
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
                'genre' => $genre
            ]);
            return $genre;
        }

        // If no author, we can't apply the other rules
        if (!$author) {
            Log::info('[ImportFile] No author provided, using default genre', [
                'defaultGenre' => $defaultGenre
            ]);
            return $defaultGenre;
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
            $topGenres = array_keys(array_filter($genreMatches, function($score) use ($maxScore) {
                return $score === $maxScore;
            }));

            // If we have multiple genres with the same match strength
            if (count($topGenres) > 1) {
                // Rule 3: Use the one with the most books
                $mostBooks = 0;
                $bestGenre = $defaultGenre;

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
                    'matchingGenres' => $topGenres
                ]);

                return $bestGenre;
            } else {
                // Rule 4: Use the matched genre
                $selectedGenre = reset($topGenres);

                Log::info('[ImportFile] Selected genre based on author/series match', [
                    'author' => $author,
                    'series' => $series,
                    'selectedGenre' => $selectedGenre,
                    'matchStrength' => $genreMatches[$selectedGenre]
                ]);

                return $selectedGenre;
            }
        }

        // Rule 5: Fallback to default
        Log::info('[ImportFile] No matching genres found, using default', [
            'author' => $author,
            'series' => $series,
            'defaultGenre' => $defaultGenre
        ]);

        return $defaultGenre;
    }

    /**
     * Extract metadata from a single audio file.
     */
    private function extractFileMetadata(string $filePath, array &$meta): void
    {
        if (class_exists('getID3')) {
            try {
                $getID3 = new \getID3();
                $info = $getID3->analyze($filePath);

                Log::debug("[ImportFile] Analyzing file: {$filePath}");

                // Extract and normalize tags from different sources
                $tags = $this->normalizeAudioTags($info);

                // Log the normalized tags for debugging
                // Log::debug('[ImportFile] Normalized audio tags: ', $tags);

                // Extract title
                if (empty($meta['title'])) {
                    $meta['title'] = $tags['title'] ?? null;

                    // If title contains book/series info in parentheses, extract it
                    if (! empty($meta['title']) && preg_match('/^(.*?)(?:\s*[:\-]\s*(.*))?$/', $meta['title'], $matches)) {
                        $meta['title'] = trim($matches[1]);
                        if (! empty($matches[2])) {
                            $meta['subtitle'] = trim($matches[2]);
                        }
                    }

                    // Check for "Book X" pattern in title
                    if (! empty($meta['title']) && preg_match('/^(.*?)(?:,\s*Book\s*(\d+))?$/', $meta['title'], $matches)) {
                        $meta['title'] = trim($matches[1]);
                        if (! empty($matches[2])) {
                            $meta['seriesNumber'] = trim($matches[2]);
                        }
                    }
                }

                // Extract author
                if (empty($meta['author'])) {
                    $meta['author'] = $tags['artist'] ?? null;

                    // Handle author with series in parentheses: "Author Name (Series Name)"
                    if (! empty($meta['author']) && preg_match('/^(.*?)\s*\(([^)]+)\)$/', $meta['author'], $matches)) {
                        $meta['author'] = trim($matches[1]);

                        // If we don't already have series info, use this
                        if (empty($meta['seriesName'])) {
                            $meta['seriesName'] = trim($matches[2]);
                        }
                    }
                }

                // Extract series information
                if (empty($meta['seriesName']) && ! empty($tags['grouping'])) {
                    $meta['seriesName'] = $tags['grouping'];
                }

                // Extract album/series info
                if (! empty($tags['album'])) {
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
                $meta['narrator'] = $tags['composer'] ?? null;
                $meta['year'] = $tags['year'] ?? null;

                // Extract description if available
                if (! empty($tags['description'])) {
                    $meta['description'] = $tags['description'];
                } elseif (! empty($tags['comment']) && strlen($tags['comment']) > 20) {
                    $meta['description'] = $tags['comment'];
                }

                // Extract cover image if available
                if (! empty($tags['picture'])) {
                    $meta['coverImage'] = $tags['picture'];
                    Log::debug('[ImportFile] Cover image extracted from audio file');
                }

                // Extract chapters if available
                if (! empty($info['quicktime']['chapters'])) {
                    $meta['chapters'] = [];
                    foreach ($info['quicktime']['chapters'] as $chapter) {
                        $meta['chapters'][] = [
                            'title' => $chapter['title'],
                            'start' => $chapter['timestamp'],
                            'duration' => isset($chapter['duration_seconds'])
                                ? $chapter['duration_seconds']
                                : null,
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
        if (! empty($info['tags']['quicktime'])) {
            $qt = $info['tags']['quicktime'];

            // Title - could be a string or array
            if (! empty($qt['title'])) {
                if (is_array($qt['title'])) {
                    // If it's an array of chapter titles, use the first non-chapter title
                    foreach ($qt['title'] as $title) {
                        if (! preg_match('/^(Chapter|Part|Section|\d+)\s*\d*$/i', $title)) {
                            $tags['title'] = $title;
                            break;
                        }
                    }
                    // If we didn't find a good title, use the first one
                    if (empty($tags['title'])) {
                        $tags['title'] = $this->sanitizeString($qt['title'][0]);
                    }
                } else {
                    $tags['title'] = $this->sanitizeString($qt['title']);
                }
            }

            // Artist
            if (! empty($qt['artist'])) {
                $tags['artist'] = $this->sanitizeString(is_array($qt['artist']) ? $qt['artist'][0] : $qt['artist']);
            } elseif (! empty($qt['album_artist'])) {
                $tags['artist'] = $this->sanitizeString(is_array($qt['album_artist']) ? $qt['album_artist'][0] : $qt['album_artist']);
            }

            // Album
            if (! empty($qt['album'])) {
                $tags['album'] = $this->sanitizeString(is_array($qt['album']) ? $qt['album'][0] : $qt['album']);
            }

            // Genre
            if (! empty($qt['genre'])) {
                $tags['genre'] = $this->sanitizeString(is_array($qt['genre']) ? $qt['genre'][0] : $qt['genre']);
            }

            // Composer (often used for narrator in audiobooks)
            if (! empty($qt['composer'])) {
                $tags['composer'] = $this->sanitizeString(is_array($qt['composer']) ? $qt['composer'][0] : $qt['composer']);
            }

            // Year/date
            if (! empty($qt['creation_date'])) {
                $tags['year'] = $this->sanitizeString(is_array($qt['creation_date']) ? $qt['creation_date'][0] : $qt['creation_date']);
            }

            // Description
            if (! empty($qt['description'])) {
                $tags['description'] = $this->sanitizeString(is_array($qt['description']) ? $qt['description'][0] : $qt['description']);
            }

            // Comment
            if (! empty($qt['comment'])) {
                $tags['comment'] = $this->sanitizeString(is_array($qt['comment']) ? $qt['comment'][0] : $qt['comment']);
            }

            // Grouping (often used for series name)
            if (! empty($qt['grouping'])) {
                $tags['grouping'] = $this->sanitizeString(is_array($qt['grouping']) ? $qt['grouping'][0] : $qt['grouping']);
            }

            // Extract cover image from QuickTime tags if available
            if (! empty($info['comments']['picture'][0])) {
                $tags['picture'] = [
                    'data' => $info['comments']['picture'][0]['data'],
                    'mime' => $info['comments']['picture'][0]['image_mime'],
                    'type' => 'front_cover',
                ];
            }
        }

        // Process ID3v2 tags (mp3 files)
        if (! empty($info['tags']['id3v2'])) {
            $id3 = $info['tags']['id3v2'];

            // Only set these if not already set from QuickTime tags
            if (empty($tags['title']) && ! empty($id3['title'][0])) {
                $tags['title'] = $this->sanitizeString($id3['title'][0]);
            }

            if (empty($tags['artist']) && ! empty($id3['artist'][0])) {
                $tags['artist'] = $this->sanitizeString($id3['artist'][0]);
            }

            if (empty($tags['album']) && ! empty($id3['album'][0])) {
                $tags['album'] = $this->sanitizeString($id3['album'][0]);
            }

            if (empty($tags['genre']) && ! empty($id3['genre'][0])) {
                $tags['genre'] = $this->sanitizeString($id3['genre'][0]);
            }

            if (empty($tags['composer']) && ! empty($id3['composer'][0])) {
                $tags['composer'] = $this->sanitizeString($id3['composer'][0]);
            }

            if (empty($tags['year']) && ! empty($id3['year'][0])) {
                $tags['year'] = $this->sanitizeString($id3['year'][0]);
            }

            if (empty($tags['comment']) && ! empty($id3['comment'][0])) {
                $tags['comment'] = $this->sanitizeString($id3['comment'][0]);
            }

            // Extract picture/cover image from ID3v2 tags
            if (! empty($info['id3v2']['APIC'])) {
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
                if (empty($tags['picture']) && ! empty($info['id3v2']['APIC'][0]['data'])) {
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
        if (! empty($info['tags']['id3v1'])) {
            $id3 = $info['tags']['id3v1'];

            // Only set these if not already set from other tag sources
            if (empty($tags['title']) && ! empty($id3['title'][0])) {
                $tags['title'] = $this->sanitizeString($id3['title'][0]);
            }

            if (empty($tags['artist']) && ! empty($id3['artist'][0])) {
                $tags['artist'] = $this->sanitizeString($id3['artist'][0]);
            }

            if (empty($tags['album']) && ! empty($id3['album'][0])) {
                $tags['album'] = $this->sanitizeString($id3['album'][0]);
            }

            if (empty($tags['genre']) && ! empty($id3['genre'][0])) {
                $tags['genre'] = $this->sanitizeString($id3['genre'][0]);
            }

            if (empty($tags['year']) && ! empty($id3['year'][0])) {
                $tags['year'] = $this->sanitizeString($id3['year'][0]);
            }

            if (empty($tags['comment']) && ! empty($id3['comment'][0])) {
                $tags['comment'] = $this->sanitizeString($id3['comment'][0]);
            }
        }

        // Use filename as fallback for title
        if (empty($tags['title']) && ! empty($info['filename'])) {
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
            $this->extractFileMetadata($firstFile, $meta);

            // Override title with directory name if it was extracted from file
            $meta['title'] = $base;

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
     * Move the selected file or directory to the composed directoryPath under the import destination root.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function moveSelected(Request $request)
    {
        $root = $request->input('root');
        $relPath = $request->input('path');
        $type = $request->input('type');
        // Get directoryPath with fallback to empty string, then validate and sanitize
        $directoryPath = $request->input('directoryPath', '');

        // Log the received directoryPath for debugging
        Log::info('[ImportFile] Received directoryPath', [
            'directoryPath' => $directoryPath,
            'isEmpty' => empty($directoryPath)
        ]);

        // If directoryPath is empty, use a default structure
        if (empty($directoryPath)) {
            $directoryPath = 'imports/' . date('Y-m-d');
            Log::info('[ImportFile] Using default directoryPath', ['directoryPath' => $directoryPath]);
        }

        // Sanitize the directoryPath to prevent directory traversal
        $directoryPath = trim($directoryPath, '/');
        $directoryPath = str_replace('..', '', $directoryPath);
        $metadata = $request->input('metadata', []);  // Allow custom metadata from the form
        // Check if root directory exists and is accessible
        if (! file_exists($root)) {
            Log::warning("[ImportFile] Root directory does not exist: {$root}");

            return response()->json([
                'success' => false,
                'message' => 'Root directory does not exist',
                'details' => "The directory '{$root}' could not be found on the server.",
            ], 404);
        }

        if (! is_dir($root)) {
            Log::warning("[ImportFile] Root path is not a directory: {$root}");

            return response()->json([
                'success' => false,
                'message' => 'Invalid root path',
                'details' => "The path '{$root}' exists but is not a directory.",
            ], 400);
        }

        if (! is_readable($root)) {
            Log::warning("[ImportFile] Root directory is not readable due to permissions: {$root}");
            $perms = substr(sprintf('%o', fileperms($root)), -4);

            return response()->json([
                'success' => false,
                'message' => 'Permission denied',
                'details' => "The directory '{$root}' exists but cannot be read due to insufficient permissions.",
                'permissions' => $perms,
            ], 403);
        }

        $absRoot = realpath($root);
        if (! $absRoot) {
            Log::warning("[ImportFile] Could not resolve real path for root: {$root}");

            return response()->json([
                'success' => false,
                'message' => 'Path resolution failed',
                'details' => "Could not resolve the real path for '{$root}'. " .
                    "This might be due to symlink issues or filesystem restrictions.",
            ], 400);
        }
        $absPath = $absRoot . ($relPath ? DIRECTORY_SEPARATOR . $relPath : '');
        $absPath = realpath($absPath) ?: $absPath;

        // Check for path traversal attempts (security check)
        if (strpos($absPath, $absRoot) !== 0) {
            Log::warning('[ImportFile] Path traversal detected: ' . $absPath);

            return response()->json([
                'success' => false,
                'message' => 'Security violation: Path traversal detected',
                'details' => 'The requested path is outside the allowed root directory. This may be an attempt to access unauthorized files.',
            ], 403);
        }

        // Check if path exists
        if (! file_exists($absPath)) {
            Log::warning('[ImportFile] File or directory does not exist: ' . $absPath);

            return response()->json([
                'success' => false,
                'message' => 'File or directory not found',
                'details' => "The requested path '{$relPath}' does not exist in the selected root directory.",
            ], 404);
        }

        // Check if path is readable
        if (! is_readable($absPath)) {
            Log::warning('[ImportFile] Path is not readable due to permissions: ' . $absPath);
            $perms = substr(sprintf('%o', fileperms($absPath)), -4);

            return response()->json([
                'success' => false,
                'message' => 'Permission denied',
                'details' => "The path '{$relPath}' exists but cannot be read due to insufficient permissions.",
                'permissions' => $perms,
            ], 403);
        }
        // Determine import destination root from environment variable or fallback to config
        $destRoot = env('BOOK_STORAGE_PATH')
            ?? Config::get('audiobooks.root')
            ?? Config::get('import.dest_root')
            ?? storage_path('audiobooks');

        // Get genre or genrePath from request if available
        $genrePath = $request->input('genrePath', $request->input('genre'));

        // Log the received parameters for debugging
        Log::info('[ImportFile] Constructing destination directory', [
            'destRoot' => $destRoot,
            'directoryPath' => $directoryPath,
            'genrePath' => $genrePath,
            'genre' => $request->input('genre')
        ]);

        // Check if directoryPath already contains the genre path
        $directoryPathParts = explode('/', $directoryPath);
        $firstPart = reset($directoryPathParts);

        if (!empty($genrePath) && $firstPart !== $genrePath) {
            // If directoryPath doesn't start with genrePath, prepend it
            $destDir = $destRoot . DIRECTORY_SEPARATOR . $genrePath . DIRECTORY_SEPARATOR . $directoryPath;
            Log::info('[ImportFile] Adding genrePath to directoryPath', [
                'destRoot' => $destRoot,
                'genrePath' => $genrePath,
                'directoryPath' => $directoryPath,
                'destDir' => $destDir
            ]);
        } else {
            // directoryPath already includes genrePath or no genrePath provided
            $destDir = $destRoot . DIRECTORY_SEPARATOR . $directoryPath;
            Log::info('[ImportFile] Using directoryPath as is', [
                'destRoot' => $destRoot,
                'directoryPath' => $directoryPath,
                'destDir' => $destDir
            ]);
        }
        if (! file_exists($destDir)) {
            try {
                if (! File::makeDirectory($destDir, 0775, true)) {
                    Log::error('[ImportFile] Failed to create directory: ' . $destDir);

                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to create destination directory',
                        'details' => "Could not create the directory '{$directoryPath}' in the destination. "
                            . "This may be due to permission issues.",
                    ], 500);
                }
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                // Calculate what the target would be, even though we haven't created it yet
                $potentialTarget = $destDir . DIRECTORY_SEPARATOR . basename($absPath);
                Log::error('[ImportFile] Exception creating directory', [
                    'from' => $absPath,
                    'potentialTarget' => $potentialTarget,
                    'type' => $type ?? 'unknown',
                    'destDir' => $destDir,
                    'error' => $errorMessage
                ]);

                // Determine if it's a permission issue
                $permissionError = false;
                if (
                    strpos(strtolower($errorMessage), 'permission') !== false ||
                    strpos(strtolower($errorMessage), 'denied') !== false
                ) {
                    $permissionError = true;
                }

                return response()->json([
                    'success' => false,
                    'message' => $permissionError ? 'Permission denied' : 'Failed to create destination directory',
                    'details' => $permissionError
                        ? "You don't have permission to create the directory '{$directoryPath}' in the destination."
                        : "An error occurred while creating the directory: {$errorMessage}",
                ], $permissionError ? 403 : 500);
            }
        } elseif (! is_writable($destDir)) {
            // Directory exists but isn't writable
            $perms = substr(sprintf('%o', fileperms($destDir)), -4);
            // Calculate what the target would be, even though we haven't assigned it yet
            $potentialTarget = $destDir . DIRECTORY_SEPARATOR . basename($absPath);
            Log::error('[ImportFile] Destination directory exists but is not writable', [
                'from' => $absPath,
                'potentialTarget' => $potentialTarget,
                'type' => $type ?? 'unknown',
                'destDir' => $destDir,
                'permissions' => $perms
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Destination directory not writable',
                'details' => "The destination directory exists but you don't have permission to write to it.",
                'permissions' => $perms,
            ], 403);
        }
        // Check if the destination path already ends with the basename of the source path
        $basename = basename($absPath);
        $destDirBasename = basename($destDir);

        // Log the path components for debugging
        Log::info('[ImportFile] Path components', [
            'absPath' => $absPath,
            'basename' => $basename,
            'destDir' => $destDir,
            'destDirBasename' => $destDirBasename
        ]);

        // If the destination directory already ends with the basename, don't append it again
        if ($destDirBasename === $basename) {
            $target = $destDir;
            Log::info('[ImportFile] Using destination directory as target (avoiding duplication)');
        } else {
            $target = $destDir . DIRECTORY_SEPARATOR . $basename;
            Log::info('[ImportFile] Appending basename to destination directory');
        }

        // Log the paths for debugging
        Log::info('[ImportFile] Move preparation', [
            'absPath' => $absPath,
            'basename' => $basename,
            'destDir' => $destDir,
            'target' => $target
        ]);
        if (realpath($absPath) === realpath($target)) {
            Log::info('[ImportFile] Source and destination are the same, skipping move.');

            return response()->json(
                ['success' => true, 'newPath' => $target, 'message' => 'Already in target location.']
            );
        }
        try {
            // Validate the type parameter
            if (empty($type)) {
                // If type is not provided, determine it based on the path
                $type = is_file($absPath) ? 'file' : (is_dir($absPath) ? 'dir' : null);
                Log::info("[ImportFile] Type parameter not provided, automatically determined as: {$type}");
            }

            if ($type !== 'file' && $type !== 'dir') {
                Log::warning("[ImportFile] Invalid type parameter: {$type}");

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid type parameter',
                    'details' => "The type parameter must be either 'file' or 'dir'. Received: '{$type}'.",
                ], 400);
            }

            // Check if target already exists (would be overwritten)
            if (file_exists($target) && $target !== $destDir) {
                // Only return an error if the target is not the same as destDir
                // This allows us to use the same directory when we've determined it's already correct
                Log::warning("[ImportFile] Target already exists: {$target}");

                return response()->json([
                    'success' => false,
                    'message' => 'Target already exists',
                    'details' => 'The destination path already contains a file or directory with the same name. ' .
                        'Cannot overwrite existing content.',
                ], 409); // HTTP 409 Conflict
            }

            // Check if source is writable (needed for deletion after move)
            if (! is_writable(dirname($absPath))) {
                Log::warning('[ImportFile] Source directory is not writable', [
                    'from' => $absPath,
                    'to' => $target,
                    'type' => $type,
                    'sourceDir' => dirname($absPath),
                    'permissions' => substr(sprintf('%o', fileperms(dirname($absPath))), -4)
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Source directory not writable',
                    'details' => 'The source directory is not writable. ' .
                        'Cannot remove the original file/directory after copying.',
                    'permissions' => substr(sprintf('%o', fileperms(dirname($absPath))), -4),
                ], 403);
            }

            // Perform the move operation
            if ($type === 'file') {
                Log::info('[ImportFile] Moving file', [
                    'from' => $absPath,
                    'to' => $target
                ]);
                File::move($absPath, $target);
            } else { // $type === 'dir'
                Log::info('[ImportFile] Moving directory', [
                    'from' => $absPath,
                    'to' => $target,
                    'source_exists' => file_exists($absPath) ? 'Yes' : 'No',
                    'source_is_dir' => is_dir($absPath) ? 'Yes' : 'No',
                    'source_readable' => is_readable($absPath) ? 'Yes' : 'No',
                    'source_writable' => is_writable($absPath) ? 'Yes' : 'No',
                    'target_exists' => file_exists($target) ? 'Yes' : 'No',
                    'target_parent_exists' => file_exists(dirname($target)) ? 'Yes' : 'No',
                    'target_parent_writable' => is_writable(dirname($target)) ? 'Yes' : 'No',
                    'source_contents' => array_slice(scandir($absPath), 0, 10) // Show first 10 items
                ]);

                try {
                    // First try the standard moveDirectory
                    $moveResult = File::moveDirectory($absPath, $target);

                    if (!$moveResult) {
                        Log::warning('[ImportFile] Standard moveDirectory failed, trying manual copy+delete approach');

                        // If standard move fails, try copy+delete approach
                        // First make sure destination directory exists
                        if (!file_exists($target)) {
                            File::makeDirectory($target, 0775, true);
                        }

                        // Copy all files from source to destination
                        $sourceFiles = File::allFiles($absPath);
                        $copySuccess = true;

                        foreach ($sourceFiles as $file) {
                            $relativePath = str_replace($absPath, '', $file->getPathname());
                            $destFile = $target . $relativePath;

                            // Ensure destination directory exists
                            $destDir = dirname($destFile);
                            if (!file_exists($destDir)) {
                                File::makeDirectory($destDir, 0775, true);
                            }

                            // Copy the file
                            if (!File::copy($file->getPathname(), $destFile)) {
                                Log::error('[ImportFile] Failed to copy file', [
                                    'from' => $file->getPathname(),
                                    'to' => $destFile
                                ]);
                                $copySuccess = false;
                                break;
                            }
                        }

                        // If all files were copied successfully, delete the source directory
                        if ($copySuccess) {
                            // Don't delete source yet for safety
                            // File::deleteDirectory($absPath);
                            Log::info('[ImportFile] Successfully copied all files from source to destination');
                        } else {
                            throw new \Exception('Failed to copy all files from source to destination');
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('[ImportFile] Exception during moveDirectory', [
                        'from' => $absPath,
                        'to' => $target,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    throw $e; // Re-throw to be caught by the outer try-catch
                }
            }

            Log::info('[ImportFile] Successfully moved "' . $absPath . '" to "' . $target . '"');

            // If metadata was provided, store it with the book
            if (! empty($metadata)) {
                // Generate a unique book ID for the imported book
                $bookId = hash('sha256', $target);

                // Store the metadata in the document store
                try {
                    $metadata['id'] = $bookId;
                    $metadata['path'] = $target;
                    $metadata['updated_at'] = now()->toIso8601String();
                    $metadata['created_at'] = $metadata['updated_at'];

                    $this->documentStoreService->createBook($metadata);
                    Log::info('[ImportFile] Stored metadata for imported book', [
                        'bookId' => $bookId,
                    ]);
                } catch (\Exception $e) {
                    Log::error('[ImportFile] Failed to store metadata', [
                        'bookId' => $bookId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'newPath' => $target,
                'message' => 'File successfully imported',
                'details' => ($type === 'file' ? 'File' : 'Directory') .
                    ' was successfully moved to the destination.',
                'metadata' => $metadata,
            ]);
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            Log::error('[ImportFile] Move failed: ' . $errorMessage, [
                'from' => $absPath,
                'to' => $target,
                'type' => $type
            ]);

            // Determine if it's a permission issue
            $permissionError = false;
            if (
                strpos(strtolower($errorMessage), 'permission') !== false ||
                strpos(strtolower($errorMessage), 'denied') !== false
            ) {
                $permissionError = true;
            }

            // Determine if it's a disk space issue
            $diskSpaceError = false;
            if (
                strpos(strtolower($errorMessage), 'space') !== false ||
                strpos(strtolower($errorMessage), 'quota') !== false ||
                strpos(strtolower($errorMessage), 'full') !== false
            ) {
                $diskSpaceError = true;
            }
            return response()->json([
                'success' => false,
                'message' => $permissionError ? 'Permission denied' :
                    ($diskSpaceError ? 'Insufficient disk space' : 'Move operation failed'),
                'details' => $permissionError
                    ? "You don't have permission to perform this operation."
                    : ($diskSpaceError
                        ? 'There is not enough disk space to complete this operation.'
                        : "An error occurred during the move operation: {$errorMessage}"),
            ], $permissionError ? 403 : ($diskSpaceError ? 507 : 500));
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
        if (! empty($meta['genre'])) {
            return;
        }

        $author = $meta['author'] ?? null;
        $series = $meta['series'] ?? null;
        $genreCounts = [];

        try {
            // First try to find books in the same series if available
            if ($series) {
                $seriesBooks = $this->documentStoreService->searchSeriesByName($series);
                if (! empty($seriesBooks)) {
                    foreach ($seriesBooks as $book) {
                        if (! empty($book['genre'])) {
                            // Give series matches higher weight
                            $genreCounts[$book['genre']] = ($genreCounts[$book['genre']] ?? 0) + 2;
                        }
                    }
                }
            }

            // Then try to find books by the same author
            if ($author) {
                $authorBooks = $this->documentStoreService->searchAuthorsByName($author);
                if (! empty($authorBooks)) {
                    foreach ($authorBooks as $book) {
                        if (! empty($book['genre'])) {
                            $genreCounts[$book['genre']] = ($genreCounts[$book['genre']] ?? 0) + 1;
                        }
                    }
                }
            }

            // Determine the most common genre
            if (! empty($genreCounts)) {
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
}
