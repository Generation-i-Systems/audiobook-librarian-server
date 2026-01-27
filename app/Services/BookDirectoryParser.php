<?php

declare(strict_types=1);

namespace App\Services;

use App\Traits\BookImportTrait;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/**
 * Service for parsing directory structures to extract book metadata.
 *
 * @deprecated This service is deprecated and logic is merged into BookImportService.
 * Please make changes in BookImportService instead.
 */
class BookDirectoryParser
{
    use BookImportTrait;

    /**
     * Debug callback function.
     *
     * @var callable|null
     */
    protected $debugCallback = null;

    /**
     * List of genres loaded from config.
     *
     * @var array<string>
     */
    protected array $genres = [];

    /**
     * List of sub-genres to skip.
     *
     * @var array<string>
     */
    protected array $subGenres = ['VA', 'R', 'Antoologies'];

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
    ];

    /**
     * Storage root directory.
     */
    protected string $storageRoot;

    /**
     * Audio file analyzer instance.
     */
    protected AudioFileAnalyzer $audioAnalyzer;

    /**
     * Metadata service instance.
     */
    protected BookMetadataService $metadataService;

    /**
     * Initialize a new BookDirectoryParser instance.
     *
     * @param  AudioFileAnalyzer|null  $audioAnalyzer  Audio file analyzer instance
     * @param  BookMetadataService|null  $metadataService  Metadata service instance
     */
    public function __construct(
        ?AudioFileAnalyzer $audioAnalyzer = null,
        ?BookMetadataService $metadataService = null
    ) {
        $this->audioAnalyzer = $audioAnalyzer ?? new AudioFileAnalyzer();
        $this->metadataService = $metadataService ?? app(BookMetadataService::class);
        $this->storageRoot = rtrim((string) config('filesystems.disks.books.root', ''), '/');
    }

    /**
     * Resolve a path to an absolute path within BOOK_STORAGE_PATH.
     * If already absolute and exists, return as-is.
     */
    public function resolveStoragePath(string $path): string
    {
        $storageRoot = $this->storageRoot;

        // If empty, return empty
        if ($path === '') {
            return $path;
        }

        // If vfs:// (for testing), treat as absolute
        if (preg_match('#^vfs://#', $path)) {
            return $path;
        }

        // If starts with storage root, treat as absolute
        if ($storageRoot && strpos($path, $storageRoot) === 0) {
            return $path;
        }

        // If absolute path and exists, return as-is
        if (strpos($path, '/') === 0 && file_exists($path)) {
            return $path;
        }

        // Treat any other path as relative to storage root
        $relativePath = ltrim($path, '/');

        return $storageRoot . '/' . $relativePath;
    }

    /**
     * Check if a directory path looks like a multi-part book component (CD 1, Disc 1, etc).
     */
    protected function isPartDirectory(string $path): bool
    {
        $basename = basename($path);
        // Matches: CD 1, CD1, Disc 1, Disk 1, Part 1, Vol 1, Volume 1
        return (bool) preg_match('/^(CD|Disc|Disk|Part|Vol|Volume)\s*[0-9]+$/i', $basename);
    }

    /**
     * Scan a directory for multi-part book subdirectories (CDs, Discs, etc) and aggregate their audio files.
     */
    protected function scanMultipart(string $directory): array
    {
        $finder = new Finder();
        try {
            $finder->directories()->in($directory)->depth('== 0');
        } catch (\Exception $e) {
            return ['count' => 0, 'totalDuration' => 0, 'fileTags' => null, 'audioFiles' => []];
        }

        $count = 0;
        $totalDuration = 0;
        $tags = null;
        $allFiles = [];

        foreach ($finder as $subDir) {
            if ($this->isPartDirectory($subDir->getPathname())) {
                $data = $this->getAudioFiles($subDir->getPathname());
                if ($data['count'] > 0) {
                    $count += $data['count'];
                    $totalDuration += $data['totalDuration'];
                    if ($tags === null && !empty($data['fileTags'])) {
                        $tags = $data['fileTags'];
                    }
                    if (!empty($data['audioFiles'])) {
                        $allFiles = array_merge($allFiles, $data['audioFiles']);
                    }
                }
            }
        }

        return [
            'count' => $count,
            'totalDuration' => $totalDuration,
            'fileTags' => $tags,
            'audioFiles' => $allFiles,
        ];
    }

    /**
     * Extract author name from file path.
     *
     * @param  string  $path  The full path to the book file
     * @return string The extracted author name, or 'Unknown Author' if not found
     */
    public function extractAuthorFromPath(string $path): string
    {
        if (empty($path)) {
            return 'Unknown Author';
        }
        $normalizedPath = str_replace('\\', '/', $path);
        $parts = explode('/', $normalizedPath);
        $parts = array_values(array_filter($parts, function ($part) {
            return !empty($part);
        }));

        $i = 0;
        // Skip genre if present
        if (isset($parts[$i]) && in_array(strtolower($parts[$i]), array_map('strtolower', $this->genres), true)) {
            $i++;
        }
        // Skip subgenre if present
        if (isset($parts[$i]) && in_array(strtolower($parts[$i]), array_map('strtolower', $this->subGenres), true)) {
            $i++;
        }
        // The next part is the author
        if (isset($parts[$i])) {
            return $parts[$i];
        }

        // Fallback to legacy logic if genre/subgenre detection fails
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
     * Read and parse metadata from a metadata.abs file (or file at given path).
     * Only parses the file and returns normalized data. No merging or path logic.
     *
     * @param  string  $path  Path to the metadata.abs file or directory containing it
     * @return array<string, mixed> Parsed metadata with expected keys, or empty array if file not found/unreadable
     */
    public function readMetadataFile(string $path): array
    {
        $path = $this->resolveStoragePath($path);
        $isFile = pathinfo($path, PATHINFO_EXTENSION) === 'abs' || is_file($path);
        $metadataPath = $isFile ? $path : rtrim($path, '/') . '/metadata.abs';

        $fileMetadata = [];
        if (file_exists($metadataPath) && is_readable($metadataPath)) {
            try {
                $contents = file_get_contents($metadataPath);
                if ($contents !== false) {
                    $contents = str_replace(["\r\n", "\r"], "\n", $contents);
                    $lines = explode("\n", $contents);
                    $descriptionLines = [];
                    $metadata = [];
                    foreach ($lines as $line) {
                        $trimmedLine = trim($line);
                        if (
                            $trimmedLine === ''
                            || str_starts_with($trimmedLine, ';')
                            || str_starts_with($trimmedLine, '#')
                        ) {
                            continue;
                        }
                        if (str_contains($trimmedLine, '=')) {
                            if (!empty($descriptionLines)) {
                                $metadata['description'] = implode("\n", $descriptionLines);
                                $descriptionLines = [];
                            }
                            [$key, $value] = explode('=', $trimmedLine, 2);
                            $key = strtolower(trim($key));
                            $value = trim($value);
                            if ($key === 'description') {
                                $descriptionLines[] = $value;

                                continue;
                            }
                            if (array_key_exists($key, $metadata)) {
                                continue;
                            }
                            switch ($key) {
                                case 'title':
                                    $metadata['title'] = $value;
                                    break;
                                case 'author':
                                case 'authors':
                                    $metadata['author'] = $this->parseAuthors($value);
                                    break;
                                case 'narrator':
                                case 'narrators':
                                    $metadata['narrator'] = $value;
                                    break;
                                case 'series':
                                    $metadata['series'] = $value;
                                    break;
                                case 'series_number':
                                    if (is_numeric($value)) {
                                        $metadata['seriesNumber'] = str_contains((string) $value, '.') ? (float) $value : (int) $value;
                                    } else {
                                        $metadata['seriesNumber'] = null;
                                    }
                                    // Keep legacy key for backward compatibility
                                    $metadata['series_number'] = $metadata['seriesNumber'];
                                    break;
                                case 'year':
                                    $yearTrimmed = trim($value);
                                    $metadata['year'] = is_numeric($yearTrimmed) ? (int) $yearTrimmed : null;
                                    break;
                                case 'genre':
                                    $metadata['genre'] = $value;
                                    break;
                                case 'publishedyear':
                                    $publishedYearTrimmed = trim($value);
                                    $metadata['publishedYear'] = is_numeric($publishedYearTrimmed) ? (int) $publishedYearTrimmed : null;
                                    break;
                                default:
                                    $metadata[$key] = $value;
                            }

                            continue;
                        }
                        $descriptionLines[] = $line;
                    }
                    if (!empty($descriptionLines)) {
                        $metadata['description'] = implode("\n", array_map('trim', $descriptionLines));
                    }
                    $fileMetadata = [
                        'title' => array_key_exists('title', $metadata) ? $metadata['title'] : '',
                        'author' => array_key_exists('author', $metadata) ? (is_array($metadata['author']) ? $metadata['author'] : [$metadata['author']]) : [],
                        'narrator' => array_key_exists('narrator', $metadata) ? $metadata['narrator'] : '',
                        'series' => array_key_exists('series', $metadata) ? $metadata['series'] : '',
                        'seriesNumber' => array_key_exists('seriesNumber', $metadata) ? (is_numeric($metadata['seriesNumber']) ? (str_contains((string) $metadata['seriesNumber'], '.') ? (float) $metadata['seriesNumber'] : (int) $metadata['seriesNumber']) : null) : (array_key_exists('series_number', $metadata) ? (is_numeric($metadata['series_number']) ? (str_contains((string) $metadata['series_number'], '.') ? (float) $metadata['series_number'] : (int) $metadata['series_number']) : null) : (array_key_exists('seriesindex', $metadata) && is_numeric($metadata['seriesindex']) ? (str_contains((string) $metadata['seriesindex'], '.') ? (float) $metadata['seriesindex'] : (int) $metadata['seriesindex']) : null)),
                        'year' => (
                            (array_key_exists('year', $metadata) && is_numeric($metadata['year'])) ? (int) $metadata['year'] : ((array_key_exists('publishedYear', $metadata) && is_numeric($metadata['publishedYear'])) ? (int) $metadata['publishedYear'] : null)
                        ),
                        'genre' => array_key_exists('genre', $metadata) ? $metadata['genre'] : '',
                        'description' => array_key_exists('description', $metadata) ? preg_replace('/^\[description\]\s*/i', '', $metadata['description']) : '',
                    ];
                }
            } catch (\Exception $e) {
            }
        }

        return $fileMetadata;
    }

    /**
     * Parse author string into an array of authors.
     *
     * @param  string  $authorsString  String containing author names
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
     * @param  string  $name  Author name to normalize
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
     * @param  string  $text  Text to clean
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
     * Calculate total duration from audio files
     *
     * @param  string $directory  Directory path
     * @return array Total duration and first audio file metadata
     */
    public function getAudioFiles($directory): array
    {
        $audioFiles = (new Finder())
            ->files()
            ->in($directory)
            ->depth('== 0')  // Only search in the current directory, not subdirectories
            ->name(['*.mp3', '*.m4b', '*.m4a', '*.aac', '*.flac', '*.wav', '*.ogg']);

        // Convert to array to allow multiple iterations
        $audioFilesArray = iterator_to_array($audioFiles);
        $audioFileCount = count($audioFilesArray);

        $totalDuration = 0;
        if ($audioFileCount > 0) {
            foreach ($audioFilesArray as $file) {
                try {
                    $duration = $this->audioAnalyzer->getAudioDuration($file->getPathname());
                    if ($duration !== null) {
                        $totalDuration += $duration;
                    }
                } catch (\Exception $e) {
                }
            }
        }

        $tags = null;
        if ($audioFileCount > 0) {
            // Get the first audio file from the array
            $firstFile = reset($audioFilesArray);
            $audioFilePath = $firstFile->getPathname();
            $tags = $this->extractTagData($audioFilePath);
        }

        return [
            'count' => $audioFileCount,
            'audioFiles' => $audioFilesArray,
            'totalDuration' => $totalDuration,
            'fileTags' => $tags,
        ];
    }

    /**
     * Format duration in seconds to a human-readable string (HH:MM:SS)
     *
     * @param  float  $seconds  Duration in seconds
     * @return string Formatted duration
     */
    public function formatDuration(float $seconds): string
    {
        $seconds = (int) round($seconds);
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    /**
     * Parse a single book file and extract metadata.
     *
     * @param  \SplFileInfo  $file  The file to parse
     * @param  array  $config  Configuration options
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
            // Count all audio files in the directory
            $directory = $file->getPath();
            $audioExtensions = ['mp3', 'm4b', 'm4a', 'aac', 'flac', 'wav', 'ogg'];
            $audioFiles = glob($directory . '/*.{mp3,m4b,m4a,aac,flac,wav,ogg}', GLOB_BRACE);
            $audioFileCount = $audioFiles ? count($audioFiles) : 1;

            $book = [
                'title' => $title,
                'author' => [$author], // Ensure author is always an array
                'path' => $file->getPathname(),
                'filename' => $filename,
                'fileSize' => $file->getSize(),
                'fileModified' => $file->getMTime(),
                'fileExtension' => strtolower($file->getExtension()),
                'fullPath' => $file->getPathname(),
                'needsReview' => true,
                'reviewReason' => 'Found individual file instead of directory',
                'audioFileCount' => $audioFileCount,
                'audio_file_count' => $audioFileCount, // For backward compatibility
                'duration' => 0,
                'durationFormatted' => '00:00:00',
                'duration_formatted' => '00:00:00', // For backward compatibility
                'description' => '',
                'edition' => null, // Initialize edition
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
                    $book['seriesNumber'] = $metadata['series_number']; // For backward compatibility
                } elseif (isset($metadata['seriesNumber'])) {
                    $book['series_number'] = $metadata['seriesNumber']; // For backward compatibility
                    $book['seriesNumber'] = $metadata['seriesNumber'];
                }
                if (!empty($metadata['year'])) {
                    $book['year'] = $metadata['year'];
                } elseif (!empty($metadata['publishedYear'])) {
                    $book['year'] = is_numeric($metadata['publishedYear']) ? (int) $metadata['publishedYear'] : $metadata['publishedYear'];
                }
                if (isset($metadata['description'])) {
                    $book['description'] = $metadata['description'];
                }
            }

            return $book;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Parse a directory for book files and extract metadata.
     * Supports both scanning for book directories and treating the given directory as a book if it contains
     * audio files.
     *
     * @param  string  $directory  The directory path to parse
     * @param  array  $config  Configuration options
     * @return array Array of book metadata
     */
    public function parseDirectory(string $directory, array $config = []): array
    {
        $directory = $this->resolveStoragePath($directory);
        if (!is_dir($directory) || !is_readable($directory)) {
            throw new \InvalidArgumentException("Directory does not exist or is not readable: $directory");
        }
        $books = [];
        $finder = new Finder();

        try {
            // 1. If the directory itself contains audio files and looks like a book, parse it as a book directory
            $audioFilesData = $this->getAudioFiles($directory);

            // Check for multipart if no direct files
            if ($audioFilesData['count'] === 0) {
                $multipartData = $this->scanMultipart($directory);
                if ($multipartData['count'] > 0) {
                    $audioFilesData = $multipartData;
                }
            }

            if ($audioFilesData['count'] > 0) {
                // Try to parse path info as a book
                $path = trim(str_replace($this->storageRoot, '', $directory), '/');
                $bookPathInfo = $this->processDirPath($path);
                if (empty($bookPathInfo['skipped']) && empty($bookPathInfo['error'])) {
                    $seriesName = '';
                    $seriesNumber = null;
                    $edition = null;

                    if (!empty($bookPathInfo['series'])) {
                        $seriesName = (string) array_key_first($bookPathInfo['series']);
                        $seriesNumber = $bookPathInfo['series'][$seriesName] ?? null;
                    }

                    if (!empty($bookPathInfo['edition'])) {
                        $edition = $bookPathInfo['edition'];
                    }

                    [$coverImage, $coverCandidates] = $this->findCoverImageCandidate($path);
                    $book = [
                        'directoryPath' => $path,
                        'directory_path' => $path,
                        'genre' => $bookPathInfo['genre'],
                        'author' => $bookPathInfo['author'],
                        'series' => $seriesName,
                        'seriesName' => $seriesName,
                        'seriesNumber' => $seriesNumber,
                        'series_number' => $seriesNumber, // Adding series_number for backward compatibility
                        'title' => $bookPathInfo['title'],
                        'audioFileCount' => $audioFilesData['count'],
                        'duration' => round($audioFilesData['totalDuration'], 0),
                        'durationFormatted' => is_numeric($audioFilesData['totalDuration']) ? $this->formatDuration((float) $audioFilesData['totalDuration']) : 'N/A',
                        'fileTags' => $audioFilesData['fileTags'],
                        'needsReview' => false,
                        'coverImage' => $coverImage ?? null,
                        'edition' => $edition,
                    ];

                    $fileMetadata = $this->readMetadataFile($directory);
                    if (!empty($fileMetadata['title'])) {
                        $book['title'] = $fileMetadata['title'];
                    }
                    if (!empty($fileMetadata['author'])) {
                        $book['author'] = $fileMetadata['author'];
                    }
                    if (!empty($fileMetadata['genre'])) {
                        $book['genre'] = $fileMetadata['genre'];
                    }
                    if (!empty($fileMetadata['description'])) {
                        $book['description'] = $fileMetadata['description'];
                    }
                    if (!empty($fileMetadata['series'])) {
                        $book['series'] = $fileMetadata['series'];
                        $book['seriesName'] = $fileMetadata['series'];
                    }
                    if (!empty($fileMetadata['seriesNumber'])) {
                        $book['seriesNumber'] = $fileMetadata['seriesNumber'];
                        $book['series_number'] = $fileMetadata['seriesNumber'];
                    }
                    if (!empty($fileMetadata['year'])) {
                        $book['year'] = $fileMetadata['year'];
                    }
                    $books[] = $book;
                }
            }

            // 2. Find all directories that could be books (2-4 levels deep from base)
            $dirs = $finder
                ->directories()
                ->in($directory)
                ->sortByName();

            foreach ($dirs as $dir) {
                // Skip part directories - they are handled by their parent
                if ($this->isPartDirectory($dir->getPathname())) {
                    \Illuminate\Support\Facades\Log::debug("Skipping part directory: " . $dir->getPathname());
                    continue;
                }

                $path = trim(str_replace($this->storageRoot, '', $dir->getPathname()), '/');
                \Illuminate\Support\Facades\Log::debug("Processing directory: {$path}");

                $bookPathInfo = $this->processDirPath($path);
                \Illuminate\Support\Facades\Log::debug("Path info: " . json_encode($bookPathInfo));

                if (!empty($bookPathInfo['skipped'])) {
                    \Illuminate\Support\Facades\Log::debug("Skipping directory (skipped): {$path}");
                    continue;
                }
                if (!empty($bookPathInfo['error'])) {
                    \Illuminate\Support\Facades\Log::debug("Skipping directory (error): {$path}");
                    continue;
                }

                // Get audio file data for this directory
                $audioFilesData = $this->getAudioFiles($dir->getPathname());

                // Check for multipart if no direct files
                if ($audioFilesData['count'] === 0) {
                    $multipartData = $this->scanMultipart($dir->getPathname());
                    if ($multipartData['count'] > 0) {
                        $audioFilesData = $multipartData;
                    }
                }

                $audioFileCount = $audioFilesData['count'];
                $totalDuration = $audioFilesData['totalDuration'];
                $fileTags = $audioFilesData['fileTags'];

                \Illuminate\Support\Facades\Log::debug("Audio file count for {$path}: {$audioFileCount}");

                if ($audioFileCount === 0) {
                    \Illuminate\Support\Facades\Log::debug("Skipping directory (no audio files): {$path}");
                    continue;
                }

                if (!empty($bookPathInfo['series'])) {
                    $seriesName = (string) array_key_first($bookPathInfo['series']);
                    $seriesNumber = $bookPathInfo['series'][$seriesName] ?? null;
                } else {
                    $seriesName = '';
                    $seriesNumber = null;
                }

                // Find cover image for this book directory
                [$coverImage, $coverCandidates] = $this->findCoverImageCandidate($path);

                // Build $book array using trait output and audio file data
                $book = [
                    'directoryPath' => $path,
                    'genre' => $bookPathInfo['genre'],
                    'author' => $bookPathInfo['author'],
                    'series' => $seriesName,
                    'seriesName' => $seriesName,
                    'seriesNumber' => $seriesNumber,
                    'series_number' => $seriesNumber, // Adding series_number for backward compatibility
                    'title' => $bookPathInfo['title'],
                    'audioFileCount' => $audioFileCount,
                    'duration' => $totalDuration,
                    'durationFormatted' => is_numeric($totalDuration) ? $this->formatDuration((float) $totalDuration) : 'N/A',
                    'fileTags' => $fileTags,
                    'needsReview' => false,
                    'coverImage' => $coverImage ?? null,
                    'edition' => $bookPathInfo['edition'] ?? null,
                ];

                \Illuminate\Support\Facades\Log::debug("Adding book: " . json_encode($book));
                $books[] = $book;
            }
        } catch (\Exception $e) {
        }

        return $books;
    }

    /**
     * Build a map of series names to their canonical forms.
     *
     * @param  array<array<string, mixed>>  $books  Array of book data
     * @return array<string, string|null> Map of series names to canonical forms
     */
    public function buildSeriesMap(array $books): array
    {
        $seriesMap = [];
        $seriesNumbers = [];

        // Collect all seriesName => seriesNumber
        foreach ($books as $book) {
            if (!empty($book['seriesName'])) {
                $series = $book['seriesName'];
                $number = isset($book['seriesNumber']) ? $book['seriesNumber'] : null;
                $normalized = $this->normalizeSeriesName($series);
                if (
                    !isset($seriesNumbers[$normalized]) ||
                    ($number !== null && $number > $seriesNumbers[$normalized])
                ) {
                    $seriesNumbers[$normalized] = $number;
                }
            }
        }

        // Add variations for each canonical series name
        foreach ($seriesNumbers as $canonical => $seriesNumber) {
            $seriesMap[$canonical] = $seriesNumber;
            // Generate common variations
            $variations = [
                str_replace(' ', '', $canonical),
                str_replace('-', ' ', $canonical),
                str_replace(' ', '-', $canonical),
                str_replace(' and ', ' & ', $canonical),
                preg_replace('/^The /i', '', $canonical),
                'The ' . $canonical,
            ];
            foreach ($variations as $variation) {
                if ($variation !== $canonical && !isset($seriesMap[$variation])) {
                    $seriesMap[$variation] = $seriesNumber;
                }
            }
        }

        return $seriesMap;
    }

    /**
     * Remove leading track numbers and separators from a directory-derived title.
     * E.g. '01 - The Fellowship of the Ring' => 'The Fellowship of the Ring'
     */
    protected function stripLeadingNumber(string $title): string
    {
        // Remove patterns like '01 - ', '1. ', '1_', '01_', etc.
        return preg_replace('/^\d+[\s._-]+/', '', $title);
    }

    /**
     * Find leaf directories containing audio files under a root directory.
     * A leaf directory is one that contains audio files but has no subdirectories with audio files.
     *
     * @param  string  $rootDir  Root directory to search in
     * @return array Array of leaf directory paths containing audio files
     */
    protected function findLeafDirectoriesWithAudioFiles(string $rootDir): array
    {
        $this->debug("Finding leaf directories with audio files in: {$rootDir}");

        // Validate root directory
        if (empty($rootDir) || !is_dir($rootDir)) {
            $this->debug("Invalid root directory: {$rootDir}");

            return [];
        }

        try {
            // Find all directories
            $finder = new Finder();
            $finder->directories()->in($rootDir);

            // Convert to array for sorting
            $directories = [];
            foreach ($finder as $dir) {
                $directories[] = $dir->getPathname();
            }

            // Add the root directory itself
            $directories[] = $rootDir;

            // Sort directories by depth (deepest first)
            usort($directories, static function ($a, $b) {
                $depthA = substr_count($a, DIRECTORY_SEPARATOR);
                $depthB = substr_count($b, DIRECTORY_SEPARATOR);

                return $depthB <=> $depthA;
            });

            $leafDirs = [];

            // Process directories from deepest to shallowest
            foreach ($directories as $dirPath) {
                foreach ($leafDirs as $leafDir) {
                    if (strpos($dirPath, $leafDir . DIRECTORY_SEPARATOR) === 0) {
                        continue 2;
                    }
                }

                // Check if this directory contains audio files
                $audioFiles = (new Finder())
                    ->files()
                    ->in($dirPath)
                    ->name(['*.mp3', '*.m4b', '*.m4a', '*.aac', '*.flac', '*.wav', '*.ogg'])
                    ->depth('== 0');

                if (iterator_count($audioFiles) > 0) {
                    $leafDirs[] = $dirPath;
                    $this->debug("Found leaf directory with audio files: {$dirPath}");
                }
            }

            return $leafDirs;
        } catch (\Exception $e) {
            $this->debug('Error finding leaf directories: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Create book metadata from directory, path info, and audio files data.
     *
     * @param  string  $directory  Directory path
     * @param  array  $pathInfo  Path info extracted from directory path
     * @param  array  $audioFilesData  Audio files data
     * @return array|null Book metadata or null if creation fails
     */
    protected function createBookMetadata(string $directory, array $pathInfo, array $audioFilesData): ?array
    {
        try {
            $seriesName = '';
            $seriesNumber = null;

            if (!empty($pathInfo['series']) && is_array($pathInfo['series'])) {
                $seriesName = array_key_first($pathInfo['series']);
                $seriesNumber = $pathInfo['series'][$seriesName] ?? null;
            }

            // Get relative path
            $path = trim(str_replace($this->storageRoot, '', $directory), '/');

            // Find cover image
            [$coverImage, $coverCandidates] = $this->findCoverImageCandidate($path);

            // Build book metadata
            $book = [
                'directoryPath' => $path,
                'genre' => $pathInfo['genre'] ?? [],
                'author' => $pathInfo['author'] ?? [],
                'series' => $pathInfo['series'] ?? null,
                'seriesName' => $seriesName,
                'seriesNumber' => $seriesNumber,
                'series_number' => $seriesNumber, // Adding series_number for backward compatibility
                'title' => $pathInfo['title'] ?? '',
                'audioFileCount' => $audioFilesData['count'],
                'duration' => round($audioFilesData['totalDuration'] ?? 0, 0),
                'durationFormatted' => is_numeric($audioFilesData['totalDuration'] ?? null) ? $this->formatDuration($audioFilesData['totalDuration']) : 'N/A',
                'fileTags' => $audioFilesData['fileTags'] ?? null,
                'needsReview' => false,
                'coverImage' => $coverImage ?? null,
            ];

            return $book;
        } catch (\Exception $e) {
            $this->debug('Error creating book metadata: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Set a debug callback function.
     *
     * @param  callable|null  $callback  Debug callback function
     */
    public function setDebugCallback(?callable $callback): void
    {
        $this->debugCallback = $callback;
    }

    /**
     * Output debug message via callback if set.
     *
     * @param  string  $message  Debug message
     */
    protected function debug(string $message): void
    {
        if ($this->debugCallback !== null) {
            call_user_func($this->debugCallback, $message);
        }
    }
}
