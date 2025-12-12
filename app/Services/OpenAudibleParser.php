<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * OpenAudible Parser
 *
 * Parses OpenAudible's books.json format and normalizes it
 * for use with UnifiedBookImporter
 */
class OpenAudibleParser
{
    /**
     * Load and parse books.json file
     *
     * @param string $openAudiblePath Path to OpenAudible directory
     * @return array Array of books from books.json
     * @throws \Exception If file doesn't exist or is invalid
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

        return $booksData;
    }

    /**
     * Normalize OpenAudible book data for UnifiedBookImporter
     *
     * @param array $rawBookData Raw book data from books.json
     * @return array Normalized book data
     */
    public function normalizeBookData(array $rawBookData): array
    {
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

            // Genre
            'genre' => $rawBookData['genre'] ?? null,

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

            // Files (for finding audio file)
            'files' => $rawBookData['files'] ?? [],
            'filename' => $rawBookData['filename'] ?? null,

            // Raw data for reference
            '_raw' => $rawBookData,
        ];
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
        return preg_match('/\.(m4b|m4a|mp3)$/i', $path);
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
            return is_array($bookData['authors'])
                ? $bookData['authors']
                : [$bookData['authors']];
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
            return is_array($bookData['narrators'])
                ? $bookData['narrators']
                : [$bookData['narrators']];
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
}
