<?php

namespace App\Traits;

use App\Models\Book;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

trait HandlesLibraryJson
{
    /**
     * Set file ownership and permissions using SUID tool if available, otherwise native PHP
     *
     * @param string $filePath
     * @return void
     */
    protected function setFileOwnership(string $filePath): void
    {
        $this->setBatchOwnership([$filePath]);
    }

    /**
     * Set directory ownership and permissions using SUID tool if available, otherwise native PHP
     *
     * @param string $dirPath
     * @return void
     */
    protected function setDirectoryOwnership(string $dirPath): void
    {
        $this->setBatchOwnership([$dirPath]);
    }

    /**
     * Set ownership and permissions for multiple paths at once
     *
     * @param array<string> $paths
     * @return void
     */
    protected function setBatchOwnership(array $paths): void
    {
        $validPaths = array_filter($paths, fn ($p) => !empty($p) && (file_exists($p) || is_dir($p)));
        if (empty($validPaths)) {
            return;
        }

        // Skip fix_perms during testing to avoid security errors with test storage paths
        $isTesting = app()->environment('testing') || app()->runningUnitTests();

        $fixPermsTool = base_path('scripts/fix_perms');
        if (!$isTesting && file_exists($fixPermsTool) && is_executable($fixPermsTool)) {
            $bookRoot = rtrim(config('app.book_root', '/media/audiobooks/books'), '/');
            $storageRoot = rtrim(config('filesystems.disks.books.root', ''), '/');
            $args = [];

            foreach ($validPaths as $path) {
                $pathArg = $path;
                if (strpos($path, $bookRoot) === 0) {
                    $pathArg = ltrim(substr($path, strlen($bookRoot)), '/');
                } elseif ($storageRoot && strpos($path, $storageRoot) === 0) {
                    $pathArg = ltrim(substr($path, strlen($storageRoot)), '/');
                }
                $args[] = escapeshellarg($pathArg);
            }

            @exec($fixPermsTool . ' ' . implode(' ', $args));
        } else {
            foreach ($validPaths as $path) {
                @chmod($path, is_dir($path) ? 0775 : 0664);
            }
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
                $book = $book->refresh()->load([
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

            if (!is_dir($bookDir)) {
                Log::debug('Skipping librarian.json — directory does not exist yet (files not yet moved)', [
                    'book_id' => $book['id'] ?? null,
                    'directory' => $bookDir,
                ]);
                return false;
            }

            // Skip if directory has no audio files - a book should never have only librarian.json
            $audioExtensions = 'mp3,m4a,m4b,flac,ogg,opus,aac,wav,wma,mp4';
            $audioFiles = glob($bookDir . '/*.{' . $audioExtensions . '}', GLOB_BRACE);
            $audioFiles = is_array($audioFiles) ? array_filter($audioFiles, 'is_file') : [];

            // Check subdirectories (CD 1, Disc 1, etc.) if no top-level audio files
            if (empty($audioFiles)) {
                $subdirs = glob($bookDir . '/*', GLOB_ONLYDIR);
                if (is_array($subdirs)) {
                    foreach ($subdirs as $subdir) {
                        $subAudioFiles = glob($subdir . '/*.{' . $audioExtensions . '}', GLOB_BRACE);
                        if (is_array($subAudioFiles) && array_filter($subAudioFiles, 'is_file')) {
                            $audioFiles = $subAudioFiles;
                            break;
                        }
                    }
                }
            }

            if (empty($audioFiles)) {
                Log::warning('Book directory has no audio files for librarian.json', [
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
        $authorNames = [];
        if (!empty($book['authors'])) {
            foreach ($book['authors'] as $author) {
                if (is_array($author)) {
                    $authors[] = [
                        'id' => $author['id'] ?? null,
                        'name' => $author['name'] ?? '',
                    ];
                    $authorNames[] = $author['name'] ?? '';
                } else {
                    $authorNames[] = (string) $author;
                }
            }
        }

        $narrators = [];
        $narratorNames = [];
        if (!empty($book['narrators'])) {
            foreach ($book['narrators'] as $narrator) {
                if (is_array($narrator)) {
                    $narrators[] = [
                        'id' => $narrator['id'] ?? null,
                        'name' => $narrator['name'] ?? '',
                    ];
                    $narratorNames[] = $narrator['name'] ?? '';
                } else {
                    $narratorNames[] = (string) $narrator;
                }
            }
        }

        $genres = [];
        $genreNames = [];
        if (!empty($book['genres'])) {
            foreach ($book['genres'] as $genre) {
                if (is_array($genre)) {
                    $genres[] = [
                        'id' => $genre['id'] ?? null,
                        'name' => $genre['name'] ?? '',
                    ];
                    $genreNames[] = $genre['name'] ?? '';
                } else {
                    $genreNames[] = (string) $genre;
                }
            }
        }

        $publisherData = null;
        $publisherName = null;
        if (!empty($book['publisher'])) {
            if (is_array($book['publisher'])) {
                $publisherData = [
                    'id' => $book['publisher']['id'] ?? null,
                    'name' => $book['publisher']['name'] ?? '',
                ];
                $publisherName = $book['publisher']['name'] ?? '';
            } else {
                $publisherName = (string) $book['publisher'];
            }
        }

        $seriesData = null;
        $seriesName = '';
        $seriesNumber = null;
        $seriesMap = [];

        if (!empty($book['series'])) {
            $series = $book['series'];

            if (is_array($series) && isset($series[0])) {
                // Multiple series - use first one as primary
                $firstSeries = $series[0];
                $seriesData = [
                    'id' => $firstSeries['id'] ?? null,
                    'name' => $firstSeries['name'] ?? '',
                    'is_collection' => $firstSeries['is_collection'] ?? $firstSeries['isCollection'] ?? false,
                ];
                $seriesName = $firstSeries['name'] ?? '';
                $seriesNumber = $firstSeries['pivot']['series_number'] ?? null;

                // Build series map for backward compatibility
                foreach ($series as $seriesItem) {
                    $name = $seriesItem['name'] ?? '';
                    $number = $seriesItem['pivot']['series_number'] ?? null;
                    if ($name) {
                        $seriesMap[$name] = $number;
                    }
                }
            } elseif (is_array($series) && isset($series['name'])) {
                // Single series object
                $seriesData = [
                    'id' => $series['id'] ?? null,
                    /** @phpstan-ignore-next-line nullCoalesce.offset */
                    'name' => $series['name'] ?? '',
                    'is_collection' => $series['is_collection'] ?? $series['isCollection'] ?? false,
                ];
                $seriesName = $series['name'];
                $seriesNumber = $series['pivot']['series_number'] ?? null;
                $seriesMap[$seriesName] = $seriesNumber;
            }
        }

        $createdAt = $book['created_at'] ?? $book['createdAt'] ?? null;
        $updatedAt = $book['updated_at'] ?? $book['updatedAt'] ?? null;

        // Handle chapters (from OpenAudible or other sources)
        $chapters = $book['chapters'] ?? [];

        return [
            'id' => $book['id'] ?? null,
            'title' => $book['title'] ?? '',
            'description' => $book['description'] ?? null,
            'isbn' => $book['isbn'] ?? null,
            'asin' => $book['asin'] ?? null,
            'release_date' => $book['release_date'] ?? $book['releaseDate'] ?? null,
            'language' => $book['language'] ?? 'english',
            'duration' => $book['duration'] ?? null,
            'cover_image' => $book['cover_image'] ?? $book['coverImage'] ?? null,
            'directoryPath' => $relativePath,
            'audioFileCount' => $book['audio_file_count'] ?? $book['audioFileCount'] ?? 0,
            'durationFormatted' => $book['duration_formatted'] ?? $book['durationFormatted'] ?? null,
            'needsReview' => $book['needs_review'] ?? $book['needsReview'] ?? false,
            'dateAdded' => $createdAt,
            'source' => $book['source'] ?? 'manual',
            'fileTags' => $book['file_tags'] ?? $book['fileTags'] ?? null,
            'runtime' => is_numeric($book['duration'] ?? null) ? round(((float) $book['duration']) / 60, 1) : null,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,

            // Chapters from OpenAudible (or other sources)
            'chapters' => $chapters,

            // New format with full objects
            'authors' => $authors,
            'narrators' => $narrators,
            'genres' => $genres,
            'series' => $seriesData,
            'publisher' => $publisherData,

            // Legacy format for backward compatibility
            'author' => !empty($authorNames) ? $authorNames : [],
            'narrator' => !empty($narratorNames) ? $narratorNames : [],
            'genre' => !empty($genreNames) ? $genreNames : [],
            'seriesName' => $seriesName,
            'seriesNumber' => $seriesNumber,
            'series_number' => $seriesNumber,
            'seriesMap' => $seriesMap,

            // Legacy audible fields
            'audibleAuthors' => [],
            'audibleNarrators' => [],
            'audibleCoverImageUrl' => null,
            'audibleCoverPath' => null,

            // Metadata
            'metadata' => [
                'version' => '1.1',
                'updated_at' => $updatedAt,
            ],
        ];
    }
}
