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
        $baseDepth = count(explode('/', rtrim($directory, '/')));

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
                        if (is_array($bookPathInfo['series'])) {
                            $seriesName = array_key_first($bookPathInfo['series']);
                            $seriesNumber = $bookPathInfo['series'][$seriesName] ?? null;
                        } else {
                            $seriesName = $bookPathInfo['series'];
                            $seriesNumber = $bookPathInfo['seriesNumber'] ?? null;
                        }
                    }

                    if (!empty($bookPathInfo['edition'])) {
                        $edition = $bookPathInfo['edition'];
                    }

                    [$coverImage, $coverCandidates] = $this->findCoverImageCandidate($path);
                    $book = [
                        'directoryPath' => $path,
                        'directory_path' => $path,
                        'genre' => $bookPathInfo['genre'] ?? [],
                        'author' => is_array($bookPathInfo['author']) ? $bookPathInfo['author'] : [$bookPathInfo['author'] ?? 'Unknown Author'],
                        'series' => $seriesName,
                        'seriesName' => $seriesName,
                        'seriesNumber' => $seriesNumber,
                        'series_number' => $seriesNumber, // Adding series_number for backward compatibility
                        'title' => $bookPathInfo['title'] ?? '',
                        'audioFileCount' => $audioFilesData['count'],
                        'duration' => round($audioFilesData['totalDuration'], 0),
                        'durationFormatted' => is_numeric($audioFilesData['totalDuration']) ? $this->formatDuration($audioFilesData['totalDuration']) : 'N/A',
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
                    if (is_array($bookPathInfo['series'])) {
                        $seriesName = array_key_first($bookPathInfo['series']);
                        $seriesNumber = $bookPathInfo['series'][$seriesName] ?? null;
                    } else {
                        $seriesName = $bookPathInfo['series'];
                        $seriesNumber = $bookPathInfo['seriesNumber'] ?? null;
                    }
                } else {
                    $seriesName = '';
                    $seriesNumber = null;
                }

                // Find cover image for this book directory
                [$coverImage, $coverCandidates] = $this->findCoverImageCandidate($path);

                // Build $book array using trait output and audio file data
                $book = [
                    'directoryPath' => $path,
                    'genre' => $bookPathInfo['genre'] ?? [],
                    'author' => is_array($bookPathInfo['author']) ? $bookPathInfo['author'] : [$bookPathInfo['author'] ?? 'Unknown Author'],
                    'series' => $seriesName,
                    'seriesName' => $seriesName,
                    'seriesNumber' => $seriesNumber,
                    'series_number' => $seriesNumber, // Adding series_number for backward compatibility
                    'title' => $bookPathInfo['title'] ?? '',
                    'audioFileCount' => $audioFileCount,
                    'duration' => $totalDuration,
                    'durationFormatted' => is_numeric($totalDuration) ? $this->formatDuration($totalDuration) : 'N/A',
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
     * @return array<string, string> Map of series names to canonical forms
     */
    /**
     * Build a map of canonical series names (and variations) to the highest seriesNumber found for that series.
     *
     * @param  array<array<string, mixed>>  $books
     * @return array<string, float|int|null> Map of canonical/variant series names to highest seriesNumber
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
        if (empty($rootDir) || !is_string($rootDir) || !is_dir($rootDir)) {
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
                // Ensure we're comparing strings
                if (!is_string($a) || !is_string($b)) {
                    return 0;
                }

                $depthA = substr_count($a, DIRECTORY_SEPARATOR);
                $depthB = substr_count($b, DIRECTORY_SEPARATOR);

                return $depthB <=> $depthA;
            });

            $leafDirs = [];
            $processedPaths = [];

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

    // formatDuration method is already defined above

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
