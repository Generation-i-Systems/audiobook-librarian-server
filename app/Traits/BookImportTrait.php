<?php

namespace App\Traits;

use App\Models\Book;
use App\Models\Author;
use App\Models\Genre;
use App\Models\Series;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Log;

trait BookImportTrait
{
    private function scanDirectory($path)
    {
        $storagePath = env('BOOK_STORAGE_PATH');

        if (!$storagePath) {
            Log::error('BOOK_STORAGE_PATH is not defined in the .env file.');
            return response()->json(['message' => 'The token could not be found and is not able to search or implement a path. Please check security of the system and notify the system admin.'], 400);
        }

        $files = scandir($path);
        if ($files === false) {
            Log::error('Attempt to perform a scandir but failed to get value.');
            return response()->json(['message' => 'Attempt to get the folder or information of the folder with failure call. Please check if it is installed. Please check security of the system and notify the system admin.'], 400);
        }
        return $files;
    }

    private function extractTagData($filePath)
    {
        $storagePath = env('BOOK_STORAGE_PATH');
        if (!$storagePath) {
            Log::error('BOOK_STORAGE_PATH is not defined in the .env file.');
            return response()->json(['message' => 'The token could not be found and is not able to be located for information . Please check security of the system and notify the system admin.'], 400);
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
            $outputImage
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
    protected function findCoverImageCandidate($directoryPath)
    {
        $storagePath = env('BOOK_STORAGE_PATH');
        $dir = rtrim($storagePath, '/') . '/' . ltrim($directoryPath, '/');
        if (!is_dir($dir))
            return [null, []];
        $images = [];
        $selected = null;
        foreach (scandir($dir) as $file) {
            if ($file === '.' || $file === '..')
                continue;
            $full = $dir . '/' . $file;
            if (!is_file($full))
                continue;
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']))
                continue;
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
            if ($file->isDir())
                continue;
            $ext = strtolower($file->getExtension());
            if (in_array($ext, ['m4b', 'jpg', 'jpeg', 'png', 'gif', 'webp']) || $file->getFilename() === 'metadata.abs') {
                $bookDirs[] = $file->getPath();
            }
        }
        return array_unique($bookDirs);
    }

    private function processDirPath($directoryPath)
    {
        $book = new Book();
        $book->directory_path = $directoryPath;
        if (preg_match('#/(.*?)/(.*?)/(.*?)/([0-9.]+) (.*)#', $directoryPath, $matches)) {
            $genre = $matches[1];
            $author = $matches[2];
            $series = $matches[3];
            $seriesNumber = $matches[4];
            $title = $matches[5];
        } elseif (preg_match('#/(.*?)/(.*?)/(.*?)/(.*)#', $directoryPath, $matches)) {
            $genre = $matches[1];
            $author = $matches[2];
            $series = $matches[3];
            $title = $matches[4];
        } elseif (preg_match('#/(.*?)/(.*?)/(.*)#', $directoryPath, $matches)) {
            $genre = $matches[1];
            $author = $matches[2];
            $title = $matches[3];
        } else {
            Log::error('Invalid directory path: ' . $directoryPath);
            return $book;
        }

        $genreRec = Genre::where('name', 'like', "%{$genre}%")->first();
        $book->genre_id = $genreRec?->id;
        $book->genre = $genreRec ?: Genre::create(['name' => $genre]);

        $authorRec = Author::where('name', 'like', "%{$author}%")->first();
        $book->author = $authorRec ?: Author::create(["name" => $author]);
        $book->author_id = $authorRec?->id;
        $book->author_name = $authorRec?->name;

        if (!empty($series)) {
            $seriesRec = Series::where('name', 'like', "%{$series}%")->first();
            $book->series = $seriesRec ? $seriesRec : Series::create(["name" => $series]);
            $book->series_id = $seriesRec?->id;
            $book->series_name = $seriesRec?->name;
        }

        if (!empty($seriesNumber)) {
            $book->seriesNumber = $seriesNumber;
        }
        $book->title = $title;

        return $book;
    }

    /**
     * Download and store a remote cover image, return the local path for storage in DB.
     */
    private function importCoverImageFromUrl($url, $directoryPath = null)
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
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3');
            $contents = curl_exec($ch);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);

            if ($contents === false || !$contents) {
                Log::error("importCoverImageFromUrl error: Unable to fetch image from {$url}");
                return null;
            }
            // Determine extension
            $ext = 'jpg';
            if (strpos($contentType, 'png') !== false)
                $ext = 'png';
            elseif (strpos($contentType, 'gif') !== false)
                $ext = 'gif';
            elseif (strpos($contentType, 'jpeg') !== false)
                $ext = 'jpg';

            $filename = 'cover.' . $ext;
            $fullPath = $fullDir . '/' . $filename;
            if (file_put_contents($fullPath, $contents) === false) {
                Log::error("importCoverImageFromUrl error: Unable to write file $fullPath");
                return null;
            }

            // Return only the path relative to BOOK_STORAGE_PATH
            return (ltrim($directoryPath, '/') . '/' . $filename);
        } catch (\Exception $e) {
            Log::error('importCoverImageFromUrl error: ' . $e->getMessage());
            return null;
        }
    }

    // --- Google Books integration ---
    protected $googleBooksApiService;

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
        $results = $this->googleBooksApiService->searchBooks($query, 30);
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
            $titleLev = 100 - min(levenshtein(mb_strtolower($title), mb_strtolower($itemTitle)), 100);
            $score += $titleLev;
            // Author similarity (Levenshtein, case-insensitive)
            $authorLev = 100 - min(levenshtein(mb_strtolower($author), mb_strtolower($itemAuthors)), 100);
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
        usort($matches, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        // Only consider a close match if score is very high (stricter)
        $closeMatch = ($bestScore > 160) ? $bestMatch : null;
        return [$matches, $closeMatch];
    }
}
