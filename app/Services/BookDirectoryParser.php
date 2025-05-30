<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;
use getID3;

class BookDirectoryParser
{
    /**
     * @var AudioFileAnalyzer
     */
    protected $audioAnalyzer;

    /**
     * @param AudioFileAnalyzer $audioAnalyzer
     */
    public function __construct(AudioFileAnalyzer $audioAnalyzer = null)
    {
        $this->audioAnalyzer = $audioAnalyzer ?? new AudioFileAnalyzer();
    }
    /**
     * @var array
     */
    protected array $patterns = [
        // Pattern: [narrator] or (narrated by [narrator])
        'narrator' => '/\[([^\]]+)\](?: - |$)|(?:\(|\[)narrated by ([^)\]]+)(?:\)|\])/i',
        // Pattern: (Graphic Audio), (Illustrated), etc.
        'edition' => '/\(([^)]+)\)|\[([^\]]+)\]/',
        // Pattern: Book 1, Vol. 2, #3, etc.
        'series_number' => '/(?:book|vol(?:\.|ume)?|#|no\.?)\s*(\d+(?:\.\d+)?)|^(\d+)(?=\s*-?\s*[A-Z])/i',
        // Pattern: Series Name [1-9] - Title
        'series_title' => '/^(.+?)\s*[\[(]?\d+[\])]?\s*-\s*(.+?)(?:\s*[\[(].+?[\])])?$/',
        // Pattern: Author1, Author2 & Author3
        'multiple_authors' => '/\s*,\s*|\s+&\s+/',
        // Year pattern: (2020) or [2020]
        'year' => '/[\[\(](\d{4})[\]\)]/',
    ];

    public function parseDirectory(string $basePath, array $options = []): array
    {
        $defaultOptions = [
            'max_depth' => 10,
            'extensions' => ['mp3', 'm4b', 'm4a', 'mp4', 'ogg', 'flac', 'aac', 'wav'],
            'exclude_dirs' => ['.*', '@eaDir', 'System Volume Information', '$RECYCLE.BIN', '*@eaDir*'],
            'min_file_size' => 1024 * 100, // 100KB minimum file size
        ];

        $options = array_merge($defaultOptions, $options);
        $books = [];
        $basePath = rtrim($basePath, '/\\');

        if (!is_dir($basePath)) {
            throw new \InvalidArgumentException("Directory not found: $basePath");
        }

        // Debug: Log the base path and options
        error_log("Scanning directory: $basePath");
        error_log("Extensions: " . implode(', ', $options['extensions']));
        error_log("Min file size: " . $options['min_file_size'] . " bytes");

        $finder = new Finder();
        $finder->files()
            ->in($basePath)
            ->ignoreDotFiles(true)
            ->ignoreVCS(true)
            ->filter(function (\SplFileInfo $file) use ($options) {
                $ext = strtolower($file->getExtension());
                $isValid = in_array($ext, $options['extensions']) && 
                          $file->getSize() >= $options['min_file_size'];
                
                if ($isValid) {
                    error_log("Found valid audio file: " . $file->getPathname());
                }
                
                return $isValid;
            });

        // Group files by their parent directory
        $filesByDir = [];
        foreach ($finder as $file) {
            $dir = dirname($file->getPathname());
            if (!isset($filesByDir[$dir])) {
                $filesByDir[$dir] = [];
            }
            $filesByDir[$dir][] = $file;
        }

        // Process each directory with audio files
        foreach ($filesByDir as $dir => $files) {
            try {
                // Sort files to ensure consistent processing
                usort($files, function($a, $b) {
                    return strcmp($a->getPathname(), $b->getPathname());
                });

                $firstFile = $files[0];
                $book = $this->parseBookFile($firstFile, $basePath);
                
                if ($book) {
                    $audioInfo = $this->audioAnalyzer->getDirectoryAudioDuration($dir);
                    if ($audioInfo['file_count'] > 0) {
                        $book['duration_seconds'] = $audioInfo['total_seconds'];
                        $book['duration_formatted'] = $audioInfo['formatted'];
                        $book['audio_file_count'] = $audioInfo['file_count'];
                        $book['directory'] = $dir;
                        $books[] = $book;
                        error_log("Successfully processed book: " . $book['title']);
                    }
                }
            } catch (\Exception $e) {
                error_log("Error processing directory $dir: " . $e->getMessage());
                continue;
            }
        }

        error_log("Found " . count($books) . " books in total");
        return $books;
    }

    /**
     * Parse a book file and extract metadata.
     *
     * @param \SplFileInfo $file
     * @param string $basePath
     * @return array|null
     */
    protected function parseBookFile(\SplFileInfo $file, string $basePath): ?array
    {
        $filename = $file->getFilename();
        $path = $file->getPath();
        $relativePath = ltrim(str_replace($basePath, '', $path), '/\\');

        // Extract genre and author from path
        $pathParts = explode(DIRECTORY_SEPARATOR, $relativePath);
        $genre = count($pathParts) > 0 ? $pathParts[0] : null;
        $author = count($pathParts) > 1 ? $pathParts[1] : 'Unknown Author';

        // Get path segments and clean them
        $pathSegments = array_values(array_filter(explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR)), function($segment) {
            return !in_array(strtolower($segment), ['books', 'audiobooks', 'audio', 'litrpg']);
        }));

        // Extract author from the directory structure (should be the first segment after filtering)
        if (count($pathSegments) > 1) {
            $potentialAuthor = $pathSegments[count($pathSegments) - 3] ?? null;
            // Only use as author if it's not empty and not the same as the current directory name
            if (!empty($potentialAuthor) && strtolower($potentialAuthor) !== strtolower(basename($path))) {
                $author = $potentialAuthor;
            }
        }

        // Initialize series and title
        $series = null;
        $seriesNumber = null;
        $title = pathinfo($filename, PATHINFO_FILENAME);
        
        // Clean up the title - remove track numbers at the end (e.g., "Title 01" -> "Title")
        $title = preg_replace('/\s*\d+(?:\.\d+)?\s*$/', '', $title);
        
        // Also handle cases like "Title - 01" or "Title -01" or "Title - 4.5"
        $title = preg_replace('/\s*-\s*\d+(?:\.\d+)?\s*$/', '', $title);
        
        // Get the current directory name (where the audio files are)
        $currentDir = basename($path);
        
        // Clean up the current directory name for series detection
        $cleanDir = preg_replace('/\s*\d+(?:\.\d+)?\s*$/', '', $currentDir);
        $cleanDir = preg_replace('/\s*-\s*\d+(?:\.\d+)?\s*$/', '', $cleanDir);
        
        $parentDir = dirname($path);
        $grandparentDir = basename(dirname($parentDir));
        
        // Only attempt to extract series if the directory structure suggests it's a series
        $potentialSeriesDir = basename($parentDir);
        $isPotentialSeriesDir = (
            !is_numeric(substr($potentialSeriesDir, 0, 1)) && 
            !preg_match('/\d/', $potentialSeriesDir) &&
            $potentialSeriesDir !== '.' && 
            $potentialSeriesDir !== '..' &&
            // Make sure the series directory name is different from the author name
            strtolower($potentialSeriesDir) !== strtolower($author)
        );
        
        if ($isPotentialSeriesDir) {
            $series = $potentialSeriesDir;
            
            // Try to extract book number and title from current directory
            // Handle formats like "4.5 Title" or "0.1 Title" or ".1 Title"
            if (preg_match('/^(\d*(?:\.\d+)?)\s+(.+)$/', $currentDir, $matches)) {
                $seriesNumber = is_numeric($matches[1]) ? (float)$matches[1] : null;
                $title = trim($matches[2]);
            } 
            // Handle formats like "Title 4.5" or "Title 0.1" or "Title .1"
            elseif (preg_match('/^(.+?)\s+(\d*(?:\.\d+)?)$/', $currentDir, $matches)) {
                $title = trim($matches[1]);
                $seriesNumber = is_numeric($matches[2]) ? (float)$matches[2] : null;
            } else {
                // If no number in directory name, use the cleaned directory name as title
                $title = $cleanDir;
            }
        } 
        // Fallback to checking current directory for series info if parent dir wasn't a series
        // Handle formats like "Title Book 4.5" or "Title Vol 0.1" or "Title #.1"
        elseif (preg_match('/(.+?)\s+(?:Book|Vol(?:\.|ume)?|#)?\s*(\d*(?:\.\d+)?)$/i', $currentDir, $matches)) {
            $potentialSeries = trim($matches[1]);
            // Only use as series if it's not the same as the author and we have a valid number
            if (strtolower($potentialSeries) !== strtolower($author) && is_numeric($matches[2])) {
                $series = $potentialSeries;
                $seriesNumber = (float)$matches[2];
            }
        }
        // Check grandparent directory for series name (only if it's not the same as author)
        elseif (!empty($grandparentDir) && $grandparentDir !== '..' && $grandparentDir !== '.' && 
               strtolower($grandparentDir) !== strtolower($author)) {
            $series = $grandparentDir;
        }

        // If we still have numbers in the title, try to clean them up
        if (preg_match('/^(.+?)\s*\d+\s*$/', $title, $matches)) {
            $title = trim($matches[1]);
        }

        // Initialize book data
        $book = [
            'title' => $title,
            'author' => $author,
            'genre' => $genre,
            'path' => $relativePath,
            'filename' => $filename,
            'series' => $series,
            'series_number' => $seriesNumber,
            'narrator' => null,
            'edition' => null,
            'year' => null,
            'file_size' => $file->getSize(),
            'file_modified' => $file->getMTime(),
            'file_extension' => strtolower($file->getExtension()),
            'full_path' => $file->getPathname(),
        ];

        // Extract any additional metadata from filename
        $this->extractMetadata($book, $title);

        return $book;
    }

    /**
     * Extract metadata from text and update book array.
     *
     * @param array $book
     * @param string $text
     * @return void
     */
    protected function extractMetadata(array &$book, string $text): void
    {
        // Extract year
        if (preg_match($this->patterns['year'], $text, $matches)) {
            $book['year'] = (int)$matches[1];
            $text = preg_replace($this->patterns['year'], '', $text);
        }

        // Extract narrator
        if (preg_match($this->patterns['narrator'], $text, $matches)) {
            $book['narrator'] = !empty($matches[1]) ? $this->cleanText($matches[1]) : $this->cleanText($matches[2]);
            $text = preg_replace($this->patterns['narrator'], '', $text);
        }

        // Extract edition
        if (preg_match_all($this->patterns['edition'], $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $edition = !empty($match[1]) ? $match[1] : $match[2];
                $edition = $this->cleanText($edition);

                // Skip common non-edition terms
                $skipTerms = ['unabridged', 'abridged', 'audiobook', 'audio book', 'mp3', 'm4b', 'aac'];
                $isSkipTerm = false;
                foreach ($skipTerms as $term) {
                    if (stripos($edition, $term) !== false) {
                        $isSkipTerm = true;
                        break;
                    }
                }

                if (!$isSkipTerm) {
                    $book['edition'] = $edition;
                    break;
                }
            }
            // Remove all edition patterns
            $text = preg_replace($this->patterns['edition'], '', $text);
        }

        // Extract series and number from title
        $this->extractSeriesInfo($book, $text);

        // Clean up title
        $book['title'] = $this->cleanText($text);
    }

    /**
     * Extract series information from text and update book array.
     *
     * @param array $book
     * @param string $text
     * @return void
     */
    protected function extractSeriesInfo(array &$book, string $text): void
    {
        // Check for patterns like "Series Name 1 - Title" or "Series Name - 1 - Title"
        if (preg_match('/^(.+?)\s*-\s*(?:#?(\d+(?:\.\d+)?)\s*-\s*)?(.+)$/i', $text, $matches)) {
            $potentialSeries = trim($matches[1]);
            $potentialNumber = $matches[2] ?? null;
            $potentialTitle = trim($matches[3]);

            // If we have a number, it's more likely to be a series
            if ($potentialNumber !== null) {
                $book['series'] = $potentialSeries;
                $book['series_number'] = is_numeric($potentialNumber) ? $potentialNumber + 0 : $potentialNumber;
                $book['title'] = $potentialTitle;
                return;
            }

            // Check if this is a book directory or a series name (capitalized words)
            if (preg_match('/^[A-Z][a-z]+(?:\s+[A-Z][a-z]+)*$/', $potentialSeries)) {
                $book['series'] = $potentialSeries;
                $book['title'] = $potentialTitle;
                return;
            }
        }

        // Check for number in the text
        if (preg_match($this->patterns['series_number'], $text, $matches)) {
            $number = !empty($matches[1]) ? $matches[1] : $matches[2];
            $book['series_number'] = is_numeric($number) ? $number + 0 : $number;

            // Try to extract series name by removing the number
            $seriesText = preg_replace($this->patterns['series_number'], '', $text);
            $seriesText = $this->cleanText($seriesText);

            if (!empty($seriesText) && $seriesText !== $book['title']) {
                $book['series'] = $seriesText;
            }
        }
    }

    /**
     * Clean and normalize text by removing extra whitespace and special characters.
     *
     * @param string $text
     * @return string
     */
    protected function cleanText(string $text): string
    {
        // Replace multiple spaces with single space
        $text = preg_replace('/\s+/', ' ', $text);
        // Remove special characters from start/end
        return trim($text, " \t\n\r\0\xB0-_,;:!?()[]{}\\/");
    }
}
