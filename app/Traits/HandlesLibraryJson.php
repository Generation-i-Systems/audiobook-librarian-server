<?php

namespace App\Traits;

use App\Models\Book;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

trait HandlesLibraryJson
{
    /**
     * Set file ownership to eric:audio with 664 permissions
     *
     * @param string $filePath
     * @return void
     */
    protected function setFileOwnership(string $filePath): void
    {
        if (file_exists($filePath)) {
            @chown($filePath, 'eric');
            @chgrp($filePath, 'audio');
            @chmod($filePath, 0664);
        }
    }

    /**
     * Set directory ownership to eric:audio with 775 permissions
     *
     * @param string $dirPath
     * @return void
     */
    protected function setDirectoryOwnership(string $dirPath): void
    {
        if (is_dir($dirPath)) {
            @chown($dirPath, 'eric');
            @chgrp($dirPath, 'audio');
            @chmod($dirPath, 0775);
        }
    }

    /**
     * Generate or update librarian.json for a book
     *
     * @param Book|array $book Book model or book data array
     * @return bool True on success, false on failure
     */
    public function updateLibraryJson($book): bool
    {
        try {
            // If we have a Book model, load its relationships
            if ($book instanceof Book) {
                $book = $book->loadMissing([
                    'authors',
                    'narrators',
                    'genres',
                    'series',
                    'publisher',
                ])->toArray();
            }

            // Ensure we have the required data
            $relativePath = $book['directory_path'] ?? $book['directoryPath'] ?? null;

            if (empty($relativePath)) {
                Log::warning('Cannot generate librarian.json: Missing directory path', [
                    'book_id' => $book['id'] ?? null
                ]);
                return false;
            }

            $bookDir = Storage::disk('books')->path($relativePath);

            // Skip if directory doesn't exist
            if (!is_dir($bookDir)) {
                Log::warning('Book directory does not exist for librarian.json', [
                    'book_id' => $book['id'] ?? null,
                    'directory' => $bookDir,
                ]);
                return false;
            }

            $jsonPath = rtrim($bookDir, '/') . '/librarian.json';

            // Prepare book data for JSON
            $jsonData = $this->prepareBookDataForJson($book);

            $jsonString = json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($jsonString === false) {
                throw new \RuntimeException('Failed to encode book data to JSON: ' . json_last_error_msg());
            }

            $result = file_put_contents($jsonPath, $jsonString);

            if ($result === false) {
                throw new \RuntimeException("Failed to write JSON file: $jsonPath");
            }

            // Set file ownership to eric:audio and permissions to 664
            $this->setFileOwnership($jsonPath);

            // Update the book's last_library_json_update timestamp if we have a model
            if (isset($book['id'])) {
                Book::where('id', $book['id'])->update([
                    'last_library_json_update' => now(),
                ]);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to generate librarian.json', [
                'book_id' => $book['id'] ?? null,
                'path' => $jsonPath ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Prepare book data for JSON serialization
     * Matches the format used by BookDirectoryParser and import process
     */
    protected function prepareBookDataForJson(array $book): array
    {
        $relativePath = $book['directory_path'] ?? $book['directoryPath'] ?? null;

        $authors = [];
        if (!empty($book['authors'])) {
            foreach ($book['authors'] as $author) {
                $authors[] = is_array($author) ? ($author['name'] ?? '') : (string) $author;
            }
        }

        $narrators = [];
        if (!empty($book['narrators'])) {
            foreach ($book['narrators'] as $narrator) {
                $narrators[] = is_array($narrator) ? ($narrator['name'] ?? '') : (string) $narrator;
            }
        }

        $genres = [];
        if (!empty($book['genres'])) {
            foreach ($book['genres'] as $genre) {
                $genres[] = is_array($genre) ? ($genre['name'] ?? '') : (string) $genre;
            }
        }

        $publishers = [];
        if (!empty($book['publisher'])) {
            if (is_array($book['publisher'])) {
                $publishers[] = $book['publisher']['name'] ?? '';
            } else {
                $publishers[] = (string) $book['publisher'];
            }
        }

        $seriesData = [];
        $seriesName = '';
        $seriesNumber = null;

        if (!empty($book['series'])) {
            $series = $book['series'];

            if (is_array($series) && isset($series[0])) {
                // Multiple series - loop through all
                foreach ($series as $seriesItem) {
                    $name = $seriesItem['name'] ?? '';
                    $number = $seriesItem['pivot']['series_number'] ?? null;
                    if ($name) {
                        $seriesData[$name] = $number;
                    }
                }
                // Set first series as primary for backward compatibility
                if (!empty($series[0])) {
                    $seriesName = $series[0]['name'] ?? '';
                    $seriesNumber = $series[0]['pivot']['series_number'] ?? null;
                }
            } elseif (is_array($series) && isset($series['name'])) {
                // Single series object
                $seriesName = $series['name'];
                $seriesNumber = $series['pivot']['series_number'] ?? null;
                $seriesData[$seriesName] = $seriesNumber;
            }
        }

        $createdAt = $book['created_at'] ?? $book['createdAt'] ?? null;
        $updatedAt = $book['updated_at'] ?? $book['updatedAt'] ?? null;

        return [
            'directoryPath' => $relativePath,
            'genre' => $genres,
            'author' => $authors,
            'series' => $seriesData,
            'seriesName' => $seriesName,
            'seriesNumber' => $seriesNumber,
            'series_number' => $seriesNumber,
            'title' => $book['title'] ?? '',
            'audioFileCount' => $book['audio_file_count'] ?? $book['audioFileCount'] ?? 0,
            'duration' => $book['duration'] ?? null,
            'durationFormatted' => $book['duration_formatted'] ?? $book['durationFormatted'] ?? null,
            'fileTags' => $book['file_tags'] ?? $book['fileTags'] ?? null,
            'needsReview' => $book['needs_review'] ?? $book['needsReview'] ?? false,
            'coverImage' => $book['cover_image'] ?? $book['coverImage'] ?? null,
            'dateAdded' => $createdAt,
            'source' => $book['source'] ?? 'manual',
            'id' => $book['id'] ?? null,
            'audibleAuthors' => [],
            'audibleNarrators' => [],
            'narrator' => $narrators,
            'audibleCoverImageUrl' => null,
            'description' => $book['description'] ?? null,
            'releaseDate' => $book['release_date'] ?? $book['releaseDate'] ?? null,
            'runtime' => $book['duration'] ? round($book['duration'] / 60, 1) : null,
            'publisher' => !empty($publishers) ? $publishers[0] : null,
            'language' => $book['language'] ?? 'english',
            'audibleCoverPath' => null,
        ];
    }
}
