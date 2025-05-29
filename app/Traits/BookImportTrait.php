<?php

namespace App\Traits;

use App\Services\FirestoreService;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use InvalidArgumentException;

trait BookImportTrait
{
    /**
     * Scan a directory and return its contents.
     *
     * @param string $path The directory path to scan
     * @return array
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
     * @param string $filePath Path to the audio file
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
            'pipe:1'  // Output to standard output
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
                list($key, $value) = explode('=', $line, 2); // Limit to 2 parts in case value also contains '='
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
     * @param string $directoryPath Relative path from BOOK_STORAGE_PATH
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
     * @param string $directoryPath The directory path to process
     * @return array{genre: array, author: array, series?: array, title: string, directory_path: string, skipped?: bool, error?: string}
     * @throws InvalidArgumentException If the directory path is invalid
     */
    public function processDirPath(string $directoryPath): array
    {
        // Initialize book array with default values
        $book = [
            'directory_path' => $directoryPath,
            'genre' => [],
            'author' => [],
            'title' => ''
        ];
        
        try {
            // Split directory path into components
            $parts = array_values(array_filter(explode('/', trim($directoryPath, '/')), 'strlen'));
            
            // Handle empty or invalid paths
            if (empty($parts)) {
                throw new InvalidArgumentException('Empty directory path');
            }
            
            // Handle VA (various artists) directories
            if (in_array('VA', $parts, true)) {
                Log::warning("Skipping VA directory: {$directoryPath}");
                return [
                    'directory_path' => $directoryPath,
                    'genre' => [],
                    'author' => [],
                    'title' => '',
                    'skipped' => true,
                    'reason' => 'VA directory'
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
            
            // If we have more than one part left, the last part is the title, rest is series
            if (count($parts) > 1) {
                $title = array_pop($parts);
                $series = array_pop($parts);
                
                // If there's still parts left, they're parent series
                if (!empty($parts)) {
                    $seriesParent = implode('/', $parts);
                    $series = $seriesParent . '/' . $series;
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
            }
            
            // Set series data if we have a series
            if (!empty($series)) {
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
     * @param string $url The URL to import the cover image from.
     * @param string|null $directoryPath The directory path to store the cover image, or null for default.
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
}
