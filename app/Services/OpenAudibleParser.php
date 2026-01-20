<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * OpenAudible Parser
 *
 * Parses OpenAudible's books.json format and normalizes it
 * for use with BookImportService
 */
class OpenAudibleParser
{
    /**
     * Cached books data indexed by filename for fast lookup
     */
    protected ?array $booksIndex = null;

    /**
     * Path to the currently loaded books.json
     */
    protected ?string $loadedPath = null;

    protected GenreMappingService $genreMappingService;

    public function __construct(GenreMappingService $genreMappingService)
    {
        $this->genreMappingService = $genreMappingService;
    }

    /**
     * Load and parse books.json file
     */
    public function loadBooksJson(string $openAudiblePath): array
    {
        $booksJsonPath = rtrim($openAudiblePath, '/') . '/books.json';

        if (!file_exists($booksJsonPath)) {
            throw new \Exception("books.json not found: {$booksJsonPath}");
        }

        $content = file_get_contents($booksJsonPath);
        $booksData = json_decode($content, true);

        if (!is_array($booksData)) {
            throw new \Exception("Invalid books.json format");
        }

        // Build filename index for fast lookup
        $this->loadedPath = $booksJsonPath;
        $this->booksIndex = $this->buildFilenameIndex($booksData);

        return $booksData;
    }

    /**
     * Build an index of books by filename for fast lookup
     */
    protected function buildFilenameIndex(array $booksData): array
    {
        $index = [];

        foreach ($booksData as $book) {
            // Index by filename (without extension)
            if (!empty($book['filename'])) {
                $key = $this->normalizeFilename($book['filename']);
                $index[$key] = $book;
            }

            // Also index by title
            if (!empty($book['title'])) {
                $key = $this->normalizeFilename($book['title']);
                if (!isset($index[$key])) {
                    $index[$key] = $book;
                }
            }

            // Index by ASIN
            if (!empty($book['asin'])) {
                $index['asin:' . $book['asin']] = $book;
            }

            // Index by product_id
            if (!empty($book['product_id'])) {
                $index['product:' . $book['product_id']] = $book;
            }
        }

        return $index;
    }

    /**
     * Normalize filename for index lookup
     */
    protected function normalizeFilename(string $filename): string
    {
        // Remove extension
        $name = preg_replace('/\.(m4b|m4a|mp3|jpg|png)$/i', '', $filename);

        // Normalize whitespace and case
        $name = strtolower(trim((string) $name));
        $name = (string) preg_replace('/\s+/', ' ', $name);

        return $name;
    }

    /**
     * Find book metadata by audio file path
     *
     * @param string $audioFilePath Path to the audio file
     * @param string|null $openAudiblePath Optional path to OpenAudible directory to load books.json
     * @return array|null Normalized book metadata or null if not found
     */
    public function findBookByAudioFile(string $audioFilePath, ?string $openAudiblePath = null): ?array
    {
        // Load books.json if not already loaded
        if ($this->booksIndex === null && $openAudiblePath !== null) {
            try {
                $this->loadBooksJson($openAudiblePath);
            } catch (\Exception $e) {
                Log::warning('OpenAudibleParser: Failed to load books.json', [
                    'path' => $openAudiblePath,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        }

        if ($this->booksIndex === null) {
            return null;
        }

        // Get filename without path and extension
        $filename = basename($audioFilePath);
        $key = $this->normalizeFilename($filename);

        Log::debug('OpenAudibleParser: Looking up book by filename', [
            'audio_file' => $audioFilePath,
            'lookup_key' => $key,
        ]);

        $rawBook = $this->booksIndex[$key] ?? null;

        if ($rawBook === null) {
            Log::debug('OpenAudibleParser: Book not found in index', [
                'key' => $key,
                'index_size' => count($this->booksIndex),
            ]);
            return null;
        }

        Log::info('OpenAudibleParser: Found book metadata', [
            'title' => $rawBook['title'] ?? 'Unknown',
            'author' => $rawBook['author'] ?? 'Unknown',
        ]);

        return $this->normalizeBookData($rawBook);
    }

    /**
     * Detect if a path is within an OpenAudible books directory
     * and return the path to books.json if found
     *
     * @param string $path Path to check
     * @return string|null Path to OpenAudible directory (parent of books/) or null
     */
    public function detectOpenAudibleDirectory(string $path): ?string
    {
        $realPath = realpath($path);
        if ($realPath === false) {
            $realPath = $path;
        }

        // Check if we're in a /books subdirectory
        $parts = explode('/', rtrim($realPath, '/'));
        $booksIndex = array_search('books', $parts);

        if ($booksIndex !== false) {
            // Reconstruct parent path
            $parentPath = implode('/', array_slice($parts, 0, (int) $booksIndex));

            if (file_exists($parentPath . '/books.json')) {
                return $parentPath;
            }
        }

        // Check parent directory
        $parentDir = dirname($realPath);
        if (file_exists($parentDir . '/books.json')) {
            return $parentDir;
        }

        // Check grandparent (in case path is to a file in books/)
        $grandparentDir = dirname($parentDir);
        if (basename($parentDir) === 'books' && file_exists($grandparentDir . '/books.json')) {
            return $grandparentDir;
        }

        return null;
    }

    /**
     * Normalize OpenAudible book data for BookImportService
     *
     * @param array $rawBookData Raw book data from books.json
     * @return array Normalized book data
     */
    public function normalizeBookData(array $rawBookData): array
    {
        // Parse and map genre
        $mappedGenre = $this->parseAndMapGenre($rawBookData['genre'] ?? null);

        // Parse chapters
        $chapters = $this->parseChapters($rawBookData['chapters'] ?? []);

        return [
            // Basic info
            'title' => $rawBookData['title'] ?? 'Unknown Title',
            'title_short' => $rawBookData['title_short'] ?? $rawBookData['title'] ?? null,
            'description' => $rawBookData['summary'] ?? $rawBookData['description'] ?? null,
            'summary' => $rawBookData['summary'] ?? null,

            // Identifiers
            'asin' => $rawBookData['asin'] ?? $rawBookData['product_id'] ?? null,
            'product_id' => $rawBookData['product_id'] ?? null,

            // People
            'author' => $this->normalizeAuthors($rawBookData),
            'narrator' => $this->normalizeNarrators($rawBookData),
            'narrated_by' => $rawBookData['narrated_by'] ?? null,

            // Genre - keep original format for compatibility
            'genre' => $rawBookData['genre'] ?? null,
            // Mapped genre for directory organization (used by book:import)
            'mapped_genre' => $mappedGenre,
            'original_genre' => $rawBookData['genre'] ?? null,
            'all_genres' => $this->genreMappingService->extractAllGenres($rawBookData['genre'] ?? ''),

            // Chapters
            'chapters' => $chapters,

            // Series
            'series' => $rawBookData['series_name'] ?? null,
            'series_name' => $rawBookData['series_name'] ?? null,
            'series_number' => $rawBookData['series_sequence'] ?? null,
            'series_sequence' => $rawBookData['series_sequence'] ?? null,

            // Publishing info
            'release_date' => $rawBookData['release_date'] ?? null,
            'publisher' => $rawBookData['publisher'] ?? null,
            'language' => $rawBookData['language'] ?? 'en',
            'abridged' => $rawBookData['abridged'] ?? 'false',

            // Duration
            'duration' => $rawBookData['duration'] ?? null,
            'seconds' => $rawBookData['seconds'] ?? null,

            // Rating
            'rating_average' => $rawBookData['rating_average'] ?? null,
            'rating_count' => $rawBookData['rating_count'] ?? null,

            // Files (for finding audio file)
            'files' => $rawBookData['files'] ?? [],
            'filename' => $rawBookData['filename'] ?? null,

            // Flag that this is from OpenAudible (enables skip enrichment)
            'source' => 'openaudible',
            'skip_enrichment' => true,

            // Raw data for reference
            '_raw' => $rawBookData,
        ];
    }

    /**
     * Parse and map OpenAudible genre to library genre
     *
     * OpenAudible genres are colon-separated hierarchies like:
     * "Science Fiction & Fantasy:Fantasy:Dragons & Mythical Creatures"
     *
     * @param string|null $genre Raw genre string from OpenAudible
     * @return string Mapped library genre
     */
    public function parseAndMapGenre(?string $genre): string
    {
        if ($genre === null || trim($genre) === '') {
            return 'Other';
        }

        // Use the service for high-quality priority-based mapping
        return $this->genreMappingService->mapToPrimaryGenre($genre);
    }

    /**
     * Parse chapters from OpenAudible format
     *
     * @param array $rawChapters Array of chapter objects from books.json
     * @return array Normalized chapters array
     */
    public function parseChapters(array $rawChapters): array
    {
        if (empty($rawChapters)) {
            return [];
        }

        $chapters = [];

        foreach ($rawChapters as $index => $chapter) {
            if (!is_array($chapter)) {
                continue;
            }

            $title = $chapter['title'] ?? "Chapter " . ($index + 1);
            // Clean trailing whitespace/tabs from chapter titles
            $title = rtrim((string) $title);

            $chapters[] = [
                'title' => $title,
                'start_time_ms' => (int) ($chapter['start_offset_ms'] ?? 0),
                'length_ms' => (int) ($chapter['length_ms'] ?? 0),
                'start_time_sec' => (int) ($chapter['start_offset_sec'] ?? 0),
            ];
        }

        return $chapters;
    }

    /**
     * Find audio file for book
     *
     * @param array $bookData Normalized book data
     * @param string $source OpenAudible directory path
     * @param bool $includeOld Whether to check books_old directory
     * @return string|null Path to audio file or null if not found
     */
    public function findAudioFile(array $bookData, string $source, bool $includeOld = false): ?string
    {
        $source = rtrim($source, '/');

        $candidateDirectories = [
            $source . '/books',
        ];

        if ($includeOld) {
            $candidateDirectories[] = $source . '/books_old';
        }

        // Try to find audio file from metadata
        if (!empty($bookData['files']) && is_array($bookData['files'])) {
            foreach ($bookData['files'] as $fileEntry) {
                $relativePath = null;
                if (is_string($fileEntry)) {
                    $relativePath = $fileEntry;
                } elseif (is_array($fileEntry)) {
                    $relativePath = $fileEntry['path'] ?? null;
                }

                if (!is_string($relativePath) || $relativePath === '') {
                    continue;
                }

                $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
                $relativePath = preg_replace('#^books/#', '', $relativePath);
                $relativePath = preg_replace('#^books_old/#', '', $relativePath);

                foreach ($candidateDirectories as $candidateDir) {
                    $filePath = $candidateDir . '/' . $relativePath;
                    if (file_exists($filePath) && $this->isAudioFile($filePath)) {
                        return $filePath;
                    }
                }
            }
        }

        // Try filename field
        if (!empty($bookData['filename']) && is_string($bookData['filename'])) {
            $filename = ltrim(str_replace('\\', '/', $bookData['filename']), '/');
            $filename = preg_replace('#^books/#', '', $filename);
            $filename = preg_replace('#^books_old/#', '', $filename);

            foreach ($candidateDirectories as $candidateDir) {
                $filePath = $candidateDir . '/' . $filename;
                if (file_exists($filePath) && $this->isAudioFile($filePath)) {
                    return $filePath;
                }
            }
        }

        // Try to construct filename from title
        if (!empty($bookData['title']) && is_string($bookData['title'])) {
            $possibleNames = [
                $bookData['title'] . '.m4b',
                $bookData['title'] . '.m4a',
                $bookData['title'] . '.mp3',
            ];

            foreach ($possibleNames as $name) {
                foreach ($candidateDirectories as $candidateDir) {
                    $filePath = $candidateDir . '/' . $name;
                    if (file_exists($filePath)) {
                        return $filePath;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Check if file is an audio file
     */
    private function isAudioFile(string $path): bool
    {
        return preg_match('/\.(m4b|m4a|mp3)$/i', $path) === 1;
    }

    /**
     * Normalize authors from various formats
     */
    private function normalizeAuthors(array $bookData): string|array
    {
        if (!empty($bookData['author'])) {
            // Already in good format
            return $bookData['author'];
        }

        if (!empty($bookData['authors'])) {
            return is_array($bookData['authors']) ? $bookData['authors'] : [$bookData['authors']];
        }

        if (!empty($bookData['author_name'])) {
            return $bookData['author_name'];
        }

        return 'Unknown Author';
    }

    /**
     * Normalize narrators from various formats
     */
    private function normalizeNarrators(array $bookData): string|array|null
    {
        if (!empty($bookData['narrated_by'])) {
            return $bookData['narrated_by'];
        }

        if (!empty($bookData['narrator'])) {
            return $bookData['narrator'];
        }

        if (!empty($bookData['narrators'])) {
            return is_array($bookData['narrators']) ? $bookData['narrators'] : [$bookData['narrators']];
        }

        return null;
    }

    /**
     * Get book directory name from metadata
     *
     * This generates the expected directory name for the book
     * based on OpenAudible's naming conventions
     */
    public function getBookDirectoryName(array $bookData): string
    {
        $title = $bookData['title_short'] ?? $bookData['title'] ?? 'Unknown';

        // Sanitize for filesystem
        $title = preg_replace('/[<>:"|?*]/', '', $title);
        $title = preg_replace('/[\x00-\x1F\x7F]/', '', $title);
        $title = trim($title);
        $title = trim($title, '.');

        return $title;
    }

    /**
     * Check if book should be skipped based on metadata
     */
    public function shouldSkipBook(array $bookData): bool
    {
        // Skip if no title
        if (empty($bookData['title'])) {
            return true;
        }

        // Skip if explicitly marked as deleted or invalid
        if (!empty($bookData['deleted']) || !empty($bookData['invalid'])) {
            return true;
        }

        return false;
    }

    /**
     * Extract cover image URL from metadata
     */
    public function getCoverImageUrl(array $bookData): ?string
    {
        if (!empty($bookData['cover_url'])) {
            return $bookData['cover_url'];
        }

        if (!empty($bookData['image_url'])) {
            return $bookData['image_url'];
        }

        if (!empty($bookData['cover_image'])) {
            return $bookData['cover_image'];
        }

        return null;
    }

    /**
     * Parse duration from various formats
     */
    public function parseDuration(array $bookData): ?int
    {
        if (!empty($bookData['seconds'])) {
            return (int) $bookData['seconds'];
        }

        if (!empty($bookData['duration'])) {
            // Parse HH:MM:SS format
            if (is_string($bookData['duration']) && str_contains($bookData['duration'], ':')) {
                $parts = explode(':', $bookData['duration']);
                if (count($parts) === 3) {
                    return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
                }
            }

            // Already in seconds
            if (is_numeric($bookData['duration'])) {
                return (int) $bookData['duration'];
            }
        }

        return null;
    }

    /**
     * Clear the loaded books index cache
     */
    public function clearCache(): void
    {
        $this->booksIndex = null;
        $this->loadedPath = null;
    }

    /**
     * Check if books.json is loaded
     */
    public function isLoaded(): bool
    {
        return $this->booksIndex !== null;
    }

    /**
     * Get the path to the currently loaded books.json
     */
    public function getLoadedPath(): ?string
    {
        return $this->loadedPath;
    }
}
