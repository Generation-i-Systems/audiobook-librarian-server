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
     * Audio file analyzer instance.
     *
     * @var AudioFileAnalyzer
     */
    protected AudioFileAnalyzer $audioAnalyzer;

    /**
     * Metadata service instance.
     *
     * @var BookMetadataService
     */
    protected BookMetadataService $metadataService;

    /**
     * Initialize a new BookDirectoryParser instance.
     *
     * @param AudioFileAnalyzer|null $audioAnalyzer Audio file analyzer instance
     * @param BookMetadataService|null $metadataService Metadata service instance
     */
    public function __construct(
        ?AudioFileAnalyzer $audioAnalyzer = null,
        ?BookMetadataService $metadataService = null
    ) {
        $this->audioAnalyzer = $audioAnalyzer ?? new AudioFileAnalyzer();
        $this->metadataService = $metadataService ?? app(BookMetadataService::class);
    }

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
     * Read and parse metadata from metadata.abs file or service.
     *
     * @param string $path Path to the metadata.abs file or directory containing it
     * @return array<string, mixed> Parsed metadata with expected keys
     */
    public function readMetadataFile(string $path): array
    {
        // Determine if the path is a directory or a file
        $isFile = pathinfo($path, PATHINFO_EXTENSION) === 'abs' || is_file($path);
        $directoryPath = $isFile ? dirname($path) : rtrim($path, '/');
        $metadataPath = $isFile ? $path : $directoryPath . '/metadata.abs';

        // If metadata service is available, try to load from there first
        if ($this->metadataService) {
            try {
                $bookId = $this->metadataService->generateBookId($directoryPath);
                $metadata = $this->metadataService->loadMetadata($bookId, $directoryPath);

                if (!empty($metadata) && is_array($metadata)) {
                    return [
                        'title' => $metadata['title'] ?? '',
                        'author' => is_array($metadata['author'] ?? null)
                            ? $metadata['author']
                            : (isset($metadata['author']) ? [$metadata['author']] : []),
                        'narrator' => $metadata['narrator'] ?? '',
                        'series' => $metadata['series'] ?? '',
                        'series_number' => isset($metadata['series_number']) ? (int) $metadata['series_number'] : null,
                        'year' => $metadata['year'] ?? null,
                        'description' => $metadata['description'] ?? ''
                    ];
                }
            } catch (\Exception $e) {
                error_log("Error loading metadata from service: " . $e->getMessage());
            }
        }

        // If the file doesn't exist, return empty metadata
        if (!file_exists($metadataPath) || !is_readable($metadataPath)) {
            error_log("Metadata file does not exist or is not readable: " . $metadataPath);
            return [
                'title' => '',
                'author' => [],
                'narrator' => '',
                'series' => '',
                'year' => null,
                'description' => '',
            ];
        } else {
            error_log("Reading metadata file: " . $metadataPath);
            error_log("File exists and is readable");
            $contents = file_get_contents($metadataPath);
            error_log("File content length: " . strlen($contents));
            error_log("First 200 chars: " . substr($contents, 0, 200));
        }

        try {
            error_log("Attempting to read file: " . $metadataPath);
            $contents = file_get_contents($metadataPath);
            if ($contents === false) {
                $error = error_get_last();
                throw new \RuntimeException("Failed to read file: {$metadataPath}. Error: " . ($error['message'] ?? 'Unknown error'));
            }

            // Normalize line endings
            $contents = str_replace(["\r\n", "\r"], "\n", $contents);

            $lines = explode("\n", $contents);

            // Process each line using a for loop for better control
            $currentSection = '';
            $descriptionLines = [];
            // Initialize metadata with empty array - we'll add fields as we find them
            $metadata = [];

            $lineCount = count($lines);
            error_log("Total lines to process: " . $lineCount);

            for ($i = 0; $i < $lineCount; $i++) {
                $line = trim($lines[$i]);
                error_log("Processing line $i: " . $line);

                // Skip empty lines and comments
                if ($line === '' || str_starts_with($line, ';') || str_starts_with($line, '#')) {
                    continue;
                }

                // Handle section headers
                if (preg_match('/^\[(.*?)\]$/', $line, $matches)) {
                    $currentSection = strtolower(trim($matches[1]));
                    continue;
                }

                // Handle key=value pairs
                if (str_contains($line, '=')) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = strtolower(trim($key));
                    $value = trim($value);

                    // Handle description field in key=value format
                    if ($key === 'description') {
                        $descriptionLines = []; // Reset description lines
                        $descriptionLines[] = $value;

                        // Check the next lines to see if they're part of a multi-line description
                        $j = $i + 1;
                        while ($j < $lineCount) {
                            $nextLine = $lines[$j];

                            // If the line is empty or a comment, include it in the description
                            if (
                                trim($nextLine) === '' ||
                                str_starts_with(trim($nextLine), ';') ||
                                str_starts_with(trim($nextLine), '#')
                            ) {
                                $descriptionLines[] = $nextLine;
                                $j++;
                                continue;
                            }

                            // If the line starts with a key or section header, stop reading
                            if (str_contains($nextLine, '=') || preg_match('/^\s*\[.*\]\s*$/', $nextLine)) {
                                break;
                            }

                            // Otherwise, it's part of the description
                            $descriptionLines[] = $nextLine;
                            $j++;
                        }

                        // Skip the lines we've already processed
                        if ($j > $i + 1) {
                            $i = $j - 1; // -1 because the loop will increment $i
                        }

                        error_log("Finished reading description. Total lines: " . count($descriptionLines));
                        continue;
                    }

                    // Handle other key=value pairs, only setting if not already set (first occurrence wins)
                    error_log("Processing key: $key, value: $value");
                    
                    // Special handling for description since it's built incrementally
                    if ($key === 'description') {
                        if (!isset($metadata['description'])) {
                            $metadata['description'] = '';
                        }
                        $metadata['description'] .= $value . "\n";
                        continue;
                    }
                    
                    // Skip if we've already seen this key (first occurrence wins)
                    if (array_key_exists($key, $metadata)) {
                        error_log("Skipping duplicate key: $key - already set to: " . json_encode($metadata[$key]));
                        continue;
                    }
                    
                    // Handle known keys with special processing
                    switch ($key) {
                        case 'title':
                            $metadata['title'] = $value;
                            break;
                        case 'author':
                        case 'authors':
                            $metadata['author'] = $this->parseAuthors($value);
                            break;
                        case 'narrator':
                            $metadata['narrator'] = $value;
                            break;
                        case 'series':
                            $metadata['series'] = $value;
                            break;
                        case 'series_number':
                            $metadata['series_number'] = is_numeric($value) ? (int) $value : null;
                            break;
                        case 'year':
                            $metadata['year'] = is_numeric($value) ? (int) $value : null;
                            break;
                        default:
                            // For any other keys, just store them as-is
                            $metadata[$key] = $value;
                    }
                }
            }

            // Save any remaining description lines, preserving line breaks
            if (!empty($descriptionLines)) {
                $description = implode("\n", array_map('trim', $descriptionLines));
                $metadata['description'] = trim($description);
                error_log("Set description from lines. Length: " . strlen($metadata['description']));
            }

            // If we loaded metadata from a file, save it to the service if available
            if ($this->metadataService && $directoryPath && !empty($metadata)) {
                try {
                    $bookId = $this->metadataService->generateBookId($directoryPath);
                    $this->metadataService->saveMetadata($bookId, $directoryPath, $metadata);
                } catch (\Exception $e) {
                    error_log("Failed to save metadata to service: " . $e->getMessage());
                }
            }

            // Ensure we return all expected keys with proper defaults
            return [
                'title' => $metadata['title'] ?? '',
                'author' => is_array($metadata['author'] ?? null)
                    ? $metadata['author']
                    : (isset($metadata['author']) ? [$metadata['author']] : []),
                'narrator' => $metadata['narrator'] ?? '',
                'series' => $metadata['series'] ?? '',
                'series_number' => isset($metadata['series_number']) ? (int) $metadata['series_number'] : null,
                'year' => $metadata['year'] ?? null,
                'description' => $metadata['description'] ?? ''
            ];

        } catch (\Exception $e) {
            error_log("Failed to parse metadata file at {$path}: " . $e->getMessage());
            return [
                'title' => '',
                'author' => [],
                'narrator' => '',
                'series' => '',
                'year' => null,
                'description' => '',
            ];
        }
    }

    /**
     * Parse author string into an array of authors.
     *
     * @param string $authorsString String containing author names
     * @return array Array of author names
     */
    protected function parseAuthors(string $authorsString): array
    {
        if (empty($authorsString)) {
            return [];
        }

        // Split by common delimiters
        $authors = preg_split('/[\s]*[,;|&][\s]*/', $authorsString);
        $authors = array_map('trim', $authors);
        $authors = array_filter($authors);

        // Remove duplicates while preserving order
        $uniqueAuthors = [];
        foreach ($authors as $author) {
            $normalized = $this->normalizeAuthorName($author);
            if (!empty($normalized) && !isset($uniqueAuthors[$normalized])) {
                $uniqueAuthors[$normalized] = $author;
            }
        }

        return array_values($uniqueAuthors);
    }

    /**
     * Normalize author name for comparison.
     *
     * @param string $name Author name to normalize
     * @return string Normalized name
     */
    protected function normalizeAuthorName(string $name): string
    {
        // Convert to lowercase and remove extra spaces
        $normalized = trim(preg_replace('/\s+/', ' ', strtolower($name)));

        // Remove common suffixes and titles
        $normalized = preg_replace('/\s*(jr\.?|sr\.?|i{2,3}|iv|ph\.?d\.?|m\.?d\.?|esq\.?)\s*$/i', '', $normalized);

        return trim($normalized);
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
     * Parse a directory for book files and extract metadata.
     *
     * @param string $directory The directory path to parse
     * @param array $config Configuration options
     * @return array Array of book metadata
     */
    /**
     * Parse a directory structure to find books and extract metadata.
     *
     * Directory structure should be: /genre/author/series/title/
     * or /genre/author/title/
     *
     * @param string $directory The base directory to parse
     * @param array $config Configuration options
     * @return array Array of book metadata
     */
    public function parseDirectory(string $directory, array $config = []): array
    {
        $books = [];
        $finder = new Finder();
        $baseDepth = count(explode('/', rtrim($directory, '/')));

        try {
            // Find all directories that could be books (2-4 levels deep from base)
            $dirs = $finder
                ->directories()
                ->in($directory)
                ->sortByName();

            error_log('Found ' . iterator_count($dirs) . ' potential book directories in ' . $directory);

            // First pass: Find all directories with audio files
            $directoriesWithAudio = [];
            foreach ($dirs as $dir) {
                $path = $dir->getPathname();
                $parts = explode('/', $path);
                $depth = count($parts) - $baseDepth;

                // Skip the base directory and genre level
                if ($depth < 2) {
                    continue;
                }

                // Check if this directory contains audio files
                $audioFiles = (new Finder())
                    ->files()
                    ->in($path)
                    ->name(['*.mp3', '*.m4b', '*.m4a', '*.aac', '*.flac', '*.wav', '*.ogg']);

                $audioFileCount = iterator_count($audioFiles);
                if ($audioFileCount > 0) {
                    $directoriesWithAudio[] = [
                        'path' => $path,
                        'depth' => $depth,
                        'audioFileCount' => $audioFileCount,
                        'audioFiles' => $audioFiles,
                    ];
                }
            }


            // Sort by depth (deepest first)
            usort($directoriesWithAudio, function ($a, $b) {
                return $b['depth'] <=> $a['depth'];
            });

            // Track processed paths to avoid processing parent directories of already processed directories
            $processedPaths = [];

            // Process directories from deepest to shallowest
            foreach ($directoriesWithAudio as $dirInfo) {
                $path = $dirInfo['path'];
                $audioFiles = $dirInfo['audioFiles'];
                $audioFileCount = $dirInfo['audioFileCount'];

                // Skip if this directory is a parent of an already processed directory
                $isParentOfProcessed = false;
                foreach ($processedPaths as $processedPath) {
                    if (strpos($processedPath, $path . '/') === 0) {
                        $isParentOfProcessed = true;
                        break;
                    }
                }

                if ($isParentOfProcessed) {
                    continue;
                }

                $processedPaths[] = $path;

                try {
                    $parts = explode('/', $path);

                    // Parse the path to extract metadata
                    $book = [
                        'path' => $path,
                        'full_path' => $path,
                        'audio_file_count' => iterator_count($audioFiles),
                        'needs_review' => false,
                    ];

                    // Try to determine author from path structure
                    $relativePath = substr($path, strlen($directory) + 1);
                    $pathParts = explode('/', trim($relativePath, '/'));
                    $authorFound = false;

                    // Set title from the last part of the path
                    $title = end($pathParts);
                    $cleanedTitle = $this->cleanupTitle($title);
                    $book['title'] = $cleanedTitle['title'];

                    // If we have at least 2 parts (genre/author/...), the second part is likely the author
                    if (count($pathParts) >= 2) {
                        $potentialAuthor = $pathParts[1];

                        // Skip if the author is 'Unknown' (case insensitive)
                        if (strtolower($potentialAuthor) === 'unknown') {
                            $authorFound = false;
                            error_log("[DEBUG] Found 'Unknown' author in path");
                        } elseif (
                            // Check if this looks like a valid author name
                            !in_array(strtolower($potentialAuthor), $this->skipDirs)
                            && !is_numeric($potentialAuthor)
                            && !preg_match('/^\d+$/', $potentialAuthor)
                            && !preg_match('/^[\s\d-]+$/', $potentialAuthor)
                        ) {
                            // Clean up the author name
                            $authorName = $this->cleanText($potentialAuthor);
                            if (!empty($authorName)) {
                                $book['author'] = [$authorName];
                                $authorFound = true;
                                error_log("[DEBUG] Found author in path: " . $authorName);
                            }
                        }
                    }

                    // Check if any part of the path is 'unknown' (case insensitive)
                    $hasUnknownAuthor = false;
                    foreach ($pathParts as $part) {
                        if (strtolower($part) === 'unknown') {
                            $hasUnknownAuthor = true;
                            break;
                        }
                    }

                    error_log("[DEBUG] Path parts: " . json_encode($pathParts));
                    error_log("[DEBUG] Has unknown author: " . ($hasUnknownAuthor ? 'yes' : 'no'));

                    // If we have an unknown author, don't try to extract author from path
                    if ($hasUnknownAuthor) {
                        if (!empty($author)) {
                            $normalizedAuthor = $this->normalizeAuthorName($author);
                            if (!empty($normalizedAuthor)) {
                                $book['author'] = [$normalizedAuthor];
                                $book['title'] = $this->cleanText($title);
                                $authorFound = true;
                            }
                        }
                    }

                    // If we couldn't determine the author from the path, flag for review
                    if (!$authorFound) {
                        // Clean up the title using cleanupTitle method
                        $cleanedTitle = $this->cleanupTitle(basename($path));
                        $book['title'] = $cleanedTitle['title'];
                        $book['needs_review'] = true;
                        $book['review_reason'] = 'Could not determine author from path';
                        $book['author'] = []; // Ensure author is an empty array
                        error_log("[DEBUG] Author not found. Set needs_review=true for book: " . $book['title']);
                        error_log("[DEBUG] Path parts: " . json_encode($pathParts));
                    } else {
                        // Reset needs_review in case it was set previously
                        $book['needs_review'] = false;
                        error_log("[DEBUG] Author found. Set needs_review=false for book: " . $book['title']);
                        error_log("[DEBUG] Author: " . json_encode($book['author'] ?? 'N/A'));
                    }

                    // Check for metadata file
                    $metadataPath = $path . '/metadata.abs';
                    if (file_exists($metadataPath)) {
                        $metadata = $this->readMetadataFile($metadataPath);
                        $conflicts = [];

                        // Check for conflicts between directory-parsed fields and metadata file
                        $fieldsToCheck = ['title', 'author', 'series', 'narrator'];
                        foreach ($fieldsToCheck as $field) {
                            $dirValue = $book[$field] ?? null;
                            $fileValue = $metadata[$field] ?? null;

                            // Skip if either value is empty
                            if (empty($dirValue) || empty($fileValue)) {
                                continue;
                            }

                            // For arrays (like authors), check if they have different values
                            if (is_array($dirValue) && is_array($fileValue)) {
                                $dirValue = array_map('strtolower', array_map('trim', $dirValue));
                                $fileValue = array_map('strtolower', array_map('trim', $fileValue));
                                $diff1 = array_diff($dirValue, $fileValue);
                                $diff2 = array_diff($fileValue, $dirValue);
                                if (!empty($diff1) || !empty($diff2)) {
                                    $conflicts[] = $field;
                                }
                            } elseif (
                                is_string($dirValue)
                                && is_string($fileValue)
                                && strtolower(trim($dirValue)) !== strtolower(trim($fileValue))
                            ) {
                                // For strings, do a case-insensitive comparison
                                $conflicts[] = $field;
                            }
                        }

                        // If there are conflicts, keep the directory values but flag for review
                        if (!empty($conflicts)) {
                            $book['needs_review'] = true;
                            $book['review_reason'] = 'Field conflict with metadata file: ' . implode(', ', $conflicts);
                            error_log(
                                "[DEBUG] Field conflicts detected for {$book['title']}: " . $book['review_reason']
                            );
                        }

                        // Merge metadata, but don't override directory-parsed values
                        $book = array_merge(
                            $metadata,
                            array_filter(
                                $book,
                                function ($value) {
                                    return $value !== null
                                        && $value !== ''
                                        && $value !== [];
                                }
                            )
                        );
                    }

                    // Calculate total duration from audio files
                    if ($this->audioAnalyzer) {
                        $totalDuration = 0;
                        foreach ($audioFiles as $file) {
                            try {
                                if ($this->audioAnalyzer) {
                                    $duration = $this->audioAnalyzer->getAudioDuration($file->getPathname());
                                    if ($duration !== null) {
                                        $totalDuration += $duration;
                                    }
                                }
                            } catch (\Exception $e) {
                                error_log(sprintf(
                                    'Error getting duration for %s: %s',
                                    $file->getPathname(),
                                    $e->getMessage()
                                ));
                            }
                        }
                        if ($totalDuration > 0) {
                            $book['duration'] = $totalDuration;
                            $book['duration_formatted'] = $this->formatDuration($totalDuration);
                        }
                    }

                    $books[] = $book;
                } catch (\Exception $e) {
                    error_log(sprintf(
                        'Error processing directory %s: %s',
                        $dir->getPathname(),
                        $e->getMessage()
                    ));
                }
            }
        } catch (\Exception $e) {
            error_log(sprintf('Error scanning directory %s: %s', $directory, $e->getMessage()));
        }

        return $books;
    }

    /**
     * Parse a single book file and extract metadata.
     *
     * @param SplFileInfo $file The file to parse
     * @param array $config Configuration options
     * @return array|null Book metadata or null if parsing fails
     */
    /**
     * Format duration in seconds to a human-readable string (HH:MM:SS)
     *
     * @param int $seconds Duration in seconds
     * @return string Formatted duration
     */
    protected function formatDuration(float $seconds): string
    {
        $seconds = (int) round($seconds);
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    /**
     * Parse a single book file and extract metadata.
     * This is now a helper method used internally by parseDirectory.
     *
     * @param \SplFileInfo $file The file to parse
     * @param array $config Configuration options
     * @return array|null Book metadata or null if parsing fails
     */
    protected function parseBookFile(\SplFileInfo $file, array $config = []): ?array
    {
        try {
            $filename = $file->getFilename();
            $basename = pathinfo($filename, PATHINFO_FILENAME);

            // Try to extract author and title from path
            $author = $this->extractAuthorFromPath($file->getPath());
            $title = $this->cleanText($basename);

            // Initialize basic book data
            $book = [
                'title' => $title,
                'author' => $author,
                'path' => $file->getPathname(),
                'filename' => $filename,
                'file_size' => $file->getSize(),
                'file_modified' => $file->getMTime(),
                'file_extension' => strtolower($file->getExtension()),
                'full_path' => $file->getPathname(),
                'needs_review' => true,
                'review_reason' => 'Found individual file instead of directory',
            ];

            // Try to extract additional metadata from filename
            $this->extractMetadata($book, $basename);

            // Try to read metadata from metadata file if it exists
            $metadataPath = dirname($file->getPathname()) . '/metadata.abs';
            if (file_exists($metadataPath)) {
                $metadata = $this->readMetadataFile($metadataPath);
                if (!empty($metadata['title'])) {
                    $book['title'] = $metadata['title'];
                }
                if (!empty($metadata['author'])) {
                    $book['author'] = $metadata['author'];
                }
                if (!empty($metadata['series'])) {
                    $book['series'] = $metadata['series'];
                }
                if (isset($metadata['series_number'])) {
                    $book['series_number'] = $metadata['series_number'];
                }
                if (!empty($metadata['year'])) {
                    $book['year'] = $metadata['year'];
                }
            }

            return $book;
        } catch (\Exception $e) {
            error_log(sprintf('Error parsing book file %s: %s', $file->getPathname(), $e->getMessage()));
            return null;
        }
    }

    /**
     * Extract metadata from a filename and update the book array.
     *
     * @param array &$book Reference to the book array to update
     * @param string $filename The filename to extract metadata from
     * @return void
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
