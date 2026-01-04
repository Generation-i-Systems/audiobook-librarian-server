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
            $series = $this->addGraphicAudioMarker($metadata['series'], $metadata);
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

        if ($relativePath === null) {
            $authorDir = $this->formatAuthorsForDirectory($authors);

            $metadata = [
                'author' => $authors,
                'genre' => $genre,
                'series' => $seriesName,
                'series_number' => $seriesNumber,
                'title' => $book->title,
            ];

            $relativePath = $this->generateDirectoryPath($metadata, $options);
        }

        $path = rtrim($basePath, '/') . '/' . ltrim($relativePath, '/');

        // Always include title in path unless explicitly disabled
        if (!isset($options['include_title_in_path']) || $options['include_title_in_path'] !== false) {
            $title = $book->title;

            $plainTitle = $book->title;

            // If we have a series number, prefix it to the title
            if (!empty($seriesNumber)) {
                $formattedNumber = str_pad($seriesNumber, 2, '0', STR_PAD_LEFT);
                $title = $formattedNumber . ' ' . $title;
            }

            $segments = explode('/', trim($path, '/'));
            $lastSegment = end($segments) ?: '';

            if (strcasecmp($lastSegment, $title) === 0) {
                return $path;
            }

            if (!empty($seriesNumber) && $this->lastPathSegmentMatchesTitle($lastSegment, $plainTitle)) {
                array_pop($segments);
                $segments[] = $title;

                return '/' . implode('/', $segments);
            }

            if ($this->lastPathSegmentMatchesTitle($lastSegment, $plainTitle)) {
                return $path;
            }

            $path .= "/{$title}";
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
            $relativePath = $file->getRelativePathname();
            $targetFile = "{$target}/{$relativePath}";

            $targetSubDir = dirname($targetFile);
            if (!File::isDirectory($targetSubDir)) {
                File::makeDirectory($targetSubDir, 0775, true);
                $this->setDirectoryOwnership($targetSubDir);
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
            $relativePath = $file->getRelativePathname();
            $targetFile = "{$target}/{$relativePath}";

            $targetSubDir = dirname($targetFile);
            if (!File::isDirectory($targetSubDir)) {
                File::makeDirectory($targetSubDir, 0775, true);
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
    protected function formatAuthorsForDirectory(array $authors): string
    {
        $normalizedAuthors = array_map([$this, 'normalizeAuthorName'], $authors);
        return implode(' & ', $normalizedAuthors);
    }

    /**
     * Normalize author names - extract actual author from patterns
     * Examples:
     *   "Graphic Audio [Alex Archer]" -> "Alex Archer"
     *   "GraphicAudio [John Smith]" -> "John Smith"
     *
     * CRITICAL: Author will NEVER contain "Graphic" AND "Audio" - this is always invalid
     */
    protected function normalizeAuthorName(string $authorName): string
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
    public function downloadCoverImage(string $imageUrl, string $directoryPath, string $source = 'unknown'): ?string
    {
        try {
            // CRITICAL: directoryPath is RELATIVE - convert to absolute
            $bookRoot = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
            $absoluteDir = $bookRoot . '/' . ltrim($directoryPath, '/');

            // Create directory if it doesn't exist
            if (!is_dir($absoluteDir)) {
                mkdir($absoluteDir, 0775, true);
            }

            // Check if this is a local file path instead of a URL
            $localImagePath = $imageUrl;
            if (str_starts_with($imageUrl, 'file://')) {
                $parsedPath = parse_url($imageUrl, PHP_URL_PATH);
                if (is_string($parsedPath) && $parsedPath !== '') {
                    $localImagePath = $parsedPath;
                }
            }

            if (file_exists($localImagePath) && is_file($localImagePath)) {
                // It's a local file - copy it instead of downloading
                $extension = strtolower(pathinfo($localImagePath, PATHINFO_EXTENSION));
                if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                    $extension = 'jpg';
                }
                $filename = "cover_{$source}.{$extension}";
                $destPath = "{$absoluteDir}/{$filename}";

                if (File::copy($localImagePath, $destPath)) {
                    chmod($destPath, 0664);
                    return $filename;
                }
                return null;
            }

            // It's a URL - download it
            $imageData = file_get_contents($imageUrl);
            if (!$imageData) {
                return null;
            }

            $extension = $this->getImageExtensionFromUrl($imageUrl);
            $filename = "cover_{$source}.{$extension}";
            $filePath = "{$absoluteDir}/{$filename}";

            if (file_put_contents($filePath, $imageData)) {
                // Set file permissions for cover image
                chmod($filePath, 0664);
                return $filename;
            }
        } catch (\Exception $e) {
            Log::warning("Failed to download cover image: " . $e->getMessage(), [
                'url' => $imageUrl,
                'directory' => $directoryPath,
            ]);
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
     * Handle directory conflict resolution
     */
    public function handleDirectoryConflict(array $audiobook, string $targetDir): string
    {
        $originalTargetDir = $targetDir;
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
    protected function calculateAudioInfo(array $audioFiles): array
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
     * Get duration of audio file in seconds
     */
    protected function getAudioFileDuration(string $filePath): int
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
    protected function cleanSeriesName(string $seriesName, array $authors): string
    {
        $cleanedSeries = $seriesName;

        foreach ($authors as $author) {
            $authorWords = explode(' ', trim($author));
            foreach ($authorWords as $word) {
                if (strlen($word) > 2) { // Only remove meaningful words, not initials
                    $cleanedSeries = preg_replace('/\b' . preg_quote($word, '/') . '\b/i', '', $cleanedSeries);
                }
            }
        }

        // Clean up extra spaces and common series words
        $cleanedSeries = preg_replace('/\b(series|saga|chronicles|collection)\b/i', '', $cleanedSeries);
        $cleanedSeries = preg_replace('/\s+/', ' ', $cleanedSeries);
        $cleanedSeries = trim($cleanedSeries, ' -,');

        return $cleanedSeries ?: $seriesName; // Return original if cleaning resulted in empty string
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

        // Check various fields for GraphicAudio indicators
        $sourcePath = $metadata['source_path'] ?? '';
        $narrator = $metadata['narrator'] ?? '';
        $publisher = $metadata['publisher'] ?? '';

        $isGraphicAudio = false;

        // Check source path
        if (stripos($sourcePath, 'graphic') !== false && stripos($sourcePath, 'audio') !== false) {
            $isGraphicAudio = true;
        }

        // Check narrator field
        if (is_array($narrator)) {
            foreach ($narrator as $n) {
                if (stripos($n, 'graphic') !== false && stripos($n, 'audio') !== false) {
                    $isGraphicAudio = true;
                    break;
                }
            }
        } elseif (is_string($narrator)) {
            if (stripos($narrator, 'graphic') !== false && stripos($narrator, 'audio') !== false) {
                $isGraphicAudio = true;
            }
        }

        // Check publisher field
        if (is_array($publisher)) {
            foreach ($publisher as $p) {
                if (stripos($p, 'graphic') !== false && stripos($p, 'audio') !== false) {
                    $isGraphicAudio = true;
                    break;
                }
            }
        } elseif (is_string($publisher)) {
            if (stripos($publisher, 'graphic') !== false && stripos($publisher, 'audio') !== false) {
                $isGraphicAudio = true;
            }
        }

        if ($isGraphicAudio && !preg_match('/\(GraphicAudio\)/i', $title)) {
            return $title . ' (GraphicAudio)';
        }

        return $title;
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
}
