<?php

namespace App\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Publisher;
use App\Models\Series;
use App\Traits\HandlesLibraryJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BookImportService
{
    use HandlesLibraryJson;

    protected GenreMappingService $genreMappingService;

    private const AUDIO_EXTENSIONS = [
        'mp3',
        'm4a',
        'm4b',
        'm4p',
        'mp4',
        'aac',
        'ogg',
        'oga',
        'wav',
        'flac',
        'wma',
    ];

    public function __construct(GenreMappingService $genreMappingService)
    {
        $this->genreMappingService = $genreMappingService;
    }

    protected function areOnSameFileSystem(string $sourcePath, string $targetPath): bool
    {
        $sourceRealPath = realpath($sourcePath) ?: $sourcePath;
        $targetRealPath = realpath($targetPath) ?: $targetPath;

        $sourceStatPath = File::isDirectory($sourceRealPath) ? $sourceRealPath : dirname($sourceRealPath);
        $targetStatPath = File::isDirectory($targetRealPath) ? $targetRealPath : dirname($targetRealPath);

        $sourceStat = @stat($sourceStatPath);
        $targetStat = @stat($targetStatPath);

        if (!is_array($sourceStat) || !is_array($targetStat)) {
            return false;
        }

        return ($sourceStat['dev'] ?? null) !== null
            && ($targetStat['dev'] ?? null) !== null
            && $sourceStat['dev'] === $targetStat['dev'];
    }

    protected function normalizeSourcePathForImport(string $sourcePath): string
    {
        $normalizedPath = rtrim($sourcePath, '/');

        while (File::isDirectory($normalizedPath)) {
            $files = File::files($normalizedPath);
            $dirs = File::directories($normalizedPath);

            if (count($files) > 0 || count($dirs) !== 1) {
                break;
            }

            $normalizedPath = rtrim($dirs[0], '/');
        }

        return $normalizedPath;
    }

    protected function moveNonAudioFilesToDirectory(string $sourceDir, string $targetDir): void
    {
        if (!File::isDirectory($sourceDir)) {
            return;
        }

        if (!File::isDirectory($targetDir)) {
            File::makeDirectory($targetDir, 0775, true);
            $this->setDirectoryOwnership($targetDir);
        }

        $files = File::files($sourceDir);
        foreach ($files as $file) {
            $path = $file->getPathname();
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($extension, self::AUDIO_EXTENSIONS, true)) {
                continue;
            }

            $targetPath = rtrim($targetDir, '/') . '/' . $file->getFilename();
            File::move($path, $targetPath);
            $this->setFileOwnership($targetPath);
        }

        if (File::isDirectory($sourceDir) && count(File::allFiles($sourceDir)) === 0) {
            File::deleteDirectory($sourceDir);
        }
    }

    public function directoryHasAudioFiles(string $directory): bool
    {
        if (File::isFile($directory)) {
            $extension = strtolower(pathinfo($directory, PATHINFO_EXTENSION));
            if (!in_array($extension, self::AUDIO_EXTENSIONS, true)) {
                return false;
            }

            return filesize($directory) > 0;
        }

        if (!File::isDirectory($directory)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (!in_array($extension, self::AUDIO_EXTENSIONS, true)) {
                continue;
            }

            if ($file->getSize() > 0) {
                return true;
            }
        }

        return false;
    }

    public function assertDirectoryHasAudioFiles(string $directory, array $context = []): void
    {
        if ($this->directoryHasAudioFiles($directory)) {
            return;
        }

        $message = "Sanity check failed: destination directory contains no audio files: {$directory}";
        Log::error($message, array_merge(['directory' => $directory], $context));

        throw new \Exception($message);
    }

    /**
     * Create book from metadata with comprehensive handling
     */
    public function createBookFromMetadata(array $metadata, array $audiobook, array $options = []): ?Book
    {
        try {
            DB::beginTransaction();

            $book = new Book();
            $book->title = $metadata['title'] ?? 'Unknown Title';
            $book->description = $metadata['description'] ?? null;

            // Handle year/release_date
            if (isset($metadata['year']) && $metadata['year'] && is_numeric($metadata['year'])) {
                $book->release_date = ((int) $metadata['year']) . '-01-01';
            }

            $book->isbn = $metadata['isbn'] ?? null;
            $book->language = $metadata['language'] ?? 'en';
            $book->source = 'import';

            // Store directory path for the audiobook files (including title)
            // This must match the actual filesystem path where files will be moved
            $book->directory_path = $this->generateDirectoryPath($metadata, ['include_title' => true]);

            // CRITICAL: Duration MUST come from actual audio files, NEVER from enrichment
            // Calculate from audio files if available
            if (!empty($audiobook['files'])) {
                $audioInfo = $this->calculateAudioInfo($audiobook['files']);
                if ($audioInfo['duration'] > 0) {
                    $book->duration = $audioInfo['duration'];
                }
            }

            // Fallback to metadata duration only if no audio files analyzed
            if (empty($book->duration) && isset($metadata['duration'])) {
                if (
                    is_string($metadata['duration'])
                    && preg_match('/(\d{2}):(\d{2}):(\d{2})/', $metadata['duration'], $matches)
                ) {
                    // Convert HH:MM:SS to seconds
                    $book->duration = ($matches[1] * 3600) + ($matches[2] * 60) + $matches[3];
                } elseif (is_numeric($metadata['duration'])) {
                    $book->duration = (int) $metadata['duration'];
                }
            }

            // Handle cover image with download support
            // CRITICAL: Priority order:
            // 1. Existing cover in directory
            // 2. Embedded cover data from M4B
            // 3. Cover path (local file)
            // 4. Cover URL (download)

            $existingCover = $this->findExistingCover($book->directory_path);

            if ($existingCover) {
                // Use existing cover - don't download
                $book->cover_image = $existingCover;
            } elseif (!empty($metadata['cover_data'])) {
                // CRITICAL: Save embedded cover from M4B file
                // This is extracted from the M4B file tags and stored in metadata['cover_data']
                $coverPath = $this->saveEmbeddedCover($metadata['cover_data'], $book->directory_path);
                if ($coverPath) {
                    $book->cover_image = $coverPath;
                }
            } elseif (!empty($metadata['cover_path'])) {
                $book->cover_image = basename((string) $metadata['cover_path']);
            } elseif (!empty($metadata['cover_url'])) {
                // Determine source for filename
                if (!empty($metadata['cover_is_local_file'])) {
                    $source = 'local';
                } elseif (isset($metadata['audible_raw'])) {
                    $source = 'audible';
                } else {
                    $source = 'googlebooks';
                }

                $coverPath = $this->downloadCoverImage($metadata['cover_url'], $book->directory_path, $source);
                if ($coverPath) {
                    $book->cover_image = $coverPath;
                }
                // Do NOT fall back to storing the URL - leave cover_image null if download fails
            }

            // Handle publisher
            if (!empty($metadata['publisher'])) {
                $publisherName = $this->resolvePublisherName($metadata['publisher']);
                if ($publisherName) {
                    $book->publisher_id = $this->findOrCreatePublisher($publisherName);
                }
            }

            // Store enrichment data in proper JSON columns
            if (!empty($metadata['audible_raw'])) {
                $book->audible_info = $metadata['audible_raw'];
            }

            if (!empty($metadata['google_books_raw'])) {
                $book->google_books_info = $metadata['google_books_raw'];
            }

            if (!empty($metadata['audiobook_bay_raw'])) {
                $book->audiobook_bay_info = $metadata['audiobook_bay_raw'];
            }

            // Store audio file count and tags (duration already calculated above)
            if (!empty($audiobook['files'])) {
                $book->audio_file_count = count($audiobook['files']);
                // Get tags if available (without recalculating duration)
                if (!empty($audiobook['files'])) {
                    $audioInfo = $this->calculateAudioInfo($audiobook['files']);
                    $book->file_tags = $audioInfo['tags'];
                }
            }

            // Set data source
            $book->source = $options['data_source'] ?? 'import';

            // Set batch_id if provided
            if (!empty($metadata['batch_id'])) {
                $book->batch_id = $metadata['batch_id'];
            }

            $book->save();

            // Handle authors
            if (!empty($metadata['author'])) {
                $authors = is_array($metadata['author']) ? $metadata['author'] : [$metadata['author']];
                $authorIds = [];
                foreach ($authors as $authorName) {
                    // Normalize author name (extract from patterns like "Graphic Audio [Alex Archer]")
                    $authorName = $this->normalizeAuthorName($authorName);
                    if (!empty($authorName)) {
                        $author = Author::firstOrCreate(['name' => trim($authorName)]);
                        $authorIds[] = $author->id;
                    }
                }
                $book->authors()->sync($authorIds);
            }

            // Handle narrators (limit to first 10 to avoid excessive data)
            if (!empty($metadata['narrator'])) {
                $narrators = is_array($metadata['narrator']) ? $metadata['narrator'] : [$metadata['narrator']];

                // Limit to 10 narrators max (Graphic Audio books can have huge casts)
                $narrators = array_slice($narrators, 0, 10);

                $narratorIds = [];
                foreach ($narrators as $narratorName) {
                    if (!empty($narratorName) && is_string($narratorName)) {
                        $narrator = Narrator::firstOrCreate(['name' => trim($narratorName)]);
                        $narratorIds[] = $narrator->id;
                    }
                }
                $book->narrators()->sync($narratorIds);
            }


            // Handle series with multi-book support
            $seriesToAttach = [];

            if (!empty($metadata['series'])) {
                $isCollection = !empty($metadata['is_collection']) || !empty($metadata['isCollection']);

                $series = Series::firstOrCreate(
                    ['name' => trim($metadata['series'])],
                    ['is_collection' => $isCollection]
                );

                // Update existing series to mark as collection if needed
                if ($isCollection && !$series->is_collection) {
                    $series->is_collection = true;
                    $series->save();
                }

                // Handle multi-book entries (e.g., books 2-3 combined)
                if (!empty($metadata['multi_book_numbers'])) {
                    $firstNumber = $metadata['multi_book_numbers'][0];
                    $seriesToAttach[$series->id] = ['series_number' => $firstNumber];
                } else {
                    $seriesNumber = $metadata['series_number'] ?? 1;
                    $seriesToAttach[$series->id] = ['series_number' => $seriesNumber];
                }
            }

            // Handle collection as a separate series (if present)
            if (!empty($metadata['collection'])) {
                $collectionSeries = Series::firstOrCreate(
                    ['name' => trim($metadata['collection'])],
                    ['is_collection' => true]
                );

                // Update existing series to mark as collection if needed
                if (!$collectionSeries->is_collection) {
                    $collectionSeries->is_collection = true;
                    $collectionSeries->save();
                }

                $collectionNumber = $metadata['collection_number'] ?? null;
                $seriesToAttach[$collectionSeries->id] = ['series_number' => $collectionNumber];
            }

            // Attach all series (primary + collection) at once
            if (!empty($seriesToAttach)) {
                $book->series()->sync($seriesToAttach);
            }

            // Handle genres
            if (!empty($metadata['genre'])) {
                $genres = is_array($metadata['genre']) ? $metadata['genre'] : [$metadata['genre']];
                $isPrimary = true; // First genre is primary
                $genresToAttach = [];

                foreach ($genres as $genreName) {
                    $validGenreName = $this->validateAndMapGenre(trim($genreName));
                    $genre = Genre::firstOrCreate(['name' => $validGenreName]);
                    $genresToAttach[$genre->id] = ['is_primary' => $isPrimary];
                    $isPrimary = false; // Subsequent genres are not primary
                }

                if (!empty($genresToAttach)) {
                    $book->genres()->sync($genresToAttach);
                    $book->unsetRelation('genres');
                }
            }

            // Generate librarian.json after all relationships are set
            $this->updateLibraryJson($book);

            DB::commit();
            return $book;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to create book from metadata: " . $e->getMessage(), [
                'metadata' => $metadata,
                'audiobook' => $audiobook,
                'trace' => $e->getTraceAsString(),
            ]);

            // CRITICAL: Re-throw the exception so the caller can see what went wrong
            // Returning null silently hides the error from the user
            throw $e;
        }
    }

    /**
     * Update existing book from metadata
     */
    public function updateBookFromMetadata(Book $book, array $metadata, array $audiobook, array $options = []): Book
    {
        try {
            DB::beginTransaction();

            // Update basic fields
            $book->title = $metadata['title'] ?? $book->title;
            $book->description = $metadata['description'] ?? $book->description;

            // Handle year/release_date
            if (isset($metadata['year']) && $metadata['year'] && is_numeric($metadata['year'])) {
                $book->release_date = ((int) $metadata['year']) . '-01-01';
            }

            $book->isbn = $metadata['isbn'] ?? $book->isbn;
            $book->language = $metadata['language'] ?? $book->language;

            // Update directory path if provided
            if (!empty($metadata['custom_directory_path'])) {
                $book->directory_path = $this->generateDirectoryPath($metadata, ['include_title' => true]);
            }

            // Update duration from audio files
            if (!empty($audiobook['files'])) {
                $audioInfo = $this->calculateAudioInfo($audiobook['files']);
                if ($audioInfo['duration'] > 0) {
                    $book->duration = $audioInfo['duration'];
                }
            }

            // Update cover if new one provided
            if (!empty($metadata['cover_data'])) {
                $coverPath = $this->saveEmbeddedCover($metadata['cover_data'], $book->directory_path);
                if ($coverPath) {
                    $book->cover_image = $coverPath;
                }
            } elseif (!empty($metadata['cover_url'])) {
                $source = 'googlebooks';
                if (!empty($metadata['cover_is_local_file'])) {
                    $source = 'local';
                } elseif (isset($metadata['audible_raw'])) {
                    $source = 'audible';
                }
                $coverPath = $this->downloadCoverImage($metadata['cover_url'], $book->directory_path, $source);
                if ($coverPath) {
                    $book->cover_image = $coverPath;
                }
            }

            // Update publisher
            if (!empty($metadata['publisher'])) {
                $publisherName = $this->resolvePublisherName($metadata['publisher']);
                if ($publisherName) {
                    $book->publisher_id = $this->findOrCreatePublisher($publisherName);
                }
            }

            // Update batch_id if provided
            if (!empty($metadata['batch_id'])) {
                $book->batch_id = $metadata['batch_id'];
            }

            $book->save();

            // Update authors (detach old, attach new)
            if (!empty($metadata['author'])) {
                $authors = is_array($metadata['author']) ? $metadata['author'] : [$metadata['author']];
                $book->authors()->detach();
                foreach ($authors as $authorName) {
                    $author = Author::firstOrCreate(['name' => trim($authorName)]);
                    $book->authors()->attach($author->id);
                }
            }

            // Update narrators
            if (!empty($metadata['narrator'])) {
                $narrators = is_array($metadata['narrator']) ? $metadata['narrator'] : [$metadata['narrator']];
                $book->narrators()->detach();
                foreach ($narrators as $narratorName) {
                    $narrator = Narrator::firstOrCreate(['name' => trim($narratorName)]);
                    $book->narrators()->attach($narrator->id);
                }
            }

            // Update series
            if (!empty($metadata['series'])) {
                $book->series()->detach();
                $series = Series::firstOrCreate(['name' => $metadata['series']]);
                $seriesNumber = $metadata['series_number'] ?? null;
                $book->series()->attach($series->id, [
                    'series_number' => $seriesNumber,
                ]);
            }

            // Update genres
            if (!empty($metadata['genre'])) {
                $genres = is_array($metadata['genre']) ? $metadata['genre'] : [$metadata['genre']];
                $isPrimary = true;
                $genresToAttach = [];
                foreach ($genres as $genreName) {
                    $validGenreName = $this->validateAndMapGenre(trim($genreName));
                    $genre = Genre::firstOrCreate(['name' => $validGenreName]);
                    $genresToAttach[$genre->id] = ['is_primary' => $isPrimary];
                    $isPrimary = false;
                }

                if (!empty($genresToAttach)) {
                    $book->genres()->sync($genresToAttach);
                    $book->unsetRelation('genres');
                }
            }

            // Generate librarian.json after all relationships are updated
            $this->updateLibraryJson($book);

            DB::commit();
            return $book;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to update book from metadata: " . $e->getMessage(), [
                'book_id' => $book->id,
                'metadata' => $metadata,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Generate directory path for book with advanced features
     */
    public function generateDirectoryPath(array $metadata, array $options = []): string
    {
        // If custom directory path is set, use it
        if (!empty($metadata['custom_directory_path'])) {
            $path = trim($metadata['custom_directory_path']);

            // CRITICAL: Always strip book_root prefix if present
            // Directory paths must ALWAYS be relative, never absolute
            $bookRoot = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
            if (str_starts_with($path, $bookRoot . '/')) {
                $path = substr($path, strlen($bookRoot) + 1);
            } elseif (str_starts_with($path, $bookRoot)) {
                $path = substr($path, strlen($bookRoot) + 1);
            }

            return $path;
        }

        $structure = $options['directory_structure'] ?? 'genre/author/series';
        $authors = is_array($metadata['author']) ? $metadata['author'] : [$metadata['author']];

        // Handle comma-separated authors
        if (count($authors) === 1 && strpos($authors[0], ',') !== false) {
            $authors = array_map('trim', explode(',', $authors[0]));
        }

        // Handle genre - convert array to string
        $genreData = $metadata['genre'] ?? 'Unknown';
        $genre = is_array($genreData) ? ($genreData[0] ?? 'Unknown') : $genreData;
        if (empty($genre)) {
            $genre = 'Unknown';
        }

        // Check for existing author directory first
        $cleanedSeries = null;
        if (!empty($metadata['series'])) {
            $cleanedSeries = $this->cleanSeriesName($metadata['series'], $authors);
        }

        $existingAuthorDir = $this->findExistingAuthorDirectory($authors, $cleanedSeries);

        if ($existingAuthorDir) {
            $authorDir = $existingAuthorDir;
        } else {
            // Check for Graphic Audio in narrator field
            $authorDir = $this->formatAuthorsForDirectory($authors);
            if ($this->isGraphicAudio($metadata)) {
                $authorDir = 'Graphic Audio';
            }
        }

        $path = match ($structure) {
            'author/series' => $this->buildAuthorSeriesPath($authorDir, $metadata),
            'genre/author' => "{$genre}/{$authorDir}",
            'series/author' => $this->buildSeriesAuthorPath($metadata, $authorDir),
            'flat' => $authorDir,
            default => $this->buildGenreAuthorSeriesPath($genre, $authorDir, $metadata)
        };

        // Add title if requested
        if (!empty($metadata['title']) && ($options['include_title'] ?? false)) {
            $title = $metadata['title'];

            // If we have a series number, prefix it to the title
            if (!empty($metadata['series_number'])) {
                $seriesNumber = $this->formatSeriesNumberForTitlePrefix($metadata['series_number']);
                if ($seriesNumber !== '') {
                    $title = $seriesNumber . ' ' . $title;
                }
            }

            // Add GraphicAudio marker if detected
            $title = $this->addGraphicAudioMarker($title, $metadata);

            $path .= '/' . $title;
        }

        return $path;
    }

    private function formatSeriesNumberForTitlePrefix(mixed $seriesNumber): string
    {
        if ($seriesNumber === null || $seriesNumber === '') {
            return '';
        }

        $value = is_string($seriesNumber) ? trim($seriesNumber) : (string) $seriesNumber;
        if ($value === '' || !is_numeric($value)) {
            return '';
        }

        if (!str_contains($value, '.')) {
            return str_pad((string) (int) $value, 2, '0', STR_PAD_LEFT);
        }

        [$intPart, $decimalPart] = array_pad(explode('.', $value, 2), 2, '');
        $intPart = str_pad((string) (int) $intPart, 2, '0', STR_PAD_LEFT);
        $decimalPart = rtrim($decimalPart, '0');
        if ($decimalPart === '') {
            return $intPart;
        }

        return $intPart . '.' . $decimalPart;
    }

    /**
     * Build genre/author/series path structure
     */
    protected function buildGenreAuthorSeriesPath(string $genre, string $authorDir, array $metadata): string
    {
        if (!empty($metadata['series'])) {
            $originalSeries = $metadata['series'];
            $series = $this->addGraphicAudioMarker($metadata['series'], $metadata);
            Log::debug('BookImportService::buildGenreAuthorSeriesPath', [
                'original_series' => $originalSeries,
                'modified_series' => $series,
                'metadata_series' => $metadata['series'],
            ]);
            return "{$genre}/{$authorDir}/{$series}";
        }
        return "{$genre}/{$authorDir}";
    }

    /**
     * Build author/series path structure
     */
    protected function buildAuthorSeriesPath(string $authorDir, array $metadata): string
    {
        if (!empty($metadata['series'])) {
            $series = $this->addGraphicAudioMarker($metadata['series'], $metadata);
            return "{$authorDir}/{$series}";
        }
        return $authorDir;
    }

    /**
     * Build series/author path structure
     */
    protected function buildSeriesAuthorPath(array $metadata, string $authorDir): string
    {
        if (!empty($metadata['series'])) {
            $series = $this->addGraphicAudioMarker($metadata['series'], $metadata);
            return "{$series}/{$authorDir}";
        }
        return "Standalone/{$authorDir}";
    }

    protected function resolvePublisherName(mixed $publisher): ?string
    {
        if (is_array($publisher)) {
            if (isset($publisher['name']) && is_string($publisher['name'])) {
                return trim($publisher['name']);
            }

            $first = reset($publisher);
            if (is_string($first)) {
                return trim($first);
            }
        }

        if (is_object($publisher) && isset($publisher->name) && is_string($publisher->name)) {
            return trim($publisher->name);
        }

        if (is_string($publisher)) {
            return trim($publisher);
        }

        return null;
    }

    /**
     * Select the best cover URL based on metadata priority.
     * Priority: local file flag > Audible cover > Google Books cover > provided cover_url (unknown).
     */
    protected function selectBestCoverUrl(array $metadata): array
    {
        $coverUrl = $metadata['cover_url'] ?? null;

        if (!empty($coverUrl) && !empty($metadata['cover_is_local_file'])) {
            return [$coverUrl, 'local'];
        }

        $audibleCover = null;
        if (!empty($metadata['audible_raw']) && is_array($metadata['audible_raw'])) {
            $audibleRaw = $metadata['audible_raw'];
            $audibleCover = $audibleRaw['coverImageUrl'] ?? $audibleRaw['audibleCoverImageUrl'] ?? ($audibleRaw['media']['source_url'] ?? null);

            if (!$audibleCover && !empty($coverUrl)) {
                $audibleCover = $coverUrl;
            }
        }

        $googleCover = null;
        if (!empty($metadata['google_books_raw']) && is_array($metadata['google_books_raw'])) {
            $googleRaw = $metadata['google_books_raw'];
            $googleCover = $googleRaw['coverImageUrl'] ?? $googleRaw['cover_image_url'] ?? ($googleRaw['imageLinks']['large'] ?? null) ?? ($googleRaw['imageLinks']['medium'] ?? null) ?? ($googleRaw['imageLinks']['thumbnail'] ?? null);
        }

        if (!empty($audibleCover)) {
            return [$audibleCover, 'audible'];
        }

        if (!empty($googleCover)) {
            return [$googleCover, 'googlebooks'];
        }

        if (!empty($coverUrl)) {
            return [$coverUrl, 'unknown'];
        }

        return [null, 'unknown'];
    }

    protected function findOrCreatePublisher(string $name): ?int
    {
        if ($name === '') {
            return null;
        }

        $slug = Str::slug($name);
        $publisher = Publisher::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'is_active' => true]
        );

        return $publisher->id;
    }

    /**
     * Move files to library
     */
    public function moveFilesToLibrary(array $audiobook, Book $book, array $options = []): bool
    {
        try {
            $bookStoragePath = $options['storage_path'] ?? rtrim(
                config('app.book_root', '/media/lyra_data1/audiobooks/books'),
                '/'
            );
            if (!$bookStoragePath) {
                throw new \Exception('Book storage path not configured');
            }

            $originalSourcePath = $audiobook['path'];
            $sourcePath = $this->normalizeSourcePathForImport($originalSourcePath);
            $targetDir = $options['target_directory'] ?? $this->generateTargetDirectory(
                $book,
                $bookStoragePath,
                $options
            );

            // Normalize target directory to avoid duplicate trailing segments like "Title/Title"
            $normalizedTargetDir = rtrim($targetDir, '/');
            $segments = explode('/', $normalizedTargetDir);
            if (count($segments) >= 2) {
                $last = $segments[count($segments) - 1];
                $prev = $segments[count($segments) - 2];
                if (strcasecmp($last, $prev) === 0) {
                    array_pop($segments);
                    $normalizedTargetDir = implode('/', $segments);
                }
            }
            $targetDir = $normalizedTargetDir;
            $operation = $options['operation'] ?? 'copy'; // 'copy', 'move', or 'in_place'

            // Check if source is already at the target location (true in-place import)
            $realSourcePath = realpath($sourcePath);
            $realTargetDir = realpath($targetDir);

            // Only skip move if source and target are exactly the same
            if ($realSourcePath && $realTargetDir && $realSourcePath === $realTargetDir) {
                // Files are already in the correct location - true in-place import
                // CRITICAL: Don't overwrite directory_path - it's already set from user-approved metadata
                $book->save();

                // Just flatten CD directories if needed, but don't move files
                $this->flattenCdDirectories($sourcePath);

                $this->assertDirectoryHasAudioFiles($sourcePath, [
                    'book_id' => $book->id,
                    'source' => $sourcePath,
                    'target' => $targetDir,
                    'operation' => 'in_place',
                ]);

                Log::info("In-place import: Files already at target location", [
                    'source' => $sourcePath,
                    'target' => $targetDir,
                    'book_id' => $book->id,
                ]);

                return true;
            }

            // Handle directory conflicts
            $originalTargetDir = $targetDir;
            $hasExplicitTargetDirectory = array_key_exists('target_directory', $options)
                && is_string($options['target_directory'])
                && $options['target_directory'] !== '';

            if (!$hasExplicitTargetDirectory && File::isDirectory($targetDir)) {
                $targetDir = $this->handleDirectoryConflict($audiobook, $targetDir);

                // If directory was changed due to conflict, update book's directory_path
                if ($targetDir !== $originalTargetDir) {
                    $bookStoragePath = rtrim($bookStoragePath, '/');
                    if (str_starts_with($targetDir, $bookStoragePath . '/')) {
                        $relativePath = substr($targetDir, strlen($bookStoragePath) + 1);
                    } else {
                        $relativePath = $targetDir;
                    }
                    $book->directory_path = $relativePath;
                    Log::warning("Directory conflict detected - updated path", [
                        'original' => $originalTargetDir,
                        'new' => $targetDir,
                        'book_id' => $book->id,
                    ]);

                    // Move cover images / librarian.json (non-audio files) into the conflict-resolved directory
                    $this->moveNonAudioFilesToDirectory($originalTargetDir, $targetDir);
                }
            }

            if (!File::isDirectory($targetDir)) {
                File::makeDirectory($targetDir, 0775, true);

                // Set directory ownership to eric:audio
                $this->setDirectoryOwnership($targetDir);
            }

            // Flatten CD directories before moving files
            $this->flattenCdDirectories($sourcePath);

            if ($operation === 'move') {
                $this->moveDirectoryContents($sourcePath, $targetDir);
                // Clean up source directory after successful move
                $cleanupAudiobook = $audiobook;
                $cleanupAudiobook['path'] = $originalSourcePath;
                $this->cleanupSourceDirectory($cleanupAudiobook);
            } else {
                $this->copyDirectoryContents($sourcePath, $targetDir);
            }

            $this->assertDirectoryHasAudioFiles($targetDir, [
                'book_id' => $book->id,
                'source' => $sourcePath,
                'target' => $targetDir,
                'operation' => $operation,
            ]);

            // Save any changes (including directory_path if there was a conflict)
            $book->save();

            // Ensure librarian.json is generated in the final directory (especially after conflict renames)
            $this->updateLibraryJson($book);

            return true;
        } catch (\Exception $e) {
            Log::error("Failed to move files to library: " . $e->getMessage(), [
                'audiobook' => $audiobook,
                'book_id' => $book->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Generate target directory for book
     */
    protected function generateTargetDirectory(Book $book, string $basePath, array $options = []): string
    {
        $authors = $book->authors->pluck('name')->toArray();
        $genre = $book->genres->first()?->name ?? 'Unknown';

        $relativePath = null;
        if (!empty($book->directory_path)) {
            $relativePath = $book->directory_path;
            if (str_starts_with($relativePath, '/')) {
                $relativePath = $this->makePathRelative($relativePath, $basePath);
            }
        }

        // Refresh the book's series relationship to ensure we have latest data with is_collection flag
        $book->load('series');

        // Get primary series (non-collection) for directory structure
        // Collections should NEVER be used for directory paths
        $primarySeries = $book->series->where('is_collection', false)->first();
        $seriesNumber = $primarySeries?->pivot->series_number ?? null;
        $seriesName = $primarySeries?->name;

        Log::debug('BookImportService::generateTargetDirectory - Series info', [
            'book_id' => $book->id,
            'book_title' => $book->title,
            'primarySeries_id' => $primarySeries?->id,
            'primarySeries_name' => $seriesName,
            'seriesNumber' => $seriesNumber,
        ]);

        if ($relativePath === null) {
            $authorDir = $this->formatAuthorsForDirectory($authors);

            $metadata = [
                'author' => $authors,
                'genre' => $genre,
                'series' => $seriesName,
                'series_number' => $seriesNumber,
                'title' => $book->title,
            ];

            Log::debug('BookImportService::generateTargetDirectory - Metadata for generateDirectoryPath', [
                'book_id' => $book->id,
                'metadata' => $metadata,
            ]);

            $relativePath = $this->generateDirectoryPath($metadata, $options);
        }

        $path = rtrim($basePath, '/') . '/' . ltrim($relativePath, '/');

        // Always include title in path unless explicitly disabled
        // However, if the book already has a directory_path that includes the title, don't append it again
        if (!isset($options['include_title_in_path']) || $options['include_title_in_path'] !== false) {
            // If the book already has a directory_path, check if it already includes the title
            // If it does, return the path as-is to avoid creating nested directories
            if ($relativePath !== null) {
                $relativeSegments = explode('/', trim($relativePath, '/'));
                $relativeLastSegment = end($relativeSegments) ?: '';

                $title = $book->title;
                $plainTitle = $book->title;

                // If we have a series number, prefix it to the title
                if (!empty($seriesNumber)) {
                    $formattedNumber = str_pad($seriesNumber, 2, '0', STR_PAD_LEFT);
                    $title = $formattedNumber . ' ' . $title;
                }

                // Remove common suffixes like (GraphicAudio) for comparison
                $cleanRelativeLast = preg_replace('/\s*\(Graphic\s*Audio\)\s*$/i', '', $relativeLastSegment);
                $cleanTitle = preg_replace('/\s*\(Graphic\s*Audio\)\s*$/i', '', $title);

                Log::debug('BookImportService::generateTargetDirectory - Checking relative path', [
                    'book_id' => $book->id,
                    'relativePath' => $relativePath,
                    'relativeLastSegment' => $relativeLastSegment,
                    'cleanRelativeLast' => $cleanRelativeLast,
                    'title' => $title,
                    'cleanTitle' => $cleanTitle,
                    'plainTitle' => $plainTitle,
                    'seriesNumber' => $seriesNumber,
                    'path' => $path,
                ]);

                // Check if the last segment of the relative path matches the title
                if (strcasecmp($cleanRelativeLast, $cleanTitle) === 0 ||
                    $this->lastPathSegmentMatchesTitle($cleanRelativeLast, $plainTitle)) {
                    Log::debug('BookImportService::generateTargetDirectory - Title already in path, returning as-is', [
                        'book_id' => $book->id,
                        'path' => $path,
                    ]);
                    return $path;
                }
            }

            // If we get here, we need to append the title
            $title = $book->title;
            $plainTitle = $book->title;

            // If we have a series number, prefix it to the title
            if (!empty($seriesNumber)) {
                $formattedNumber = str_pad($seriesNumber, 2, '0', STR_PAD_LEFT);
                $title = $formattedNumber . ' ' . $title;
            }

            $segments = explode('/', trim($path, '/'));
            $lastSegment = end($segments) ?: '';

            // Check if title is already in the path to avoid nested directories
            if (strcasecmp($lastSegment, $title) === 0) {
                return $path;
            }

            // Check if last segment matches the plain title (without series number prefix)
            if ($this->lastPathSegmentMatchesTitle($lastSegment, $plainTitle)) {
                return $path;
            }

            // Check if last segment matches the formatted title (with series number prefix)
            if (!empty($seriesNumber) && $this->lastPathSegmentMatchesTitle($lastSegment, $title)) {
                // Replace the last segment with the properly formatted title
                array_pop($segments);
                $segments[] = $title;

                return '/' . implode('/', $segments);
            }

            // Only append title if it's not already in the path
            // This prevents creating nested directories like "Title/Title"
            $path .= "/{$title}";
            Log::debug('BookImportService::generateTargetDirectory - Appending title to path', [
                'book_id' => $book->id,
                'finalPath' => $path,
            ]);
        }

        return $path;
    }

    private function lastPathSegmentMatchesTitle(string $lastSegment, string $title): bool
    {
        $lastSegment = trim($lastSegment);
        $title = trim($title);

        if ($lastSegment === '' || $title === '') {
            return false;
        }

        if (strcasecmp($lastSegment, $title) === 0) {
            return true;
        }

        $normalize = static function (string $value): string {
            $value = trim($value);
            $value = preg_replace('/^0*\d+\s+/', '', $value) ?? $value;
            $value = preg_replace('/\s+/', ' ', $value) ?? $value;

            return mb_strtolower($value);
        };

        return $normalize($lastSegment) === $normalize($title);
    }

    /**
     * Copy directory contents
     */
    protected function copyDirectoryContents(string $source, string $target): void
    {
        // Handle single file source
        if (File::isFile($source)) {
            $filename = basename($source);
            $targetFile = "{$target}/{$filename}";

            if (!File::isDirectory($target)) {
                File::makeDirectory($target, 0775, true);
                $this->setDirectoryOwnership($target);
            }

            if (!File::copy($source, $targetFile)) {
                throw new \Exception("Failed to copy file: {$source} to {$targetFile}");
            }

            chmod($targetFile, 0664);
            $this->setFileOwnership($targetFile);

            $this->copyMatchingPdfFile($source, $target);
            return;
        }

        if (!File::isDirectory($source)) {
            throw new \Exception("Source directory does not exist: {$source}");
        }

        $files = File::allFiles($source);

        foreach ($files as $file) {
            // Always use just the basename to avoid creating nested directories
            $filename = $file->getFilename();
            $targetFile = "{$target}/{$filename}";

            Log::debug('BookImportService: copyDirectoryContents - Processing file', [
                'source' => $file->getPathname(),
                'relativePath' => $file->getRelativePathname(),
                'filename' => $filename,
                'target' => $target,
                'targetFile' => $targetFile,
            ]);

            if (!File::isDirectory($target)) {
                File::makeDirectory($target, 0775, true);
                $this->setDirectoryOwnership($target);
            }

            File::copy($file->getPathname(), $targetFile);

            // Set file permissions after copying
            chmod($targetFile, 0664);
            $this->setFileOwnership($targetFile);
        }
    }

    /**
     * Move directory contents
     */
    protected function moveDirectoryContents(string $source, string $target): void
    {
        $sameFileSystem = $this->areOnSameFileSystem($source, $target);

        // Handle single file source
        if (File::isFile($source)) {
            $filename = basename($source);
            $targetFile = "{$target}/{$filename}";

            if (!File::isDirectory($target)) {
                File::makeDirectory($target, 0775, true);
            }

            if ($sameFileSystem) {
                if (!File::move($source, $targetFile)) {
                    throw new \Exception("Failed to move file: {$source} to {$targetFile}");
                }
            } else {
                if (!File::copy($source, $targetFile)) {
                    throw new \Exception("Failed to copy file: {$source} to {$targetFile}");
                }
                File::delete($source);
            }

            chmod($targetFile, 0664);

            $this->moveMatchingPdfFile($source, $target);
            return;
        }

        if (!File::isDirectory($source)) {
            throw new \Exception("Source directory does not exist: {$source}");
        }

        $files = File::allFiles($source);

        foreach ($files as $file) {
            // Always use just the basename to avoid creating nested directories
            $filename = $file->getFilename();
            $targetFile = "{$target}/{$filename}";

            Log::debug('BookImportService: moveDirectoryContents - Processing file', [
                'source' => $file->getPathname(),
                'relativePath' => $file->getRelativePathname(),
                'filename' => $filename,
                'target' => $target,
                'targetFile' => $targetFile,
            ]);

            if (!File::isDirectory($target)) {
                File::makeDirectory($target, 0775, true);
            }

            if ($sameFileSystem) {
                if (!File::move($file->getPathname(), $targetFile)) {
                    throw new \Exception("Failed to move file: {$file->getPathname()} to {$targetFile}");
                }
            } else {
                // Use copy+delete instead of move to avoid cross-filesystem issues
                if (!File::copy($file->getPathname(), $targetFile)) {
                    throw new \Exception("Failed to copy file: {$file->getPathname()} to {$targetFile}");
                }

                File::delete($file->getPathname());
            }

            // Set file permissions after move/copy
            chmod($targetFile, 0664);
        }

        // Remove empty directories from source
        $this->removeEmptyDirectories($source);
    }

    /**
     * Find and copy matching PDF file for an audio file
     */
    protected function copyMatchingPdfFile(string $audioFilePath, string $targetDir): void
    {
        $pdfPath = $this->findMatchingPdfFile($audioFilePath);

        if ($pdfPath && File::exists($pdfPath)) {
            $pdfFilename = basename($pdfPath);
            $targetPdfPath = "{$targetDir}/{$pdfFilename}";

            try {
                if (File::copy($pdfPath, $targetPdfPath)) {
                    chmod($targetPdfPath, 0664);
                    Log::info("Copied matching PDF file", [
                        'pdf' => $pdfPath,
                        'target' => $targetPdfPath,
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning("Failed to copy matching PDF file: " . $e->getMessage(), [
                    'pdf' => $pdfPath,
                    'target' => $targetPdfPath,
                ]);
            }
        }
    }

    /**
     * Find and move matching PDF file for an audio file
     */
    protected function moveMatchingPdfFile(string $audioFilePath, string $targetDir): void
    {
        $pdfPath = $this->findMatchingPdfFile($audioFilePath);

        if ($pdfPath && File::exists($pdfPath)) {
            $pdfFilename = basename($pdfPath);
            $targetPdfPath = "{$targetDir}/{$pdfFilename}";

            $sameFileSystem = $this->areOnSameFileSystem($pdfPath, $targetDir);

            try {
                if ($sameFileSystem) {
                    if (File::move($pdfPath, $targetPdfPath)) {
                        chmod($targetPdfPath, 0664);
                        Log::info("Moved matching PDF file", [
                            'pdf' => $pdfPath,
                            'target' => $targetPdfPath,
                        ]);
                    }
                } elseif (File::copy($pdfPath, $targetPdfPath)) {
                    chmod($targetPdfPath, 0664);
                    File::delete($pdfPath);
                    Log::info("Moved matching PDF file", [
                        'pdf' => $pdfPath,
                        'target' => $targetPdfPath,
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning("Failed to move matching PDF file: " . $e->getMessage(), [
                    'pdf' => $pdfPath,
                    'target' => $targetPdfPath,
                ]);
            }
        }
    }

    /**
     * Find a PDF file with the same basename as an audio file
     */
    protected function findMatchingPdfFile(string $audioFilePath): ?string
    {
        $pathInfo = pathinfo($audioFilePath);
        $directory = $pathInfo['dirname'];
        $basename = $pathInfo['filename'];

        $pdfPath = "{$directory}/{$basename}.pdf";

        if (File::exists($pdfPath) && File::isFile($pdfPath)) {
            return $pdfPath;
        }

        return null;
    }

    /**
     * Remove empty directories recursively
     */
    protected function removeEmptyDirectories(string $path): void
    {
        if (!File::isDirectory($path)) {
            return;
        }

        $directories = File::directories($path);

        foreach ($directories as $dir) {
            $this->removeEmptyDirectories($dir);
        }

        if ($this->isDirectoryEmpty($path)) {
            File::deleteDirectory($path);
        }
    }

    /**
     * Check if this is a Graphic Audio audiobook
     */
    protected function isGraphicAudio(array $metadata): bool
    {
        // Check narrator field
        if (!empty($metadata['narrator'])) {
            $narrators = is_array($metadata['narrator']) ? $metadata['narrator'] : [$metadata['narrator']];
            foreach ($narrators as $narrator) {
                if (
                    is_string($narrator) &&
                    (stripos($narrator, 'Graphic Audio') !== false ||
                        stripos($narrator, 'GraphicAudio') !== false)
                ) {
                    return true;
                }
            }
        }

        // Also check publisher field as fallback
        if (!empty($metadata['publisher'])) {
            $publishers = is_array($metadata['publisher']) ? $metadata['publisher'] : [$metadata['publisher']];
            foreach ($publishers as $publisher) {
                if (
                    is_string($publisher) &&
                    (stripos($publisher, 'Graphic Audio') !== false ||
                        stripos($publisher, 'GraphicAudio') !== false)
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Format authors for directory paths
     */
    public function formatAuthorsForDirectory(array $authors): string
    {
        $normalizedAuthors = array_map([$this, 'normalizeAuthorName'], $authors);
        return implode(' & ', $normalizedAuthors);
    }

    /**
     * Normalize author names for directory use
     *
     * CRITICAL: Author will NEVER contain "Graphic" AND "Audio" - this is always invalid
     */
    public function normalizeAuthorName(string $authorName): string
    {
        $name = trim($authorName);

        // Pattern: "Publisher/Narrator [Actual Author]"
        if (preg_match('/^.+?\s*\[([^\]]+)\]$/', $name, $matches)) {
            $name = trim($matches[1]);
        }

        // CRITICAL: If author contains both "Graphic" and "Audio", it's INVALID
        // This should NEVER be an author - it's a narrator/publisher
        if (stripos($name, 'graphic') !== false && stripos($name, 'audio') !== false) {
            return '';
        }

        // If it's just "Full Cast", return empty (narrator, not author)
        if (preg_match('/^Full\s*Cast$/i', $name)) {
            return '';
        }

        // Normalize initials
        $name = preg_replace('/\b([A-Z])\s+/', '$1. ', $name);
        $name = preg_replace('/\s+([A-Z])$/', ' $1.', $name);
        $name = preg_replace('/\b([A-Z]\.)\s+([A-Z]\.)/', '$1$2', $name);
        $name = preg_replace('/\b([A-Z]\.)\s+([A-Z]\.)/', '$1$2', $name);

        return trim($name);
    }

    /**
     * Find existing cover image in directory
     */
    protected function findExistingCover(string $directoryPath): ?string
    {
        $bookRoot = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
        $fullPath = $bookRoot . '/' . $directoryPath;

        if (!is_dir($fullPath)) {
            return null;
        }

        // Check for common cover image filenames
        $coverNames = ['cover.jpg', 'cover.jpeg', 'cover.png', 'folder.jpg', 'folder.jpeg', 'folder.png'];

        foreach ($coverNames as $coverName) {
            $coverPath = $fullPath . '/' . $coverName;
            if (file_exists($coverPath)) {
                return $directoryPath . '/' . $coverName;
            }
        }

        return null;
    }

    /**
     * Save embedded cover image from M4B file
     */
    protected function saveEmbeddedCover(string $coverData, string $directoryPath): ?string
    {
        try {
            // CRITICAL: directoryPath is RELATIVE - convert to absolute
            $bookRoot = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
            $absoluteDir = $bookRoot . '/' . ltrim($directoryPath, '/');

            // Create directory if it doesn't exist
            if (!is_dir($absoluteDir)) {
                mkdir($absoluteDir, 0775, true);
                $this->setDirectoryOwnership($absoluteDir);
            }

            $filename = 'cover.jpg';
            $filePath = "{$absoluteDir}/{$filename}";

            if (file_put_contents($filePath, $coverData)) {
                chmod($filePath, 0664);
                $this->setFileOwnership($filePath);
                return $filename;
            }
        } catch (\Exception $e) {
            Log::warning("Failed to save embedded cover image: " . $e->getMessage(), [
                'directory' => $directoryPath,
            ]);
        }

        return null;
    }

    /**
     * Download cover image
     */
    public function downloadCoverImage(string $imageUrl, string $directoryPath, string $source = 'unknown', ?ExternalCoverService $coverService = null): ?string
    {
        if (!$coverService) {
            return null;
        }

        $result = $coverService->downloadCoverImage($imageUrl, $directoryPath, $source);

        if ($result['success']) {
            return $result['path'];
        }

        return null;
    }

    /**
     * Get image extension from URL
     */
    protected function getImageExtensionFromUrl(string $url): string
    {
        $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);

        if (in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            return strtolower($extension);
        }

        return 'jpg';
    }

    /**
     * Find existing author directory
     */
    public function findExistingAuthorDirectory(array $authors, string $seriesName = null): ?string
    {
        if (empty($authors)) {
            return null;
        }

        $bookStoragePath = config('filesystems.disks.books.root') ?? env('BOOK_STORAGE_PATH');
        if (!$bookStoragePath || !File::isDirectory($bookStoragePath)) {
            return null;
        }

        $normalizedAuthors = array_map([$this, 'normalizeAuthorName'], $authors);

        $authorCombinations = [];

        if (count($normalizedAuthors) > 1) {
            $authorCombinations[] = $normalizedAuthors;
            $authorCombinations[] = array_reverse($normalizedAuthors);

            if (count($normalizedAuthors) >= 3) {
                $authorCombinations[] = [$normalizedAuthors[0], $normalizedAuthors[1]];
                $authorCombinations[] = [$normalizedAuthors[1], $normalizedAuthors[0]];
            }
        }

        if (count($normalizedAuthors) === 1) {
            $authorCombinations[] = $normalizedAuthors;
        }

        try {
            // Only scan 2 levels deep: [genre]/[author]
            // No need for recursive scanning since all authors are at this depth
            $genreDirs = File::directories($bookStoragePath);

            foreach ($genreDirs as $genreDir) {
                $authorDirs = File::directories($genreDir);

                foreach ($authorDirs as $authorDir) {
                    $authorDirName = basename($authorDir);

                    foreach ($authorCombinations as $combination) {
                        $expectedDirName = $this->formatAuthorsForDirectory($combination);

                        if ($authorDirName === $expectedDirName) {
                            // If series name is specified, check if this author has that series
                            if ($seriesName) {
                                $seriesDirs = File::directories($authorDir);
                                foreach ($seriesDirs as $seriesDir) {
                                    $seriesDirName = basename($seriesDir);
                                    if (stripos($seriesDirName, $seriesName) !== false) {
                                        return $authorDirName;
                                    }
                                }
                            } else {
                                return $authorDirName;
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("Error searching for existing author directories: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Metadata filenames that can be safely overwritten
     */
    private const METADATA_FILES = ['librarian.json', 'cover.jpg', 'cover.jpeg', 'cover.png', 'cover.webp'];

    /**
     * Handle directory conflict resolution
     */
    public function handleDirectoryConflict(array $audiobook, string $targetDir): string
    {
        $originalTargetDir = $targetDir;

        // Check if target directory exists
        if (!File::isDirectory($targetDir)) {
            return $targetDir;
        }

        // Get all files in the directory
        $files = File::allFiles($targetDir);
        if (empty($files)) {
            return $targetDir;
        }

        // Check if directory only contains metadata files
        if ($this->containsOnlyMetadata($targetDir, $files)) {
            // Safe to use this directory - metadata files will be overwritten
            return $targetDir;
        }

        // Directory has actual content, find a non-conflicting path
        $counter = 1;
        while (File::isDirectory($targetDir)) {
            $targetDir = "{$originalTargetDir}_" . str_pad($counter, 2, '0', STR_PAD_LEFT);
            $counter++;

            if ($counter > 99) {
                break;
            }
        }

        return $targetDir;
    }

    /**
     * Check if directory contains only metadata files (librarian.json, covers, etc.)
     */
    private function containsOnlyMetadata(string $directoryPath, array $files): bool
    {
        foreach ($files as $file) {
            $filename = $file->getFilename();
            $extension = strtolower($file->getExtension());

            // If it's an audio file, this is not just metadata
            if (in_array($extension, self::AUDIO_EXTENSIONS)) {
                return false;
            }

            // If it's not a recognized metadata file, consider it content
            if (!in_array($filename, self::METADATA_FILES)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Flatten CD directories by moving files up one level
     */
    public function flattenCdDirectories(string $sourcePath): void
    {
        if (!File::isDirectory($sourcePath)) {
            return;
        }

        $directories = File::directories($sourcePath);
        $hasCdDirectories = false;

        foreach ($directories as $dir) {
            $dirName = basename($dir);
            if (preg_match('/^(cd|disc|disk)\s*\d+$/i', $dirName)) {
                $hasCdDirectories = true;
                break;
            }
        }

        if (!$hasCdDirectories) {
            return;
        }

        foreach ($directories as $dir) {
            $dirName = basename($dir);
            if (preg_match('/^(cd|disc|disk)\s*(\d+)$/i', $dirName, $matches)) {
                $cdNumber = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
                $files = File::files($dir);

                foreach ($files as $file) {
                    $filename = $file->getFilename();
                    $extension = $file->getExtension();
                    $baseName = pathinfo($filename, PATHINFO_FILENAME);

                    $newFilename = "{$cdNumber}-{$baseName}.{$extension}";
                    $newPath = "{$sourcePath}/{$newFilename}";

                    File::move($file->getPathname(), $newPath);
                }

                File::deleteDirectory($dir);
            }
        }
    }

    /**
     * Extract track number from filename
     */
    public function extractTrackNumber(string $filename): ?int
    {
        $patterns = [
            '/^(\d{1,3})[\s\-_\.]+/',
            '/^Track[\s_]*(\d{1,3})/i',
            '/^(\d{1,3})\./',
            '/[\s\-_](\d{1,3})[\s\-_\.]+/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $filename, $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    /**
     * Check if directory is empty
     */
    public function isDirectoryEmpty(string $path): bool
    {
        if (!File::isDirectory($path)) {
            return true;
        }

        $files = File::allFiles($path);
        $directories = File::directories($path);

        return empty($files) && empty($directories);
    }

    /**
     * Clean up source directory after successful import
     */
    public function cleanupSourceDirectory(array $audiobook, bool $filesAlreadyExist = false): void
    {
        $sourcePath = $audiobook['path'];

        if (!File::isDirectory($sourcePath)) {
            return;
        }

        try {
            if ($this->isDirectoryEmpty($sourcePath)) {
                File::deleteDirectory($sourcePath);
                Log::info("Cleaned up empty source directory: {$sourcePath}");
            }
        } catch (\Exception $e) {
            Log::warning("Failed to cleanup source directory: " . $e->getMessage(), [
                'path' => $sourcePath,
            ]);
        }
    }

    /**
     * Calculate audio information from files with advanced tag extraction
     */
    public function calculateAudioInfo(array $audioFiles): array
    {
        $totalDuration = 0;
        $allTags = [];
        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];
        $audioFileCount = 0;

        foreach ($audioFiles as $filePath) {
            $extension = strtolower(pathinfo(is_string($filePath) ? $filePath : $filePath['path'], PATHINFO_EXTENSION));

            if (in_array($extension, $audioExtensions)) {
                $audioFileCount++;

                try {
                    // Handle both string paths and file arrays
                    $file = is_string($filePath) ? $filePath : $filePath['path'];
                    $fileName = basename($file);

                    // If we have pre-calculated data, use it
                    if (is_array($filePath) && isset($filePath['duration'])) {
                        $totalDuration += (int) $filePath['duration'];
                        if (isset($filePath['tags'])) {
                            $allTags[$fileName] = $filePath['tags'];
                        }
                    } else {
                        // Try to get duration from file directly
                        $fileDuration = $this->getAudioFileDuration($file);
                        if ($fileDuration > 0) {
                            $totalDuration += $fileDuration;
                            $allTags[$fileName] = ['calculated_duration' => $fileDuration];
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning("Failed to calculate audio info for {$file}: " . $e->getMessage());
                }
            }
        }

        return [
            'count' => $audioFileCount,
            'duration' => $totalDuration, // in seconds
            'tags' => $allTags,
        ];
    }

    /**
     * Check if two files are identical by comparing size and hash
     */
    public function areFilesIdentical(string $file1, string $file2): bool
    {
        if (!File::exists($file1) || !File::exists($file2)) {
            return false;
        }

        if (File::size($file1) !== File::size($file2)) {
            return false;
        }

        $maxHashSize = 1024 * 1024;

        $size1 = File::size($file1);
        $size2 = File::size($file2);

        if ($size1 <= $maxHashSize && $size2 <= $maxHashSize) {
            return hash_file('md5', $file1) === hash_file('md5', $file2);
        } else {
            $handle1 = fopen($file1, 'rb');
            $handle2 = fopen($file2, 'rb');

            if (!$handle1 || !$handle2) {
                if ($handle1) {
                    fclose($handle1);
                }
                if ($handle2) {
                    fclose($handle2);
                }
                return false;
            }

            $chunk1 = fread($handle1, $maxHashSize);
            $chunk2 = fread($handle2, $maxHashSize);

            fclose($handle1);
            fclose($handle2);

            return hash('md5', $chunk1) === hash('md5', $chunk2);
        }
    }

    /**
     * Get all files from a directory recursively
     */
    public function getAllFilesFromDirectory(string $path): array
    {
        $files = [];

        if (!File::isDirectory($path)) {
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Get data source based on AI model used
     */
    public function getDataSource(string $model): string
    {
        if (str_contains($model, 'gemini')) {
            return 'gemini';
        } elseif (str_contains($model, 'gpt') || str_contains($model, 'openai')) {
            return 'chatgpt';
        } elseif (str_contains($model, 'claude')) {
            return 'claude';
        }

        return 'ai';
    }

    /**
     * Move files to narrator-named directory
     */
    public function moveFilesToNarratorDirectory(array $audiobook, string $targetDir, bool $copyFiles = false): void
    {
        File::makeDirectory($targetDir, 0755, true);

        $this->flattenCdDirectories($audiobook['path']);

        $allFiles = File::allFiles($audiobook['path']);
        $filesToMove = array_map(function ($file) {
            return $file->getPathname();
        }, $allFiles);

        foreach ($filesToMove as $sourceFilePath) {
            $filename = basename($sourceFilePath);

            if ($this->isTorrentTrackingFile($filename)) {
                File::delete($sourceFilePath);
                continue;
            }

            $relativePath = str_replace($audiobook['path'] . '/', '', $sourceFilePath);
            $targetFile = $targetDir . '/' . $relativePath;

            $targetFileDir = dirname($targetFile);
            if (!File::isDirectory($targetFileDir)) {
                File::makeDirectory($targetFileDir, 0755, true);
            }

            try {
                if ($copyFiles) {
                    File::copy($sourceFilePath, $targetFile);
                } else {
                    File::move($sourceFilePath, $targetFile);
                }
            } catch (\Exception $e) {
                Log::warning("Error processing {$filename}: " . $e->getMessage());
            }
        }

        if (!$copyFiles) {
            $this->cleanupSourceDirectory($audiobook, false);
        }
    }

    /**
     * Create directory name in format "title (narrator)"
     */
    public function createNarratorDirectoryName(string $title, string $narrator): string
    {
        $cleanTitle = $this->removeSeriesFromTitle($title);

        $cleanTitle = preg_replace('/\[[^\]]*\]/', '', $cleanTitle);
        $cleanTitle = preg_replace('/\{[^}]*\}/', '', $cleanTitle);
        $cleanTitle = trim($cleanTitle);

        $cleanTitle = str_replace(['<', '>', ':', '"', '/', '\\', '|', '?', '*'], '', $cleanTitle);
        $cleanNarrator = str_replace(['<', '>', ':', '"', '/', '\\', '|', '?', '*'], '', $narrator);

        $cleanTitle = preg_replace('/\s+/', ' ', trim($cleanTitle));
        $cleanNarrator = preg_replace('/\s+/', ' ', trim($cleanNarrator));

        if (empty($cleanNarrator) || $cleanNarrator === 'Unknown Narrator') {
            return $cleanTitle;
        }

        $maxLength = 100;
        $combined = "{$cleanTitle} ({$cleanNarrator})";

        if (strlen($combined) > $maxLength) {
            $availableForTitle = $maxLength - strlen($cleanNarrator) - 3;
            if ($availableForTitle > 10) {
                $cleanTitle = substr($cleanTitle, 0, $availableForTitle) . '...';
                $combined = "{$cleanTitle} ({$cleanNarrator})";
            } else {
                return substr($cleanTitle, 0, $maxLength);
            }
        }

        return $combined;
    }

    /**
     * Handle directory conflict by comparing and prompting for action
     */
    public function renameBothDirectoriesByNarrator(array $audiobook, string $targetDir, Book $book, string $bookStoragePath): void
    {
        $existingBook = Book::where('directory_path', str_replace($bookStoragePath . '/', '', $targetDir))->first();

        $existingNarrator = $this->getNarratorFromDirectory($targetDir, $existingBook);
        $newNarrator = $this->getNarratorFromMetadata($audiobook);

        $existingTitle = $existingBook ? $existingBook->title : basename($targetDir);
        $newTitle = $book->title;

        $baseDir = dirname($targetDir);
        $existingNewPath = $baseDir . '/' . $this->createNarratorDirectoryName($existingTitle, $existingNarrator);
        $newImportPath = $baseDir . '/' . $this->createNarratorDirectoryName($newTitle, $newNarrator);

        if (File::exists($targetDir)) {
            File::move($targetDir, $existingNewPath);

            if ($existingBook) {
                $existingBook->directory_path = str_replace($bookStoragePath . '/', '', $existingNewPath);
                $existingBook->save();
            }
        }

        $this->moveFilesToNarratorDirectory($audiobook, $newImportPath, false);

        $book->directory_path = str_replace($bookStoragePath . '/', '', $newImportPath);
        $book->save();
    }

    /**
     * Parse plain text NFO files
     */
    public function parsePlainTextNfo(string $content): array
    {
        $data = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            if (preg_match('/^title\s*[:\-=]\s*(.+)$/i', $line, $matches)) {
                $data['title'] = trim($matches[1]);
            } elseif (preg_match('/^author\s*[:\-=]\s*(.+)$/i', $line, $matches)) {
                $data['author'] = trim($matches[1]);
            } elseif (preg_match('/^(?:narrator|read\s+by)\s*[:\-=]\s*(.+)$/i', $line, $matches)) {
                $data['narrator'] = trim($matches[1]);
            } elseif (preg_match('/^series\s*[:\-=]\s*(.+)$/i', $line, $matches)) {
                $data['series'] = trim($matches[1]);
            } elseif (preg_match('/^(?:series.?number|book.?number)\s*[:\-=]\s*(.+)$/i', $line, $matches)) {
                $data['series_number'] = trim($matches[1]);
            } elseif (preg_match('/^genre\s*[:\-=]\s*(.+)$/i', $line, $matches)) {
                $data['genre'] = trim($matches[1]);
            } elseif (preg_match('/^(?:year|original\s+publication)\s*[:\-=]\s*(.+)$/i', $line, $matches)) {
                $data['year'] = trim($matches[1]);
            } elseif (preg_match('/^publisher\s*[:\-=]\s*(.+)$/i', $line, $matches)) {
                $data['publisher'] = trim($matches[1]);
            } elseif (preg_match('/^isbn\s*[:\-=]\s*(.+)$/i', $line, $matches)) {
                $data['isbn'] = trim($matches[1]);
            } elseif (preg_match('/^(?:description|plot|summary)\s*[:\-=]\s*(.+)$/i', $line, $matches)) {
                $data['description'] = trim($matches[1]);
            }
        }

        return $data;
    }

    /**
     * Extract metadata from .nfo files if present
     */
    public function extractNfoData(string $directoryPath): ?array
    {
        $nfoFiles = glob($directoryPath . '/*.nfo');
        if (empty($nfoFiles)) {
            return null;
        }

        $nfoFile = $nfoFiles[0];
        $nfoContent = file_get_contents($nfoFile);

        if (!$nfoContent) {
            return null;
        }

        $nfoData = [];

        if (strpos($nfoContent, '<') !== false) {
            $nfoData = $this->parseXmlNfo($nfoContent);
        } else {
            $nfoData = $this->parsePlainTextNfo($nfoContent);
        }

        return $nfoData ?: null;
    }

    /**
     * Parse XML-format NFO files
     */
    public function parseXmlNfo(string $content): array
    {
        $data = [];

        try {
            $xml = simplexml_load_string($content);

            if ($xml) {
                if (isset($xml->title)) {
                    $data['title'] = (string) $xml->title;
                }
                if (isset($xml->author)) {
                    $data['author'] = (string) $xml->author;
                }
                if (isset($xml->narrator)) {
                    $data['narrator'] = (string) $xml->narrator;
                }
                if (isset($xml->series)) {
                    $data['series'] = (string) $xml->series;
                }
                if (isset($xml->seriesNumber)) {
                    $data['series_number'] = (string) $xml->seriesNumber;
                }
                if (isset($xml->genre)) {
                    $data['genre'] = (string) $xml->genre;
                }
                if (isset($xml->year)) {
                    $data['year'] = (string) $xml->year;
                }
                if (isset($xml->publisher)) {
                    $data['publisher'] = (string) $xml->publisher;
                }
                if (isset($xml->isbn)) {
                    $data['isbn'] = (string) $xml->isbn;
                }
                if (isset($xml->plot)) {
                    $data['description'] = (string) $xml->plot;
                }
                if (isset($xml->description)) {
                    $data['description'] = (string) $xml->description;
                }
            }
        } catch (\Exception $e) {
            Log::warning("Failed to parse XML NFO: " . $e->getMessage());
        }

        return $data;
    }

    /**
     * Clean description text (remove HTML, limit length, etc.)
     */
    public function cleanDescription(string $description): string
    {
        $cleaned = strip_tags($description);
        $cleaned = html_entity_decode($cleaned, ENT_QUOTES, 'UTF-8');
        $cleaned = trim($cleaned);

        if (strlen($cleaned) > 2000) {
            $cleaned = substr($cleaned, 0, 1997) . '...';
        }

        return $cleaned;
    }

    /**
     * Map genre to valid genre name
     */
    public function mapToValidGenre(string $genre): string
    {
        $genreMap = [
            'sci-fi' => 'Science Fiction',
            'scifi' => 'Science Fiction',
            'science fiction' => 'Science Fiction',
            'fantasy' => 'Fantasy',
            'mystery' => 'Mystery',
            'thriller' => 'Thriller',
            'romance' => 'Romance',
            'horror' => 'Horror',
            'adventure' => 'Adventure',
            'action' => 'Action',
            'historical' => 'Historical',
            'contemporary' => 'Contemporary',
            'literary' => 'Literary',
            'non-fiction' => 'Non-Fiction',
            'nonfiction' => 'Non-Fiction',
            'biography' => 'Biography',
            'autobiography' => 'Autobiography',
            'self-help' => 'Self-Help',
            'business' => 'Business',
            'history' => 'History',
            'politics' => 'Politics',
            'philosophy' => 'Philosophy',
            'psychology' => 'Psychology',
            'science' => 'Science',
            'technology' => 'Technology',
            'health' => 'Health',
            'fitness' => 'Fitness',
            'cooking' => 'Cooking',
            'travel' => 'Travel',
            'children' => 'Children',
            'young adult' => 'Young Adult',
            'ya' => 'Young Adult',
        ];

        $normalizedGenre = strtolower(trim($genre));
        return $genreMap[$normalizedGenre] ?? ucfirst($genre);
    }

    /**
     * Get first non-empty metadata value from array of keys
     */
    public function getFirstNonEmptyMetadataValue(array $metadata, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $metadata)) {
                continue;
            }

            $value = $metadata[$key];
            if ($value === null) {
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            if (is_array($value) && count($value) === 0) {
                continue;
            }

            return $value;
        }

        return null;
    }

    /**
     * Display enriched metadata (AI + external data) for review
     */
    public function displayEnrichedMetadata(array $metadata): array
    {
        $arrayToString = function ($value) {
            if (is_array($value)) {
                $filtered = array_filter($value, function ($v) {
                    return !is_array($v) && !is_object($v) && $v !== null && $v !== '';
                });
                return implode(', ', $filtered);
            }
            return $value ?? 'N/A';
        };

        $formatAuthors = function ($authors) {
            if (is_array($authors)) {
                $filtered = array_filter($authors, function ($v) {
                    return !is_array($v) && !is_object($v) && $v !== null && $v !== '';
                });
                return implode(' & ', $filtered);
            }
            return $authors ?? 'N/A';
        };

        $displaySeries = '';
        if (!empty($metadata['series'])) {
            $authors = is_array($metadata['author']) ? $metadata['author'] : [$metadata['author']];
            $cleanedSeriesName = $this->cleanSeriesName($metadata['series'], $authors);
            $displaySeries = $cleanedSeriesName . ($metadata['series_number'] ? " #{$metadata['series_number']}" : '');
        }

        $tableData = [
            ['Title', $arrayToString($metadata['title'])],
            ['Author', $formatAuthors($metadata['author'])],
            ['Narrator', $arrayToString($metadata['narrator'])],
            ['Series', $displaySeries],
            ['Genre', $arrayToString($metadata['genre'])],
            ['Year', $metadata['year'] ?? 'N/A'],
            ['Publisher', $arrayToString($metadata['publisher'])],
            ['Language', $metadata['language'] ?? 'N/A'],
            ['ISBN', $metadata['isbn'] ?? 'N/A'],
            ['Confidence', $metadata['confidence'] . '%'],
        ];

        if (!empty($metadata['source_path'])) {
            $tableData[] = ['Source Path', $metadata['source_path']];
        }

        $expectedPath = $this->generateDirectoryPath($metadata);
        $tableData[] = ['Directory Path', $expectedPath];

        if (!empty($metadata['description'])) {
            $description = $metadata['description'];
            if (strlen($description) > 80) {
                $description = substr($description, 0, 80) . '...';
            }
            $tableData[] = ['Description', $description];
        }

        if (!empty($metadata['cover_url'])) {
            $source = 'Unknown';
            if (isset($metadata['audible_raw'])) {
                $source = 'Audible';
            } elseif (isset($metadata['google_books_raw'])) {
                $source = 'Google Books';
            }
            $tableData[] = ['Cover Source', $source];
        }

        return $tableData;
    }

    /**
     * Find existing book in database (returns Book model instead of boolean)
     */
    public function findExistingBook(string $path, array $metadata = []): ?Book
    {
        $baseName = basename($path);

        if (!empty($metadata['isbn'])) {
            $existingBook = Book::where('isbn', $metadata['isbn'])->first();
            if ($existingBook) {
                return $existingBook;
            }
        }

        if (!empty($metadata['title']) && !empty($metadata['author'])) {
            $title = $metadata['title'];
            $author = is_array($metadata['author']) ? $metadata['author'][0] : $metadata['author'];

            $existingBook = Book::where('title', '=', $title)
                ->whereHas('authors', function ($query) use ($author) {
                    $query->where('name', $author);
                })
                ->first();

            if ($existingBook) {
                if (strtolower(trim($existingBook->title)) === strtolower(trim($title))) {
                    $existingSeries = $existingBook->series ?? '';
                    $existingSeriesNumber = $existingBook->series_number ?? 0;
                    $newSeries = $metadata['series'] ?? '';
                    $newSeriesNumber = $metadata['series_number'] ?? 0;

                    if ($existingSeriesNumber > 0 || $newSeriesNumber > 0) {
                        if ($existingSeriesNumber != $newSeriesNumber) {
                            return null;
                        }
                    }

                    if (!empty($existingSeries) && !empty($newSeries)) {
                        if ($existingSeries !== $newSeries) {
                            return null;
                        }
                    }

                    return $existingBook;
                }
            }
        }

        $existingBook = Book::where('directory_path', '=', $baseName)->first();

        return $existingBook;
    }

    /**
     * Check if audiobook is already imported
     */
    public function isAlreadyImported(string $path, array $metadata = []): bool
    {
        $baseName = basename($path);

        if (!empty($metadata['isbn'])) {
            $existingBook = Book::where('isbn', $metadata['isbn'])->first();
            if ($existingBook) {
                return true;
            }
        }

        if (!empty($metadata['title']) && !empty($metadata['author'])) {
            $title = $metadata['title'];
            $author = is_array($metadata['author']) ? $metadata['author'][0] : $metadata['author'];

            $existingBook = Book::where('title', '=', $title)
                ->whereHas('authors', function ($query) use ($author) {
                    $query->where('name', $author);
                })
                ->first();

            if ($existingBook) {
                if (strtolower(trim($existingBook->title)) === strtolower(trim($title))) {
                    $existingSeries = $existingBook->series ?? '';
                    $existingSeriesNumber = $existingBook->series_number ?? 0;
                    $newSeries = $metadata['series'] ?? '';
                    $newSeriesNumber = $metadata['series_number'] ?? 0;

                    if ($existingSeriesNumber > 0 || $newSeriesNumber > 0) {
                        if ($existingSeriesNumber != $newSeriesNumber) {
                            return false;
                        }
                    }

                    if (!empty($existingSeries) && !empty($newSeries)) {
                        if ($existingSeries !== $newSeries) {
                            return false;
                        }
                    }

                    return true;
                }
            }
        }

        $existingBook = Book::where('directory_path', '=', $baseName)->first();

        return $existingBook !== null;
    }

    /**
     * Group CD directories under their parent directory to treat multi-disc books as single audiobooks
     */
    public function groupCdDirectories(array $potentialBooks): array
    {
        $grouped = [];
        $cdPattern = '/^(cd|disc|disk)[\s_-]*(\d+)$/i';

        $cdDirectories = [];
        $parentDirectories = [];

        foreach ($potentialBooks as $path => $bookData) {
            $dirName = basename($path);
            if (preg_match($cdPattern, $dirName, $matches)) {
                $parentPath = dirname($path);
                $cdDirectories[$path] = [
                    'parent' => $parentPath,
                    'cd_number' => (int) $matches[2],
                    'data' => $bookData,
                ];

                if (!isset($parentDirectories[$parentPath])) {
                    $parentDirectories[$parentPath] = [
                        'path' => $parentPath,
                        'name' => basename($parentPath),
                        'files' => [],
                        'total_size' => 0,
                        'cd_count' => 0,
                    ];
                }

                $parentDirectories[$parentPath]['files'] = array_merge(
                    $parentDirectories[$parentPath]['files'],
                    $bookData['files']
                );
                $parentDirectories[$parentPath]['total_size'] += $bookData['total_size'];
                $parentDirectories[$parentPath]['cd_count']++;
            } else {
                $grouped[$path] = $bookData;
            }
        }

        foreach ($parentDirectories as $parentPath => $parentData) {
            if ($parentData['cd_count'] > 1) {
                $grouped[$parentPath] = $parentData;
            } else {
                foreach ($cdDirectories as $cdPath => $cdInfo) {
                    if ($cdInfo['parent'] === $parentPath) {
                        $grouped[$cdPath] = $cdInfo['data'];
                        break;
                    }
                }
            }
        }

        return $grouped;
    }

    /**
     * Format bytes to human-readable format
     */
    public function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Get directory modification time
     */
    public function getDirectoryModificationTime(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $latestMtime = filemtime($path);

        $files = File::allFiles($path);
        foreach ($files as $file) {
            $mtime = $file->getMTime();
            if ($mtime > $latestMtime) {
                $latestMtime = $mtime;
            }
        }

        return $latestMtime;
    }

    /**
     * Get directory size
     */
    public function getDirectorySize(string $path): int
    {
        $size = 0;
        $files = File::allFiles($path);

        foreach ($files as $file) {
            $size += $file->getSize();
        }

        return $size;
    }

    /**
     * Check if directory has CD subdirectories
     */
    public function hasCdDirectories(string $path): bool
    {
        $cdPattern = '/^(cd|disc|disk)[\s_-]*(\d+)$/i';
        $directories = File::directories($path);

        foreach ($directories as $dir) {
            if (preg_match($cdPattern, basename($dir))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Analyze file types
     */
    public function analyzeFileTypes(array $files): array
    {
        $types = [];
        foreach ($files as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $types[$ext] = ($types[$ext] ?? 0) + 1;
        }
        return $types;
    }

    /**
     * Format file types for display
     */
    public function formatFileTypes(array $fileTypes): string
    {
        $formatted = [];
        foreach ($fileTypes as $ext => $count) {
            $formatted[] = "{$ext}({$count})";
        }
        return implode(', ', $formatted);
    }

    /**
     * Analyze directory name
     */
    public function analyzeDirectoryName(string $directoryName): array
    {
        return [
            'contains_numbers' => preg_match('/\d+/', $directoryName),
            'contains_series_markers' => preg_match('/\b(book|vol|volume|part|chapter)\b/i', $directoryName),
            'has_separators' => preg_match('/[-:_]/', $directoryName),
            'word_count' => str_word_count($directoryName),
            'length' => strlen($directoryName),
        ];
    }

    /**
     * Check if directory is multi-book directory
     */
    public function isMultiBookDirectory(string $path): bool
    {
        $files = File::files($path);
        $multiBookPattern = '/\[(\d{2,3})\]/';

        foreach ($files as $file) {
            if (preg_match($multiBookPattern, $file->getFilename())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find potential duplicates
     */
    public function findPotentialDuplicates(string $path): array
    {
        $baseName = basename($path);

        return Book::where('directory_path', 'LIKE', "%{$baseName}%")
            ->orWhere('title', 'LIKE', "%{$baseName}%")
            ->limit(5)
            ->get()
            ->toArray();
    }

    /**
     * Find similar books
     */
    public function findSimilarBooks(array $audiobook): array
    {
        $baseName = basename($audiobook['path']);

        return Book::where('title', 'LIKE', "%{$baseName}%")
            ->orWhere('directory_path', 'LIKE', "%{$baseName}%")
            ->limit(10)
            ->get()
            ->toArray();
    }

    /**
     * Find duplicate paths
     */
    public function findDuplicatePaths(string $path): array
    {
        $results = [];
        $baseName = basename($path);

        $existingBooks = Book::where('directory_path', 'LIKE', "%{$baseName}%")->get();

        foreach ($existingBooks as $book) {
            $results[] = [
                'id' => $book->id,
                'title' => $book->title,
                'path' => $book->directory_path,
                'similarity' => similar_text($baseName, basename($book->directory_path)),
            ];
        }

        return $results;
    }

    /**
     * Check if metadata contains enrichment data from external sources
     */
    public function hasEnrichmentData(array $metadata): bool
    {
        $enrichmentFields = [
            'audible_raw',
            'google_books_raw',
            'audiobook_bay_raw',
            'cover_url',
        ];

        foreach ($enrichmentFields as $field) {
            if (!empty($metadata[$field])) {
                return true;
            }
        }

        if (!empty($metadata['description']) && strlen($metadata['description']) > 100) {
            return true;
        }

        return false;
    }

    /**
     * Extract tag metadata from audiobook
     */
    public function extractTagMetadataFromAudiobook(array $audiobook, AIBookProcessor $aiProcessor): array
    {
        if (empty($audiobook['files']) || !$aiProcessor) {
            return [];
        }

        $fileTags = [];
        foreach (array_slice($audiobook['files'], 0, 3) as $filePath) {
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if ($ext !== 'm4b' && $ext !== 'mp3') {
                continue;
            }

            $tags = $aiProcessor->extractFileTags($filePath);
            if (!empty($tags)) {
                $fileTags[basename($filePath)] = $tags;
            }
        }

        return $this->extractMetadataFromFileTags($fileTags);
    }

    /**
     * Extract basic metadata from audiobook
     */
    public function extractBasicMetadata(array $audiobook): array
    {
        $metadata = [
            'title' => $audiobook['name'] ?? basename($audiobook['path'] ?? ''),
            'path' => $audiobook['path'] ?? '',
            'file_count' => count($audiobook['files'] ?? []),
            'total_size' => $audiobook['total_size'] ?? 0,
        ];

        return $metadata;
    }

    /**
     * Handle low confidence metadata
     */
    public function handleLowConfidenceMetadata(array $audiobook, ?array &$aiMetadata, int $minConfidence, bool $hasCriticalTagMetadata): bool
    {
        if (!is_array($aiMetadata)) {
            $aiMetadata = [];
        }

        $tagMetadata = $this->extractTagMetadataFromAudiobook($audiobook, new AIBookProcessor());
        if (!empty($tagMetadata)) {
            $aiMetadata = $this->mergeMetadataFillMissing($aiMetadata, $tagMetadata);
        }

        $aiMetadata['confidence'] = (int) ($aiMetadata['confidence'] ?? 0);
        $aiMetadata['title'] = $aiMetadata['title'] ?? ($audiobook['name'] ?? '');
        $aiMetadata['source_path'] = $aiMetadata['source_path'] ?? ($audiobook['path'] ?? '');

        return false;
    }

    /**
     * Analyze if a cover image is a low-quality text-on-white cover
     */
    public function isTextOnWhiteCover(string $imagePath, ?CoverImageAnalysisService $coverAnalysisService): bool
    {
        if (!$coverAnalysisService) {
            return false;
        }

        return $coverAnalysisService->isTextOnWhiteCover($imagePath);
    }

    /**
     * Search for alternative book covers using Google Image Search
     */
    public function searchAlternativeCovers(array $metadata, int $limit = 3, ?GoogleImageSearchService $googleImageService): array
    {
        if (!$googleImageService) {
            return ['success' => false, 'images' => [], 'error' => 'Google Image Service not available'];
        }

        $author = is_array($metadata['author']) ? implode(' ', $metadata['author']) : ($metadata['author'] ?? '');
        $title = $metadata['title'] ?? '';

        if (empty($author) || empty($title)) {
            return ['success' => false, 'images' => [], 'error' => 'Missing author or title'];
        }

        return $googleImageService->searchBookCovers($author, $title, $limit);
    }

    /**
     * Create thumbnail image
     */
    public function createThumbnail(string $imagePath, int $width, int $height)
    {
        if (!extension_loaded('gd')) {
            return null;
        }

        $imageInfo = getimagesize($imagePath);
        if (!$imageInfo) {
            return null;
        }

        $mime = $imageInfo['mime'];

        switch ($mime) {
            case 'image/jpeg':
                $source = imagecreatefromjpeg($imagePath);
                break;
            case 'image/png':
                $source = imagecreatefrompng($imagePath);
                break;
            case 'image/gif':
                $source = imagecreatefromgif($imagePath);
                break;
            default:
                return null;
        }

        if (!$source) {
            return null;
        }

        $thumb = imagecreatetruecolor($width, $height);

        if ($mime === 'image/png') {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
            imagefill($thumb, 0, 0, $transparent);
        }

        imagecopyresampled(
            $thumb,
            $source,
            0,
            0,
            0,
            0,
            $width,
            $height,
            $imageInfo[0],
            $imageInfo[1]
        );

        imagedestroy($source);
        return $thumb;
    }

    /**
     * Build UI metadata for display
     */
    public function buildUiMetadata(array $metadata): array
    {
        $uiMetadata = $metadata;

        $coverSource = '';

        if (!empty($uiMetadata['cover_data'])) {
            $coverSource = 'Embedded';
        } elseif (!empty($uiMetadata['cover_url'])) {
            if (isset($uiMetadata['audible_raw'])) {
                $coverSource = 'Audible';
            } elseif (isset($uiMetadata['google_books_raw'])) {
                $coverSource = 'Google Books';
            } else {
                $coverSource = 'Unknown';
            }
        }

        $uiMetadata['cover_source'] = $coverSource;

        return $uiMetadata;
    }

    /**
     * Manual enrichment with comparison
     */
    public function manualEnrichmentWithComparison(array $metadata, array $audiobook, ?BookEnrichmentService $enrichmentService): array
    {
        if (!$enrichmentService) {
            return $metadata;
        }

        $enrichedData = $enrichmentService->enrichWithExternalData($metadata, ['force_refresh' => true]);
        if ($enrichedData && $enrichmentService->isValidEnrichment($metadata, $enrichedData)) {
            return array_merge($metadata, $enrichedData);
        }

        return $metadata;
    }

    /**
     * Process audiobook with AI
     */
    public function processWithAI(array $audiobook, AIBookProcessor $aiProcessor): ?array
    {
        try {
            $nfoData = $this->extractNfoData($audiobook['path']);

            $fileTags = [];
            $fileNames = [];

            foreach (array_slice($audiobook['files'], 0, 3) as $filePath) {
                $fileName = basename($filePath);
                $fileNames[] = $fileName;

                $tags = $aiProcessor->extractFileTags($filePath);
                if (!empty($tags)) {
                    $fileTags[$fileName] = $tags;
                }
            }

            $aiResult = $aiProcessor->processBookDirectory(
                $audiobook['path'],
                $fileNames,
                $fileTags,
                $nfoData
            );

            if ($aiResult) {
                $tagMetadata = $this->extractMetadataFromFileTags($fileTags);
                $aiResult = $this->mergeMetadataFillMissing($aiResult, $tagMetadata);

                $aiResult = $this->postProcessAIResult($aiResult, $audiobook);
            }

            return $aiResult;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Process audiobook using audio analysis fallback
     */
    public function processWithAudioAnalysis(array $audiobook, AIBookProcessor $aiProcessor): ?array
    {
        try {
            if (empty($audiobook['files'])) {
                return null;
            }

            $sortedFiles = $audiobook['files'];
            sort($sortedFiles, SORT_STRING);

            $firstAudioFile = $sortedFiles[0];

            if (!file_exists($firstAudioFile)) {
                return null;
            }

            $tempAudioFile = tempnam(sys_get_temp_dir(), 'audio_sample_') . '.mp3';
            $ffmpegCommand = sprintf(
                'ffmpeg -i %s -t 30 -acodec libmp3lame -ab 64k %s -y 2>/dev/null',
                escapeshellarg($firstAudioFile),
                escapeshellarg($tempAudioFile)
            );

            exec($ffmpegCommand, $output, $returnCode);

            if ($returnCode !== 0 || !file_exists($tempAudioFile)) {
                @unlink($tempAudioFile);
                return null;
            }

            $audioAnalysis = $aiProcessor->processAudioSample(
                $tempAudioFile,
                basename($audiobook['path'])
            );

            @unlink($tempAudioFile);

            if ($audioAnalysis) {
                return $audioAnalysis;
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Process a single audio file as an individual audiobook
     */
    public function processSingleAudioFile(string $filePath): ?array
    {
        if (!file_exists($filePath) || !is_file($filePath)) {
            return null;
        }

        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (!in_array($extension, $audioExtensions)) {
            return null;
        }

        $fileSize = filesize($filePath);

        if ($fileSize < 10 * 1024 * 1024) {
            return null;
        }

        return [
            'path' => $filePath,
            'name' => pathinfo($filePath, PATHINFO_FILENAME),
            'files' => [$filePath],
            'total_size' => $fileSize,
        ];
    }

    /**
     * Process a single directory as an audiobook
     */
    public function processAudiobookDirectory(string $directory): ?array
    {
        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];
        $files = [];
        $totalSize = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $extension = strtolower($file->getExtension());
                if (in_array($extension, $audioExtensions)) {
                    $files[] = $file->getPathname();
                    $totalSize += $file->getSize();
                }
            }
        }

        if (count($files) >= 1 && $totalSize > 10 * 1024 * 1024) {
            return [
                'path' => $directory,
                'name' => basename($directory),
                'files' => $files,
                'total_size' => $totalSize,
            ];
        }

        return null;
    }

    /**
     * Scan directories for audiobook folders/files
     */
    public function scanForAudiobooks(array $directories, callable $isAlreadyImportedCallback = null): array
    {
        $audiobooks = [];
        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];

        foreach ($directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            $potentialBooks = [];

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $extension = strtolower($file->getExtension());
                    if (in_array($extension, $audioExtensions)) {
                        $bookDir = $file->getPath();
                        if (!isset($potentialBooks[$bookDir])) {
                            $potentialBooks[$bookDir] = [
                                'path' => $bookDir,
                                'name' => basename($bookDir),
                                'files' => [],
                                'total_size' => 0,
                            ];
                        }
                        $potentialBooks[$bookDir]['files'][] = $file->getPathname();
                        $potentialBooks[$bookDir]['total_size'] += $file->getSize();
                    }
                }
            }

            $potentialBooks = $this->groupCdDirectories($potentialBooks);

            foreach ($potentialBooks as $bookData) {
                if (count($bookData['files']) >= 1 && $bookData['total_size'] > 10 * 1024 * 1024) {
                    if (in_array($bookData['path'], $directories)) {
                        continue;
                    }

                    if (!$isAlreadyImportedCallback || !$isAlreadyImportedCallback($bookData['path'])) {
                        $audiobooks[] = $bookData;
                    }
                }
            }
        }

        return $audiobooks;
    }

    /**
     * Process specific files or folders provided as arguments
     */
    public function processSpecificPaths(array $paths, callable $processSingleAudioFileCallback, callable $processAudiobookDirectoryCallback): array
    {
        $audiobooks = [];
        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];
        $processedDirectories = [];

        foreach ($paths as $path) {
            $normalizedPath = str_replace('\ ', ' ', $path);

            $pathsToTry = [
                $path,
                $normalizedPath,
            ];

            if (!str_starts_with($path, '/')) {
                $currentDir = getcwd();
                $pathsToTry[] = $currentDir . '/' . $path;
                $pathsToTry[] = $currentDir . '/' . $normalizedPath;

                $commonDirs = ['/media/download/audiobooks', '/media/download'];
                foreach ($commonDirs as $baseDir) {
                    $pathsToTry[] = $baseDir . '/' . $path;
                    $pathsToTry[] = $baseDir . '/' . $normalizedPath;
                }
            }

            $actualPath = null;
            foreach ($pathsToTry as $tryPath) {
                if (file_exists($tryPath)) {
                    $actualPath = $tryPath;
                    break;
                }
            }

            if (!$actualPath) {
                continue;
            }

            $path = $actualPath;

            if (is_file($path)) {
                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (in_array($extension, $audioExtensions)) {
                    $audiobook = $processSingleAudioFileCallback($path);
                    if ($audiobook) {
                        $audiobooks[] = $audiobook;
                    }
                }
            } elseif (is_dir($path)) {
                if (!in_array($path, $processedDirectories)) {
                    $audiobook = $processAudiobookDirectoryCallback($path);
                    if ($audiobook) {
                        $audiobooks[] = $audiobook;
                        $processedDirectories[] = $path;
                    }
                }
            }
        }

        return $audiobooks;
    }

    /**
     * Get embedded cover temp path
     */
    public function getEmbeddedCoverTempPath(string $coverData): ?string
    {
        $binary = base64_decode($coverData, true);
        if (!is_string($binary)) {
            $binary = $coverData;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'embedded_cover_');
        if ($tempFile === false) {
            return null;
        }

        file_put_contents($tempFile, $binary);

        return $tempFile;
    }

    /**
     * Edit metadata fields interactively
     */
    public function editMetadataFields(array $metadata, array $audiobook, callable $askInlineCallback, callable $selectWithImmediateInterruptCallback, callable $getFirstNonEmptyMetadataValueCallback, callable $extractSeriesNumberFromTitleCallback, callable $getValidGenresCallback): array
    {
        $currentTitle = $getFirstNonEmptyMetadataValueCallback($metadata, ['title', 'book_title', 'name']);
        $metadata['title'] = $askInlineCallback('Title', is_string($currentTitle) ? $currentTitle : (string) ($metadata['title'] ?? ''));

        $currentAuthor = $getFirstNonEmptyMetadataValueCallback($metadata, ['author', 'authors', 'authorName', 'author_name']);
        if (is_array($currentAuthor)) {
            $currentAuthor = implode(', ', $currentAuthor);
        }
        $newAuthor = $askInlineCallback("Author(s) (comma-separated)", $currentAuthor);
        $metadata['author'] = array_map('trim', explode(',', $newAuthor));

        $currentNarrator = $getFirstNonEmptyMetadataValueCallback($metadata, ['narrator', 'narrators', 'narratorName', 'narrator_name']);
        if (is_array($currentNarrator)) {
            $currentNarrator = implode(', ', $currentNarrator);
        }
        $newNarrator = $askInlineCallback('Narrator(s) (comma-separated)', is_string($currentNarrator) ? $currentNarrator : '');
        $metadata['narrator'] = array_map('trim', explode(',', $newNarrator));

        $validGenres = $getValidGenresCallback();
        $genreOptions = [];
        foreach ($validGenres as $idx => $g) {
            $genreOptions[$idx + 1] = $g;
        }

        $currentGenre = $getFirstNonEmptyMetadataValueCallback($metadata, ['genre', 'genres', 'genreName', 'genre_name']) ?? 'Other';
        if (is_array($currentGenre)) {
            $currentGenre = $currentGenre[0] ?? 'Other';
        }
        $currentGenreIdx = array_search($currentGenre, $validGenres);
        $defaultGenreIdx = ($currentGenreIdx !== false) ? $currentGenreIdx + 1 : count($validGenres);

        $selectedGenreIdx = $selectWithImmediateInterruptCallback("Genre", $genreOptions, (string) $defaultGenreIdx);
        $metadata['genre'] = $genreOptions[$selectedGenreIdx] ?? $currentGenre;

        $currentSeries = $getFirstNonEmptyMetadataValueCallback($metadata, ['series', 'seriesName', 'series_name']);
        $metadata['series'] = $askInlineCallback('Series', is_string($currentSeries) ? $currentSeries : (string) ($metadata['series'] ?? ''));

        $currentSeriesNumber = $getFirstNonEmptyMetadataValueCallback($metadata, ['series_number', 'seriesNumber', 'series_num', 'seriesNum']);
        $metadata['series_number'] = $askInlineCallback(
            'Series Number',
            is_scalar($currentSeriesNumber) ? (string) $currentSeriesNumber : (string) ($metadata['series_number'] ?? '')
        );

        $currentYear = $getFirstNonEmptyMetadataValueCallback($metadata, ['year', 'publishedYear', 'published_year', 'published_date']);
        if (is_string($currentYear) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $currentYear)) {
            $currentYear = substr($currentYear, 0, 4);
        }
        $metadata['year'] = $askInlineCallback('Year', is_scalar($currentYear) ? (string) $currentYear : (string) ($metadata['year'] ?? ''));

        $currentDirectory = (string) ($metadata['custom_directory_path'] ?? '');
        if ($currentDirectory === '') {
            $currentDirectory = $this->generateDirectoryPath($metadata, [
                'include_title' => true,
            ]);
        }

        $metadata['custom_directory_path'] = $askInlineCallback('Directory', $currentDirectory);

        $extractSeriesNumberFromTitleCallback($metadata);

        return $metadata;
    }

    /**
     * Build review options for metadata review
     */
    public function buildReviewOptions(string $currentCoverUrl, string $currentGenre, string $currentDirectoryPath, bool $isFinalConfirmation, callable $getValidGenresCallback): array
    {
        $validGenres = $getValidGenresCallback();
        $normalizedGenre = trim($currentGenre);
        $isGenreValid = in_array($normalizedGenre, $validGenres, true);

        $displayGenre = $normalizedGenre;
        if (strlen($displayGenre) > 16) {
            $displayGenre = substr($displayGenre, 0, 15) . '…';
        }

        $acceptLabel = $isFinalConfirmation ? 'Accept all' : 'Accept all metadata';
        if (!$isGenreValid) {
            $acceptLabel = "\e[9m{$acceptLabel}\e[0m";
        }

        $options = [
            '1' => $acceptLabel,
            '2' => $isFinalConfirmation ? 'Edit again' : 'Edit individual fields',
            '3' => $isFinalConfirmation ? 'Skip' : 'Skip this book',
            '4' => 'Update cover' . ($currentCoverUrl !== '' ? ' (has URL)' : ''),
            '5' => 'Update genre (' . $displayGenre . ')',
            '6' => 'Update directory',
            '7' => 'Request enrichment (Audible/Google Books)',
        ];

        return $options;
    }

    /**
     * Display directory comparison information
     */
    public function displayDirectoryComparison(array $comparison, callable $formatBytesCallback, callable $formatFileTypesCallback): ?array
    {
        if (isset($comparison['source']) && isset($comparison['target'])) {
            return [
                [
                    'Location',
                    'Files',
                    'Total Size',
                    'File Types',
                ],
                [
                    'Source (New)',
                    $comparison['source']['count'] ?? 0,
                    $formatBytesCallback($comparison['source']['total_size'] ?? 0),
                    $formatFileTypesCallback($comparison['source']['file_types'] ?? []),
                ],
                [
                    'Target (Existing)',
                    $comparison['target']['count'] ?? 0,
                    $formatBytesCallback($comparison['target']['total_size'] ?? 0),
                    $formatFileTypesCallback($comparison['target']['file_types'] ?? []),
                ],
            ];
        }

        return null;
    }

    /**
     * Prompt user for action when duplicate is detected but can't be compared
     * Returns action result: 'skip', 'delete', 'continue', or 'skip'
     */
    public function promptForDuplicateAction(array $options, callable $selectCallback): string
    {
        $choice = $selectCallback("Duplicate detected - choose action", $options, '1');

        $choice = strtolower(trim($choice));
        if (in_array($choice, ['1', 's', 'skip'])) {
            return 'skip';
        }

        if (in_array($choice, ['2', 'd', 'delete'])) {
            return 'delete';
        }

        if (in_array($choice, ['3', 'c', 'continue'])) {
            return 'continue';
        }

        return 'skip';
    }

    /**
     * Process a single book (used for both regular books and split multi-books)
     */
    public function processSingleBook(array $audiobook, array $metadata, callable $enrichWithExternalDataCallback, callable $isValidEnrichmentCallback, callable $generateDirectoryPathCallback, callable $createBookFromMetadataCallback, callable $moveFilesToLibraryCallback, callable $getFileOperationCallback): ?Book
    {
        $enrichedData = $enrichWithExternalDataCallback($metadata);
        if ($enrichedData) {
            if ($isValidEnrichmentCallback($metadata, $enrichedData)) {
                $metadata = array_merge($metadata, $enrichedData);
            }
        }

        $metadata['source_path'] = $audiobook['path'];
        $expectedPath = $generateDirectoryPathCallback($metadata);

        $book = $createBookFromMetadataCallback($metadata, $audiobook);

        if ($book) {
            $moveFilesToLibraryCallback($audiobook, $book, [
                'operation' => $getFileOperationCallback(),
            ]);
        }

        return $book;
    }

    /**
     * Extract series number from title and clean the title
     */
    public function extractSeriesNumberFromTitle(array &$metadata): void
    {
        if (empty($metadata['title'])) {
            return;
        }

        $title = trim($metadata['title']);

        $patterns = [
            '/^(.+?),\s*Book\s+(\d+)$/i',
            '/^(.+?)\s+Book\s+(\d+)$/i',
            '/^(.+?),\s*Volume\s+(\d+)$/i',
            '/^(.+?)\s+Volume\s+(\d+)$/i',
            '/^(.+?),\s*#(\d+)$/i',
            '/^(.+?)\s+#(\d+)$/i',
            '/^(.+?),\s*Part\s+(\d+)$/i',
            '/^(.+?)\s+Part\s+(\d+)$/i',
            '/^(.+?)\s+(\d+)$/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $title, $matches)) {
                $cleanTitle = trim($matches[1]);
                $bookNumber = (int) $matches[2];

                $metadata['title'] = $cleanTitle;
                $metadata['series_number'] = $bookNumber;

                return;
            }
        }
    }

    /**
     * Process multi-book directory by splitting into individual books
     */
    public function processMultiBookSplit(
        array $audiobook,
        array $multiBookInfo,
        array $splitGroups,
        array $aiMetadata
    ): array {
        $books = [];

        foreach ($splitGroups as $bookNumber => $fileInfos) {
            if (empty($fileInfos)) {
                continue;
            }

            $files = array_map(function ($fileInfo) {
                return $fileInfo['file'];
            }, $fileInfos);
            $bookTitle = $fileInfos[0]['title'];

            $bookMetadata = $aiMetadata;
            $bookMetadata['title'] = $bookTitle;
            $bookMetadata['series'] = $multiBookInfo['series_name'];
            $bookMetadata['series_number'] = $bookNumber;
            unset($bookMetadata['series_original']);

            $virtualAudiobook = [
                'path' => $audiobook['path'],
                'name' => $bookTitle,
                'files' => $files,
                'total_size' => array_sum(array_map('filesize', $files)),
                'is_multi_book_part' => true,
                'multi_book_files_only' => $files,
            ];

            $books[] = [
                'audiobook' => $virtualAudiobook,
                'metadata' => $bookMetadata,
            ];
        }

        return $books;
    }

    /**
     * Get duration of audio file in seconds
     */
    public function getAudioFileDuration(string $filePath): int
    {
        try {
            // Try ffprobe first (most reliable)
            $command = 'ffprobe -i ' . escapeshellarg($filePath)
                . ' -show_entries format=duration -v quiet -of csv="p=0"';
            $output = shell_exec($command);
            if ($output && is_numeric(trim($output))) {
                return (int) round(floatval(trim($output)));
            }

            // Fallback to file modification patterns
            return 0;
        } catch (\Exception $e) {
            Log::warning("Failed to get audio file duration: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get author's preferred genre based on existing books
     */
    public function getAuthorPreferredGenre($authorData): ?string
    {
        if (empty($authorData)) {
            return null;
        }

        // Handle both string and array author data
        $authorNames = is_array($authorData) ? $authorData : [$authorData];

        // Split comma-separated author strings
        $splitAuthors = [];
        foreach ($authorNames as $authorName) {
            if (strpos($authorName, ',') !== false) {
                // This is a comma-separated list of authors, split it
                $split = array_map('trim', explode(',', $authorName));
                $splitAuthors = array_merge($splitAuthors, $split);
            } else {
                $splitAuthors[] = $authorName;
            }
        }

        foreach ($splitAuthors as $authorName) {
            $authorName = trim($authorName);
            if (empty($authorName)) {
                continue;
            }

            // Find the author in the database
            $author = Author::where('name', $authorName)->first();
            if (!$author) {
                continue;
            }

            // Get genre distribution for this author's books
            $genreStats = DB::table('books')
                ->join('author_book', 'books.id', '=', 'author_book.book_id')
                ->join('book_genre', 'books.id', '=', 'book_genre.book_id')
                ->join('genres', 'book_genre.genre_id', '=', 'genres.id')
                ->where('author_book.author_id', $author->id)
                ->select('genres.name', DB::raw('COUNT(*) as count'))
                ->groupBy('genres.name')
                ->orderByDesc('count')
                ->first();

            if ($genreStats && $genreStats->count >= 2) {
                // If author has 2+ books in the same genre, use that genre
                return $genreStats->name;
            }
        }

        return null;
    }

    /**
     * Clean series name by removing author names
     */
    public function cleanSeriesName(string $seriesName, array $authors): string
    {
        $originalSeries = $seriesName;
        $cleanedSeries = $seriesName;

        // Preserve GraphicAudio markers - extract and reapply later
        $graphicAudioMarker = '';
        if (preg_match('/\(Graphic\s*Audio\)/i', $cleanedSeries, $matches)) {
            $graphicAudioMarker = ' (GraphicAudio)';
            $cleanedSeries = preg_replace('/\(Graphic\s*Audio\)/i', '', $cleanedSeries);
        }

        // First try to remove the complete author list as a combined string
        // Try both comma and & separators since both are common
        $combinedAuthorsComma = implode(', ', $authors);
        $combinedAuthorsAmpersand = implode(' & ', $authors);

        $combinedPatterns = [
            // Patterns with comma separator
            '/^' . preg_quote($combinedAuthorsComma, '/') . '\s*-\s*/i',
            '/^' . preg_quote($combinedAuthorsComma, '/') . '\s+/i',
            '/\s*-\s*' . preg_quote($combinedAuthorsComma, '/') . '$/i',
            // Patterns with & separator
            '/^' . preg_quote($combinedAuthorsAmpersand, '/') . '\s*-\s*/i',
            '/^' . preg_quote($combinedAuthorsAmpersand, '/') . '\s+/i',
            '/\s*-\s*' . preg_quote($combinedAuthorsAmpersand, '/') . '$/i',
        ];

        foreach ($combinedPatterns as $pattern) {
            $before = $cleanedSeries;
            $cleanedSeries = preg_replace($pattern, '', $cleanedSeries);
            if ($before !== $cleanedSeries) {
                $cleanedSeries = preg_replace('/^[\s\-_]+|[\s\-_]+$/', '', $cleanedSeries);
                $cleanedSeries = trim($cleanedSeries);
                if (!empty($cleanedSeries) && strlen($cleanedSeries) >= 2) {
                    return $cleanedSeries . $graphicAudioMarker;
                }
            }
        }

        // If combined pattern didn't work, try individual authors
        foreach ($authors as $author) {
            $authorName = trim($author);

            // Try different patterns to remove author names from series
            $patterns = [
                '/^' . preg_quote($authorName, '/') . '\s*-\s*/i',
                '/^' . preg_quote($authorName, '/') . '\s+/i',
                '/\s*-\s*' . preg_quote($authorName, '/') . '$/i',
                '/\s+' . preg_quote($authorName, '/') . '$/i',
            ];

            foreach ($patterns as $pattern) {
                $cleanedSeries = preg_replace($pattern, '', $cleanedSeries);
            }

            // Also try with normalized author name (with periods)
            $normalizedAuthor = $this->normalizeAuthorName($authorName);
            if ($normalizedAuthor !== $authorName) {
                $patterns = [
                    '/^' . preg_quote($normalizedAuthor, '/') . '\s*-\s*/i',
                    '/^' . preg_quote($normalizedAuthor, '/') . '\s+/i',
                    '/\s*-\s*' . preg_quote($normalizedAuthor, '/') . '$/i',
                    '/\s+' . preg_quote($normalizedAuthor, '/') . '$/i',
                ];

                foreach ($patterns as $pattern) {
                    $cleanedSeries = preg_replace($pattern, '', $cleanedSeries);
                }
            }
        }

        // Clean up any remaining separators and whitespace
        $cleanedSeries = preg_replace('/^[\s\-_]+|[\s\-_]+$/', '', $cleanedSeries);
        $cleanedSeries = trim($cleanedSeries);

        // Clean up extra spaces and common series words
        $cleanedSeries = preg_replace('/\b(series|saga|chronicles|collection)\b/i', '', $cleanedSeries);
        $cleanedSeries = preg_replace('/\s+/', ' ', $cleanedSeries);
        $cleanedSeries = trim($cleanedSeries, ' -,');

        // If we cleaned too much and ended up with nothing, return original
        if (empty($cleanedSeries) || strlen($cleanedSeries) < 2) {
            return $originalSeries;
        }

        return $cleanedSeries . $graphicAudioMarker;
    }

    /**
     * Extract metadata from audio file tags
     */
    public function extractMetadataFromFileTags(array $fileTags): array
    {
        if (empty($fileTags)) {
            return [];
        }

        $firstTags = reset($fileTags);
        if (!is_array($firstTags) || empty($firstTags)) {
            return [];
        }

        $metadata = [];

        if (!empty($firstTags['album']) && is_string($firstTags['album'])) {
            $metadata['title'] = $firstTags['album'];
        }

        if (!empty($firstTags['artist'])) {
            if (is_array($firstTags['artist'])) {
                $metadata['author'] = $firstTags['artist'];
            } else {
                $metadata['author'] = [(string) $firstTags['artist']];
            }
        }

        if (!empty($firstTags['genre']) && is_string($firstTags['genre'])) {
            $metadata['genre'] = [$firstTags['genre']];
        }

        if (!empty($firstTags['year']) && is_numeric($firstTags['year'])) {
            $metadata['year'] = (int) $firstTags['year'];
        }

        if (!empty($firstTags['narrator'])) {
            if (is_array($firstTags['narrator'])) {
                $metadata['narrator'] = array_map('strval', $firstTags['narrator']);
            } else {
                $metadata['narrator'] = [(string) $firstTags['narrator']];
            }
        } elseif (!empty($firstTags['writer']) && is_string($firstTags['writer'])) {
            $writerParts = array_map('trim', explode(',', $firstTags['writer']));
            $writerParts = array_values(array_filter(
                $writerParts,
                static fn ($value) => $value !== '' && strtolower($value) !== 'full cast'
            ));
            if (!empty($writerParts)) {
                $metadata['narrator'] = $writerParts;
            }
        }

        return $metadata;
    }

    /**
     * Add GraphicAudio marker if detected from source or metadata
     */
    public function addGraphicAudioMarker(string $title, array $metadata): string
    {
        // Check if GraphicAudio marker is already present
        if (preg_match('/\(Graphic\s*Audio\)/i', $title)) {
            return preg_replace('/\(Graphic\s*Audio\)/i', '(GraphicAudio)', $title);
        }

        // Check if series already has GraphicAudio marker - if so, don't add to title
        $series = $metadata['series'] ?? '';
        if (!empty($series) && preg_match('/\(Graphic\s*Audio\)/i', $series)) {
            return $title;
        }

        // Check various fields for GraphicAudio indicators
        $sourcePath = $metadata['source_path'] ?? '';
        $narrator = $metadata['narrator'] ?? '';
        $publisher = $metadata['publisher'] ?? '';
        $originalTitle = $metadata['original_title'] ?? $title;

        $isGraphicAudio = false;

        // Check source directory path
        if (preg_match('/\(Graphic\s*Audio\)/i', $sourcePath)) {
            $isGraphicAudio = true;
        }

        // Check narrator field (handle arrays)
        $narratorString = is_array($narrator) ? implode(' ', $narrator) : (string) $narrator;
        if (preg_match('/Graphic\s*Audio/i', $narratorString)) {
            $isGraphicAudio = true;
        }

        // Check publisher field
        if (is_array($publisher)) {
            foreach ($publisher as $p) {
                if (is_string($p) && stripos($p, 'graphic') !== false && stripos($p, 'audio') !== false) {
                    $isGraphicAudio = true;
                    break;
                }
            }
        } elseif (is_string($publisher)) {
            if (stripos($publisher, 'graphic') !== false && stripos($publisher, 'audio') !== false) {
                $isGraphicAudio = true;
            }
        }

        // Check original title
        if (is_string($originalTitle) && preg_match('/\(Graphic\s*Audio\)/i', $originalTitle)) {
            $isGraphicAudio = true;
        }

        // Check if narrator contains typical GraphicAudio cast indicators
        $graphicAudioNarratorPatterns = [
            '/full\s*cast/i',
            '/ensemble\s*cast/i',
            '/multi\s*cast/i',
            '/cast\s*of\s*voices/i',
        ];

        foreach ($graphicAudioNarratorPatterns as $pattern) {
            if (preg_match($pattern, $narratorString)) {
                $isGraphicAudio = true;
                break;
            }
        }

        // Add GraphicAudio marker if detected
        if ($isGraphicAudio && !preg_match('/\(GraphicAudio\)/i', $title)) {
            return $title . ' (GraphicAudio)';
        }

        return $title;
    }

    /**
     * Remove series names from title when they contain colons
     */
    public function removeSeriesFromTitle(string $title): string
    {
        // Handle pattern "Series: Title" - remove series before colon
        if (preg_match('/^([^:]+):\s*(.+)$/', $title, $matches)) {
            $beforeColon = trim($matches[1]);
            $afterColon = trim($matches[2]);

            // Always prioritize the part after the colon as the title
            // Exception: if the part after colon is clearly metadata (Book, Vol, etc.)
            if (preg_match('/^\b(book|vol|volume|part|chapter)\s*\d+/i', $afterColon)) {
                return $beforeColon;
            }

            return $afterColon;
        }

        // Handle pattern "Title: Series" - remove series after colon
        if (preg_match('/^(.+?):\s*([^:]+)$/', $title, $matches)) {
            $beforeColon = trim($matches[1]);
            $afterColon = trim($matches[2]);

            // If the part after colon looks like metadata/series info, keep the part before
            if (
                strlen($afterColon) < strlen($beforeColon) ||
                preg_match('/\b(series|book|vol|volume|\d+|saga|chronicles|collection)\b/i', $afterColon)
            ) {
                return $beforeColon;
            }
        }

        return $title;
    }

    /**
     * Get narrator information from audiobook metadata
     */
    public function getNarratorFromMetadata(array $audiobook): string
    {
        if (isset($audiobook['metadata']['narrator'])) {
            $narrator = $audiobook['metadata']['narrator'];
            if (is_array($narrator)) {
                return implode(', ', $narrator);
            }
            return $narrator;
        }

        // Try to extract narrator from directory name patterns
        $dirName = basename($audiobook['path']);

        $patterns = [
            '/\{([^}]+)\}/',
            '/\(([^)]+)\)$/',
            '/\[([^\]]+)\]$/',
            '/ - ([^-]+)$/',
            '/ narrated by ([^,]+)/i',
            '/ read by ([^,]+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $dirName, $matches)) {
                $narrator = trim($matches[1]);
                if (
                    !preg_match('/^\d{4}$/', $narrator) &&
                    !preg_match('/\b(book|vol|volume|series|edition|unabridged|audiobook)\b/i', $narrator)
                ) {
                    return $narrator;
                }
            }
        }

        return 'Unknown Narrator';
    }

    /**
     * Get narrator information from existing directory/book
     */
    public function getNarratorFromDirectory(string $targetDir, ?Book $existingBook): string
    {
        if ($existingBook && !empty($existingBook->narrator)) {
            return $existingBook->narrator;
        }

        $dirName = basename($targetDir);

        $patterns = [
            '/\{([^}]+)\}/',
            '/\(([^)]+)\)$/',
            '/\[([^\]]+)\]$/',
            '/ - ([^-]+)$/',
            '/ narrated by ([^,]+)/i',
            '/ read by ([^,]+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $dirName, $matches)) {
                $narrator = trim($matches[1]);
                if (
                    !preg_match('/^\d{4}$/', $narrator) &&
                    !preg_match('/\b(book|vol|volume|series|edition|unabridged|audiobook)\b/i', $narrator)
                ) {
                    return $narrator;
                }
            }
        }

        return 'Unknown Narrator';
    }

    /**
     * Get detailed information about files in a directory
     */
    public function getDirectoryInfo(string $path): array
    {
        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];
        $files = [];
        $totalSize = 0;
        $fileTypes = [];

        if (File::isFile($path)) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($extension, $audioExtensions)) {
                $size = filesize($path);
                $filename = basename($path);
                return [
                    'files' => [
                        [
                            'name' => $filename,
                            'size' => $size,
                            'extension' => $extension,
                            'hash' => md5($filename . $size),
                        ],
                    ],
                    'total_size' => $size,
                    'file_types' => [$extension => 1],
                    'count' => 1,
                ];
            }
            return [
                'files' => [],
                'total_size' => 0,
                'file_types' => [],
                'count' => 0,
            ];
        }

        if (!File::isDirectory($path)) {
            return [
                'files' => [],
                'total_size' => 0,
                'file_types' => [],
                'count' => 0,
            ];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $extension = strtolower($file->getExtension());
                if (in_array($extension, $audioExtensions)) {
                    $size = $file->getSize();
                    $files[] = [
                        'name' => $file->getFilename(),
                        'size' => $size,
                        'extension' => $extension,
                        'hash' => md5($file->getFilename() . $size),
                    ];
                    $totalSize += $size;
                    $fileTypes[$extension] = ($fileTypes[$extension] ?? 0) + 1;
                }
            }
        }

        return [
            'files' => $files,
            'total_size' => $totalSize,
            'file_types' => $fileTypes,
            'count' => count($files),
        ];
    }

    /**
     * Check if two directories have identical content
     */
    public function areDirectoriesIdentical(array $sourceFiles, array $targetFiles): bool
    {
        if ($sourceFiles['count'] !== $targetFiles['count']) {
            return false;
        }

        if ($sourceFiles['total_size'] !== $targetFiles['total_size']) {
            return false;
        }

        $sourceHashes = array_column($sourceFiles['files'], 'hash');
        $targetHashes = array_column($targetFiles['files'], 'hash');

        sort($sourceHashes);
        sort($targetHashes);

        return $sourceHashes === $targetHashes;
    }

    /**
     * Compare two directories for content differences
     */
    public function compareDirectories(string $sourcePath, string $targetPath): array
    {
        $sourceFiles = $this->getDirectoryInfo($sourcePath);
        $targetFiles = $this->getDirectoryInfo($targetPath);

        $identical = $this->areDirectoriesIdentical($sourceFiles, $targetFiles);

        return [
            'identical' => $identical,
            'source' => $sourceFiles,
            'target' => $targetFiles,
            'source_path' => $sourcePath,
            'target_path' => $targetPath,
        ];
    }

    /**
     * Parse duration string (e.g., "1:23:45") to seconds
     */
    public function parseDurationString(string $duration): int
    {
        $parts = explode(':', $duration);
        $seconds = 0;

        if (count($parts) === 3) {
            $seconds = ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
        } elseif (count($parts) === 2) {
            $seconds = ($parts[0] * 60) + $parts[1];
        } else {
            $seconds = (int) $duration;
        }

        return (int) $seconds;
    }

    /**
     * Merge metadata arrays, filling missing fields from fill into base
     */
    public function mergeMetadataFillMissing(array $base, array $fill): array
    {
        $merged = $base;

        foreach ($fill as $key => $value) {
            $current = $merged[$key] ?? null;

            $isEmpty = $current === null
                || $current === ''
                || (is_array($current) && count($current) === 0);

            $hasValue = $value !== null
                && $value !== ''
                && (!is_array($value) || count($value) > 0);

            if ($isEmpty && $hasValue) {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Check if tag metadata has critical fields
     */
    public function hasCriticalTagMetadata(array $tagMetadata): bool
    {
        if (empty($tagMetadata)) {
            return false;
        }

        $hasTitle = isset($tagMetadata['title']) && is_string($tagMetadata['title']) && trim($tagMetadata['title']) !== '';
        $hasAuthor = isset($tagMetadata['author']) && !empty($tagMetadata['author']);

        return $hasTitle && $hasAuthor;
    }

    /**
     * Check if metadata has cover
     */
    public function hasCover(array $metadata): bool
    {
        return !empty($metadata['cover_data'])
            || !empty($metadata['cover_image'])
            || !empty($metadata['cover_path'])
            || !empty($metadata['cover_url']);
    }

    /**
     * Check if metadata has critical fields
     */
    public function hasCriticalMetadata(array $metadata): bool
    {
        $hasTitle = isset($metadata['title']) && is_string($metadata['title']) && trim($metadata['title']) !== '';
        $authors = $metadata['author'] ?? [];
        $hasAuthor = !empty($authors) && (is_string($authors) ? trim($authors) !== '' : count($authors) > 0);
        $hasDescription = isset($metadata['description']) && is_string($metadata['description']) && trim($metadata['description']) !== '';

        return $hasTitle && $hasAuthor && $hasDescription && $this->hasCover($metadata);
    }

    /**
     * Check if filename is a torrent/piracy tracking file
     */
    public function isTorrentTrackingFile(string $filename): bool
    {
        $filename = strtolower($filename);

        // Common torrent/piracy tracking file patterns
        $patterns = [
            '/torrent.*download.*from/i',
            '/downloaded.*from.*\.txt$/i',
            '/\.torrent$/i',
            '/read.*me.*first.*\.txt$/i',
            '/please.*seed.*\.txt$/i',
            '/visit.*for.*more.*\.txt$/i',
            '/source.*\.txt$/i',
            '/magnet.*link.*\.txt$/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $filename)) {
                return true;
            }
        }

        // Check for specific known tracking file names
        $knownTrackingFiles = [
            'demonoid.me.txt',
            'piratebay.txt',
            'kickass.txt',
            'extratorrent.txt',
            'thepiratebay.org.txt',
            'rarbg.txt',
            'torrentday.txt',
            'iptorrents.txt',
            'what.cd.txt',
            'passthepopcorn.txt',
            'redacted.ch.txt',
            'orpheus.network.txt',
            'source.txt',
            'readme.txt',
            'read me.txt',
            'info.txt',
        ];

        foreach ($knownTrackingFiles as $trackingFile) {
            if (str_contains($filename, $trackingFile)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Post-process AI result to fix common issues with numbered series books
     */
    public function postProcessAIResult(array $aiResult, array $audiobook): array
    {
        $directoryName = basename($audiobook['path']);

        if (preg_match('/^(\d{1,2})\s*-\s*(.+)$/', $directoryName, $matches)) {
            $bookNumber = (int) $matches[1];
            $bookTitle = trim($matches[2]);

            $aiTitle = $aiResult['title'] ?? '';
            $aiSeries = $aiResult['series'] ?? '';

            if (strcasecmp($aiTitle, $bookTitle) !== 0) {
                if (empty($aiSeries) || strcasecmp($aiTitle, $aiSeries) !== 0) {
                    $aiResult['series'] = $aiTitle;
                    $aiResult['title'] = $bookTitle;
                } else {
                    $aiResult['title'] = $bookTitle;
                }
            }

            $aiResult['series_number'] = $bookNumber;
        } elseif (preg_match('/^(.+),\s*Book\s*(\d{1,2})\s*-\s*(.+)$/', $directoryName, $matches)) {
            $seriesName = trim($matches[1]);
            $bookNumber = (int) $matches[2];
            $bookTitle = trim($matches[3]);

            $aiResult['series'] = $seriesName;
            $aiResult['title'] = $bookTitle;
            $aiResult['series_number'] = $bookNumber;
        }

        if (!empty($aiResult['title'])) {
            $originalTitle = $aiResult['title'];
            $cleanedTitle = $this->removeSeriesFromTitle($originalTitle);

            if ($cleanedTitle !== $originalTitle) {
                $aiResult['title'] = $cleanedTitle;
            }
        }

        return $aiResult;
    }

    /**
     * Detect multi-book directory patterns like "Series [2-3]" or "Series [1-4]"
     */
    public function detectMultiBookPattern(string $title): ?array
    {
        $patterns = [
            '/^(.+?)\s*\[(\d+)\s*-\s*(\d+)\]$/i',           // "Series [2-3]" or "Series [2-10]"
            '/^(.+?)\s*-\s*\[(\d+)\s*-\s*(\d+)\]$/i',        // "Series - [2-3]"
            '/^(.+?)\s*\((\d+)\s*-\s*(\d+)\)$/i',           // "Series (2-3)"
            '/^(.+?)\s*-\s*\((\d+)\s*-\s*(\d+)\)$/i',        // "Series - (2-3)"
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $title, $matches)) {
                $seriesName = trim($matches[1]);
                $startNumber = (int) $matches[2];
                $endNumber = (int) $matches[3];

                if ($endNumber > $startNumber && ($endNumber - $startNumber) <= 20) {
                    return [
                        'series_name' => $seriesName,
                        'start_number' => $startNumber,
                        'end_number' => $endNumber,
                        'numbers' => range($startNumber, $endNumber),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Analyze files in multi-book directory to determine if they can be split
     */
    public function analyzeMultiBookFiles(array $audiobook, array $multiBookInfo): array
    {
        $files = $audiobook['files'];
        $numbers = $multiBookInfo['numbers'];
        $splitGroups = [];

        foreach ($files as $filePath) {
            $filename = basename($filePath);
            $title = $this->extractBookTitleFromFilename($filename, $multiBookInfo['series_name'], 0);

            foreach ($numbers as $number) {
                if (stripos($title, (string) $number) !== false || stripos($filename, (string) $number) !== false) {
                    if (!isset($splitGroups[$number])) {
                        $splitGroups[$number] = [];
                    }
                    $splitGroups[$number][] = [
                        'file' => $filePath,
                        'title' => $title,
                    ];
                    break;
                }
            }

            if (!isset($splitGroups[$number])) {
                if (!isset($splitGroups['unmatched'])) {
                    $splitGroups['unmatched'] = [];
                }
                $splitGroups['unmatched'][] = [
                    'file' => $filePath,
                    'title' => $title,
                ];
            }
        }

        return $splitGroups;
    }

    /**
     * Extract individual book title from filename
     */
    public function extractBookTitleFromFilename(string $filename, string $seriesName, int $bookNumber): string
    {
        $name = preg_replace('/\.[^.]+$/', '', $filename);

        $name = preg_replace('/^(\d+)[\s\-_\.]+/', '', $name);
        $name = preg_replace('/^Track[\s_]*(\d+)[\s\-_\.]+/i', '', $name);
        $name = preg_replace('/^CD[\s_]*(\d+)[\s\-_\.]+/i', '', $name);
        $name = preg_replace('/^Disc[\s_]*(\d+)[\s\-_\.]+/i', '', $name);

        $name = str_ireplace($seriesName, '', $name);
        $name = str_ireplace((string) $bookNumber, '', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        $name = trim($name, ' -_');

        if (empty($name) || strlen($name) < 2) {
            $name = $seriesName . " Book " . $bookNumber;
        }

        return $name;
    }

    /**
     * Extract book number from filename
     */
    public function extractBookNumberFromFilename(string $filename): ?int
    {
        $patterns = [
            '/^(\d{1,2})[\s\-_\.]+/',
            '/^Book[\s_]*(\d{1,2})/i',
            '/^Part[\s_]*(\d{1,2})/i',
            '/[\s\-_](\d{1,2})[\s\-_\.]+/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $filename, $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    /**
     * Convert absolute path to relative path by removing book root
     */
    protected function makePathRelative(string $absolutePath, string $bookRoot): string
    {
        $bookRoot = rtrim($bookRoot, '/');
        $absolutePath = rtrim($absolutePath, '/');

        // Try with trailing slash first
        if (str_starts_with($absolutePath, $bookRoot . '/')) {
            return substr($absolutePath, strlen($bookRoot) + 1);
        }

        // Try exact match
        if (str_starts_with($absolutePath, $bookRoot)) {
            return ltrim(substr($absolutePath, strlen($bookRoot)), '/');
        }

        // If path is already relative, return as-is
        if (!str_starts_with($absolutePath, '/')) {
            return $absolutePath;
        }

        // CRITICAL: Try alternative book root paths
        // config('app.book_root') and config('filesystems.disks.books.root') might differ
        $alternativeRoots = [
            config('app.book_root'),
            config('filesystems.disks.books.root'),
            env('BOOK_STORAGE_PATH'),
        ];

        foreach ($alternativeRoots as $altRoot) {
            if (!$altRoot) {
                continue;
            }
            $altRoot = rtrim($altRoot, '/');
            if (str_starts_with($absolutePath, $altRoot . '/')) {
                return substr($absolutePath, strlen($altRoot) + 1);
            }
            if (str_starts_with($absolutePath, $altRoot)) {
                return ltrim(substr($absolutePath, strlen($altRoot)), '/');
            }
        }

        // CRITICAL: If we still have an absolute path, throw an error instead of storing it
        // This prevents database corruption with absolute paths
        throw new \Exception("Cannot convert absolute path to relative: {$absolutePath} (book root: {$bookRoot})");
    }

    /**
     * Validate and map genre to valid primary genre
     * Prevents creation of invalid genre directories
     */
    protected function validateAndMapGenre(string $genreName): string
    {
        $validGenres = [
            'Science Fiction',
            'Fantasy',
            'LitRPG',
            'Romance',
            'History',
            'Historical Fiction',
            'Non Fiction',
            'Religion',
            'Church',
            'Kids',
            'Action',
            'Classic',
            'General Fiction',
            'Computer',
            'Western',
            'Horror',
            'Mystery',
            'Other',
            'Science',
        ];

        // If already a valid genre, return as-is
        if (in_array($genreName, $validGenres)) {
            return $genreName;
        }

        // Map to valid primary genre using GenreMappingService
        return $this->genreMappingService->mapToPrimaryGenre($genreName);
    }

    /**
     * Extract NFO data in background
     */
    public function extractNfoDataInBackground(string $nfoPath): array
    {
        $data = [];

        if (file_exists($nfoPath)) {
            $content = file_get_contents($nfoPath);

            if (strpos($content, '<?xml') !== false) {
                try {
                    $xml = simplexml_load_string($content);
                    if ($xml) {
                        $data['title'] = (string) $xml->title ?? null;
                        $data['plot'] = (string) $xml->plot ?? null;
                        $data['year'] = (string) $xml->year ?? null;
                        $data['genre'] = (string) $xml->genre ?? null;
                    }
                } catch (\Exception $e) {
                }
            }

            $lines = explode("\n", $content);
            foreach ($lines as $line) {
                if (strpos($line, ':') !== false) {
                    [$key, $value] = explode(':', $line, 2);
                    $data[strtolower(trim($key))] = trim($value);
                }
            }
        }

        return $data;
    }

    /**
     * Show background processing status
     */
    public function showBackgroundProcessingStatus(array $backgroundTasks): string
    {
        $completed = 0;
        $failed = 0;
        $pending = 0;

        foreach ($backgroundTasks as $task) {
            switch ($task['status']) {
                case 'completed':
                    $completed++;
                    break;
                case 'failed':
                    $failed++;
                    break;
                case 'pending':
                    $pending++;
                    break;
            }
        }

        if ($completed > 0 || $failed > 0) {
            $status = "🔄 Background: {$completed} completed";
            if ($failed > 0) {
                $status .= ", {$failed} failed";
            }
            if ($pending > 0) {
                $status .= ", {$pending} pending";
            }
            return $status;
        }

        return '';
    }

    /**
     * Show enhanced background processing status
     */
    public function showEnhancedBackgroundStatus(array $backgroundTasks, int $taskQueueCount): string
    {
        $completed = 0;
        $failed = 0;
        $processing = 0;
        $cached = 0;

        foreach ($backgroundTasks as $task) {
            switch ($task['status']) {
                case 'completed':
                    $completed++;
                    if (isset($task['result']['from_cache']) && $task['result']['from_cache']) {
                        $cached++;
                    }
                    break;
                case 'failed':
                    $failed++;
                    break;
                case 'processing':
                    $processing++;
                    break;
            }
        }

        if ($processing > 0 || $completed > 0 || $taskQueueCount > 0) {
            $parts = [];

            if ($processing > 0) {
                $parts[] = "{$processing} running";
            }
            if ($taskQueueCount > 0) {
                $parts[] = "{$taskQueueCount} queued";
            }
            if ($completed > 0) {
                $parts[] = "{$completed} done";
                if ($cached > 0) {
                    $parts[] = "{$cached} cached";
                }
            }
            if ($failed > 0) {
                $parts[] = "{$failed} failed";
            }

            return "🔄 Background: " . implode(', ', $parts);
        }

        return '';
    }

    /**
     * Show cost estimate for AI processing
     */
    public function showCostEstimate(int $bookCount, callable $estimateBatchCostCallback, callable $warnCallback, callable $errorCallback, callable $infoCallback, callable $optionCallback): void
    {
        $costEstimate = $estimateBatchCostCallback($bookCount);

        if ($costEstimate['total_cost'] > 0) {
            $warnCallback(
                "💰 Estimated AI processing cost: \${$costEstimate['total_cost']} " .
                "(\${$costEstimate['cost_per_book']} per book)"
            );

            if ($costEstimate['total_cost'] > 1.0) {
                $errorCallback("⚠️  High cost operation (>\$1.00) - use --force to proceed");
                if (!$optionCallback('force')) {
                    exit(1);
                }
            }
        } else {
            $infoCallback("💰 Using free tier AI model - no cost");
        }
    }

    /**
     * Display processing summary
     */
    public function displaySummary(int $totalFound, array $processedBooks, array $failedBooks, array $skippedBooks, callable $infoCallback, callable $warnCallback, callable $lineCallback, callable $getTotalCostCallback): void
    {
        $infoCallback('📊 Import Summary:');

        if (!empty($processedBooks)) {
            $infoCallback('✅ Successfully Imported:');
            foreach ($processedBooks as $book) {
                $lineCallback("  📚 {$book['title']} (ID: {$book['book_id']})");
            }
        }

        if (!empty($failedBooks)) {
            $warnCallback('❌ Failed Imports:');
            foreach ($failedBooks as $failed) {
                $lineCallback("  🚫 {$failed['path']}: {$failed['error']}");
            }
        }

        if (!empty($skippedBooks)) {
            $infoCallback('⏭️  Skipped:');
            foreach ($skippedBooks as $skipped) {
                $lineCallback("  ⚠️  {$skipped['path']}: {$skipped['reason']}");
            }
        }

        $totalCost = $getTotalCostCallback();
        if ($totalCost > 0) {
            $infoCallback("💰 Total AI cost: \${$totalCost}");
        }
    }

    /**
     * Display available cover options
     */
    public function displayCoverOptions(array $coverOptions, callable $displayCoverImageCallback): void
    {
        foreach ($coverOptions as $index => $option) {
            $label = ($index + 1) . '. ' . $option['label'];
            $displayCoverImageCallback($option['url']);
        }
    }

    /**
     * Prompt user to select a cover from available options
     */
    public function promptForCoverSelection(array $coverOptions, callable $choiceCallback, callable $askCallback): ?string
    {
        if (empty($coverOptions)) {
            return null;
        }

        $choices = [];
        foreach ($coverOptions as $index => $option) {
            $choices[(string) ($index + 1)] = $option['label'];
        }
        $choices['0'] = 'None - skip cover';
        $choices['u'] = 'Enter custom URL';

        $selection = $choiceCallback('Select a cover image', $choices, '1');

        if ($selection === '0' || $selection === 'None - skip cover') {
            return '';
        }

        if ($selection === 'u' || $selection === 'Enter custom URL') {
            $customUrl = $askCallback('Enter cover URL');
            return $customUrl ? trim($customUrl) : null;
        }

        foreach ($coverOptions as $index => $option) {
            if ($option['label'] === $selection || (string) ($index + 1) === $selection) {
                return $option['url'];
            }
        }

        return null;
    }
}
