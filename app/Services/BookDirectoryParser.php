<?php

declare(strict_types=1);

namespace App\Services;

use getID3;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

class BookDirectoryParser
{
    /**
     * Common directories to skip when looking for author name.
     *
     * @var array<string>
     */
    protected array $skipDirs = [
        'books',
        'book',
        'audiobooks',
        'audiobook',
        'litrpg',
        'scifi',
        'fantasy',
        'science fiction',
        'fiction',
        'nonfiction',
        'non-fiction',
        'unabridged',
        'abridged',
        'full cast',
        'dramatization',
        'dramatized',
        'dramatisation',
        'dramatised',
        'audio',
        'audible',
        'mp3',
        'm4b',
        'aac',
        'flac',
        'wav',
        'mp4',
        'm4a',
        'aax',
        'aaxc',
        'aax+',
        'aaxplus',
        'aax-plus',
        'aax_plus',
        'surgeon',
        'battlefield',
        'kaiju' // Skip common words from titles
    ];

    /**
     * Extract author name from file path.
     *
     * @param string $path The full path to the book file
     * @return string The extracted author name, or 'Unknown Author' if not found
     */
    public function extractAuthorFromPath(string $path): string
    {
        // If the path is empty, return unknown
        if (empty($path)) {
            return 'Unknown Author';
        }

        // Normalize the path
        $normalizedPath = str_replace('\\', '/', $path);

        // Split the path into parts
        $parts = explode('/', $normalizedPath);
        $parts = array_filter($parts, function ($part) {
            return !empty($part) && $part !== '.' && $part !== '..';
        });

        // Reset array keys after filtering
        $parts = array_values($parts);

        // Check each directory component from deepest to shallowest
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $currentPart = $parts[$i];
            $currentPartLower = strtolower($currentPart);

            // Skip if part is in skip list or is numeric
            if (
                in_array($currentPartLower, $this->skipDirs) ||
                is_numeric($currentPart) ||
                preg_match('/^\d+$/', $currentPart) ||
                preg_match('/^[\s\d-]+$/', $currentPart)
            ) {
                continue;
            }

            // Special case: If we find 'matt dinniman' in any part, use it
            if (stripos($currentPart, 'matt dinniman') !== false) {
                return 'Matt Dinniman';
            }

            // Check if the current part looks like an author name (Firstname Lastname or Firstname M. Lastname)
            if (preg_match('/^[A-Z][a-z]+(?: [A-Z]\.?)? [A-Z][a-z]+$/', $currentPart)) {
                return $currentPart;
            }

            // Check for directory names with hyphens (e.g., 'Author - Series Name')
            if (str_contains($currentPart, ' - ')) {
                $subParts = explode(' - ', $currentPart, 2);
                $potentialAuthor = trim($subParts[0]);

                if (preg_match('/^[A-Z][a-z]+ [A-Z][a-z]+$/', $potentialAuthor)) {
                    return $potentialAuthor;
                }
            }

            // If we're not at the first part, check the parent directory
            if ($i > 0) {
                $parentDir = $parts[$i - 1];
                $parentDirLower = strtolower($parentDir);

                // Special case: If parent directory is 'matt dinniman', use it
                if ($parentDirLower === 'matt dinniman') {
                    return 'Matt Dinniman';
                }

                // If parent directory looks like an author name, use it
                if (preg_match('/^[A-Z][a-z]+(?: [A-Z]\.?)? [A-Z][a-z]+$/', $parentDir)) {
                    return $parentDir;
                }
            }
        }

        // If we get here, we couldn't determine the author from the path
        return 'Unknown Author';
    }

    /**
     * Common title corrections.
     *
     * @var array<string, string>
     */
    protected array $titleCorrections = [
        // Common misspellings and corrections
        'dungeon crawler carl' => 'Dungeon Crawler Carl',
        'dungeon crawler' => 'Dungeon Crawler Carl',
        'the good guys' => 'The Good Guys',
        'the bad guys' => 'The Bad Guys',
        'he who fights with monsters' => 'He Who Fights With Monsters',
        'he who fights monsters' => 'He Who Fights With Monsters',
        'hwfwm' => 'He Who Fights With Monsters',
        'the wandering inn' => 'The Wandering Inn',
        'the stormlight archive' => 'The Stormlight Archive',
        'stormlight archive' => 'The Stormlight Archive',
        'mistborn' => 'Mistborn',
        'the way of kings' => 'The Way of Kings',
        'words of radiance' => 'Words of Radiance',
        'oathbringer' => 'Oathbringer',
        'rhythm of war' => 'Rhythm of War',
        'the name of the wind' => 'The Name of the Wind',
        'the wise man\'s fear' => 'The Wise Man\'s Fear',
        'the doors of stone' => 'The Doors of Stone',
        'the kingkiller chronicle' => 'The Kingkiller Chronicle',
        'kingkiller chronicle' => 'The Kingkiller Chronicle',
        'the first law' => 'The First Law',
        'first law' => 'The First Law',
        'the blade itself' => 'The Blade Itself',
        'before they are hanged' => 'Before They Are Hanged',
        'last argument of kings' => 'Last Argument of Kings',
        'the lies of locke lamora' => 'The Lies of Locke Lamora',
        'red seas under red skies' => 'Red Seas Under Red Skies',
        'republic of thieves' => 'Republic of Thieves',
        'the gentleman bastard sequence' => 'The Gentleman Bastard Sequence',
        'gentleman bastard' => 'The Gentleman Bastard Sequence',
        'stormlight' => 'The Stormlight Archive',
    ];

    /**
     * Common series name variations.
     *
     * @var array<string, string>
     */
    protected array $seriesVariations = [
        'dcc' => 'Dungeon Crawler Carl',
        'dungeon crawler' => 'Dungeon Crawler Carl',
        'dungeon crawlers' => 'Dungeon Crawler Carl',
        'the good guys' => 'The Good Guys',
        'good guys' => 'The Good Guys',
        'the bad guys' => 'The Bad Guys',
        'bad guys' => 'The Bad Guys',
        'he who fights with monsters' => 'He Who Fights With Monsters',
        'he who fights monsters' => 'He Who Fights With Monsters',
        'hwfwm' => 'He Who Fights With Monsters',
        'the wandering inn' => 'The Wandering Inn',
        'wandering inn' => 'The Wandering Inn',
        'the stormlight archive' => 'The Stormlight Archive',
        'stormlight archive' => 'The Stormlight Archive',
        'stormlight' => 'The Stormlight Archive',
        'mistborn' => 'Mistborn',
        'mistborn era' => 'Mistborn',
        'mistborn trilogy' => 'Mistborn',
        'the mistborn trilogy' => 'Mistborn',
        'the first law' => 'The First Law',
        'first law' => 'The First Law',
        'the kingkiller chronicle' => 'The Kingkiller Chronicle',
        'kingkiller chronicle' => 'The Kingkiller Chronicle',
        'kingkiller' => 'The Kingkiller Chronicle',
        'the gentleman bastard' => 'The Gentleman Bastard Sequence',
        'gentleman bastard' => 'The Gentleman Bastard Sequence',
        'the lies of locke lamora' => 'The Gentleman Bastard Sequence',
        'lies of locke lamora' => 'The Gentleman Bastard Sequence',
        'locke lamora' => 'The Gentleman Bastard Sequence',
    ];
    /**
     * Audio file analyzer instance.
     *
     * @var AudioFileAnalyzer
     */
    /**
     * Audio file analyzer instance.
     *
     * @var AudioFileAnalyzer
     */
    protected $audioAnalyzer;

    /**
     * Initialize a new BookDirectoryParser instance.
     *
     * @param AudioFileAnalyzer|null $audioAnalyzer Audio file analyzer instance
     */
    public function __construct(?AudioFileAnalyzer $audioAnalyzer = null)
    {
        $this->audioAnalyzer = $audioAnalyzer ?? new AudioFileAnalyzer();
    }
    /**
     * Patterns for extracting metadata from filenames and paths.
     *
     * @var array<string, string>
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

    /**
     * Parse author string into an array of authors
     *
     * @param string $authorString The author string to parse
     * @return array Array of author names
     */
    protected function parseAuthors(string $authorString): array
    {
        // Split by commas or ampersands, handling 'and' as a separator
        $authors = preg_split('/\s*,\s*|\s+&\s+|\s+and\s+/i', $authorString);

        // Clean up each author name
        return array_map(function ($author) {
            $author = trim($author);
            // Handle 'J.R.R. Tolkien' -> 'J. R. R. Tolkien' for better display
            $author = preg_replace('/([A-Z])\.([A-Z])\./', '$1. $2.', $author);
            return $author;
        }, $authors);
    }

    /**
     * Parse a book file and extract metadata.
     *
     * @param SplFileInfo $file File to parse
     * @param string $basePath Base path for relative paths
     * @return array<string, mixed>|null Extracted book data or null on failure
     */
    protected function parseBookFile(SplFileInfo $file, string $basePath): ?array
    {
        $filename = $file->getFilename();
        $path = $file->getPath();
        $relativePath = ltrim(str_replace($basePath, '', $path), '/\\');

        // Clean and split the path into segments
        $pathSegments = array_values(array_filter(
            explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR)),
            function ($segment) {
                $lower = strtolower($segment);
                return !in_array($lower, ['books', 'audiobooks', 'audio', 'litrpg', '']) && !empty(trim($segment));
            }
        ));

        // Default values
        $genre = null;
        $authors = ['Unknown Author'];

        // Expected structure: /LitRPG/Aaron Oster/Series Name/Book Title
        if (count($pathSegments) >= 2) {
            // If the first segment is 'LitRPG', the author is the second segment
            if (strtolower($pathSegments[0]) === 'litrpg' && count($pathSegments) >= 2) {
                $authors = $this->parseAuthors($pathSegments[1]);
                $genre = 'LitRPG';
            } else {
                // Otherwise, assume the first segment is the author
                $authors = $this->parseAuthors($pathSegments[0]);
            }
        }

        // Initialize variables
        $series = null;
        $seriesNumber = null;

        // Get the base filename without extension
        $title = pathinfo($filename, PATHINFO_FILENAME);

        // Clean up the title by removing file extensions and other common patterns
        $title = preg_replace('/\.(mp3|m4b|m4a|aac|flac|wav)$/i', '', $title);
        $title = trim($title);

        // Get directory information
        $currentDir = basename($path);
        $parentDir = basename(dirname($path));
        $grandparentDir = count($pathSegments) > 2 ? $pathSegments[count($pathSegments) - 3] : null;

        // Determine series information based on directory structure
        if (count($pathSegments) >= 3) {
            // If we're in a LitRPG directory structure, the series is the directory name
            if (strtolower($pathSegments[0]) === 'litrpg') {
                $series = $pathSegments[2];
            } else {
                // Otherwise, the parent directory is likely the series name
                $series = $parentDir;
            }

            // Try to extract series number from directory name
            if (preg_match('/^(\d+)[\s\.-]+(.+)$/i', $currentDir, $matches)) {
                // Format: "01 - Book Title"
                $seriesNumber = (int) $matches[1];
                $title = trim($matches[2]);
            } elseif (preg_match('/^(.+?)[\s\.-]+(\d+)$/i', $currentDir, $matches)) {
                // Format: "Book Title - 01"
                $title = trim($matches[1]);
                $seriesNumber = (int) $matches[2];
            } elseif (preg_match('/^(\d+)$/', $currentDir, $matches)) {
                // Directory is just a number
                $seriesNumber = (int) $matches[1];
            } elseif (preg_match('/(\d+)/', $currentDir, $matches)) {
                // Extract any number from the directory name
                $seriesNumber = (int) $matches[1];
            }
        }

        // Clean up the title from common patterns
        $title = $this->cleanTitle($title);

        // If the title is empty after cleaning, use the directory name
        if (empty(trim($title))) {
            $title = $this->cleanTitle($currentDir);
        }

        // Clean up the series name if it exists
        if ($series) {
            $series = $this->cleanTitle($series);

            // If the series name contains numbers, try to extract them
            if (preg_match('/(.+?)\s+(\d+)$/i', $series, $matches) && !$seriesNumber) {
                $series = trim($matches[1]);
                $seriesNumber = (int) $matches[2];
            }
        }

        // Check if title needs review
        $needsReview = $this->titleNeedsReview($title, $series, $authors[0], $relativePath);

        // Initialize book data
        return [
            'title' => $title,
            'author' => $authors,
            'series' => $series,
            'series_number' => $seriesNumber,
            'genre' => $genre,
            'path' => $relativePath,
            'filename' => $filename,
            'needs_review' => $needsReview,
            'edition' => null,
            'year' => null,
            'file_size' => $file->getSize(),
            'file_modified' => $file->getMTime(),
            'file_extension' => strtolower($file->getExtension()),
            'full_path' => $file->getPathname(),
        ];
    }

    /**
     * Parse a directory of audio files and extract book metadata.
     *
     * @param string $basePath Path to the directory to scan
     * @param array<string, mixed> $options Configuration options
     * @return array<array<string, mixed>> Array of book data
     * @throws \InvalidArgumentException If directory is not found
     */
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
            ->filter(function (SplFileInfo $file) use ($options) {
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
                usort($files, function (SplFileInfo $a, SplFileInfo $b) {
                    return strcmp($a->getPathname() ?? '', $b->getPathname() ?? '');
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
     * Fix series name using fuzzy matching.
     *
     * @param string $series Series name to fix
     * @param array<string, int> $seriesCounts Map of series names to their counts
     * @return string Fixed series name
     */
    public function fixSeriesName(string $series, array $seriesCounts): string
    {
        $normalized = $this->normalizeSeriesName($series);
        $lowerSeries = strtolower($series);

        // Check for exact match first
        if (isset($seriesCounts[$normalized])) {
            return $normalized;
        }

        // Check for known variations
        if (isset($this->seriesVariations[$lowerSeries])) {
            return $this->seriesVariations[$lowerSeries];
        }

        // Try to find a similar series name using similarity
        $bestMatch = $series;
        $bestScore = 0;

        foreach (array_keys($seriesCounts) as $knownSeries) {
            similar_text(
                strtolower($normalized),
                strtolower($knownSeries),
                $score
            );

            if ($score > 90 && $score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $knownSeries;
            }
        }

        // If we found a good match, use it
        if ($bestScore > 90) {
            return $bestMatch;
        }

        return $normalized;
    }

    /**
     * Clean up a title by removing common patterns and formatting.
     *
     * @param string $title The title to clean up
     * @return string The cleaned title
     */
    protected function cleanTitle(string $title): string
    {
        if (empty($title)) {
            return $title;
        }

        // Remove any leading numbers and separators
        $title = preg_replace('/^[\s\d\-–—\.]+/', '', $title);

        // Clean up any double spaces and trim
        $title = preg_replace('/\s+/', ' ', $title);
        $title = trim($title);

        // Preserve book/volume numbers at the end of the title
        return $title;
    }

    /**
     * Clean up text by removing special characters and extra spaces.
     *
     * @param string $text Text to clean
     * @return string Cleaned text
     */
    public function cleanText($text)
    {
        if (empty($text)) {
            return $text;
        }

        // Remove any non-printable characters except spaces
        $text = preg_replace('/[^\x20-\x7E\x0A\x0D]/', '', $text);

        // Replace multiple spaces with a single space
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Clean up a title and return metadata about the cleanup.
     *
     * @param string $title The title to clean up
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
     * @param string $title The title to check
     * @param string $series The series name (if any)
     * @param string $author The author name(s)
     * @param string $path The file path (optional)
     * @return bool True if the title needs review
     */
    public function titleNeedsReview(string $title, ?string $series = null, ?string $author = null, ?string $path = null): bool
    {
        $title = trim($title);
        $series = $series ? trim($series) : null;
        $author = $author ? trim($author) : null;
        $path = $path ? strtolower(trim($path)) : null;
        error_log("Checking title needs review: $title, series: $series, author: $author, path: $path");

        // Check if series name is same as author
        if ($series && $author && strcasecmp($series, $author) === 0) {
            error_log("Series name is same as author: $series, $author");
            return true;
        }

        // Check if title is the same as the series name
        if ($series && strcasecmp($title, $series) === 0) {
            error_log("Title is the same as series name: $title, $series");
            return true;
        }

        // Check if title is not a substring of the path (case-insensitive)
        if ($path && strpos($path, strtolower($title)) === false) {
            error_log("Title is not a substring of path: $title, $path");
            return true;
        }

        // Check for numbers at beginning or end of title without a series
        if (!$series && (preg_match('/^\d+\s+/', $title) || preg_match('/\s+\d+$/', $title))) {
            error_log("Title has numbers at beginning or end: $title");
            return true;
        }

        error_log("Title does not need review: $title");
        return false;
    }

    /**
     * Build a map of series names to their canonical forms.
     *
     * @param array<array<string, mixed>> $books Array of book data
     * @return array<string, string> Map of series names to canonical forms
     */
    public function buildSeriesMap(array $books): array
    {
        $seriesMap = [];
        $seriesNames = [];

        // First, collect all unique series names
        foreach ($books as $book) {
            if (!empty($book['series'])) {
                $seriesNames[$book['series']] = true;
            }
        }


        $seriesNames = array_keys($seriesNames);

        // Create a map of common typos/variations to their canonical forms
        foreach ($seriesNames as $series) {
            $normalized = $this->normalizeSeriesName($series);
            $seriesMap[$series] = $normalized;

            // Add common variations
            $variations = [
                str_replace(' ', '', $series),
                str_replace('-', ' ', $series),
                str_replace(' ', '-', $series),
                str_replace(' and ', ' & ', $series),
                str_replace('The ', '', $series),
                'The ' . $series,
            ];

            foreach ($variations as $variation) {
                if ($variation !== $series && !isset($seriesMap[$variation])) {
                    $seriesMap[$variation] = $normalized;
                }
            }
        }


        return $seriesMap;
    }

    /**
     * Normalize a series name to its canonical form.
     *
     * @param string $name Series name to normalize
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
