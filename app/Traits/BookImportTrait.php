<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

trait BookImportTrait
{
    /**
     * Scan a directory and return its contents.
     *
     * @param  string  $path  The directory path to scan
     *
     * @throws RuntimeException If the directory cannot be scanned
     */
    private function scanDirectory(string $path): array
    {
        $storagePath = env('BOOK_STORAGE_PATH');
        if (!$storagePath) {
            throw new RuntimeException('BOOK_STORAGE_PATH is not defined in the .env file');
        }

        $files = scandir($path);
        if ($files === false) {
            throw new RuntimeException("Failed to scan directory: {$path}");
        }

        return array_diff($files, ['.', '..']);
    }

    /**
     * Extract tag data from an audio file.
     *
     * @param  string  $filePath  Path to the audio file
     * @return array Extracted tag data
     */
    private function extractTagData(string $filePath): array
    {
        $storagePath = env('BOOK_STORAGE_PATH');
        if (!$storagePath) {
            throw new RuntimeException('BOOK_STORAGE_PATH is not defined in the .env file');
        }
        $directoryPath = dirname($filePath);
        $process = new Process([
            'ffmpeg',
            '-i',
            $filePath,
            '-f',
            'ffmetadata',
            'pipe:1',  // Output to standard output
        ]);

        $process->run();

        if (!$process->isSuccessful()) {
            return []; // Return empty array if FFmpeg fails
        }

        $output = $process->getOutput();
        $lines = explode("\n", $output);

        $tags = [];
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2); // Limit to 2 parts in case value also contains '='
                $tags[trim($key)] = trim($value);
            }
        }

        $title = $tags['title'] ?? null;
        $artist = $tags['artist'] ?? null;
        $album = $tags['album'] ?? null;
        $comment = $tags['comment'] ?? $tags['description'] ?? null;

        // Check if tags match the directory structure
        $tagMatch = true;

        if ($artist && !str_contains(strtolower($directoryPath), strtolower($artist))) {
            $tagMatch = false;
        }

        if ($album && !str_contains(strtolower($directoryPath), strtolower($album))) {
            $tagMatch = false;
        }

        return [
            'title' => $title,
            'artist' => $artist,
            'album' => $album,
            'description' => $comment,
            'tagMatch' => $tagMatch,
        ];
    }

    /**
     * Extract cover image from m4b file using ffmpeg.
     * Returns filename (relative, e.g. 'cover.jpg') or null.
     */
    private function extractCoverFromM4B($m4bPath, $outputDir)
    {
        $outputImage = rtrim($outputDir, '/') . '/cover.jpg';
        $process = new Process([
            'ffmpeg',
            '-y',
            '-i',
            $m4bPath,
            '-an',
            '-vcodec',
            'copy',
            $outputImage,
        ]);
        $process->run();
        if ($process->isSuccessful() && file_exists($outputImage)) {
            return basename($outputImage);
        }

        return null;
    }

    /**
     * Extract description and year from metadata.abs file in directory.
     * Returns ['description' => ..., 'year' => ...]
     */
    private function extractMetadataAbs($dir)
    {
        $file = rtrim($dir, '/') . '/metadata.abs';
        $result = [];
        if (!file_exists($file)) {
            return $result;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $inDescriptionSection = false;
        $descriptionLines = [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if (preg_match('/^\[DESCRIPTION\]/i', $trim)) {
                $inDescriptionSection = true;

                continue;
            }
            if ($inDescriptionSection) {
                if (preg_match('/^\[.*\]/', $trim)) {
                    // New section starts, stop capturing
                    break;
                }
                $descriptionLines[] = $trim;

                continue;
            }
            if (preg_match('/^description\s*[:=]\s*(.+)$/i', $trim, $m)) {
                $result['description'] = trim($m[1]);
            }
            if (preg_match('/^(year|published|publication_year)\s*[:=]\s*(\d{4})$/i', $trim, $m)) {
                $result['year'] = trim($m[2]);
            }
        }
        if ($inDescriptionSection && $descriptionLines) {
            $result['description'] = implode("\n", $descriptionLines);
        }

        return $result;
    }

    /**
     * Scan directory for images, prefer one with 'cover' in the name.
     * Returns [selected, candidates[]]
     */
    /**
     * Find a suitable cover image in the specified directory.
     *
     * @param  string  $directoryPath  Relative path from BOOK_STORAGE_PATH
     * @return array [string|null $selected, array $candidates]
     */
    protected function findCoverImageCandidate(string $directoryPath): array
    {
        $storagePath = env('BOOK_STORAGE_PATH');
        $dir = rtrim($storagePath, '/') . '/' . ltrim($directoryPath, '/');
        if (!is_dir($dir)) {
            return [null, []];
        }
        $images = [];
        $selected = null;
        foreach (scandir($dir) as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $full = $dir . '/' . $file;
            if (!is_file($full)) {
                continue;
            }
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                continue;
            }
            $images[] = $file;
            if (!$selected && stripos($file, 'cover') !== false) {
                $selected = $file;
            }
        }

        // If no 'cover' found, leave $selected null
        return [$selected, $images];
    }

    /**
     * Recursively find book directories (heuristic: contains m4b or metadata.abs or image file).
     */
    private function findBookDirectories($root)
    {
        $bookDirs = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($rii as $file) {
            if ($file->isDir()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (
                in_array($ext, ['m4b', 'jpg', 'jpeg', 'png', 'gif', 'webp']) ||
                $file->getFilename() === 'metadata.abs'
            ) {
                $bookDirs[] = $file->getPath();
            }
        }

        return array_unique($bookDirs);
    }

    /**
     * Process a directory path and extract book metadata.
     *
     * @param  string  $directoryPath  The directory path to process
     * @return array{
     *      genre: array, author: array, series?: array, title: string, directoryPath: string, skipped?: bool,
     *      error?: string
     * }
     *
     * @throws InvalidArgumentException If the directory path is invalid
     */
    public function processDirPath(string $directoryPath): array
    {
        // Initialize book array with default values
        $book = [
            'directoryPath' => $directoryPath,
            'genre' => [],
            'author' => [],
            'title' => '',
        ];

        try {
            // Split directory path into components
            $parts = array_values(array_filter(explode('/', trim($directoryPath, '/')), 'strlen'));

            // Debug output
            if (method_exists($this, 'debug')) {
                $this->debug("Processing directory path: {$directoryPath}");
                $this->debug('Path parts: ' . json_encode($parts));
            }

            // Handle empty or invalid paths
            if (empty($parts)) {
                if (method_exists($this, 'debug')) {
                    $this->debug("Empty parts array for path: $directoryPath");
                }

                // Check if this is an absolute path outside the storage root
                if (strpos($directoryPath, '/') === 0) {
                    // This is an absolute path, try to extract meaningful parts
                    $pathParts = explode('/', trim($directoryPath, '/'));

                    // Look for common patterns in test directories
                    if (
                        count($pathParts) >= 3 && (in_array('Fiction', $pathParts) ||
                            in_array('NonFiction', $pathParts))
                    ) {
                        // Find the index of Fiction or NonFiction
                        $genreIndex = array_search('Fiction', $pathParts);
                        if ($genreIndex === false) {
                            $genreIndex = array_search('NonFiction', $pathParts);
                        }

                        if (
                            $genreIndex !== false && isset($pathParts[$genreIndex + 1])
                            && isset($pathParts[$genreIndex + 2])
                        ) {
                            $book['genre'] = [$pathParts[$genreIndex]];
                            $book['author'] = [$pathParts[$genreIndex + 1]];
                            $book['title'] = $pathParts[$genreIndex + 2];
                            if (method_exists($this, 'debug')) {
                                $this->debug("Extracted from absolute path: Genre={$pathParts[$genreIndex]}, " .
                                    "Author={$pathParts[$genreIndex + 1]}, Title={$pathParts[$genreIndex + 2]}");
                            }

                            return $book;
                        }
                    }
                }

                throw new InvalidArgumentException('Empty directory path');
            }

            // Handle VA (various artists) directories
            if (in_array('VA', $parts, true)) {
                Log::warning("Skipping VA directory: {$directoryPath}");

                return [
                    'directoryPath' => $directoryPath,
                    'genre' => [],
                    'author' => [],
                    'title' => '',
                    'skipped' => true,
                    'reason' => 'VA directory',
                ];
            }

            // Remove 'R' rating if present
            if (($key = array_search('R', $parts, true)) !== false) {
                unset($parts[$key]);
                $parts = array_values($parts); // Re-index array
            }

            // We need at least genre and author
            if (count($parts) < 2) {
                throw new InvalidArgumentException("Path too short: {$directoryPath}");
            }

            // First part is always genre
            $genre = array_shift($parts);
            $book['genre'] = [$genre];

            // Second part is author(s)
            $author = array_shift($parts);

            // Handle multiple authors
            if (str_contains($author, ',') || stripos($author, ' and ') !== false || str_contains($author, '&')) {
                $author = str_replace([' and ', ' & '], ',', $author);
                $authors = array_map('trim', explode(',', $author));
                $book['author'] = array_values(array_filter($authors, fn($a) => strlen(trim($a)) > 4));
            } else {
                $book['author'] = [trim($author)];
            }

            // Remaining parts are series/title
            if (empty($parts)) {
                throw new InvalidArgumentException("No title in path: {$directoryPath}");
            }

            $series = null;
            $seriesNumber = null;
            $title = '';
            $seriesParent = null;

            // If we have more than one part left, the last part is the title, the immediate parent is the series
            if (count($parts) > 1) {
                $title = array_pop($parts);
                $seriesCandidate = array_pop($parts);
                // Only set series if the folder is not numeric
                if (!is_numeric($seriesCandidate)) {
                    $series = $seriesCandidate;
                } else {
                    // If the folder is numeric, treat it as part of the title (prepend to title)
                    $title = $seriesCandidate . ' ' . $title;
                    $series = null;
                }
                // Try to extract series number from title
                if (preg_match('#^[\[\{\(]?([0-9.]+)[\]\}\)]?\s?(.*)$#', $title, $matches)) {
                    $seriesNumber = $matches[1];
                    $title = trim($matches[2]);
                } elseif (preg_match('/(?:book|volume|vol\.?)[ _-]*(\d{1,3}(\.\d{1,2})?)/i', $title, $m)) {
                    $seriesNumber = $m[1];
                } elseif (preg_match('/(\d{1,3}(\.\d{1,2})?)$/', $title, $m)) {
                    $seriesNumber = $m[1];
                } elseif (preg_match('/\s*[\[\{\(](\d{1,3}(\.\d{1,2})?)[\]\)\}](?:\s|$)/', $title, $m)) {
                    $seriesNumber = $m[1];
                }
            } else {
                $title = $parts[0];
                $series = null;
            }

            // Set series data if we have a valid series name (string, not numeric)
            if (!empty($series) && is_string($series)) {
                $book['series'] = empty($seriesNumber) ? [$series => null] : [$series => $seriesNumber];
            }

            $book['title'] = trim($title);
        } catch (\Exception $e) {
            Log::error("Error processing directory path {$directoryPath}: " . $e->getMessage());
            $book['error'] = $e->getMessage();
            $book['skipped'] = true;
        }

        return $book;
    }

    /**
     * Download and store a remote cover image, return the local path for storage in DB.
     *
     * @param  string  $url  The URL to import the cover image from.
     * @param  string|null  $directoryPath  The directory path to store the cover image, or null for default.
     * @return string|null The relative path for storage in DB, or null on failure.
     */
    private function importCoverImageFromUrl($url, $directoryPath = null): ?string
    {
        if (!$url) {
            Log::error("Invalid URL: {$url}");

            return null;
        }

        try {
            $storagePath = env('BOOK_STORAGE_PATH'); // absolute path
            if (!$storagePath) {
                Log::error('BOOK_STORAGE_PATH is not defined.');

                return null;
            }

            $fullDir = rtrim($storagePath, '/') . '/' . ltrim($directoryPath, '/');
            if (!is_dir($fullDir)) {
                if (!mkdir($fullDir, 0775, true) && !is_dir($fullDir)) {
                    Log::error("importCoverImageFromUrl error: Unable to create directory at $fullDir");

                    return null;
                }
            }

            // Use cURL with a browser User-Agent
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt(
                $ch,
                CURLOPT_USERAGENT,
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) ' .
                'Chrome/58.0.3029.110 Safari/537.3'
            );
            $contents = curl_exec($ch);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);

            if ($contents === false || !$contents) {
                Log::error("importCoverImageFromUrl error: Unable to fetch image from {$url}");

                return null;
            }

            // Determine extension
            $ext = 'jpg';
            if (strpos($contentType, 'png') !== false) {
                $ext = 'png';
            } elseif (strpos($contentType, 'gif') !== false) {
                $ext = 'gif';
            } elseif (strpos($contentType, 'jpeg') !== false) {
                $ext = 'jpg';
            }

            $filename = 'cover.' . $ext;
            $fullPath = $fullDir . '/' . $filename;
            if (file_put_contents($fullPath, $contents) === false) {
                Log::error("importCoverImageFromUrl error: Unable to write file $fullPath");

                return null;
            }

            // Return only the path relative to BOOK_STORAGE_PATH
            return ltrim($directoryPath, '/') . '/' . $filename;
        } catch (\Exception $e) {
            Log::error('importCoverImageFromUrl error: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Google Books API service instance.
     *
     * @var mixed|null
     */
    protected $googleBooksApiService = null;

    public function setGoogleBooksApiService($service)
    {
        $this->googleBooksApiService = $service;
    }

    public function searchGoogleBooks($query)
    {
        return $this->googleBooksApiService->searchBooks($query, 30);
    }

    public function getBookDetails($volumeId)
    {
        return $this->googleBooksApiService->getBookDetails($volumeId);
    }

    /**
     * Search Google Books and return matches sorted by similarity to title, author, series, and number.
     * Returns [matches (sorted), close_match (or null)]
     */
    public function searchGoogleBooksWithSimilarity($title, $author, $series = '', $seriesNumber = '')
    {
        $query = trim("intitle:{$title} inauthor:{$author}");
        $results = $this->googleBooksApiService->searchBooks($query, ['limit' => 30]);
        if (empty($results['items'])) {
            return [[], null];
        }
        // Compute similarity for each item
        $matches = [];
        $bestScore = 0;
        $bestMatch = null;
        $maxScore = 120;
        if ($series) {
            $maxScore += 10;
        }
        if ($seriesNumber) {
            $maxScore += 5;
        }
        foreach ($results['items'] as $item) {
            $info = $item['volumeInfo'];
            $itemTitle = $info['title'] ?? '';
            $itemAuthors = isset($info['authors']) ? implode(' ', $info['authors']) : '';
            $itemSeries = $info['series'] ?? ($info['subtitle'] ?? '');
            $itemSeriesNumber = $info['seriesNumber'] ?? '';
            $score = 0;
            // Title similarity (Levenshtein, case-insensitive)
            $titleLev = 100 -
                min(
                    levenshtein(mb_strtolower($title), mb_strtolower($itemTitle)),
                    100
                );
            $score += $titleLev;
            // Author similarity (Levenshtein, case-insensitive)
            $authorLev = 100 -
                min(
                    levenshtein(mb_strtolower($author), mb_strtolower($itemAuthors)),
                    100
                );
            $score += $authorLev;
            // Series similarity
            if ($series && stripos($itemSeries, $series) !== false) {
                $score += 10;
            }
            // Series number
            if ($seriesNumber && $itemSeriesNumber == $seriesNumber) {
                $score += 5;
            }
            if (empty($info['description'])) {
                $score -= 40;
            }
            $matches[] = [
                'score' => $score,
                'item' => $item,
            ];
            if ($score > $bestScore) {
                $bestScore = $score;
                $item['score'] = $score;
                $bestMatch = $item;
            }
        }
        usort($matches, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        // Only consider a close match if score is very high (stricter)
        $closeMatch = ($bestScore > 160) ? $bestMatch : null;

        return [$matches, $closeMatch];
    }

    /**
     * Extract metadata from a filename and update the book array.
     *
     * @param  array  &$book  Reference to the book array to update
     * @param  string  $filename  The filename to extract metadata from
     */
    protected function extractMetadata(array &$book, string $filename): void
    {
        // Extract year (e.g., (2020) or [2020])
        if (preg_match('/\(([0-9]{4})\)|\[([0-9]{4})\]/', $filename, $matches)) {
            $book['year'] = (int) ($matches[1] ?? $matches[2]);
        }

        // Extract narrator (e.g., 'narrated by John Smith' or 'narr. Jane Doe')
        if (preg_match('/(?:narrated by|narr\.?|read by)\s+([^\[\]()]+)/i', $filename, $matches)) {
            $book['narrator'] = trim($matches[1]);
        }

        // Extract edition (e.g., '2nd edition', 'revised edition')
        if (preg_match('/(\d+(?:st|nd|rd|th) edition|revised edition|unabridged|abridged)/i', $filename, $matches)) {
            $book['edition'] = $matches[1];
        }

        // Extract series number (e.g., 'Book 1', '#2', 'Vol. 3')
        if (preg_match('/(?:book|vol\.?|#)\s*(\d+)/i', $filename, $matches)) {
            $book['series_number'] = (int) $matches[1];
        }
    }

    /**
     * Clean up a title and return metadata about the cleanup.
     *
     * @param  string  $title  The title to clean up
     * @return array Returns an array with 'title' and 'metadata' about the cleanup
     */
    public function cleanupTitle(string $title): array
    {
        if (empty($title)) {
            return [
                'title' => $title,
                'metadata' => [
                    'needs_review' => false,
                    'applied_corrections' => [],
                ],
            ];
        }

        $originalTitle = $title;
        $appliedCorrections = [];

        // Apply title cleaning logic here
        $cleaned = $this->cleanText($title);
        if ($cleaned !== $title) {
            $appliedCorrections[] = 'Cleaned whitespace and special characters';
            $title = $cleaned;
        }

        // Normalize whitespace
        $title = trim(preg_replace('/\s+/', ' ', $title));

        // Check if the title needs review
        $needsReview = $this->titleNeedsReview($title);

        return [
            'title' => $title,
            'metadata' => [
                // 'needs_review' => $needsReview,
                'original_title' => $originalTitle,
                'applied_corrections' => $appliedCorrections,
            ],
        ];
    }

    /**
     * Check if a title needs manual review
     *
     * @param  string  $title  The title to check
     * @param  string  $series  The series name (if any)
     * @param  string  $author  The author name(s)
     * @param  string  $path  The file path (optional)
     * @return bool True if the title needs review
     */
    /**
     * Check if a title needs manual review. Returns an array of reasons if review is needed, or null if not.
     *
     * @param  string  $title  The title to check
     * @param  string  $series  The series name (if any)
     * @param  string  $author  The author name(s)
     * @param  string  $path  The file path (optional)
     * @return array|null Array of reasons, or null if no review needed
     */
    public function titleNeedsReview(
        string $title,
        ?string $series = null,
        ?string $author = null,
        ?string $path = null
    ): ?array {
        $title = trim($title);
        $series = $series ? trim($series) : null;
        $author = $author ? trim($author) : null;
        $path = $path ? strtolower(trim($path)) : null;
        $reasons = [];

        // Check if series name is same as author
        if ($series && $author && strcasecmp($series, $author) === 0) {
            $reasons[] = "Series name is same as author: $series, $author";
        }

        // Check if title is the same as the series name
        if ($series && strcasecmp($title, $series) === 0) {
            $reasons[] = "Title is the same as series name: $title, $series";
        }

        // Check if title is not a substring of the path (case-insensitive)
        if ($path && strpos($path, strtolower($title)) === false) {
            $reasons[] = "Title is not a substring of path: $title, $path";
        }

        // Check for numbers at beginning or end of title without a series
        if (!$series && (preg_match('/^\d+\s+/', $title) || preg_match('/\s+\d+$/', $title))) {
            $reasons[] = "Title has numbers at beginning or end: $title";
        }

        // Return reasons if any, otherwise null
        return !empty($reasons) ? $reasons : null;
    }

    /**
     * Normalize a series name to its canonical form.
     *
     * @param  string  $name  Series name to normalize
     * @return string Normalized series name
     */
    public function normalizeSeriesName(string $name): string
    {
        $name = trim($name);
        $lowerName = strtolower($name);

        // Check for known variations
        if (isset($this->seriesVariations[$lowerName])) {
            return $this->seriesVariations[$lowerName];
        }

        // Remove common prefixes/suffixes
        $name = preg_replace('/\s*\([^)]*\)\s*$/', '', $name); // Remove parentheticals at end
        $name = preg_replace('/^\s*[\[\{\(]|[\]\)\}]\s*$/', '', $name); // Remove brackets/parentheses
        $name = preg_replace('/\s*[-_|\/]\s*$/', '', $name); // Remove trailing separators
        $name = trim($name);

        // Common replacements
        $replacements = [
            '&' => 'and',
            '  ' => ' ', // Multiple spaces to single space
        ];

        $name = str_replace(array_keys($replacements), array_values($replacements), $name);

        // Title case the name
        $name = ucwords(strtolower($name));

        // Handle special cases
        $specialCases = [
            'Dcc' => 'DCC',
            'Rpg' => 'RPG',
            'LitRpg' => 'LitRPG',
        ];

        foreach ($specialCases as $from => $to) {
            if (str_contains($name, $from)) {
                $name = str_replace($from, $to, $name);
            }
        }

        return $name;
    }
}
