<?php

namespace App\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Publisher;
use App\Models\Series;
use App\Traits\HandlesLibraryJson;
use App\Exceptions\MergeIntoParentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BookImportService
{
    use HandlesLibraryJson;

    protected GenreMappingService $genreMappingService;
    protected SourceTrashService $sourceTrashService;
    protected ?OpenAudibleParser $openAudibleParser = null;
    protected array $config = [];
    protected array $multiBookSharedOverrides = [];

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

    public function __construct(
        GenreMappingService $genreMappingService,
        SourceTrashService $sourceTrashService,
        ?OpenAudibleParser $openAudibleParser = null
    ) {
        $this->genreMappingService = $genreMappingService;
        $this->sourceTrashService = $sourceTrashService;
        $this->openAudibleParser = $openAudibleParser ?? app(OpenAudibleParser::class);
    }

    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function getAllBooks(bool $processAll = false): array
    {
        $query = Book::with(['authors', 'narrators', 'genres', 'series', 'publisher']);

        if (!$processAll) {
            $query->where(function ($q) {
                $q->whereNull('last_library_json_update')
                    ->orWhereNull('directory_path');
            });
        }

        return $query->get()->map(static function (Book $book): array {
            return $book->toArray();
        })->toArray();
    }

    public function previewLibraryJson(array $book): array
    {
        return $this->prepareBookDataForJson($book);
    }

    public function generateLibraryJson(array $book, bool $dryRun = false): bool
    {
        if ($dryRun) {
            return true;
        }

        return $this->updateLibraryJson($book);
    }

    public function resolveBookDirectoryPath(array $book): ?string
    {
        $relativePath = $book['directory_path'] ?? $book['directoryPath'] ?? null;

        if (!is_string($relativePath) || $relativePath === '') {
            return null;
        }

        return (string) \Illuminate\Support\Facades\Storage::disk('books')->path($relativePath);
    }

    /**
     * Look up book metadata from OpenAudible books.json if available
     *
     * This automatically detects if the audio file is in an OpenAudible directory
     * and returns rich metadata including chapters, genre, series, etc.
     *
     * @param array $audiobook Audiobook data with 'path' and optionally 'files'
     * @return array|null Normalized metadata from OpenAudible or null if not available
     */
    public function lookupOpenAudibleMetadata(array $audiobook): ?array
    {
        $path = $audiobook['path'] ?? null;
        if (!$path) {
            return null;
        }

        // Detect if this is in an OpenAudible directory
        $openAudibleDir = $this->openAudibleParser->detectOpenAudibleDirectory($path);
        if (!$openAudibleDir) {
            return null;
        }

        Log::info('BookImportService: Detected OpenAudible directory', [
            'path' => $path,
            'openaudible_dir' => $openAudibleDir,
        ]);

        // Find the primary audio file
        $audioFile = null;
        if (!empty($audiobook['files'])) {
            foreach ($audiobook['files'] as $file) {
                if (preg_match('/\.(m4b|m4a|mp3)$/i', $file)) {
                    $audioFile = $file;
                    break;
                }
            }
        }

        // If no files array, check if path is directly to a file
        if (!$audioFile && is_file($path)) {
            $audioFile = $path;
        }

        // Try finding .m4b file in the directory
        if (!$audioFile && is_dir($path)) {
            $m4bFiles = glob($path . '/*.m4b');
            if (!empty($m4bFiles)) {
                $audioFile = $m4bFiles[0];
            }
        }

        if (!$audioFile) {
            Log::debug('BookImportService: No audio file found for OpenAudible lookup', [
                'path' => $path,
            ]);
            return null;
        }

        // Look up the book in OpenAudible's books.json
        $metadata = $this->openAudibleParser->findBookByAudioFile($audioFile, $openAudibleDir);

        if ($metadata) {
            Log::info('BookImportService: Found OpenAudible metadata', [
                'title' => $metadata['title'] ?? 'Unknown',
                'author' => $metadata['author'] ?? 'Unknown',
                'has_chapters' => !empty($metadata['chapters']),
                'chapter_count' => count($metadata['chapters'] ?? []),
            ]);
        }

        return $metadata;
    }

    /**
     * Merge OpenAudible metadata with existing metadata
     *
     * OpenAudible metadata takes priority except for cover (use M4B embedded)
     *
     * @param array $existingMetadata Existing metadata from file tags or AI
     * @param array $openAudibleMetadata Metadata from OpenAudible books.json
     * @return array Merged metadata
     */
    public function mergeWithOpenAudibleMetadata(array $existingMetadata, array $openAudibleMetadata): array
    {
        // Start with existing metadata
        $merged = $existingMetadata;

        // Fields to always take from OpenAudible (they have the best data)
        $priorityFields = [
            'title',
            'description',
            'author',
            'narrator',
            'genre',
            'mapped_genre',
            'series',
            'series_name',
            'series_number',
            'series_sequence',
            'publisher',
            'release_date',
            'language',
            'asin',
            'chapters',
            'rating_average',
            'rating_count',
        ];

        foreach ($priorityFields as $field) {
            if (!empty($openAudibleMetadata[$field])) {
                $merged[$field] = $openAudibleMetadata[$field];
            }
        }

        // Copy duration if not already set from actual audio files
        if (empty($merged['duration']) && !empty($openAudibleMetadata['seconds'])) {
            $merged['duration'] = $openAudibleMetadata['seconds'];
        }

        // Mark as coming from OpenAudible (enables skip enrichment)
        $merged['source'] = 'openaudible';
        $merged['skip_enrichment'] = true;

        // Keep original genre for reference
        if (!empty($openAudibleMetadata['original_genre'])) {
            $merged['original_genre'] = $openAudibleMetadata['original_genre'];
        }

        // IMPORTANT: Use mapped_genre as the primary genre for import flow
        // This ensures the library genre is used instead of raw OpenAudible genre
        if (!empty($openAudibleMetadata['mapped_genre'])) {
            $mapped = $openAudibleMetadata['mapped_genre'];
            $allGenres = $openAudibleMetadata['all_genres'] ?? [];

            // Build a unique list of genres with the mapped one first
            $genres = [$mapped];
            foreach ($allGenres as $g) {
                if (trim($g) !== '' && strcasecmp(trim($g), $mapped) !== 0) {
                    $genres[] = trim($g);
                }
            }
            $merged['genre'] = array_values(array_unique($genres));
        }

        // IMPORTANT: Do NOT override cover from OpenAudible
        // We want to use the embedded M4B cover which is better quality
        // The calling code should extract and use cover_data from the M4B file

        Log::debug('BookImportService: Merged OpenAudible metadata', [
            'title' => $merged['title'] ?? 'Unknown',
            'has_chapters' => !empty($merged['chapters']),
            'skip_enrichment' => $merged['skip_enrichment'] ?? false,
        ]);

        return $merged;
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

    /**
     * Resolve a file conflict when a file already exists at the target path.
     *
     * Calls $handleFileConflictCallback($sourcePath, $targetPath, $type) where $type
     * is 'audio' or 'non-audio'. The callback must return one of:
     *   'keep'        — leave the existing file, skip the incoming one
     *   'replace'     — overwrite the existing file with the incoming one
     *   'rename:name' — move the incoming file to "$targetDir/name" instead
     *
     * When no callback is provided, defaults to 'keep' (safe fallback that never
     * silently destroys data).
     *
     * Returns the resolved target path to write to, or null to skip.
     */
    protected function resolveFileConflict(
        string $sourcePath,
        string $targetPath,
        string $type,
        ?callable $handleFileConflictCallback
    ): ?string {
        if ($handleFileConflictCallback === null) {
            Log::warning("File conflict — no callback provided, keeping existing file: {$targetPath}");
            return null;
        }

        $resolution = $handleFileConflictCallback($sourcePath, $targetPath, $type);

        if ($resolution === 'keep') {
            return null;
        }

        if ($resolution === 'replace') {
            return $targetPath;
        }

        if (is_string($resolution) && str_starts_with($resolution, 'rename:')) {
            $newName = substr($resolution, strlen('rename:'));
            return dirname($targetPath) . '/' . $newName;
        }

        Log::warning("Unknown file conflict resolution '{$resolution}', keeping existing file: {$targetPath}");
        return null;
    }

    protected function moveNonAudioFilesToDirectory(string $sourceDir, string $targetDir, ?callable $handleFileConflictCallback = null): void
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

            if (file_exists($targetPath)) {
                $resolved = $this->resolveFileConflict($path, $targetPath, 'non-audio', $handleFileConflictCallback);
                if ($resolved === null) {
                    continue;
                }
                $targetPath = $resolved;
            }

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
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
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
        // Validate that author is not listed as series - clear series if it matches author
        $authors = $metadata['author'] ?? [];
        $authors = is_array($authors) ? $authors : [$authors];
        $seriesName = $metadata['series'] ?? null;

        if ($seriesName && !empty($authors)) {
            $seriesNormalized = strtolower(trim($seriesName));
            foreach ($authors as $authorName) {
                $authorNormalized = strtolower(trim($authorName));
                if ($authorNormalized === $seriesNormalized) {
                    Log::info('Clearing series that matches author', [
                        'title' => $metadata['title'] ?? 'Unknown',
                        'author' => $authorName,
                        'series' => $seriesName,
                    ]);
                    $metadata['series'] = null;
                    $metadata['series_number'] = null;
                    break;
                }
            }
        }

        $existingBook = $this->findExistingBook((string) ($audiobook['path'] ?? ''), $metadata);
        if ($existingBook instanceof Book) {
            $metadataForUpdate = $metadata;
            if (empty($existingBook->directory_path)) {
                $metadataForUpdate['custom_directory_path'] = $this->generateDirectoryPath(
                    $metadata,
                    ['include_title' => true]
                );
            }

            Log::info('Reusing existing book record before create', [
                'existing_book_id' => $existingBook->id,
                'existing_directory_path' => $existingBook->directory_path,
                'incoming_source_path' => $audiobook['path'] ?? null,
                'incoming_title' => $metadata['title'] ?? null,
            ]);

            return $this->updateBookFromMetadata($existingBook, $metadataForUpdate, $audiobook, $options);
        }

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

            // Cover image processing is deferred to processCoverImage() to prevent premature directory creation
            // which causes conflict detection in moveFilesToLibrary()

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
                    $name = trim((string) $genreName);
                    if ($name === '') {
                        continue;
                    }

                    // Map the genre to a valid library genre
                    // If genre is forced via config, we skip mapping to ensure user intent
                    if (empty($this->config['genre']) || $name !== trim((string) $this->config['genre'])) {
                        $name = $this->validateAndMapGenre($name);
                    }

                    $genre = Genre::firstOrCreate(['name' => $name]);

                    // Avoid duplicate genres (e.g. if multiple raw genres map to same library genre)
                    if (!isset($genresToAttach[$genre->id])) {
                        $genresToAttach[$genre->id] = ['is_primary' => $isPrimary];
                        $isPrimary = false; // Subsequent genres are not primary
                    }
                }

                if (!empty($genresToAttach)) {
                    $book->genres()->sync($genresToAttach);
                    $book->unsetRelation('genres');
                }
            }

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
     * Process cover image (deferred execution)
     */
    public function processCoverImage(Book $book, array $metadata): void
    {
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

        if ($book->isDirty('cover_image')) {
            $book->save();
        }
    }

    /**
     * Update existing book from metadata
     */

    /**
     * Build a metadata array from an existing Book model, suitable for use in
     * editMetadataFields() and updateBookFromMetadata(). This is the inverse of
     * createBookFromMetadata() and is used when re-editing an already-imported book.
     */
    public function buildMetadataFromBook(Book $book): array
    {
        $authors = $book->authors->pluck('name')->toArray();
        $narrators = $book->narrators->pluck('name')->toArray();
        $genres = $book->genres->pluck('name')->toArray();

        $series = $book->series->first();
        $seriesName = $series !== null ? ($series->name ?? '') : '';
        $seriesNumber = null;
        if ($series) {
            $pivot = $series->pivot ?? null;
            $seriesNumber = $pivot !== null ? ($pivot->series_number ?? null) : null;
        }

        return [
            'title'          => $book->title ?? '',
            'author'         => $authors,
            'narrator'       => $narrators,
            'genre'          => $genres[0] ?? '',
            'series'         => $seriesName,
            'series_number'  => $seriesNumber,
            'year'           => $book->release_date?->year,
            'description'    => $book->description ?? '',
            'language'       => $book->language ?? '',
            'isbn'           => $book->isbn ?? '',
            'asin'           => $book->asin ?? '',
            'publisher'      => $book->publisher ?? '',
            'cover_url'      => '',
            'confidence'     => 100,
            'source_path'    => $book->directory_path ?? '',
        ];
    }

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
                    $name = trim((string) $genreName);
                    if ($name === '') {
                        continue;
                    }

                    if ($isPrimary) {
                        // Map the first (primary) genre to a valid library genre
                        $name = $this->validateAndMapGenre($name);
                    }

                    $genre = Genre::firstOrCreate(['name' => $name]);

                    // Avoid duplicate genres
                    if (!isset($genresToAttach[$genre->id])) {
                        $genresToAttach[$genre->id] = ['is_primary' => $isPrimary];
                        $isPrimary = false; // Subsequent genres are not primary
                    }
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

        // Handle custom directory pattern if provided
        $pattern = $options['directory_pattern'] ?? $this->config['directory_pattern'] ?? null;
        if ($pattern && str_contains($pattern, '[')) {
            return $this->generatePathFromPattern($pattern, $metadata, $options);
        }

        $structure = $options['directory_structure'] ?? $this->config['directory_structure'] ?? 'genre/author/series';
        $includeNarrator = $options['include_narrator'] ?? $this->config['include_narrator'] ?? false;
        $authors = is_array($metadata['author']) ? $metadata['author'] : [$metadata['author']];

        // Handle comma-separated authors
        if (count($authors) === 1 && strpos($authors[0], ',') !== false) {
            $authors = array_map('trim', explode(',', $authors[0]));
        }

        // Handle genre - use the genre field (which contains the mapped primary genre first)
        $genreData = $metadata['genre'] ?? 'Unknown';
        $genre = is_array($genreData) ? ($genreData[0] ?? 'Unknown') : $genreData;

        // If genre is colon-separated (raw format), just take the first part
        if (is_string($genre) && str_contains($genre, ':')) {
            $genre = trim(explode(':', $genre)[0]);
        }
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

            // Add narrator if requested
            if ($includeNarrator) {
                $narrators = $metadata['narrator'] ?? null;
                if ($narrators !== null) {
                    $narratorString = is_array($narrators) ? implode(', ', $narrators) : (string) $narrators;
                    if ($narratorString !== '') {
                        $title .= " ({$narratorString})";
                    }
                }
            }

            $path .= '/' . $title;
        }

        return $path;
    }

    /**
     * Generate directory path from a custom pattern template
     * Supported placeholders: [genre], [author], [series], [title], [series_number], [year], [narrator]
     */
    protected function generatePathFromPattern(string $pattern, array $metadata, array $options = []): string
    {
        $includeTitle = $options['include_title'] ?? false;

        // Extract basic metadata values
        $authors = is_array($metadata['author'] ?? []) ? $metadata['author'] : (array) ($metadata['author'] ?? []);
        $author = $this->formatAuthorsForDirectory($authors);
        if ($this->isGraphicAudio($metadata)) {
            $author = 'Graphic Audio';
        }

        $genreData = $metadata['genre'] ?? 'Unknown';
        $genre = is_array($genreData) ? ($genreData[0] ?? 'Unknown') : $genreData;
        if (is_string($genre) && str_contains($genre, ':')) {
            $genre = trim(explode(':', $genre)[0]);
        }
        $genre = empty($genre) ? 'Unknown' : $genre;

        $series = $metadata['series'] ?? '';
        if ($series !== '') {
            $series = $this->addGraphicAudioMarker($series, $metadata);
        }

        $title = $metadata['title'] ?? 'Unknown';
        // Prefix series number to title if available and [series_number] is not explicitly in pattern
        if (!empty($metadata['series_number']) && !str_contains($pattern, '[series_number]')) {
            $seriesNumberPrefix = $this->formatSeriesNumberForTitlePrefix($metadata['series_number']);
            if ($seriesNumberPrefix !== '') {
                $title = $seriesNumberPrefix . ' ' . $title;
            }
        }
        $title = $this->addGraphicAudioMarker($title, $metadata);

        $seriesNumber = '';
        if (!empty($metadata['series_number'])) {
            $seriesNumber = $this->formatSeriesNumberForTitlePrefix($metadata['series_number']);
        }

        $year = $metadata['year'] ?? '';
        $narrator = '';
        if (!empty($metadata['narrator'])) {
            $narrators = is_array($metadata['narrator']) ? $metadata['narrator'] : [$metadata['narrator']];
            $narrator = implode(', ', $narrators);
        }

        // Replacements
        $replacements = [
            '[genre]' => $genre,
            '[author]' => $author,
            '[series]' => $series,
            '[title]' => $title,
            '[series_number]' => $seriesNumber,
            '[year]' => $year,
            '[narrator]' => $narrator,
        ];

        $path = $pattern;
        foreach ($replacements as $placeholder => $value) {
            $path = str_replace($placeholder, (string) $value, $path);
        }

        // Clean up path (remove empty segments, multiple slashes, leading/trailing slashes)
        $segments = explode('/', $path);
        $cleanSegments = [];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            // Remove empty parentheticals or brackets resulting from missing metadata
            $segment = preg_replace('/\s*\(\s*\)/', '', $segment);
            $segment = preg_replace('/\s*\[\s*\]/', '', $segment);
            $segment = trim($segment, ' -_');
            if ($segment !== '') {
                $cleanSegments[] = $segment;
            }
        }

        // If include_title is false, and the pattern likely includes the title at the end,
        // we might want to strip the last segment.
        // However, custom patterns are explicit. If the user wants the parent,
        // they should probably provide a pattern for the parent.
        // But for compatibility with existing code that expects parent when include_title is false:
        if (!$includeTitle && !empty($cleanSegments) && str_contains($pattern, '[title]')) {
            // Find where [title] was in the pattern
            $patternSegments = explode('/', $pattern);
            $titleSegmentIndex = -1;
            foreach ($patternSegments as $index => $seg) {
                if (str_contains($seg, '[title]')) {
                    $titleSegmentIndex = $index;
                    break;
                }
            }

            if ($titleSegmentIndex !== -1) {
                // Return segments before the title segment
                $cleanSegments = array_slice($cleanSegments, 0, $titleSegmentIndex);
            }
        }

        return implode('/', $cleanSegments);
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
     * Move files to library after successful import
     */
    public function moveFilesToLibrary(
        array $audiobook,
        Book $book,
        callable|array $warnCallback,
        ?callable $getBookStoragePathCallback = null,
        ?callable $getCopyFilesOptionCallback = null,
        ?callable $handleDirectoryConflictCallback = null,
        ?callable $handleFileConflictCallback = null
    ): bool {
        $legacyOptions = null;
        if (is_array($warnCallback)) {
            $legacyOptions = $warnCallback;
            $warnCallback = fn ($message) => Log::warning($message);
        }

        $bookStoragePath = $getBookStoragePathCallback ? $getBookStoragePathCallback() : ($legacyOptions['storage_path'] ?? (config('filesystems.disks.books.root') ?? config('app.book_root', '/media/lyra_data1/audiobooks/books')));

        $copyFiles = $getCopyFilesOptionCallback ? (bool) $getCopyFilesOptionCallback() : (($legacyOptions['operation'] ?? 'move') === 'copy');

        $options = [
            'storage_path' => $bookStoragePath,
            'operation' => $copyFiles ? 'copy' : 'move',
        ];

        if (isset($legacyOptions['target_directory'])) {
            $options['target_directory'] = $legacyOptions['target_directory'];
        }

        return $this->moveFilesToLibraryInternal($audiobook, $book, $options, $warnCallback, $handleDirectoryConflictCallback, $handleFileConflictCallback);
    }

    /**
     * Internal method to move files to library
     */
    protected function moveFilesToLibraryInternal(
        array $audiobook,
        Book $book,
        array $options,
        callable $warnCallback,
        ?callable $handleDirectoryConflictCallback = null,
        ?callable $handleFileConflictCallback = null
    ): bool {
        try {
            $bookStoragePath = $options['storage_path'] ?? rtrim(
                config('app.book_root', '/media/lyra_data1/audiobooks/books'),
                '/'
            );
            if (!$bookStoragePath) {
                if ($warnCallback) {
                    $warnCallback("⚠️  Book storage path not configured - files not moved");
                }
                return false;
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
                if ($handleDirectoryConflictCallback) {
                    $conflictResolution = $handleDirectoryConflictCallback($audiobook, $targetDir, $book);

                    if ($conflictResolution === 'cancel') {
                        throw new \Exception("Import cancelled by user due to directory conflict");
                    } elseif ($conflictResolution === 'skip') {
                        return true;
                    } elseif (is_string($conflictResolution) && $conflictResolution !== 'replace') {
                        $targetDir = $conflictResolution;
                    }
                } else {
                    $targetDir = $this->resolveDirectoryConflictPath($targetDir);
                }

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
                    $this->moveNonAudioFilesToDirectory($originalTargetDir, $targetDir, $handleFileConflictCallback);
                }
            }

            if (!File::isDirectory($targetDir)) {
                File::makeDirectory($targetDir, 0775, true);

                // Set directory ownership to eric:audio
                $this->setDirectoryOwnership($targetDir);
            }

            // Check if this is a multi-book part - only move specific files
            $isMultiBookPart = !empty($audiobook['is_multi_book_part']) && !empty($audiobook['multi_book_files_only']);

            if ($isMultiBookPart) {
                // Multi-book part: only move the specific files for this book
                $filesToMove = $audiobook['multi_book_files_only'];
                if ($operation === 'move') {
                    $this->moveSpecificFiles($filesToMove, $targetDir, $handleFileConflictCallback);
                } else {
                    $this->copySpecificFiles($filesToMove, $targetDir, $handleFileConflictCallback);
                }
            } else {
                // Regular book: move entire directory contents
                // Flatten CD directories before moving files
                $this->flattenCdDirectories($sourcePath);

                if ($operation === 'move') {
                    $this->moveDirectoryContents($sourcePath, $targetDir, $handleFileConflictCallback);
                } else {
                    $this->copyDirectoryContents($sourcePath, $targetDir, $handleFileConflictCallback);
                }
            }

            // CRITICAL: Verify files exist in destination BEFORE cleaning up source
            $this->assertDirectoryHasAudioFiles($targetDir, [
                'book_id' => $book->id,
                'source' => $sourcePath,
                'target' => $targetDir,
                'operation' => $operation,
            ]);

            // Only clean up source after verifying destination has audio files
            if ($operation === 'move' && !$isMultiBookPart) {
                $cleanupAudiobook = $audiobook;
                $cleanupAudiobook['path'] = $originalSourcePath;
                $this->cleanupSourceDirectory($cleanupAudiobook);
            }

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
    /**
     * Generate target directory for book
     */
    protected function generateTargetDirectory(Book $book, string $basePath, array $options = []): string
    {
        // CRITICAL: Once user approves a path, use it EXACTLY - NO modifications
        // Only exception: Prompt user to resolve conflicts, don't silently add suffixes or modify

        $relativePath = null;
        if (!empty($book->directory_path)) {
            $relativePath = $book->directory_path;
            if (str_starts_with($relativePath, '/')) {
                $relativePath = $this->makePathRelative($relativePath, $basePath);
            }
        } else {
            // Fallback to generating from metadata if directory_path is empty (useful for tests)
            // In real usage, directory_path is always set before moving files
            $authors = $book->authors->pluck('name')->toArray();
            $genres = $book->genres->pluck('name')->toArray();
            $series = $book->series->first();

            $metadata = [
                'title' => $book->title,
                'author' => $authors,
                'genre' => $genres,
                'series' => $series?->name,
                'series_number' => $series?->pivot?->series_number,
            ];

            $relativePath = $this->generateDirectoryPath($metadata, ['include_title' => true]);
        }

        Log::debug('BookImportService::generateTargetDirectory - Using path', [
            'book_id' => $book->id,
            'directory_path' => $relativePath,
            'basePath' => $basePath,
        ]);

        return rtrim($basePath, '/') . '/' . ltrim($relativePath, '/');
    }
    protected function copyDirectoryContents(string $source, string $target, ?callable $handleFileConflictCallback = null): void
    {
        // Handle single file source
        if (File::isFile($source)) {
            $filename = basename($source);
            $targetFile = "{$target}/{$filename}";

            if (!File::isDirectory($target)) {
                File::makeDirectory($target, 0775, true);
                $this->setDirectoryOwnership($target);
            }

            if (file_exists($targetFile)) {
                $type = in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::AUDIO_EXTENSIONS, true) ? 'audio' : 'non-audio';
                $resolved = $this->resolveFileConflict($source, $targetFile, $type, $handleFileConflictCallback);
                if ($resolved === null) {
                    return;
                }
                $targetFile = $resolved;
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

            if (file_exists($targetFile)) {
                $type = in_array(strtolower($file->getExtension()), self::AUDIO_EXTENSIONS, true) ? 'audio' : 'non-audio';
                $resolved = $this->resolveFileConflict($file->getPathname(), $targetFile, $type, $handleFileConflictCallback);
                if ($resolved === null) {
                    continue;
                }
                $targetFile = $resolved;
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
    protected function moveDirectoryContents(string $source, string $target, ?callable $handleFileConflictCallback = null): void
    {
        $sameFileSystem = $this->areOnSameFileSystem($source, $target);

        // Handle single file source
        if (File::isFile($source)) {
            $filename = basename($source);
            $targetFile = "{$target}/{$filename}";

            if (!File::isDirectory($target)) {
                File::makeDirectory($target, 0775, true);
            }

            if (file_exists($targetFile)) {
                $type = in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::AUDIO_EXTENSIONS, true) ? 'audio' : 'non-audio';
                $resolved = $this->resolveFileConflict($source, $targetFile, $type, $handleFileConflictCallback);
                if ($resolved === null) {
                    return;
                }
                $targetFile = $resolved;
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

            if (file_exists($targetFile)) {
                $type = in_array(strtolower($file->getExtension()), self::AUDIO_EXTENSIONS, true) ? 'audio' : 'non-audio';
                $resolved = $this->resolveFileConflict($file->getPathname(), $targetFile, $type, $handleFileConflictCallback);
                if ($resolved === null) {
                    continue;
                }
                $targetFile = $resolved;
            }

            if ($sameFileSystem) {
                if (!File::move($file->getPathname(), $targetFile)) {
                    throw new \Exception("Failed to move file: {$file->getPathname()} to {$targetFile}");
                }
            } else {
                // Use copy+delete instead of move to avoid cross-filesystem issues
                // NOTE: Source is deleted after successful copy. This is safe because:
                // 1. Copy is verified successful before delete
                // 2. Target (library) is the permanent location - source is staging
                // 3. Entire source directory is moved to trash after import completes
                // 4. If import fails mid-process, remaining source files can still be recovered
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
     * Move specific files to target directory (for multi-book parts)
     */
    protected function moveSpecificFiles(array $files, string $targetDir, ?callable $handleFileConflictCallback = null): void
    {
        if (!File::isDirectory($targetDir)) {
            File::makeDirectory($targetDir, 0775, true);
            $this->setDirectoryOwnership($targetDir);
        }

        $filesToDelete = [];

        // First, copy/move all files
        foreach ($files as $filePath) {
            if (!file_exists($filePath)) {
                Log::warning("File not found for multi-book move: {$filePath}");
                continue;
            }

            $filename = basename($filePath);
            $targetFile = "{$targetDir}/{$filename}";

            if (file_exists($targetFile)) {
                $type = in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::AUDIO_EXTENSIONS, true) ? 'audio' : 'non-audio';
                $resolved = $this->resolveFileConflict($filePath, $targetFile, $type, $handleFileConflictCallback);
                if ($resolved === null) {
                    continue;
                }
                $targetFile = $resolved;
            }

            $sameFileSystem = $this->areOnSameFileSystem($filePath, $targetDir);

            if ($sameFileSystem) {
                if (!File::move($filePath, $targetFile)) {
                    throw new \Exception("Failed to move file: {$filePath} to {$targetFile}");
                }
            } else {
                if (!File::copy($filePath, $targetFile)) {
                    throw new \Exception("Failed to copy file: {$filePath} to {$targetFile}");
                }
                // Mark for deletion AFTER verification
                $filesToDelete[] = $filePath;
            }

            chmod($targetFile, 0664);
            $this->setFileOwnership($targetFile);

            // Also move matching PDF if exists
            $this->moveMatchingPdfFile($filePath, $targetDir);
        }

        // CRITICAL: Verify destination has files BEFORE deleting cross-filesystem copies
        if (!empty($filesToDelete)) {
            if (!$this->directoryHasAudioFiles($targetDir)) {
                throw new \Exception("Multi-book file move failed - destination has no audio files: {$targetDir}");
            }

            // Only delete source files after verification
            foreach ($filesToDelete as $filePath) {
                File::delete($filePath);
            }
        }
    }

    /**
     * Copy specific files to target directory (for multi-book parts)
     */
    protected function copySpecificFiles(array $files, string $targetDir, ?callable $handleFileConflictCallback = null): void
    {
        if (!File::isDirectory($targetDir)) {
            File::makeDirectory($targetDir, 0775, true);
            $this->setDirectoryOwnership($targetDir);
        }

        foreach ($files as $filePath) {
            if (!file_exists($filePath)) {
                Log::warning("File not found for multi-book copy: {$filePath}");
                continue;
            }

            $filename = basename($filePath);
            $targetFile = "{$targetDir}/{$filename}";

            if (file_exists($targetFile)) {
                $type = in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::AUDIO_EXTENSIONS, true) ? 'audio' : 'non-audio';
                $resolved = $this->resolveFileConflict($filePath, $targetFile, $type, $handleFileConflictCallback);
                if ($resolved === null) {
                    continue;
                }
                $targetFile = $resolved;
            }

            if (!File::copy($filePath, $targetFile)) {
                throw new \Exception("Failed to copy file: {$filePath} to {$targetFile}");
            }

            chmod($targetFile, 0664);
            $this->setFileOwnership($targetFile);

            // Also copy matching PDF if exists
            $this->copyMatchingPdfFile($filePath, $targetDir);
        }
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
     * Normalize author names for both database and directory use (unspaced initials)
     * e.g., "J.R.R. Tolkien"
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

        // Normalize initials (Ensure period and remove spaces between them)
        // e.g. "J R R Tolkien" -> "J. R. R. Tolkien" -> "J.R.R. Tolkien"
        $name = preg_replace('/\b([A-Z])\s+/', '$1. ', $name);
        $name = preg_replace('/\s+([A-Z])$/', ' $1.', $name);

        // Remove spaces between initials
        $name = preg_replace('/\b([A-Z]\.)\s+([A-Z]\.)/', '$1$2', $name);
        $name = preg_replace('/\b([A-Z]\.)\s+([A-Z]\.)/', '$1$2', $name);

        return trim($name);
    }

    /**
     * @deprecated Use normalizeAuthorName - now unified for both DB and Directory
     */
    public function normalizeAuthorNameForDirectory(string $authorName): string
    {
        return $this->normalizeAuthorName($authorName);
    }

    /**
     * Find a cover image in a source (import) directory using absolute path.
     * Prefers standard names (cover.*, folder.*), then falls back to any image file.
     */
    protected function findCoverInSourceDirectory(string $absolutePath): ?string
    {
        if (!is_dir($absolutePath)) {
            return null;
        }

        $files = scandir($absolutePath);
        if ($files === false) {
            return null;
        }

        $imageExtensions = ['jpg', 'jpeg', 'png', 'webp'];
        $preferred = ['cover.jpg', 'cover.jpeg', 'cover.png', 'cover.webp', 'folder.jpg', 'folder.jpeg', 'folder.png'];

        foreach ($preferred as $name) {
            foreach ($files as $file) {
                if (strcasecmp($file, $name) === 0) {
                    return $absolutePath . '/' . $file;
                }
            }
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, $imageExtensions, true)) {
                return $absolutePath . '/' . $file;
            }
        }

        return null;
    }

    /**
     * Apply the best default cover source to $metadata based on priority.
     * When metadata.json is present, the local cover file is preferred (curated).
     * Otherwise, embedded cover takes priority.
     */
    private function applyDefaultCoverSource(array &$metadata, array $coverSources): void
    {
        $embedded = null;
        $localFile = null;

        foreach ($coverSources as $source) {
            if ($source['type'] === 'embedded' && $embedded === null) {
                $embedded = $source;
            } elseif ($source['type'] === 'file' && $localFile === null) {
                $localFile = $source;
            }
        }

        $hasMetadataJson = !empty($metadata['_metadata_json']);

        if ($hasMetadataJson && $localFile !== null) {
            $metadata['cover_data'] = null;
            $metadata['cover_path'] = $localFile['path'];
            unset($metadata['cover_url']);
        } elseif ($embedded !== null) {
            $metadata['cover_data'] = $embedded['data'];
            $metadata['cover_source'] = 'Embedded';
            $metadata['cover_path'] = null;
        } elseif ($localFile !== null) {
            $metadata['cover_data'] = null;
            $metadata['cover_path'] = $localFile['path'];
        }
    }

    /**
     * Find existing cover image in directory
     */
    protected function findExistingCover(string $directoryPath): ?string
    {
        $bookRoot = rtrim(config('filesystems.disks.books.root') ?? config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
        $fullPath = $bookRoot . '/' . $directoryPath;

        if (!is_dir($fullPath)) {
            return null;
        }

        // Check for common cover image filenames (case-insensitive)
        $targetNames = ['cover.jpg', 'cover.jpeg', 'cover.png', 'folder.jpg', 'folder.jpeg', 'folder.png', 'cover.webp'];

        $files = scandir($fullPath);
        if ($files === false) {
            return null;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $lowerFile = strtolower($file);
            if (in_array($lowerFile, $targetNames, true)) {
                // Return just the filename - cover_image stores filename only, resolved relative to directory_path
                return $file;
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
            $bookRoot = rtrim(config('filesystems.disks.books.root') ?? config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
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
        if ($coverService) {
            $result = $coverService->downloadCoverImage($imageUrl, $directoryPath, $source);

            return $result['success'] ? $result['path'] : null;
        }

        if ($imageUrl === '') {
            return null;
        }

        // CRITICAL: Use the correct book storage path, prioritizing filesystems config
        $bookStoragePath = rtrim(config('filesystems.disks.books.root') ?? config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
        $targetDirectory = $bookStoragePath . '/' . ltrim($directoryPath, '/');

        if (!File::exists($targetDirectory)) {
            File::makeDirectory($targetDirectory, 0775, true);

            // Set directory ownership to eric:audio
            $this->setDirectoryOwnership($targetDirectory);
        }

        $extension = $this->getImageExtensionFromUrl($imageUrl);
        $filename = sprintf('cover_%s.%s', strtolower($source ?: 'unknown'), $extension);
        $targetPath = $targetDirectory . '/' . $filename;

        try {
            $context = stream_context_create([
                'http' => ['timeout' => 15],
                'https' => ['timeout' => 15],
            ]);
            $imageData = @file_get_contents($imageUrl, false, $context);
            if ($imageData === false || $imageData === null) {
                return null;
            }

            if (@file_put_contents($targetPath, $imageData) === false) {
                return null;
            }

            @chmod($targetPath, 0664);
        } catch (\Throwable $e) {
            Log::warning('Failed to download cover image', [
                'error' => $e->getMessage(),
                'url' => $imageUrl,
                'directory' => $targetDirectory,
                'target_path' => $targetPath,
            ]);
            return null;
        }

        return $filename;
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

        $bookStoragePath = config('filesystems.disks.books.root') ?? config('app.book_root');
        if (!$bookStoragePath || !File::isDirectory($bookStoragePath)) {
            return null;
        }

        // Unified normalization (unspaced initials: J.R.R. Tolkien)
        $normalizedAuthors = array_map([$this, 'normalizeAuthorName'], $authors);

        $authorCombinations = [];
        $authorCombinations[] = implode(' & ', $normalizedAuthors);
        if (count($normalizedAuthors) > 1) {
            $authorCombinations[] = implode(' & ', array_reverse($normalizedAuthors));
        }

        // For backward compatibility, also check the version with spaces
        $spacedAuthors = array_map(function ($name) {
            $name = $this->normalizeAuthorName($name);
            return preg_replace('/\b([A-Z]\.)([A-Z]\.)/', '$1 $2', $name);
        }, $authors);

        $authorCombinations[] = implode(' & ', $spacedAuthors);
        if (count($spacedAuthors) > 1) {
            $authorCombinations[] = implode(' & ', array_reverse($spacedAuthors));
        }

        $authorCombinations = array_unique($authorCombinations);

        try {
            // Only scan 2 levels deep: [genre]/[author]
            // No need for recursive scanning since all authors are at this depth
            $genreDirs = File::directories($bookStoragePath);

            foreach ($genreDirs as $genreDir) {
                $authorDirs = File::directories($genreDir);

                foreach ($authorDirs as $authorDir) {
                    $authorDirName = basename($authorDir);

                    foreach ($authorCombinations as $expectedDirName) {
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
     * Resolve directory conflict by finding a non-conflicting path (automated)
     */
    public function resolveDirectoryConflictPath(string $targetDir): string
    {
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

        // Directory has actual content.
        // Previously we appended _01, _02 etc. but this causes "Wrong Directory" issues and duplicates.
        // Now we return the original path, effectively allowing merge/overwrite.
        // This relies on the file copy operation to handle file-level conflicts (usually overwrite).
        return $targetDir;
    }

    /**
     * Handle directory conflict resolution with UI interaction
     */

    /**
     * Handle a file-level conflict where the destination file already exists.
     *
     * For images: displays both files side-by-side using the terminal image protocol.
     * For text files: shows first 10 + last 10 lines of each file side-by-side.
     * For audio files: shows file sizes and modification dates.
     *
     * Returns one of: 'keep', 'replace', 'rename:<newname>'
     */
    public function handleFileConflict(
        string $sourcePath,
        string $targetPath,
        string $type,
        callable $lineCallback,
        callable $selectCallback,
        callable $askCallback,
        callable $displayImageCallback
    ): string {
        $filename = basename($targetPath);
        $lineCallback('');
        $lineCallback("⚠️  File conflict: <comment>{$filename}</comment> already exists in destination");

        $sourceSize = file_exists($sourcePath) ? filesize($sourcePath) : 0;
        $targetSize = file_exists($targetPath) ? filesize($targetPath) : 0;
        $sourceMtime = file_exists($sourcePath) ? date('Y-m-d H:i:s', filemtime($sourcePath)) : 'unknown';
        $targetMtime = file_exists($targetPath) ? date('Y-m-d H:i:s', filemtime($targetPath)) : 'unknown';

        // If files are identical (same hash), default to keep to avoid pointless prompt
        if (file_exists($sourcePath) && file_exists($targetPath) && md5_file($sourcePath) === md5_file($targetPath)) {
            $lineCallback("  ✅ Files are identical — keeping existing file");
            return 'keep';
        }

        $imageExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $isImage = in_array($extension, $imageExtensions, true);
        $isText = in_array($extension, ['txt', 'json', 'nfo', 'xml', 'md', 'cue'], true);

        $lineCallback("  Existing : {$targetPath}");
        $lineCallback("    Size: " . $this->formatBytesInternal($targetSize) . "  Modified: {$targetMtime}");
        $lineCallback("  Incoming : {$sourcePath}");
        $lineCallback("    Size: " . $this->formatBytesInternal($sourceSize) . "  Modified: {$sourceMtime}");

        if ($isImage) {
            $lineCallback('');
            $lineCallback('  📷 Existing cover:');
            $displayImageCallback($targetPath);
            $lineCallback('');
            $lineCallback('  📷 Incoming cover:');
            $displayImageCallback($sourcePath);
            $lineCallback('');
        } elseif ($isText) {
            $lineCallback('');
            $this->showTextFilePreview('  Existing', $targetPath, $lineCallback);
            $this->showTextFilePreview('  Incoming', $sourcePath, $lineCallback);
            $lineCallback('');
        }

        $options = [
            'keep'    => "Keep existing {$filename}",
            'replace' => "Replace with incoming {$filename}",
            'rename'  => 'Rename incoming file (keep both)',
        ];

        $choice = $selectCallback("How should the conflict be resolved?", $options, 'keep');

        if ($choice === 'rename' || $choice === 'Rename incoming file (keep both)') {
            $suggested = pathinfo($filename, PATHINFO_FILENAME)
                . '_new'
                . ($extension !== '' ? '.' . $extension : '');
            $newName = $askCallback("New filename for incoming file", $suggested);
            $newName = trim((string) $newName);
            if ($newName === '' || $newName === $filename) {
                $newName = $suggested;
            }
            return 'rename:' . $newName;
        }

        if ($choice === 'replace' || $choice === "Replace with incoming {$filename}") {
            return 'replace';
        }

        return 'keep';
    }

    /**
     * Show first 10 + last 10 lines of a text file.
     */
    protected function showTextFilePreview(string $label, string $path, callable $lineCallback): void
    {
        if (!file_exists($path)) {
            $lineCallback("  {$label}: (file not found)");
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            $lineCallback("  {$label}: (could not read)");
            return;
        }

        $total = count($lines);
        $lineCallback("  {$label} ({$total} lines):");
        $head = array_slice($lines, 0, 10);
        foreach ($head as $line) {
            $lineCallback('    ' . $line);
        }
        if ($total > 20) {
            $lineCallback('    ...');
            $tail = array_slice($lines, -10);
            foreach ($tail as $line) {
                $lineCallback('    ' . $line);
            }
        } elseif ($total > 10) {
            $tail = array_slice($lines, 10);
            foreach ($tail as $line) {
                $lineCallback('    ' . $line);
            }
        }
    }

    protected function formatBytesInternal(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }

    public function handleDirectoryConflict(
        array $audiobook,
        string $targetDir,
        ?callable $compareDirectoriesCallback = null,
        ?callable $displayDirectoryComparisonCallback = null,
        ?callable $logMessageCallback = null,
        ?callable $selectCallback = null,
        ?callable $optionCallback = null
    ): string {
        $targetDir = rtrim($targetDir, '/');
        if ($targetDir === '') {
            return $targetDir;
        }

        if (!File::isDirectory($targetDir)) {
            return $targetDir;
        }

        $hasInteractiveCallbacks = $compareDirectoriesCallback
            || $displayDirectoryComparisonCallback
            || $logMessageCallback
            || $selectCallback
            || $optionCallback;

        if (!$hasInteractiveCallbacks) {
            return $this->resolveDirectoryConflictPath($targetDir);
        }

        $log = $logMessageCallback ?? fn (...$args) => null;

        $log("⚠️  Target directory already exists: " . basename($targetDir));

        // For multi-book parts, compare only the files being imported rather than
        // the full source directory (which contains files for all book parts).
        $isMultiPart = !empty($audiobook['is_multi_book_part']) && !empty($audiobook['multi_book_files_only']);
        if ($isMultiPart) {
            $sourceInfo = $this->getDirectoryInfoFromFiles($audiobook['multi_book_files_only']);
            $targetInfo = $this->getDirectoryInfo($targetDir);
            $comparison = [
                'identical' => $this->areDirectoriesIdentical($sourceInfo, $targetInfo),
                'source' => $sourceInfo,
                'target' => $targetInfo,
                'source_path' => $audiobook['path'],
                'target_path' => $targetDir,
            ];
        } else {
            $comparison = $compareDirectoriesCallback ? $compareDirectoriesCallback($audiobook['path'], $targetDir) : $this->compareDirectories($audiobook['path'], $targetDir);
        }

        // Display comparison
        if ($displayDirectoryComparisonCallback) {
            $displayDirectoryComparisonCallback($comparison);
        }

        // If directories are identical, automatically clean up source
        if ($comparison['identical']) {
            $log("🔍 Directories are identical - source will be automatically deleted");
            return 'skip';
        }

        // If in auto mode, default to replace
        if ($optionCallback) {
            try {
                if ($optionCallback('auto')) {
                    $log("🤖 Auto mode: Replacing existing directory");
                    return 'replace';
                }
            } catch (\Throwable $e) {
                $log("⚠️  Auto option callback failed: " . $e->getMessage());
            }
        }

        // Prompt user for action
        $options = [
            '1' => 'Replace existing directory with new files',
            '2' => 'Rename existing directory (add _01 suffix)',
            '3' => 'Rename new import (add _01 suffix)',
            '4' => 'Merge directories',
            '5' => 'Cancel import',
        ];

        $choice = $selectCallback("Target directory conflict - choose action", $options, '1');

        switch ($choice) {
            case '1':
                // Replace: move existing directory to trash
                $trashResult = $this->sourceTrashService->movePathToTrash(
                    $targetDir,
                    'directory_conflict_replace',
                    ['conflict_reason' => 'user chose replace during import']
                );
                if ($trashResult) {
                    $log("🗑️ Moved existing directory to trash ({$trashResult['id']})");
                }
                return $targetDir;

            case '2':
                // Rename existing: find suffix for old directory
                $existingRenamed = $this->findAvailableDirectoryWithSuffix($targetDir);
                File::move($targetDir, $existingRenamed);
                $log("📁 Renamed existing directory to: " . basename($existingRenamed));
                return $targetDir;

            case '3':
                // Rename new: find suffix for new import
                $newRenamed = $this->findAvailableDirectoryWithSuffix($targetDir);
                $log("📁 New import will use: " . basename($newRenamed));
                return $newRenamed;

            case '4':
                // Merge: use existing directory
                $log("🔀 Merging into existing directory");
                return $targetDir;

            case '5':
                return 'cancel';

            default:
                return $targetDir;
        }
    }

    /**
     * Find an available directory path with _01, _02, etc. suffix
     */
    protected function findAvailableDirectoryWithSuffix(string $basePath): string
    {
        $counter = 1;
        while ($counter <= 99) {
            $newPath = $basePath . '_' . str_pad($counter, 2, '0', STR_PAD_LEFT);
            if (!File::isDirectory($newPath)) {
                return $newPath;
            }
            $counter++;
        }

        return $basePath . '_99';
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
            if (!in_array(strtolower($filename), self::METADATA_FILES, true)) {
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

        try {
            $files = File::allFiles($path);
            $directories = File::directories($path);
        } catch (\Exception $e) {
            // Permission denied or unreadable subdirectory — treat as non-empty to avoid deletion
            return false;
        }

        return empty($files) && empty($directories);
    }

    /**
     * Clean up source directory after successful import
     */
    public function cleanupSourceDirectory(
        array $audiobook,
        bool $filesAlreadyExist = false,
        bool $isCopyOperation = false,
        ?callable $infoCallback = null,
        ?callable $isDirectoryCallback = null,
        ?callable $filesCallback = null
    ): void {
        $sourcePath = $audiobook['path'] ?? null;

        if ($sourcePath === null || $sourcePath === '') {
            return;
        }

        if ($isCopyOperation) {
            return;
        }

        $trashMetadata = [
            'files_already_in_library' => $filesAlreadyExist,
            'source_audiobook' => $audiobook,
        ];

        $trashResult = $this->sourceTrashService->movePathToTrash(
            $sourcePath,
            $filesAlreadyExist ? 'duplicate_source_after_import' : 'source_cleanup_after_import',
            $trashMetadata
        );

        if ($trashResult !== null && $infoCallback) {
            $infoCallback(
                $filesAlreadyExist ? "✅ Moved duplicate source to trash ({$trashResult['id']})" : "🗑️  Moved source to trash ({$trashResult['id']})"
            );
        }

        // Check if the parent directory is now empty and remove it if so
        if ($sourcePath && file_exists(dirname($sourcePath))) {
            $parentPath = dirname($sourcePath);
            if ($this->isDirectoryEmpty($parentPath)) {
                @rmdir($parentPath);
                if ($infoCallback) {
                    $infoCallback("🗑️  Removed empty parent directory: " . basename($parentPath));
                }
            }
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
    public function createNarratorDirectoryName(string $title, string $narrator, ?string $seriesName = null): string
    {
        $cleanTitle = $this->removeSeriesFromTitle($title, $seriesName);

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

        if (mb_strlen($combined) > $maxLength) {
            $combined = mb_substr($combined, 0, $maxLength - 3) . '...';
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

        $existingSeries = $existingBook ? $existingBook->series->first()?->name : null;
        $newSeries = $book->series->first()?->name;

        $baseDir = dirname($targetDir);
        $existingNewPath = $baseDir . '/' . $this->createNarratorDirectoryName($existingTitle, $existingNarrator, $existingSeries);
        $newImportPath = $baseDir . '/' . $this->createNarratorDirectoryName($newTitle, $newNarrator, $newSeries);

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
    public function extractNfoData(string $directoryPath, ?callable $infoCallback = null): ?array
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

        if (!empty($nfoData) && $infoCallback) {
            $infoCallback("📄 Found .nfo file with metadata");
        }

        return $nfoData ?: null;
    }

    /**
     * Read and parse an AudioBookShelf-style metadata.json file.
     * Returns a normalized metadata array or null if not found/invalid.
     */
    public function readMetadataJson(string $directoryPath): ?array
    {
        $path = rtrim($directoryPath, '/') . '/metadata.json';
        if (!file_exists($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false || $content === '') {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data) || empty($data)) {
            return null;
        }

        $result = [];

        if (!empty($data['title'])) {
            $result['title'] = (string) $data['title'];
        }
        if (!empty($data['authors'])) {
            $authors = is_array($data['authors']) ? $data['authors'] : [(string) $data['authors']];
            $result['author'] = array_values(array_filter($authors));
        }
        if (!empty($data['narrators'])) {
            $narrators = is_array($data['narrators']) ? $data['narrators'] : [(string) $data['narrators']];
            $result['narrator'] = array_values(array_filter($narrators));
        }
        if (!empty($data['genres'])) {
            $genres = is_array($data['genres']) ? $data['genres'] : [(string) $data['genres']];
            $genres = array_values(array_filter($genres));
            if (!empty($genres)) {
                $result['genre'] = $genres[0];
            }
        }
        if (!empty($data['series'])) {
            $seriesList = is_array($data['series']) ? $data['series'] : [(string) $data['series']];
            $firstSeries = trim((string) ($seriesList[0] ?? ''));
            if ($firstSeries !== '') {
                if (preg_match('/^(.+?)\s*#(\d+(?:\.\d+)?)$/', $firstSeries, $m)) {
                    $result['series'] = trim($m[1]);
                    $result['series_number'] = $m[2];
                } else {
                    $result['series'] = $firstSeries;
                }
            }
        }
        if (!empty($data['publishedYear'])) {
            $result['year'] = (string) $data['publishedYear'];
        }
        if (!empty($data['description'])) {
            $result['description'] = (string) $data['description'];
        }
        if (!empty($data['isbn'])) {
            $result['isbn'] = (string) $data['isbn'];
        }
        if (!empty($data['asin'])) {
            $result['asin'] = (string) $data['asin'];
        }
        if (!empty($data['publisher'])) {
            $result['publisher'] = (string) $data['publisher'];
        }
        if (!empty($data['language'])) {
            $result['language'] = (string) $data['language'];
        }
        if (isset($data['abridged'])) {
            $result['abridged'] = (bool) $data['abridged'];
        }

        return !empty($result) ? $result : null;
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
     * Map genre to valid genre name using the primary mapping service
     */
    public function mapToValidGenre(string $genre): string
    {
        return $this->genreMappingService->mapToPrimaryGenre($genre);
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
    public function displayEnrichedMetadata(
        array $metadata,
        ?callable $tableCallback = null,
        ?callable $handleCoverSelectionCallback = null,
        ?callable $displayCoverImageCallback = null,
        ?callable $isInteractiveCallback = null
    ): array {
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

        if (!empty($metadata['is_multi_part'])) {
            $tableData[] = ['⚠ Multi-Part', 'Part number stripped from title — verify title is correct'];
        }

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

        // Display table if callback provided
        if ($tableCallback) {
            $tableCallback(['Field', 'Value'], $tableData);
        }

        // Handle cover selection if callback provided
        if ($handleCoverSelectionCallback) {
            $handleCoverSelectionCallback($metadata);
        }

        // Display cover image if callback provided
        if ($displayCoverImageCallback && !empty($metadata['cover_url'])) {
            $displayCoverImageCallback($metadata['cover_url']);
        }

        return $tableData;
    }

    /**
     * Look up genre from existing books in the same series by the same author
     */
    public function lookupGenreFromExistingSeries(array $metadata): ?string
    {
        $seriesName = $metadata['series'] ?? '';
        if (empty($seriesName)) {
            return null;
        }

        $authors = $metadata['author'] ?? [];
        if (is_string($authors)) {
            $authors = [$authors];
        }
        if (empty($authors)) {
            return null;
        }

        $query = Book::whereHas('series', function ($q) use ($seriesName) {
            $q->where('name', $seriesName);
        })->whereHas('authors', function ($q) use ($authors) {
            $q->whereIn('name', $authors);
        })->whereHas('genres');

        $existingBook = $query->with('genres')->first();
        if (!$existingBook) {
            return null;
        }

        $primaryGenre = $existingBook->genres->first();

        return $primaryGenre?->name;
    }

    /**
     * Adjust confidence based on series matches and genre quality.
     *
     * +10% if other books by the same author exist in the same series.
     * -30% if genre is "Other" or "Unknown".
     * -20% if genre is "Action" and author has no other Action books and no books in any genre.
     * If genre is "Action", author has no Action books, but has books in another genre,
     * update genre to the preferred genre and leave confidence unchanged.
     */
    public function adjustConfidence(array &$metadata, ?callable $infoCallback = null): void
    {
        $confidence = (int) ($metadata['confidence'] ?? 0);

        $seriesName = $metadata['series'] ?? '';
        $authors = $metadata['author'] ?? [];
        if (is_string($authors)) {
            $authors = [$authors];
        }

        if (!empty($seriesName) && !empty($authors)) {
            $seriesBookCount = Book::whereHas('series', function ($q) use ($seriesName) {
                $q->where('name', $seriesName);
            })->whereHas('authors', function ($q) use ($authors) {
                $q->whereIn('name', $authors);
            })->count();

            if ($seriesBookCount > 0) {
                $confidence = min(100, $confidence + 10);
                if ($infoCallback) {
                    $infoCallback("📈 Confidence +10% (found {$seriesBookCount} book(s) in same series by author)");
                }
            }
        }

        $genre = $metadata['genre'] ?? '';
        if (is_array($genre)) {
            $genre = $genre[0] ?? '';
        }

        if (in_array($genre, ['Other', 'Unknown', 'Classic'], true)) {
            $genreOverridden = false;

            if (!empty($seriesName) && !empty($authors)) {
                $matchingBook = Book::whereHas('series', function ($q) use ($seriesName) {
                    $q->where('name', $seriesName);
                })->whereHas('authors', function ($q) use ($authors) {
                    $q->whereIn('name', $authors);
                })->whereHas('genres', function ($q) {
                    $q->where('book_genre.is_primary', true)
                        ->whereNotIn('name', ['Other', 'Unknown', 'Classic']);
                })->first();

                if ($matchingBook) {
                    $seriesGenre = Genre::join('book_genre', 'genres.id', '=', 'book_genre.genre_id')
                        ->where('book_genre.book_id', $matchingBook->id)
                        ->where('book_genre.is_primary', true)
                        ->value('genres.name');

                    if ($seriesGenre) {
                        if (is_array($metadata['genre'])) {
                            $metadata['genre'] = [$seriesGenre];
                        } else {
                            $metadata['genre'] = $seriesGenre;
                        }
                        $genreOverridden = true;
                        if ($infoCallback) {
                            $infoCallback("🔄 Genre updated from '{$genre}' to '{$seriesGenre}' (from existing series books)");
                        }
                    }
                }
            }

            if (!$genreOverridden) {
                $confidence = max(0, $confidence - 30);
                if ($infoCallback) {
                    $infoCallback("📉 Confidence -30% (genre is '{$genre}')");
                }
            }
        } elseif ($genre === 'Action' && !empty($authors)) {
            $preferredGenre = $this->getAuthorPreferredGenre($authors);
            if ($preferredGenre && $preferredGenre !== 'Action') {
                if (is_array($metadata['genre'])) {
                    $metadata['genre'] = [$preferredGenre];
                } else {
                    $metadata['genre'] = $preferredGenre;
                }
                if ($infoCallback) {
                    $infoCallback("🔄 Genre updated from 'Action' to '{$preferredGenre}' (author's preferred genre)");
                }
            } elseif (!$preferredGenre) {
                $confidence = max(0, $confidence - 20);
                if ($infoCallback) {
                    $infoCallback("📉 Confidence -20% (genre is 'Action' with no author genre history)");
                }
            }
        }

        $metadata['confidence'] = $confidence;
    }

    /**
     * Find existing book in database (returns Book model instead of boolean)
     */
    public function findExistingBook(string $path, array $metadata = []): ?Book
    {
        $sourceDirectoryName = $this->extractSourceDirectoryName($path);

        if (!empty($metadata['isbn'])) {
            $existingBook = Book::where('isbn', $metadata['isbn'])->first();
            if ($existingBook) {
                return $existingBook;
            }
        }

        $candidateDirectories = [];
        if (!empty($metadata['custom_directory_path']) && is_string($metadata['custom_directory_path'])) {
            $candidateDirectories[] = trim($this->generateDirectoryPath($metadata, ['include_title' => true]), '/');
        }
        if ($sourceDirectoryName !== '') {
            $candidateDirectories[] = $sourceDirectoryName;
        }

        foreach (array_values(array_unique(array_filter($candidateDirectories))) as $directoryPath) {
            $existingByDirectory = Book::where('directory_path', $directoryPath)->first();
            if ($existingByDirectory instanceof Book) {
                return $existingByDirectory;
            }

            $existingByDirectoryName = Book::where('directory_path', 'like', '%/' . $directoryPath)
                ->orWhere('directory_path', $directoryPath)
                ->get()
                ->sortByDesc(static fn (Book $book): int => empty($book->directory_path) ? 1 : 0)
                ->first();

            if ($existingByDirectoryName instanceof Book) {
                return $existingByDirectoryName;
            }
        }

        if (!empty($metadata['title']) && !empty($metadata['author'])) {
            $title = trim((string) $metadata['title']);
            $author = is_array($metadata['author']) ? ($metadata['author'][0] ?? '') : $metadata['author'];
            $author = trim((string) $author);
            $seriesName = trim((string) ($metadata['series'] ?? ''));
            $seriesNumber = isset($metadata['series_number']) ? (float) $metadata['series_number'] : null;

            if ($title !== '' && $author !== '') {
                $existingBooks = Book::whereRaw('LOWER(title) = ?', [mb_strtolower($title)])
                    ->whereHas('authors', function ($query) use ($author) {
                        $query->whereRaw('LOWER(name) = ?', [mb_strtolower($author)]);
                    })
                    ->with('series')
                    ->get();

                $matchedBook = $existingBooks->first(function (Book $book) use ($seriesName, $seriesNumber): bool {
                    if ($seriesName !== '') {
                        $matchedSeries = $book->series->first(
                            static fn ($series): bool => strcasecmp((string) ($series->name ?? ''), $seriesName) === 0
                        );

                        if ($matchedSeries === null) {
                            return false;
                        }

                        if ($seriesNumber !== null) {
                            $existingNumber = $matchedSeries->pivot?->getAttribute('series_number');
                            if ($existingNumber !== null && (float) $existingNumber !== $seriesNumber) {
                                return false;
                            }
                        }
                    }

                    return true;
                });

                if ($matchedBook instanceof Book) {
                    return $matchedBook;
                }
            }
        }

        return null;
    }

    private function extractSourceDirectoryName(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $trimmedPath = rtrim($path, '/');
        if ($trimmedPath === '') {
            return '';
        }

        if (is_file($trimmedPath)) {
            return basename(dirname($trimmedPath));
        }

        return basename($trimmedPath);
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
    public function groupCdDirectories(array $potentialBooks, ?callable $lineCallback = null): array
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
                if ($lineCallback) {
                    $lineCallback(
                        "📀 Detected multi-disc audiobook: " . basename($parentPath) . " ({$parentData['cd_count']} discs)"
                    );
                }
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
     * Display cache statistics
     */
    public function displayCacheStatistics(
        array $backgroundCache,
        array $backgroundTasks,
        callable $infoCallback,
        callable $lineCallback,
        callable $formatBytesCallback
    ): void {
        $totalEntries = count($backgroundCache);

        if ($totalEntries === 0) {
            $infoCallback("💾 Cache: empty");
            return;
        }

        $taskTypes = [];
        $totalSize = 0;
        $cacheHits = 0;

        foreach ($backgroundCache as $entry) {
            $taskType = $entry['task_type'] ?? 'unknown';
            $taskTypes[$taskType] = ($taskTypes[$taskType] ?? 0) + 1;
            $totalSize += strlen(json_encode($entry));
        }

        // Count cache hits from this session
        foreach ($backgroundTasks as $task) {
            if (isset($task['result']['from_cache']) && $task['result']['from_cache']) {
                $cacheHits++;
            }
        }

        $infoCallback("💾 Cache: {$totalEntries} entries, " . $formatBytesCallback($totalSize) . " size");

        if ($cacheHits > 0) {
            $infoCallback("🎯 Cache hits this session: {$cacheHits}");
        }

        if (!empty($taskTypes)) {
            $typesList = [];
            foreach ($taskTypes as $type => $count) {
                $typesList[] = "{$type}({$count})";
            }
            $lineCallback("   Types: " . implode(', ', $typesList));
        }
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
     * Check if directory is a container for multiple audiobooks
     * (e.g. Author directory containing multiple Book directories)
     */
    public function isContainerDirectory(string $path): bool
    {
        // If it looks like a CD/Disc directory, it's a single book part, not a container
        if ($this->hasCdDirectories($path)) {
            return false;
        }

        $directories = File::directories($path);
        $bookCount = 0;

        foreach ($directories as $dir) {
            $dirName = basename($dir);

            // Skip Extras, etc.
            if (preg_match('/^(extras?|artwork|scans?|covers?|sample)$/i', $dirName)) {
                continue;
            }

            if ($this->directoryHasAudioFiles($dir)) {
                $bookCount++;
            }
        }

        // It's a container if it has multiple subdirectories that contain audio files
        return $bookCount > 1;
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
    public function searchAlternativeCovers(array $metadata, ?GoogleImageSearchService $googleImageService, int $limit = 3): array
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
    public function buildUiMetadata(array $metadata, ?callable $getEmbeddedCoverTempPathCallback = null, ?callable $generateDirectoryPathCallback = null): array
    {
        $uiMetadata = $metadata;

        $coverSource = '';

        if (!empty($uiMetadata['cover_data'])) {
            $coverSource = 'Embedded';

            if ($getEmbeddedCoverTempPathCallback) {
                $tempPath = $getEmbeddedCoverTempPathCallback($uiMetadata['cover_data']);
                if ($tempPath) {
                    $uiMetadata['cover_url'] = $tempPath;
                    $uiMetadata['cover_is_local_file'] = true;
                }
            }
        } elseif (!empty($uiMetadata['cover_path'])) {
            $coverSource = 'Local file';
            $uiMetadata['cover_url'] = (string) $uiMetadata['cover_path'];
            $uiMetadata['cover_is_local_file'] = true;
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

        // Prioritize OpenAudible XML metadata when path includes OpenAudible
        $sourcePath = $uiMetadata['source_path'] ?? '';
        if (is_string($sourcePath) && stripos($sourcePath, 'OpenAudible') !== false) {
            // OpenAudible XML is the best metadata source - mark as high confidence
            $uiMetadata['confidence'] = 95;
            $uiMetadata['metadata_source'] = 'OpenAudible XML';

            // Ensure OpenAudible metadata takes precedence
            if (isset($uiMetadata['openaudible_raw'])) {
                $uiMetadata['metadata_priority'] = 'openaudible';
            }
        }

        if ($generateDirectoryPathCallback) {
            $uiMetadata['directory_path'] = $generateDirectoryPathCallback($uiMetadata, [
                'include_title' => true,
            ]);
        }

        return $uiMetadata;
    }

    /**
     * Manual enrichment with comparison
     */
    public function manualEnrichmentWithComparison(
        array $metadata,
        array $audiobook,
        ?BookEnrichmentService $enrichmentService,
        ?callable $tableCallback = null
    ): array {
        if (!$enrichmentService) {
            return $metadata;
        }

        $enrichedData = $enrichmentService->enrichWithExternalData($metadata, ['force' => true]);
        if ($enrichedData && $enrichmentService->isValidEnrichment($metadata, $enrichedData)) {
            if ($tableCallback) {
                $enrichmentService->manualSelectionWithComparison(
                    $metadata,
                    $enrichedData,
                    $tableCallback,
                    fn ($bytes) => $this->formatBytes($bytes)
                );
            }
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

            // Check for OpenAudible metadata to get better genre hints
            $openAudibleMetadata = $this->lookupOpenAudibleMetadata($audiobook);
            if ($openAudibleMetadata !== null && !empty($openAudibleMetadata['original_genre'])) {
                // Inject OpenAudible genre into file tags for AI prompt
                $firstFile = array_key_first($fileTags) ?? $fileNames[0] ?? 'file';
                if (!isset($fileTags[$firstFile])) {
                    $fileTags[$firstFile] = [];
                }
                // Use original (unmapped) genre to give AI context for mapping
                $fileTags[$firstFile]['genre'] = $openAudibleMetadata['original_genre'];
            }

            $aiResult = $aiProcessor->processBookDirectory(
                basename($audiobook['path']),
                $fileNames,
                $fileTags,
                $nfoData
            );

            if ($aiResult) {
                $tagMetadata = $this->extractMetadataFromFileTags($fileTags);
                $aiResult = $this->mergeMetadataFillMissing($aiResult, $tagMetadata);

                // Tags are authoritative for author and year — override AI guesses when tags are present
                if (!empty($tagMetadata['author'])) {
                    $aiResult['author'] = $tagMetadata['author'];
                }
                if (!empty($tagMetadata['year'])) {
                    $aiResult['year'] = $tagMetadata['year'];
                }

                // metadata.json is fully authoritative — override AI for all provided fields
                $metadataJson = $this->readMetadataJson($audiobook['path']);
                if ($metadataJson !== null) {
                    $aiResult = array_merge($aiResult, $metadataJson);
                    $aiResult['confidence'] = 100;
                    $aiResult['_metadata_json'] = true;
                }

                // Collect all available cover sources so the user can choose between them
                $coverSources = [];

                if (!empty($fileTags)) {
                    $firstTags = reset($fileTags);
                    if (!empty($firstTags['picture']['data'])) {
                        $coverSources[] = [
                            'type' => 'embedded',
                            'data' => $firstTags['picture']['data'],
                            'label' => 'Embedded (audio file tags)',
                        ];
                    }
                }

                $sourceCover = $this->findCoverInSourceDirectory($audiobook['path']);
                if ($sourceCover !== null) {
                    $coverSources[] = [
                        'type' => 'file',
                        'path' => $sourceCover,
                        'label' => 'Local file: ' . basename($sourceCover),
                    ];
                }

                if (!empty($aiResult['cover_url'])) {
                    $coverSources[] = [
                        'type' => 'url',
                        'url' => (string) $aiResult['cover_url'],
                        'label' => 'Remote URL (AI/enrichment)',
                    ];
                }

                if (!empty($coverSources)) {
                    $aiResult['cover_sources'] = $coverSources;
                    $this->applyDefaultCoverSource($aiResult, $coverSources);
                }

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

        $forceInclude = $this->config['force_include'] ?? false;
        if (!$forceInclude && $fileSize < 10 * 1024 * 1024) {
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

        $iterator = $this->createRecursiveIterator(
            $directory,
            \RecursiveDirectoryIterator::SKIP_DOTS,
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        if (!$iterator) {
            return null;
        }

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

    protected function createRecursiveIterator(
        string $path,
        int $directoryFlags,
        int $iteratorMode
    ): ?\RecursiveIteratorIterator {
        if (!is_dir($path) || !is_readable($path)) {
            Log::warning('Directory not accessible for iteration', ['path' => $path]);
            return null;
        }

        try {
            $directoryIterator = new \RecursiveDirectoryIterator($path, $directoryFlags);

            return new \RecursiveIteratorIterator($directoryIterator, $iteratorMode);
        } catch (\UnexpectedValueException $e) {
            Log::warning('Failed to iterate directory', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            Log::warning('Unexpected error iterating directory', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Get directories to scan for audiobooks
     */
    public function getDirectoriesToScan(array $customDirs = [], ?callable $warnCallback = null, bool $includeOld = false): array
    {
        $directories = [];

        // Check for custom directories
        if (!empty($customDirs)) {
            foreach ($customDirs as $dir) {
                if (is_dir($dir) && is_readable($dir)) {
                    $directories[] = $dir;
                } else {
                    if ($warnCallback) {
                        $warnCallback("⚠️  Directory not accessible: {$dir}");
                    }
                }
            }
        } else {
            // Use default directories
            $defaultDirs = [
                '/media/download',
                '/media/download/audiobooks',
                '/media/audiobooks/OpenAudible/books',
            ];

            // Add books_old if requested
            if ($includeOld) {
                $defaultDirs[] = '/media/audiobooks/OpenAudible/books_old';
            }

            foreach ($defaultDirs as $dir) {
                if (is_dir($dir) && is_readable($dir)) {
                    $directories[] = $dir;
                }
            }
        }

        return $directories;
    }

    /**
     * Scan directories for audiobook folders/files
     */
    public function scanForAudiobooks(array $directories, callable $isAlreadyImportedCallback = null, ?callable $infoCallback = null): array
    {
        $audiobooks = [];
        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];

        foreach ($directories as $directory) {
            if ($infoCallback) {
                $infoCallback("🔍 Scanning: {$directory}");
            }

            $iterator = $this->createRecursiveIterator(
                $directory,
                \RecursiveDirectoryIterator::SKIP_DOTS,
                \RecursiveIteratorIterator::SELF_FIRST
            );

            if (!$iterator) {
                if ($infoCallback) {
                    $infoCallback("⚠️  Skipping inaccessible directory: {$directory}");
                }
                continue;
            }

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

            // Filter out container directories that are parents of other books
            // If /Series and /Series/Book1 are both candidates, remove /Series
            $paths = array_keys($potentialBooks);
            foreach ($paths as $parentPath) {
                foreach ($paths as $childPath) {
                    if ($parentPath !== $childPath && str_starts_with($childPath, $parentPath . DIRECTORY_SEPARATOR)) {
                        // Found a child book within this parent.
                        // The parent is likely a Series container, not a book itself.
                        // However, we should be careful: if the child is "Extras", maybe we keep the parent?
                        // But generally, if there is a distinct book in a subdir, the parent is a container.
                        unset($potentialBooks[$parentPath]);
                        break;
                    }
                }
            }

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
    public function processSpecificPaths(array $paths, callable $processSingleAudioFileCallback, callable $processAudiobookDirectoryCallback, ?callable $warnCallback = null, bool $forceInclude = false): array
    {
        $audiobooks = [];
        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];
        $processedDirectories = [];

        // Store for use in callbacks
        $this->config['force_include'] = $forceInclude;

        foreach ($paths as $path) {
            $path = trim($path);
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
                $warn = $warnCallback ?? fn ($msg) => Log::warning($msg);
                $warn("Path not found: {$path}");
                continue;
            }

            $path = $actualPath;

            $warn = $warnCallback ?? fn ($msg) => Log::warning($msg);

            if (is_file($path)) {
                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (!in_array($extension, $audioExtensions)) {
                    $warn("Skipping non-audio file: {$path} (extension: {$extension})");
                } else {
                    $audiobook = $processSingleAudioFileCallback($path);
                    if ($audiobook) {
                        $audiobooks[] = $audiobook;
                    } else {
                        $fileSize = filesize($path);
                        $warn("Skipping audio file (too small: " . round($fileSize / 1024 / 1024, 1) . " MB): {$path}");
                    }
                }
            } elseif (is_dir($path)) {
                if (!in_array($path, $processedDirectories)) {
                    // Check if this is a container directory with multiple books
                    if ($this->isContainerDirectory($path)) {
                        Log::info("Detected container directory: {$path}");
                        $subDirs = File::directories($path);
                        $foundBooks = false;

                        foreach ($subDirs as $subDir) {
                            $subDirName = basename($subDir);
                            // Skip Extras, etc.
                            if (preg_match('/^(extras?|artwork|scans?|covers?|sample)$/i', $subDirName)) {
                                continue;
                            }

                            // Skip if it doesn't look like a book
                            if (!$this->directoryHasAudioFiles($subDir)) {
                                continue;
                            }

                            $audiobook = $processAudiobookDirectoryCallback($subDir);
                            if ($audiobook) {
                                $audiobooks[] = $audiobook;
                                $processedDirectories[] = $subDir;
                                $foundBooks = true;
                            }
                        }

                        if ($foundBooks) {
                            $processedDirectories[] = $path; // Mark container as processed
                        }
                    }

                    // If not a container (or no books found in subdirs), try processing the directory itself
                    if (!in_array($path, $processedDirectories)) {
                        $audiobook = $processAudiobookDirectoryCallback($path);
                        if ($audiobook) {
                            $audiobooks[] = $audiobook;
                            $processedDirectories[] = $path;
                        }
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

        // Create unique temp file name using cover data hash to avoid collisions
        $coverHash = substr(md5($coverData), 0, 8);
        $tempFile = tempnam(sys_get_temp_dir(), 'embedded_cover_' . $coverHash . '_');
        if ($tempFile === false) {
            return null;
        }

        file_put_contents($tempFile, $binary);

        return $tempFile;
    }

    /**
     * Edit metadata fields interactively
     */
    public function editMetadataFields(
        array $metadata,
        array $audiobook,
        callable $askInlineCallback,
        callable $selectWithImmediateInterruptCallback,
        callable $getFirstNonEmptyMetadataValueCallback,
        callable $extractSeriesNumberFromTitleCallback,
        callable $getValidGenresCallback,
        callable $uiServiceLogCallback,
        callable $buildUiMetadataCallback,
        bool $forceSequential = false,
        ?callable $manualEnrichmentCallback = null,
        ?callable $getEnrichmentServiceCallback = null,
        ?callable $generateDirectoryPathCallback = null
    ): array {
        if ($forceSequential) {
            $currentTitle = $metadata['title'] ?? $getFirstNonEmptyMetadataValueCallback($metadata, ['title', 'book_title', 'name']) ?? '';
            $metadata['title'] = $askInlineCallback('Title', (string) $currentTitle);

            $currentAuthor = $metadata['author'] ?? $getFirstNonEmptyMetadataValueCallback($metadata, ['author', 'authors', 'authorName', 'author_name']) ?? '';
            $displayAuthor = is_array($currentAuthor) ? implode(', ', $currentAuthor) : $currentAuthor;
            $newAuthor = $askInlineCallback('Author(s) (comma-separated)', (string) $displayAuthor);
            $metadata['author'] = array_map('trim', explode(',', $newAuthor));

            $currentNarrator = $metadata['narrator'] ?? $getFirstNonEmptyMetadataValueCallback($metadata, ['narrator', 'narrators', 'narratorName', 'narrator_name']) ?? '';
            $displayNarrator = is_array($currentNarrator) ? implode(', ', $currentNarrator) : $currentNarrator;
            $newNarrator = $askInlineCallback('Narrator(s) (comma-separated)', (string) $displayNarrator);
            $metadata['narrator'] = array_map('trim', explode(',', $newNarrator));

            $currentSeries = $metadata['series'] ?? $getFirstNonEmptyMetadataValueCallback($metadata, ['series', 'seriesName', 'series_name']) ?? '';
            $metadata['series'] = $askInlineCallback('Series', (string) $currentSeries);
            if (!empty($metadata['series'])) {
                $currentSeriesNumber = $metadata['series_number'] ?? $getFirstNonEmptyMetadataValueCallback($metadata, ['series_number', 'seriesNumber', 'series_num', 'seriesNum']) ?? '';
                $metadata['series_number'] = $askInlineCallback('Series Number', (string) $currentSeriesNumber);
            } else {
                unset($metadata['series']);
                $metadata['series_number'] = '';
            }

            $currentYear = $metadata['year'] ?? $getFirstNonEmptyMetadataValueCallback($metadata, ['year', 'publishedYear', 'published_year', 'published_date']) ?? '';
            if (is_string($currentYear) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $currentYear)) {
                $currentYear = substr($currentYear, 0, 4);
            }
            $metadata['year'] = $askInlineCallback('Year', (string) $currentYear);

            if (!empty($this->config['genre'])) {
                $uiServiceLogCallback('⚠️  Genre is forced to "' . $this->config['genre'] . '" by CLI option.');
                $metadata['genre'] = $this->config['genre'];
            } else {
                $validGenres = $getValidGenresCallback();
                $genreOptions = [];
                foreach ($validGenres as $idx => $g) {
                    $genreOptions[(string) ($idx + 1)] = $g;
                }
                $currentGenre = $metadata['genre'] ?? $getFirstNonEmptyMetadataValueCallback($metadata, ['genre', 'genres', 'genreName', 'genre_name']) ?? 'Other';
                $displayGenre = is_array($currentGenre) ? ($currentGenre[0] ?? 'Other') : $currentGenre;
                $currentGenreIdx = array_search($displayGenre, $validGenres, true);
                $defaultGenreIdx = ($currentGenreIdx !== false) ? (string) ($currentGenreIdx + 1) : (string) count($validGenres);
                $selectedGenreIdx = $selectWithImmediateInterruptCallback('Genre', $genreOptions, $defaultGenreIdx);
                $newGenre = $genreOptions[$selectedGenreIdx] ?? $displayGenre;
                if (is_array($metadata['genre'] ?? null)) {
                    $others = array_filter($metadata['genre'], fn ($g) => $g !== $newGenre);
                    $metadata['genre'] = array_values(array_merge([$newGenre], $others));
                } else {
                    $metadata['genre'] = $newGenre;
                }
            }

            $currentDirectory = (string) ($metadata['custom_directory_path'] ?? '');
            if ($currentDirectory === '') {
                $currentDirectory = $this->generateDirectoryPath($metadata, [
                    'include_title' => true,
                ]);
            }
            $metadata['custom_directory_path'] = $askInlineCallback('Directory Path', $currentDirectory);

            $metadata['confidence'] = 100;
            $uiServiceLogCallback('setCurrentBook', $buildUiMetadataCallback($metadata));

            if (!empty($audiobook['is_multi_book_part'])) {
                foreach (['author', 'narrator', 'genre', 'series'] as $field) {
                    if (!empty($metadata[$field])) {
                        $this->multiBookSharedOverrides[$field] = $metadata[$field];
                    }
                }
            }

            return $metadata;
        }

        while (true) {
            $currentTitle = $metadata['title'] ?? $getFirstNonEmptyMetadataValueCallback($metadata, ['title', 'book_title', 'name']) ?? '';
            $currentAuthor = $metadata['author'] ?? $getFirstNonEmptyMetadataValueCallback($metadata, ['author', 'authors', 'authorName', 'author_name']) ?? '';
            $displayAuthor = is_array($currentAuthor) ? implode(', ', $currentAuthor) : $currentAuthor;

            $currentNarrator = $metadata['narrator'] ?? $getFirstNonEmptyMetadataValueCallback($metadata, ['narrator', 'narrators', 'narratorName', 'narrator_name']) ?? '';
            $displayNarrator = is_array($currentNarrator) ? implode(', ', $currentNarrator) : $currentNarrator;

            $currentSeries = $metadata['series'] ?? $getFirstNonEmptyMetadataValueCallback($metadata, ['series', 'seriesName', 'series_name']) ?? '';
            $currentSeriesNumber = $metadata['series_number'] ?? $getFirstNonEmptyMetadataValueCallback($metadata, ['series_number', 'seriesNumber', 'series_num', 'seriesNum']) ?? '';

            $currentYear = $metadata['year'] ?? $getFirstNonEmptyMetadataValueCallback($metadata, ['year', 'publishedYear', 'published_year', 'published_date']) ?? '';
            if (is_string($currentYear) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $currentYear)) {
                $currentYear = substr($currentYear, 0, 4);
            }

            $currentGenre = $metadata['genre'] ?? $getFirstNonEmptyMetadataValueCallback($metadata, ['genre', 'genres', 'genreName', 'genre_name']) ?? 'Other';
            $displayGenre = is_array($currentGenre) ? ($currentGenre[0] ?? 'Other') : $currentGenre;

            $currentDirectory = (string) ($metadata['custom_directory_path'] ?? '');
            if ($currentDirectory === '') {
                $currentDirectory = $this->generateDirectoryPath($metadata, [
                    'include_title' => true,
                ]);
            }

            $currentCoverUrl = (string) ($metadata['cover_url'] ?? '');
            $options = [
                '1' => 'Title: ' . $currentTitle,
                '2' => 'Author(s): ' . $displayAuthor,
                '3' => 'Narrator(s): ' . $displayNarrator,
                '4' => 'Series: ' . $currentSeries,
                '5' => 'Series Number: ' . $currentSeriesNumber,
                '6' => 'Year: ' . $currentYear,
                '7' => 'Genre: ' . $displayGenre,
                '8' => 'Directory Path: ' . $currentDirectory,
                'a' => 'Edit all fields (sequential)',
                'c' => 'Update cover' . ($currentCoverUrl !== '' ? ' (has URL)' : ''),
                'n' => 'Add narrator to directory name',
            ];
            if ($manualEnrichmentCallback !== null && $getEnrichmentServiceCallback !== null) {
                $options['r'] = 'Request enrichment (Audible/Google Books)';
            }
            $options['9'] = "\e[1;32mDone\e[0m";

            $choice = $selectWithImmediateInterruptCallback('Select field to edit', $options, '9');

            if ($choice === '9' || $choice === 'd' || $choice === 'done' || $choice === '') {
                break;
            }

            $titleManuallyEdited = false;
            switch ($choice) {
                case '1':
                    $metadata['title'] = $askInlineCallback('Title', (string) $currentTitle);
                    $titleManuallyEdited = true;
                    break;
                case '2':
                    $newAuthor = $askInlineCallback('Author(s) (comma-separated)', (string) $displayAuthor);
                    $metadata['author'] = array_map('trim', explode(',', $newAuthor));
                    break;
                case '3':
                    $newNarrator = $askInlineCallback('Narrator(s) (comma-separated)', (string) $displayNarrator);
                    $metadata['narrator'] = array_map('trim', explode(',', $newNarrator));
                    break;
                case '4':
                    $metadata['series'] = $askInlineCallback('Series', (string) $currentSeries);
                    if (empty($metadata['series'])) {
                        unset($metadata['series']);
                        $metadata['series_number'] = '';
                    }
                    break;
                case '5':
                    $metadata['series_number'] = $askInlineCallback('Series Number', (string) $currentSeriesNumber);
                    break;
                case '6':
                    $metadata['year'] = $askInlineCallback('Year', (string) $currentYear);
                    break;
                case '7':
                    if (!empty($this->config['genre'])) {
                        $uiServiceLogCallback('⚠️  Genre is forced to "' . $this->config['genre'] . '" by CLI option.');
                        $metadata['genre'] = $this->config['genre'];
                        break;
                    }
                    $validGenres = $getValidGenresCallback();
                    $genreOptions = [];
                    foreach ($validGenres as $idx => $g) {
                        $genreOptions[(string) ($idx + 1)] = $g;
                    }
                    $currentGenreIdx = array_search($displayGenre, $validGenres, true);
                    $defaultGenreIdx = ($currentGenreIdx !== false) ? (string) ($currentGenreIdx + 1) : (string) count($validGenres);
                    $selectedGenreIdx = $selectWithImmediateInterruptCallback('Genre', $genreOptions, $defaultGenreIdx);
                    $newGenre = $genreOptions[$selectedGenreIdx] ?? $displayGenre;

                    if (is_array($metadata['genre'] ?? null)) {
                        $others = array_filter($metadata['genre'], fn ($g) => $g !== $newGenre);
                        $metadata['genre'] = array_values(array_merge([$newGenre], $others));
                    } else {
                        $metadata['genre'] = $newGenre;
                    }
                    break;
                case '8':
                    $metadata['custom_directory_path'] = $askInlineCallback('Directory Path', $currentDirectory);
                    break;
                case 'a':
                    $metadata = $this->editMetadataFields(
                        $metadata,
                        $audiobook,
                        $askInlineCallback,
                        $selectWithImmediateInterruptCallback,
                        $getFirstNonEmptyMetadataValueCallback,
                        $extractSeriesNumberFromTitleCallback,
                        $getValidGenresCallback,
                        $uiServiceLogCallback,
                        $buildUiMetadataCallback,
                        true,
                        $manualEnrichmentCallback,
                        $getEnrichmentServiceCallback,
                        $generateDirectoryPathCallback
                    );
                    continue 2;
                case 'c':
                    $newCoverUrl = $askInlineCallback('Cover URL', $currentCoverUrl);
                    $metadata['cover_url'] = $newCoverUrl ?: $currentCoverUrl;
                    $uiServiceLogCallback('setCurrentBook', $buildUiMetadataCallback($metadata));
                    continue 2;
                case 'r':
                    if ($manualEnrichmentCallback !== null && $getEnrichmentServiceCallback !== null) {
                        $metadata = $manualEnrichmentCallback($metadata, $audiobook, $getEnrichmentServiceCallback());
                        $uiServiceLogCallback('setCurrentBook', $buildUiMetadataCallback($metadata));
                    }
                    continue 2;
                case 'n':
                    $narrators = $metadata['narrator'] ?? null;
                    $narratorString = '';
                    if ($narrators !== null) {
                        $narratorString = is_array($narrators) ? implode(', ', $narrators) : (string) $narrators;
                    }
                    if ($narratorString === '') {
                        $narratorString = $askInlineCallback('Narrator name', '');
                    }
                    if (trim($narratorString) !== '') {
                        $resolvedDir = $metadata['custom_directory_path'] ?? '';
                        if ($resolvedDir === '' && $generateDirectoryPathCallback !== null) {
                            $resolvedDir = $generateDirectoryPathCallback($metadata, ['include_title' => true]);
                        }
                        $base = rtrim($resolvedDir, '/');
                        $base = preg_replace('/\s*\([^)]+\)$/', '', $base);
                        $metadata['custom_directory_path'] = $base . " ({$narratorString})";
                        $uiServiceLogCallback("📁 Directory updated: {$metadata['custom_directory_path']}");
                    }
                    continue 2;
            }

            if (!$titleManuallyEdited) {
                $extractSeriesNumberFromTitleCallback($metadata);
            }
            $metadata['confidence'] = 100;
            $uiServiceLogCallback('setCurrentBook', $buildUiMetadataCallback($metadata));
        }

        if (!empty($audiobook['is_multi_book_part'])) {
            foreach (['author', 'narrator', 'genre', 'series'] as $field) {
                if (!empty($metadata[$field])) {
                    $this->multiBookSharedOverrides[$field] = $metadata[$field];
                }
            }
        }

        return $metadata;
    }

    /**
     * Build review options for metadata review
     */
    public function buildReviewOptions(
        string $currentCoverUrl,
        string $currentGenre,
        string $currentDirectoryPath,
        bool $isFinalConfirmation,
        callable $getValidGenresCallback,
        bool $isMultiBookPart = false,
        int $fileCount = 0,
        ?array $previousImport = null
    ): array {
        $validGenres = $getValidGenresCallback();
        $normalizedGenre = trim($currentGenre);
        $isGenreValid = in_array($normalizedGenre, $validGenres, true);

        $acceptLabel = $isFinalConfirmation ? 'Accept and save' : 'Accept all as correct';
        if (!$isGenreValid) {
            $acceptLabel = "\e[9m{$acceptLabel}\e[0m";
        }

        $options = [
            '1' => $acceptLabel,
            '2' => 'Edit',
            '3' => $isFinalConfirmation ? 'Skip' : 'Skip this book',
        ];

        // Only show "Reprocess as Multi-Book Archive" if NOT already a multi-book part AND has more than 1 file
        if (!$isMultiBookPart && $fileCount > 1) {
            $options['4'] = 'Reprocess as Multi-Book Archive (Split)';
        }

        // Only show "Merge into Parent Book" if this IS a multi-book part
        if ($isMultiBookPart) {
            $options['5'] = 'Merge into Parent Book';
        }

        // Show "Go back" option when there is a previous book in session history
        if ($previousImport !== null) {
            $prevTitle = $previousImport['title'] ?? 'unknown';
            $prevType = $previousImport['type'] ?? 'imported';
            $label = $prevType === 'skipped' ? "Retry skipped: {$prevTitle}" : "Fix previous import: {$prevTitle}";
            $options['p'] = $label;
        }

        return $options;
    }

    /**
     * Display directory comparison information
     */
    protected function formatOptionsAsColumns(array $options, int $columns = 3): array
    {
        $keys = array_keys($options);
        $totalItems = count($keys);
        if ($totalItems === 0) {
            return [];
        }

        $rows = (int) ceil($totalItems / $columns);
        $lines = [];

        for ($r = 0; $r < $rows; $r++) {
            $line = '';
            for ($c = 0; $c < $columns; $c++) {
                $idx = ($c * $rows) + $r;
                if ($idx < $totalItems) {
                    $key = $keys[$idx];
                    $val = (string) $options[$key];
                    $item = "(\e[1;36m{$key}\e[0m) {$val}";
                    $line .= str_pad($item, 30); // Use a fixed width for simplicity or calculate max
                }
            }
            $lines[] = rtrim($line);
        }

        return $lines;
    }

    public function displayDirectoryComparison(
        array $comparison,
        callable $formatBytesCallback,
        callable $formatFileTypesCallback,
        ?callable $lineCallback = null,
        ?callable $tableCallback = null
    ): ?array {
        if ($lineCallback) {
            $lineCallback("📁 Directory Comparison:");
            $lineCallback("   Source:  " . ($comparison['source_path'] ?? 'N/A'));
            $lineCallback("   Target:  " . ($comparison['target_path'] ?? 'N/A'));
            $lineCallback("");
        }

        if (isset($comparison['source']) && isset($comparison['target'])) {
            $locHeader = 'Location';
            $filesHeader = 'Files';
            $sizeHeader = 'Total Size';
            $typesHeader = 'File Types';

            $sourceLabel = 'Source (New)';
            $targetLabel = 'Target (Existing)';

            $sourceCount = (string) ($comparison['source']['count'] ?? 0);
            $targetCount = (string) ($comparison['target']['count'] ?? 0);

            $sourceSize = $formatBytesCallback($comparison['source']['total_size'] ?? 0);
            $targetSize = $formatBytesCallback($comparison['target']['total_size'] ?? 0);

            $sourceTypes = $formatFileTypesCallback($comparison['source']['file_types'] ?? []);
            $targetTypes = $formatFileTypesCallback($comparison['target']['file_types'] ?? []);

            // Calculate widths for manual alignment if tableCallback is just doing line-by-line
            if ($tableCallback) {
                $tableCallback(
                    [$locHeader, $filesHeader, $sizeHeader, $typesHeader],
                    [
                        [$sourceLabel, $sourceCount, $sourceSize, $sourceTypes],
                        [$targetLabel, $targetCount, $targetSize, $targetTypes],
                    ]
                );
            }

            return [
                [$locHeader, $filesHeader, $sizeHeader, $typesHeader],
                [$sourceLabel, $sourceCount, $sourceSize, $sourceTypes],
                [$targetLabel, $targetCount, $targetSize, $targetTypes],
            ];
        }

        if ($lineCallback) {
            $lineCallback("❌ Missing source or target data in comparison");
        }

        return null;
    }

    /**
     * Prompt user for action when duplicate is detected but can't be compared
     * Returns action result: 'skip', 'delete', 'continue', or 'skip'
     */
    public function promptForDuplicateAction(
        array $options,
        callable $selectCallback,
        ?callable $logMessageCallback = null,
        ?callable $cleanupSourceDirectoryCallback = null,
        array $audiobook = null,
        array &$skippedBooks = null,
        $existingBook = null
    ): string {
        if ($logMessageCallback && $existingBook) {
            $logMessageCallback("🔍 Duplicate book detected:");
            $logMessageCallback("  Existing: '{$existingBook->title}' (ID: {$existingBook->id})");
        }

        $choice = $selectCallback("Duplicate detected - choose action", $options, '1');

        $choice = strtolower(trim($choice));
        if (in_array($choice, ['1', 's', 'skip'])) {
            if ($logMessageCallback) {
                $logMessageCallback("📁 Skipping import, keeping both");
            }
            if ($skippedBooks !== null && $audiobook !== null) {
                $skippedBooks[] = [
                    'path' => $audiobook['path'],
                    'reason' => 'User chose to skip (duplicate detected)',
                ];
            }
            return 'skip';
        }

        if (in_array($choice, ['2', 'd', 'delete'])) {
            if ($logMessageCallback) {
                $logMessageCallback("🗑️ Removing source directory");
            }
            if ($cleanupSourceDirectoryCallback && $audiobook !== null) {
                $cleanupSourceDirectoryCallback($audiobook, true);
            }
            if ($skippedBooks !== null && $audiobook !== null) {
                $skippedBooks[] = [
                    'path' => $audiobook['path'],
                    'reason' => 'User chose to delete source (duplicate detected)',
                ];
            }
            return 'delete';
        }

        if (in_array($choice, ['3', 'c', 'continue'])) {
            if ($logMessageCallback) {
                $logMessageCallback("⚠️ Continuing with import despite duplicate detection");
            }
            return 'continue';
        }

        return 'skip';
    }

    public function buildMergeMetadata(
        Book $existingBook,
        array $newMetadata,
        callable $selectCallback,
        callable $askCallback,
        callable $infoCallback
    ): ?array {
        $existingBook->load(['authors', 'narrators', 'series', 'genres', 'publisher']);

        $firstSeries = $existingBook->series->first();

        $existingData = [
            'title' => $existingBook->title ?? '',
            'author' => $existingBook->authors->pluck('name')->toArray(),
            'narrator' => $existingBook->narrators->pluck('name')->toArray(),
            'series' => $firstSeries ? $firstSeries->name : '',
            'series_number' => $firstSeries ? ($firstSeries->pivot->series_number ?? '') : '',
            'year' => $existingBook->release_date ? substr((string) $existingBook->release_date, 0, 4) : '',
            'genre' => $existingBook->genres->pluck('name')->toArray(),
            'description' => $existingBook->description ?? '',
            'isbn' => $existingBook->isbn ?? '',
            'language' => $existingBook->language ?? 'en',
        ];

        $merged = $newMetadata;

        $fields = [
            'title' => 'Title',
            'author' => 'Author',
            'narrator' => 'Narrator',
            'series' => 'Series',
            'series_number' => 'Series Number',
            'year' => 'Year',
            'genre' => 'Genre',
            'description' => 'Description',
            'isbn' => 'ISBN',
            'language' => 'Language',
        ];

        foreach ($fields as $key => $label) {
            $existingVal = $existingData[$key];
            $newVal = $newMetadata[$key] ?? '';

            $existingStr = is_array($existingVal) ? implode(', ', $existingVal) : (string) $existingVal;
            $newStr = is_array($newVal) ? implode(', ', (array) $newVal) : (string) $newVal;

            if (strcasecmp(trim($existingStr), trim($newStr)) === 0) {
                if ($existingStr !== '') {
                    $merged[$key] = $existingVal;
                }
                continue;
            }

            if (trim($existingStr) === '' && trim($newStr) !== '') {
                $infoCallback("  {$label}: using new value '{$newStr}'");
                $merged[$key] = $newVal;
                continue;
            }

            if (trim($newStr) === '' && trim($existingStr) !== '') {
                $merged[$key] = $existingVal;
                continue;
            }

            $options = [
                '1' => "Keep existing: {$existingStr}",
                '2' => "Use new: {$newStr}",
                '3' => 'Edit manually',
                '4' => 'Cancel merge',
            ];

            $choice = $selectCallback("{$label} differs", $options, '1');

            switch ($choice) {
                case '2':
                    $merged[$key] = $newVal;
                    break;
                case '3':
                    $editedValue = $askCallback("{$label}", $newStr);
                    if (is_array($existingVal)) {
                        $merged[$key] = array_map('trim', explode(',', $editedValue));
                    } else {
                        $merged[$key] = $editedValue;
                    }
                    break;
                case '4':
                    return null;
                default:
                    $merged[$key] = $existingVal;
                    break;
            }
        }

        return $merged;
    }

    public function mergeIntoExistingBook(
        Book $existingBook,
        array $audiobook,
        array $mergedMetadata,
        callable $warnCallback,
        callable $infoCallback,
        callable $getFileOperationCallback
    ): Book {
        if (empty($existingBook->directory_path)) {
            $existingBook->directory_path = $this->generateDirectoryPath($mergedMetadata, ['include_title' => true]);
            $existingBook->save();
            $infoCallback("  Generated directory path: {$existingBook->directory_path}");
        }

        $book = $this->updateBookFromMetadata($existingBook, $mergedMetadata, $audiobook);
        $infoCallback("  Updated book record (ID: {$book->id})");

        $bookStoragePath = config('filesystems.disks.books.root') ?? config('app.book_root');
        $targetDir = rtrim($bookStoragePath, '/') . '/' . ltrim($existingBook->directory_path, '/');

        $operation = $getFileOperationCallback();
        $this->moveFilesToLibrary(
            $audiobook,
            $book,
            [
                'storage_path' => $bookStoragePath,
                'operation' => $operation === 'copy' ? 'copy' : 'move',
                'target_directory' => $targetDir,
            ]
        );

        $this->processCoverImage($book, $mergedMetadata);

        return $book;
    }

    /**
     * Process a single book (used for both regular books and split multi-books)
     */
    public function processSingleBook(
        array $audiobook,
        array $metadata,
        callable $enrichWithExternalDataCallback,
        callable $isValidEnrichmentCallback,
        callable $generateDirectoryPathCallback,
        callable $createBookFromMetadataCallback,
        callable $moveFilesToLibraryCallback,
        callable $getFileOperationCallback,
        ?callable $infoCallback = null,
        ?callable $displayEnrichedMetadataCallback = null,
        ?callable $reviewAndApproveCallback = null,
        ?callable $hasEnrichmentDataCallback = null,
        bool $skipEnrichment = false,
        bool $isAutoMode = false,
        bool $isDryRun = false,
        array &$skippedBooks = null,
        array &$processedBooks = null
    ): ?Book {
        // Inject collection from config if provided
        if (!empty($this->config['collection']) && empty($metadata['collection'])) {
            $metadata['collection'] = $this->config['collection'];
        }

        if ($infoCallback && !$skipEnrichment) {
            $infoCallback("🔍 Enriching with external data...");
            $enriched = $enrichWithExternalDataCallback($metadata);
            if ($enriched) {
                $metadata = array_merge($metadata, $enriched);
            }
        }

        // Force genre from config if provided - do this after potential enrichment calls
        // to ensure it takes priority.
        if (!empty($this->config['genre'])) {
            $metadata['genre'] = $this->config['genre'];
        }

        // Clean series name from title before display
        if (!empty($metadata['title']) && !empty($metadata['series'])) {
            $metadata['title'] = $this->removeSeriesFromTitle($metadata['title'], $metadata['series']);
        }

        // Use display_path for UI if available (multi-book parts), otherwise use path
        $displayPath = $audiobook['display_path'] ?? $audiobook['path'];
        $metadata['source_path'] = $displayPath;
        $expectedPath = $generateDirectoryPathCallback($metadata);
        if ($infoCallback) {
            $infoCallback("📁 Expected directory path: {$expectedPath}");
        }

        if ($displayEnrichedMetadataCallback) {
            $displayEnrichedMetadataCallback($metadata);
        }

        Log::debug("processSingleBook: Review decision point", [
            'title' => $metadata['title'] ?? 'unknown',
            'isAutoMode' => $isAutoMode,
            'isDryRun' => $isDryRun,
            'hasReviewCallback' => $reviewAndApproveCallback !== null,
            'is_multi_book_part' => !empty($audiobook['is_multi_book_part']),
        ]);

        if (!$isAutoMode && !$isDryRun && $reviewAndApproveCallback) {
            Log::info("processSingleBook: Calling review and approve", [
                'title' => $metadata['title'] ?? 'unknown',
            ]);

            if (!$reviewAndApproveCallback($metadata, $audiobook)) {
                if ($infoCallback) {
                    $infoCallback("❌ Import rejected by user");
                }
                if ($skippedBooks !== null) {
                    $skippedBooks[] = [
                        'path' => $audiobook['path'],
                        'reason' => 'Rejected by user',
                    ];
                }
                Log::info("processSingleBook: User rejected import", [
                    'title' => $metadata['title'] ?? 'unknown',
                ]);
                return null;
            }

            Log::info("processSingleBook: User approved import", [
                'title' => $metadata['title'] ?? 'unknown',
            ]);
        } elseif ($isAutoMode && $hasEnrichmentDataCallback && !$hasEnrichmentDataCallback($metadata)) {
            if ($infoCallback) {
                $infoCallback("⚠️  No enrichment data found in auto mode - skipping (detected fields might be incorrect)");
            }
            if ($skippedBooks !== null) {
                $skippedBooks[] = [
                    'path' => $audiobook['path'],
                    'reason' => 'No enrichment data in auto mode',
                ];
            }
            return null;
        }

        if (!$isDryRun) {
            $book = $createBookFromMetadataCallback($metadata, $audiobook);

            if ($book) {
                $moveFilesToLibraryCallback($audiobook, $book, [
                    'operation' => $getFileOperationCallback(),
                ]);

                if ($infoCallback) {
                    $infoCallback("✅ Book imported successfully: {$book->title} (ID: {$book->id})");
                }

                if ($processedBooks !== null) {
                    $processedBooks[] = [
                        'path' => $audiobook['path'],
                        'book_id' => $book->id,
                        'title' => $book->title,
                    ];
                }
            }
        } else {
            if ($infoCallback) {
                $infoCallback("🔍 [DRY RUN] Would import: {$metadata['title']}");
            }
        }

        return $book ?? null;
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
            '/^(.+?),\s*Book\s+([\d.]+)$/i',
            '/^(.+?)\s+Book\s+([\d.]+)$/i',
            '/^(.+?),\s*Volume\s+([\d.]+)$/i',
            '/^(.+?)\s+Volume\s+([\d.]+)$/i',
            '/^(.+?),\s*#([\d.]+)$/i',
            '/^(.+?)\s+#([\d.]+)$/i',
            '/^(.+?),\s*Part\s+([\d.]+)$/i',
            '/^(.+?)\s+Part\s+([\d.]+)$/i',
            '/^(.+?)\s+([\d.]+)$/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $title, $matches)) {
                $cleanTitle = trim($matches[1]);
                $raw = $matches[2];
                $bookNumber = str_contains($raw, '.') ? (float) $raw : (int) $raw;

                $metadata['title'] = $cleanTitle;
                $metadata['series_number'] = $bookNumber;

                return;
            }
        }
    }

    /**
     * Get file operation type (copy or move)
     */
    public function getFileOperation(?callable $getCopyFilesOptionCallback = null): string
    {
        $copyFiles = $getCopyFilesOptionCallback ? $getCopyFilesOptionCallback() : false;
        return $copyFiles ? 'copy' : 'move';
    }

    /**
     * Process multi-book directory by splitting into individual books
     */
    public function processMultiBookSplit(
        array $audiobook,
        array $multiBookInfo,
        array $splitGroups,
        array $aiMetadata,
        ?callable $infoCallback = null,
        ?callable $processSingleBookCallback = null
    ): array {
        if ($infoCallback) {
            $infoCallback("🔄 Processing {$multiBookInfo['series_name']} as split books...");
        }

        // Look up genre from existing books in the same series by the same author
        $lookupMetadata = $aiMetadata;
        $lookupMetadata['series'] = $multiBookInfo['series_name'];
        $seriesGenre = $this->lookupGenreFromExistingSeries($lookupMetadata);
        if ($seriesGenre) {
            $aiMetadata['genre'] = $seriesGenre;
            if ($infoCallback) {
                $infoCallback("📚 Genre set from existing series: {$seriesGenre}");
            }
        }

        $books = [];
        $this->multiBookSharedOverrides = [];

        foreach ($splitGroups as $bookNumber => $fileInfos) {
            if (empty($fileInfos)) {
                continue;
            }

            $files = array_map(function ($fileInfo) {
                return $fileInfo['file'];
            }, $fileInfos);
            $bookTitle = $fileInfos[0]['title'];

            // Get the first file path - this is what we show as the "path" being imported
            $firstFilePath = $files[0];
            $firstFilename = basename($firstFilePath);

            // Parse the filename to extract metadata (author, series, title, etc.)
            $filenameMetadata = $this->parseFilenameForMetadata($firstFilename);

            // Extract file tags from this book's files to get embedded metadata
            $fileTagMetadata = $this->extractFileTagsFromFiles($files);

            // Start with AI metadata as base, applying shared overrides from previous edits
            $bookMetadata = array_merge($aiMetadata, $this->multiBookSharedOverrides);
            $bookMetadata['series'] = $multiBookInfo['series_name'];
            $bookMetadata['series_number'] = $bookNumber;
            unset($bookMetadata['series_original']);

            // Merge filename-parsed metadata (fills in missing fields)
            if (!empty($filenameMetadata)) {
                $bookMetadata = $this->mergeMetadataFillMissing($bookMetadata, $filenameMetadata);
                // Use filename-parsed title if we got one
                if (!empty($filenameMetadata['title'])) {
                    $bookTitle = $filenameMetadata['title'];
                    $bookMetadata['title'] = $bookTitle;
                }
            }

            // Merge file tag metadata - embedded tags take priority
            if (!empty($fileTagMetadata)) {
                $bookMetadata = $this->mergeMetadataFillMissing($bookMetadata, $fileTagMetadata);
                // If file tags have a different title (album), prefer it
                if (!empty($fileTagMetadata['title']) && $fileTagMetadata['title'] !== $bookTitle) {
                    $bookMetadata['title'] = $fileTagMetadata['title'];
                    $bookTitle = $fileTagMetadata['title'];
                }
            }

            // If we still don't have a good title, use the one from fileInfos
            if (empty($bookMetadata['title'])) {
                $bookMetadata['title'] = $fileInfos[0]['title'];
            }

            // Clean the title to remove series name prefix/suffix
            if (!empty($bookMetadata['title']) && !empty($multiBookInfo['series_name'])) {
                $bookMetadata['title'] = $this->removeSeriesFromTitle(
                    $bookMetadata['title'],
                    $multiBookInfo['series_name']
                );
            }

            $virtualAudiobook = [
                'path' => $audiobook['path'],  // Keep parent path for file operations
                'display_path' => $firstFilePath,  // Show the actual file being imported in UI
                'name' => $bookTitle,
                'files' => $files,
                'total_size' => array_sum(array_map('filesize', $files)),
                'is_multi_book_part' => true,
                'multi_book_files_only' => $files,
                'parent_path' => $audiobook['path'],
            ];

            $bookData = [
                'audiobook' => $virtualAudiobook,
                'metadata' => $bookMetadata,
            ];

            $books[] = $bookData;

            // Process each book if callback provided
            if ($processSingleBookCallback) {
                if ($infoCallback) {
                    $infoCallback("📖 Processing Book {$bookMetadata['series_number']} with " . count($virtualAudiobook['files']) . " files");
                    $infoCallback("📁 File: {$firstFilename}");
                }

                Log::info("Processing multi-book part", [
                    'series' => $multiBookInfo['series_name'],
                    'book_number' => $bookNumber,
                    'title' => $bookMetadata['title'],
                    'file_count' => count($virtualAudiobook['files']),
                ]);

                try {
                    $processSingleBookCallback($virtualAudiobook, $bookMetadata);

                    Log::info("Multi-book part processed successfully", [
                        'series' => $multiBookInfo['series_name'],
                        'book_number' => $bookNumber,
                    ]);
                } catch (MergeIntoParentException $e) {
                    if ($infoCallback) {
                        $infoCallback("🛑 Merge requested: Stopping split processing and reprocessing parent directory...");
                    }

                    Log::info("Merge requested - reprocessing as single book", [
                        'series' => $multiBookInfo['series_name'],
                    ]);

                    // Reprocess original parent audiobook (all files) as a single book
                    // Pass flag to prevent it from being re-detected as a multi-book/flat archive
                    $parentAudiobook = $audiobook;
                    $parentAudiobook['_force_single_mode'] = true;

                    // Reset metadata to original AI metadata (stripping split info)
                    $parentMetadata = $aiMetadata;
                    unset($parentMetadata['series_number']);
                    unset($parentMetadata['multi_book_numbers']);

                    // Process the parent directory as a single book
                    $processSingleBookCallback($parentAudiobook, $parentMetadata);

                    // Stop processing further split groups
                    return $books;
                } catch (\Exception $e) {
                    // CRITICAL: Any exception during multi-book processing should stop the entire split
                    // to prevent data loss from automated continuation without user confirmation
                    Log::error("Failed to process multi-book part - stopping split processing", [
                        'series' => $multiBookInfo['series_name'],
                        'book_number' => $bookNumber,
                        'title' => $bookMetadata['title'],
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    if ($infoCallback) {
                        $infoCallback("❌ Error processing book {$bookNumber}: " . $e->getMessage());
                        $infoCallback("🛑 Stopping multi-book import to prevent data loss");
                    }

                    // Re-throw to stop the import process
                    throw $e;
                }
            }
        }

        return $books;
    }

    /**
     * Parse a filename to extract metadata (author, series, title, etc.)
     *
     * Handles patterns like:
     * - "Author - Series #N - Title (extra).ext"
     * - "Series #N - Title by Author.ext"
     * - "Author - Title (Unabridged).ext"
     */
    public function parseFilenameForMetadata(string $filename): array
    {
        $metadata = [];

        // Remove extension
        $name = preg_replace('/\.[^.]+$/', '', $filename);

        // Remove common suffixes like (Unabridged), [Unabridged], etc.
        $name = preg_replace('/\s*[\(\[]?(Unabridged|Abridged|Audiobook|Audio)[\)\]]?\s*$/i', '', $name);
        $name = trim($name);

        // Pattern 1: "Author - Series #N - Title" or "Author - Series, Book N - Title"
        if (preg_match('/^(.+?)\s*-\s*(.+?)\s*[#,]\s*([\d.]+)\s*-\s*(.+)$/i', $name, $matches)) {
            $metadata['author'] = [trim($matches[1])];
            $metadata['series'] = trim($matches[2]);
            $raw = $matches[3];
            $metadata['series_number'] = str_contains($raw, '.') ? (float) $raw : (int) $raw;
            $metadata['title'] = trim($matches[4]);
            return $metadata;
        }

        // Pattern 2: "Author - Series Book N - Title"
        if (preg_match('/^(.+?)\s*-\s*(.+?)\s+Book\s+([\d.]+)\s*-\s*(.+)$/i', $name, $matches)) {
            $metadata['author'] = [trim($matches[1])];
            $metadata['series'] = trim($matches[2]);
            $raw = $matches[3];
            $metadata['series_number'] = str_contains($raw, '.') ? (float) $raw : (int) $raw;
            $metadata['title'] = trim($matches[4]);
            return $metadata;
        }

        // Pattern 2b: "Series Book N - Title" (no author prefix)
        if (preg_match('/^(.+?)\s+Book\s+([\d.]+)\s*-\s*(.+)$/i', $name, $matches)) {
            $raw = $matches[2];
            $metadata['series'] = trim($matches[1]);
            $metadata['series_number'] = str_contains($raw, '.') ? (float) $raw : (int) $raw;
            $metadata['title'] = trim($matches[3]);
            return $metadata;
        }

        // Pattern 3: "Series #N - Title by Author"
        if (preg_match('/^(.+?)\s*#([\d.]+)\s*-\s*(.+?)\s+by\s+(.+)$/i', $name, $matches)) {
            $metadata['series'] = trim($matches[1]);
            $raw = $matches[2];
            $metadata['series_number'] = str_contains($raw, '.') ? (float) $raw : (int) $raw;
            $metadata['title'] = trim($matches[3]);
            $metadata['author'] = [trim($matches[4])];
            return $metadata;
        }

        // Pattern 4: "Author - Series, The Series Name, Book N" (with nested series info)
        if (preg_match('/^(.+?)\s*-\s*(.+?)\s+The\s+(.+?)\s+Series,?\s*Book\s*([\d.]+)$/i', $name, $matches)) {
            $metadata['author'] = [trim($matches[1])];
            $metadata['title'] = trim($matches[2]);
            $metadata['series'] = trim($matches[3]) . ' Series';
            $raw = $matches[4];
            $metadata['series_number'] = str_contains($raw, '.') ? (float) $raw : (int) $raw;
            return $metadata;
        }

        // Pattern 5: "Author - Title The Series, Book N"
        if (preg_match('/^(.+?)\s*-\s*(.+?)\s+The\s+(.+?),?\s*Book\s*([\d.]+)$/i', $name, $matches)) {
            $metadata['author'] = [trim($matches[1])];
            $metadata['title'] = trim($matches[2]);
            $metadata['series'] = trim($matches[3]);
            $raw = $matches[4];
            $metadata['series_number'] = str_contains($raw, '.') ? (float) $raw : (int) $raw;
            return $metadata;
        }

        // Pattern 6: Simple "Author - Title"
        if (preg_match('/^(.+?)\s*-\s*(.+)$/', $name, $matches)) {
            $potentialAuthor = trim($matches[1]);
            $potentialTitle = trim($matches[2]);

            // Check if this looks like an author name (has name-like structure)
            if ($this->looksLikeAuthorName($potentialAuthor)) {
                $metadata['author'] = [$potentialAuthor];
                $metadata['title'] = $potentialTitle;
                return $metadata;
            }
        }

        // Fallback: just use the whole thing as title
        $metadata['title'] = $name;
        return $metadata;
    }

    /**
     * Check if a string looks like an author name
     */
    protected function looksLikeAuthorName(string $str): bool
    {
        // Author names typically:
        // - Have 2-4 words
        // - Each word starts with uppercase
        // - Don't contain numbers (unless initials like "J.K.")
        $words = preg_split('/\s+/', trim($str));
        $wordCount = count($words);

        if ($wordCount < 1 || $wordCount > 5) {
            return false;
        }

        // Check if it contains common non-author patterns
        if (preg_match('/(book|series|volume|part|chapter|\d{4})/i', $str)) {
            return false;
        }

        // Check if words look like name parts
        foreach ($words as $word) {
            // Allow initials like "J.K." or "R.R."
            if (preg_match('/^[A-Z]\.[A-Z]?\.?$/', $word)) {
                continue;
            }
            // Allow capitalized words
            if (preg_match('/^[A-Z][a-z]+$/', $word)) {
                continue;
            }
            // Allow all-caps short words (initials)
            if (preg_match('/^[A-Z]{1,3}$/', $word)) {
                continue;
            }
            // Allow hyphenated names
            if (preg_match('/^[A-Z][a-z]+-[A-Z][a-z]+$/', $word)) {
                continue;
            }
            return false;
        }

        return true;
    }

    /**
     * Extract file tags from a list of files
     */
    protected function extractFileTagsFromFiles(array $files, int $maxFiles = 3): array
    {
        if (empty($files)) {
            return [];
        }

        $fileTags = [];
        $processedCount = 0;

        foreach ($files as $filePath) {
            if ($processedCount >= $maxFiles) {
                break;
            }

            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if (!in_array($ext, ['m4b', 'mp3', 'm4a'])) {
                continue;
            }

            if (!file_exists($filePath)) {
                continue;
            }

            $tags = $this->extractSingleFileTags($filePath);
            if (!empty($tags)) {
                $fileTags[basename($filePath)] = $tags;
                $processedCount++;
            }
        }

        return $this->extractMetadataFromFileTags($fileTags);
    }

    /**
     * Extract tags from a single audio file using getID3
     */
    protected function extractSingleFileTags(string $filePath): array
    {
        if (!class_exists('getID3')) {
            return [];
        }

        try {
            $getID3 = new \getID3();
            $fileInfo = $getID3->analyze($filePath);

            $tags = [];

            if (isset($fileInfo['tags'])) {
                $preferredFormats = ['quicktime', 'id3v2', 'id3v1'];

                foreach ($preferredFormats as $format) {
                    if (isset($fileInfo['tags'][$format])) {
                        foreach ($fileInfo['tags'][$format] as $key => $values) {
                            if (!isset($tags[$key]) && !empty($values[0])) {
                                $tags[$key] = is_array($values) ? $values[0] : $values;
                            }
                        }
                    }
                }
            }

            return $tags;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Sanitize a string for use in a file path
     */
    protected function sanitizeForPath(string $input): string
    {
        $sanitized = preg_replace('/[<>:"\/\\|?*]/', '', $input);
        $sanitized = preg_replace('/\s+/', ' ', $sanitized);
        return trim($sanitized);
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
     * Resolve a list of genre candidates to a single genre.
     *
     * Priority:
     * 1. Author's DB history – if the author has more books in one candidate genre, use it.
     * 2. AI targeted prompt – ask the AI to choose between the specific candidates.
     * 3. First candidate as fallback.
     */
    public function disambiguateGenreCandidates(
        array $candidates,
        array $metadata,
        ?object $aiProcessor,
        ?callable $infoCallback = null,
        array $genreBySource = []
    ): string {
        $weakGenres = ['General Fiction', 'Action', 'Other', 'Unknown', ''];
        $candidates = array_values(array_unique(array_filter(
            $candidates,
            fn ($g) => !in_array($g, $weakGenres, true)
        )));
        if (count($candidates) === 0) {
            return 'Other';
        }
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        // 1. Other enrichment sources: tally votes from sources whose genre matches a candidate
        if (!empty($genreBySource)) {
            $votes = array_fill_keys($candidates, 0);
            foreach ($genreBySource as $source => $sourceGenre) {
                if (!$sourceGenre) {
                    continue;
                }
                $sourceParts = array_map('trim', explode(', ', $sourceGenre));
                foreach ($sourceParts as $part) {
                    if (isset($votes[$part])) {
                        $votes[$part]++;
                    }
                }
            }
            arsort($votes);
            $topVotes = reset($votes);
            $topCandidates = array_keys(array_filter($votes, fn ($v) => $v === $topVotes));
            if ($topVotes > 0 && count($topCandidates) === 1) {
                $winner = $topCandidates[0];
                if ($infoCallback) {
                    $sourceNames = array_keys(array_filter($genreBySource));
                    $infoCallback("🎯 Genre '{$winner}' selected from candidates [" . implode(', ', $candidates) . "] via enrichment sources (" . implode(', ', $sourceNames) . ")");
                }
                return $winner;
            }
        }

        // 2. Author DB history: pick the candidate the author writes most
        $authors = $metadata['author'] ?? [];
        if (is_string($authors)) {
            $authors = [$authors];
        }
        $preferredGenre = $this->getAuthorPreferredGenre($authors);
        if ($preferredGenre && in_array($preferredGenre, $candidates, true)) {
            if ($infoCallback) {
                $infoCallback("🎯 Genre '{$preferredGenre}' selected from candidates [" . implode(', ', $candidates) . "] via author history");
            }
            return $preferredGenre;
        }

        // 3. AI targeted disambiguation
        if ($aiProcessor) {
            try {
                $title = $metadata['title'] ?? '';
                $author = is_array($authors) ? implode(', ', $authors) : (string) $authors;
                $series = $metadata['series'] ?? '';
                $description = isset($metadata['description']) ? substr($metadata['description'], 0, 300) : '';
                $candidateList = implode(' or ', $candidates);

                $prompt = "An audiobook needs genre classification. Choose the single best genre.\n\n"
                    . "Title: {$title}\n"
                    . "Author: {$author}\n"
                    . ($series ? "Series: {$series}\n" : '')
                    . ($description ? "Description (excerpt): {$description}\n" : '')
                    . "\nCandidates: {$candidateList}\n\n"
                    . "Reply with ONLY the genre name from the candidates list. No explanation.";

                $response = $aiProcessor->complete($prompt);
                $aiAnswer = trim($response['data'] ?? $response['text'] ?? '');

                foreach ($candidates as $candidate) {
                    if (strcasecmp($aiAnswer, $candidate) === 0) {
                        if ($infoCallback) {
                            $infoCallback("🎯 Genre '{$candidate}' selected from candidates [" . implode(', ', $candidates) . "] via AI disambiguation");
                        }
                        return $candidate;
                    }
                }
            } catch (\Exception $e) {
                // AI disambiguation failed — fall through to default
            }
        }

        // 4. Fallback: return first candidate
        if ($infoCallback) {
            $infoCallback("🎯 Genre '{$candidates[0]}' selected (first of ambiguous candidates [" . implode(', ', $candidates) . "])");
        }
        return $candidates[0];
    }

    /**
     * Clean series name by removing author names
     */
    public function cleanSeriesName(string $seriesName, array $authors): string
    {
        $originalSeries = $seriesName;
        $cleanedSeries = $seriesName;

        // If series exactly matches any author, return empty string
        $seriesNormalized = strtolower(trim($seriesName));
        foreach ($authors as $author) {
            if (strtolower(trim($author)) === $seriesNormalized) {
                return '';
            }
        }

        // If the series name contains " - ", the part after is likely the actual series name
        // This handles "Author Name - Series Name" format from directory names
        if (str_contains($cleanedSeries, ' - ')) {
            $parts = explode(' - ', $cleanedSeries);
            $candidate = trim(end($parts));
            if (strlen($candidate) >= 2) {
                $cleanedSeries = $candidate;
            }
        }

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

        $albumTag = !empty($firstTags['album']) && is_string($firstTags['album']) ? $firstTags['album'] : null;
        $titleTag = !empty($firstTags['title']) && is_string($firstTags['title']) ? $firstTags['title'] : null;

        // If the ID3 title tag contains a series pattern (e.g. "The Messenger, Book 01"),
        // parse it and use the album tag to confirm the series name. The album tag is used
        // as-is for the book title (it typically holds the series/product title).
        if ($titleTag && $albumTag) {
            $seriesPatterns = [
                '/^(.+?),\s*Book\s+([\d.]+)$/i',
                '/^(.+?)\s*#([\d.]+)$/i',
                '/^(.+?)\s+Book\s+([\d.]+)$/i',
            ];
            $parsedSeries = null;
            $parsedNumber = null;
            foreach ($seriesPatterns as $pattern) {
                if (preg_match($pattern, $titleTag, $m)) {
                    $parsedSeries = trim($m[1]);
                    $raw = $m[2];
                    $parsedNumber = str_contains($raw, '.') ? (float) $raw : (int) $raw;
                    break;
                }
            }
            if ($parsedSeries !== null) {
                $metadata['title'] = $albumTag;
                $metadata['series'] = $parsedSeries;
                $metadata['series_number'] = $parsedNumber;
            } else {
                $metadata['title'] = $albumTag;
            }
        } elseif ($albumTag) {
            $metadata['title'] = $albumTag;
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
     * Remove series names from title when they contain colons or hyphens
     */
    public function removeSeriesFromTitle(string $title, ?string $seriesName = null): string
    {
        // Normalize dashes for consistent matching
        $title = str_replace(['–', '—'], '-', $title);

        // 1. If series name is known, try to remove it along with trailing info
        if ($seriesName && strlen($seriesName) > 0) {
            $seriesName = str_replace(['–', '—'], '-', $seriesName);

            // If the series name contains " - " (e.g. "Author - Series"), try the part after
            // the separator first since that's the actual series name
            if (str_contains($seriesName, ' - ')) {
                $parts = explode(' - ', $seriesName);
                $shortSeriesName = trim(end($parts));
                if (strlen($shortSeriesName) >= 2) {
                    $result = $this->removeSeriesFromTitle($title, $shortSeriesName);
                    if ($result !== $title) {
                        return $result;
                    }
                }
            }
            $seriesEscaped = preg_quote($seriesName, '/');

            // Handle patterns like "Title - Series, Book N", "Title: Series #N", "Title (Series)"
            // This is "Greedy" - if we find the series name after a separator, we assume everything
            // from there to the end is series metadata.
            $patterns = [
                '/\s*[\-:]\s*' . $seriesEscaped . '.*$/i',
                '/\s*\(' . $seriesEscaped . '.*\)$/i',
                '/\s*\[' . $seriesEscaped . '.*\]$/i',
            ];

            foreach ($patterns as $pattern) {
                $cleaned = preg_replace($pattern, '', $title);
                if ($cleaned !== $title) {
                    return trim($cleaned, ' ,-');
                }
            }

            // Remove series name from the start of the title (with any separator or just whitespace)
            $startPatterns = [
                '/^' . $seriesEscaped . '\s*[\-:]\s*/i',
                '/^' . $seriesEscaped . '\s*\[\d+\]\s*/i',
                '/^' . $seriesEscaped . '\s*\(\d+\)\s*/i',
                '/^' . $seriesEscaped . '\s+\d+\s*[\-:]\s*/i',
                '/^' . $seriesEscaped . '\s+/i',
            ];
            foreach ($startPatterns as $pattern) {
                $cleaned = preg_replace($pattern, '', $title);
                if ($cleaned !== $title) {
                    $trimmed = trim($cleaned, ' ,-');
                    // If stripping leaves only numbers, the series name is part of the real title
                    if (preg_match('/^\d+$/', $trimmed)) {
                        return $title;
                    }
                    return $trimmed;
                }
            }

            // Remove series name from the end of the title
            $endPatterns = [
                '/\s*,\s*Book\s+\d+\s*$/i',
            ];
            // First strip trailing ", Book N" then check for series at end
            $strippedTitle = $title;
            foreach ($endPatterns as $pattern) {
                $strippedTitle = preg_replace($pattern, '', $strippedTitle);
            }
            $trailingPattern = '/\s*[\-,]\s*' . $seriesEscaped . '$/i';
            $cleaned = preg_replace($trailingPattern, '', $strippedTitle);
            if ($cleaned !== $title) {
                return trim($cleaned, ' ,-');
            }
        }

        // 2. Existing colon logic for "Series: Title" or "Title: Series"
        if (preg_match('/^([^:]+):\s*(.+)$/', $title, $matches)) {
            $beforeColon = trim($matches[1]);
            $afterColon = trim($matches[2]);

            if (preg_match('/^\b(book|vol|volume|part|chapter)\s*\d+/i', $afterColon)) {
                return $beforeColon;
            }

            return (strlen($afterColon) >= strlen($beforeColon)) ? $afterColon : $beforeColon;
        }

        // 3. Hyphen logic for "Title - Series" or "Title - Metadata"
        if (preg_match('/^(.+?)\s+-\s+([^:-]+)$/', $title, $matches)) {
            $beforeHyphen = trim($matches[1]);
            $afterHyphen = trim($matches[2]);

            // If the part after looks like metadata or series info, keep the first part
            if (
                preg_match('/\b(series|book|vol|volume|\d+|saga|chronicles|collection|trilogy)\b/i', $afterHyphen) ||
                !str_contains($afterHyphen, ' ') // Single word often indicates series name
            ) {
                return $beforeHyphen;
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
    public function getDirectoryInfoFromFiles(array $filePaths): array
    {
        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];
        $files = [];
        $totalSize = 0;
        $fileTypes = [];

        foreach ($filePaths as $filePath) {
            if (!is_file($filePath)) {
                continue;
            }
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if (!in_array($extension, $audioExtensions)) {
                continue;
            }
            $size = filesize($filePath);
            $filename = basename($filePath);
            $files[] = [
                'name' => $filename,
                'size' => $size,
                'extension' => $extension,
                'hash' => md5($filename . $size),
            ];
            $totalSize += $size;
            $fileTypes[$extension] = ($fileTypes[$extension] ?? 0) + 1;
        }

        return [
            'files' => $files,
            'total_size' => $totalSize,
            'file_types' => $fileTypes,
            'count' => count($files),
        ];
    }

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
    /**
     * Strip part-number and production-marker suffixes from a directory/title string.
     *
     * Handles patterns like:
     *   "01 The Warded Man (2 of 2) [Dramatized Adaptation] - Peter V. Brett"
     *   "05.6 Butter Cookies and Demon Claws [Graphic Audio] - Peter V. Brett"
     *   "05.6 Butter Cookies and Demon Claws (GraphicAudio) - Peter V. Brett"
     *
     * Returns ['title' => cleaned string, 'is_multi_part' => bool].
     */
    public function stripPartAndProductionMarkers(string $name): array
    {
        $isMultiPart = false;

        // Strip " (N of M)" part-number markers — must come before author stripping
        if (preg_match('/\s*\(\s*\d+\s+of\s+\d+\s*\)/i', $name)) {
            $isMultiPart = true;
            $name = preg_replace('/\s*\(\s*\d+\s+of\s+\d+\s*\)/i', '', $name);
        }

        // Strip production markers: [Dramatized Adaptation], [Graphic Audio], (GraphicAudio), etc.
        $name = preg_replace('/\s*[\[\(]\s*Dramatized\s+Adaptation\s*[\]\)]/i', '', $name);
        $name = preg_replace('/\s*[\[\(]\s*Graphic\s*Audio\s*[\]\)]/i', '', $name);

        return ['title' => trim($name), 'is_multi_part' => $isMultiPart];
    }

    public function postProcessAIResult(array $aiResult, array $audiobook): array
    {
        $directoryName = basename($audiobook['path']);

        // Strip part-number markers (e.g. "(2 of 2)") and production markers from the
        // directory name before it is used for title/series inference. We do this on the
        // raw directory name so all downstream regex sees clean text.
        $stripped = $this->stripPartAndProductionMarkers($directoryName);
        $directoryName = $stripped['title'];
        if ($stripped['is_multi_part']) {
            $aiResult['is_multi_part'] = true;
        }

        if (
            preg_match('/^(\d{1,2})\s*-\s*(.+)$/', $directoryName, $matches) ||
            preg_match('/^(\d{1,2})\s+(.+)$/', $directoryName, $matches)
        ) {
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
            // Strip part-number / production markers that the AI may have echoed from the directory name
            $strippedTitle = $this->stripPartAndProductionMarkers($aiResult['title']);
            if ($strippedTitle['title'] !== $aiResult['title']) {
                $aiResult['title'] = $strippedTitle['title'];
                if ($strippedTitle['is_multi_part']) {
                    $aiResult['is_multi_part'] = true;
                }
            }

            $originalTitle = $aiResult['title'];
            $seriesName = $aiResult['series'] ?? null;
            $cleanedTitle = $this->removeSeriesFromTitle($originalTitle, $seriesName);

            if ($cleanedTitle !== $originalTitle) {
                $aiResult['title'] = $cleanedTitle;
            }

            $aiResult['title'] = $this->removeExtensionFromTitle($aiResult['title']);
        }

        if (!empty($aiResult['series'])) {
            $aiResult['series'] = $this->removeAuthorFromSeries($aiResult['series'], $aiResult['author'] ?? []);
        }

        // Map AI genre to valid library genre
        if (!empty($aiResult['genre'])) {
            $genre = is_array($aiResult['genre']) ? ($aiResult['genre'][0] ?? '') : $aiResult['genre'];
            if (!empty($genre)) {
                $aiResult['original_genre'] = $genre;
                $aiResult['genre'] = $this->genreMappingService->mapToPrimaryGenre($genre);
            }
        }

        // 1. Extract Year from Title or Directory Name
        $yearSource = $aiResult['title'] ?? $directoryName;
        $extractedYear = null;
        $yearPattern = null;

        // Check for (YYYY-YYYY) or [YYYY-YYYY]
        if (preg_match('/[\[\(](\d{4})-(\d{4})[\]\)]/', $yearSource, $matches)) {
            $extractedYear = (int) $matches[1];
            $yearPattern = '/[\[\(]' . preg_quote($matches[1] . '-' . $matches[2]) . '[\]\)]/';
        }
        // Check for trailing " - YYYY-YYYY"
        elseif (preg_match('/[\s\-_]+(\d{4})-(\d{4})$/', $yearSource, $matches)) {
            $extractedYear = (int) $matches[1];
            $yearPattern = '/[\s\-_]+' . preg_quote($matches[1] . '-' . $matches[2]) . '$/';
        }
        // Check for (YYYY) or [YYYY]
        elseif (preg_match('/[\[\(](\d{4})[\]\)]/', $yearSource, $matches)) {
            $extractedYear = (int) $matches[1];
            $yearPattern = '/[\[\(]' . $extractedYear . '[\]\)]/';
        }
        // Check for trailing " - YYYY" or " YYYY"
        elseif (preg_match('/[\s\-_]+(\d{4})$/', $yearSource, $matches)) {
            $extractedYear = (int) $matches[1];
            $yearPattern = '/[\s\-_]+' . $extractedYear . '$/';
        }

        if ($extractedYear && $extractedYear > 1900 && $extractedYear <= (int) date('Y') + 2) {
            $aiResult['year'] = $extractedYear;
            // Clean year from title if it was found there
            if (!empty($aiResult['title'])) {
                $aiResult['title'] = trim(preg_replace($yearPattern, '', $aiResult['title']));
                // Clean up any double separators left behind
                $aiResult['title'] = trim($aiResult['title'], " \t\n\r\0\x0B-_");
            }
        }

        // 2. Extract Series from Parent Directory if missing or identical to title
        $currentSeries = $aiResult['series'] ?? '';
        $currentTitle = $aiResult['title'] ?? '';

        // Normalize for comparison: remove numbers, brackets, years to check if Series is just the Title
        $normSeries = trim(preg_replace('/[#\d\(\)\[\]]|\b\d{4}\b/', '', $currentSeries));
        $normTitle = trim(preg_replace('/[#\d\(\)\[\]]|\b\d{4}\b/', '', $currentTitle));

        if ((empty($currentSeries) || strcasecmp($normSeries, $normTitle) === 0) && !empty($audiobook['path'])) {
            $parentPath = dirname($audiobook['path']);
            // Only use parent if it's not the root import directory
            // (Naive check: assuming we are at least 1 level deep from import root)
            $nonSeriesDirs = ['download', 'downloads', 'audiobooks', 'audiobook', 'unsorted', 'books', 'media'];
            if (!in_array(strtolower(basename($parentPath)), $nonSeriesDirs, true)) {
                $parentName = basename($parentPath);

                // Clean parent name: Remove Year, Author, Narrator
                $seriesName = $parentName;

                // Remove Year range (YYYY-YYYY) or single Year (YYYY)
                $seriesName = preg_replace('/[\(\[]?\d{4}(?:-\d{4})?[\)\]]?/', '', $seriesName);

                // Remove Author (if known)
                if (!empty($aiResult['author'])) {
                    $seriesName = $this->removeAuthorFromSeries($seriesName, $aiResult['author']);
                }

                // Remove Narrator (e.g. "(Narrator Name)")
                $seriesName = preg_replace('/\([^\)]+\)/', '', $seriesName);

                $seriesName = trim($seriesName, " \t\n\r\0\x0B-_");

                if (!empty($seriesName) && !is_numeric($seriesName)) {
                    $aiResult['series'] = $seriesName;
                    // If we found a better series name, verify title doesn't still have it
                    if (!empty($aiResult['title'])) {
                        $aiResult['title'] = $this->removeSeriesFromTitle($aiResult['title'], $seriesName);
                    }
                }
            }
        }

        // Final cleanup: Remove leading numbers/track numbers from title if they persist
        // e.g. "03 Lord Sorcerer" -> "Lord Sorcerer"
        if (!empty($aiResult['title']) && preg_match('/^(\d{1,3})[\s\-_]+(.+)$/', $aiResult['title'], $matches)) {
            // Keep if it looks like a year (e.g. 1984, 2001) - naive check > 1900
            $possibleNumber = (int) $matches[1];
            if ($possibleNumber < 1900) {
                $aiResult['title'] = trim($matches[2]);
            }
        }

        // Clean title: Remove (unabridged) and (Author Name)
        if (!empty($aiResult['title'])) {
            // Remove (unabridged) - case insensitive
            $aiResult['title'] = str_ireplace(['(unabridged)', '(abridged)'], '', $aiResult['title']);

            // Remove (Author Name) if present
            if (!empty($aiResult['author'])) {
                $authors = is_array($aiResult['author']) ? $aiResult['author'] : [$aiResult['author']];
                foreach ($authors as $author) {
                    if (empty($author)) {
                        continue;
                    }
                    // Pattern: (Author)
                    $aiResult['title'] = str_ireplace("({$author})", '', $aiResult['title']);
                    // Pattern: - Author
                    $aiResult['title'] = str_ireplace(" - {$author}", '', $aiResult['title']);
                }
            }

            $aiResult['title'] = trim($aiResult['title']);
        }

        // Discard purely numeric series names (e.g. "11") — not valid series
        if (!empty($aiResult['series']) && is_numeric($aiResult['series'])) {
            unset($aiResult['series']);
        }

        return $aiResult;
    }

    /**
     * Remove file extensions from title
     */
    protected function removeExtensionFromTitle(string $title): string
    {
        $audioExtensions = ['m4b', 'mp3', 'm4a', 'flac', 'ogg', 'oga', 'wav', 'wma', 'aac', 'opus'];

        foreach ($audioExtensions as $ext) {
            if (preg_match('/^(.+)\.' . preg_quote($ext, '/') . '$/i', $title, $matches)) {
                return trim($matches[1]);
            }
        }

        return $title;
    }

    /**
     * Remove author name from series name
     */
    protected function removeAuthorFromSeries(string $series, array|string $authors): string
    {
        if (is_string($authors)) {
            $authors = [$authors];
        }

        if (empty($authors)) {
            return $series;
        }

        // If series exactly matches any author, return empty string
        $seriesNormalized = strtolower(trim($series));
        foreach ($authors as $author) {
            if (strtolower(trim($author)) === $seriesNormalized) {
                return '';
            }
        }

        foreach ($authors as $author) {
            if (empty($author)) {
                continue;
            }

            $patterns = [
                '/^' . preg_quote($author, '/') . '\s*-\s*(.+)$/i',
                '/^(.+)\s*-\s*' . preg_quote($author, '/') . '$/i',
                '/^' . preg_quote($author, '/') . '\s*:\s*(.+)$/i',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $series, $matches)) {
                    return trim($matches[1]);
                }
            }
        }

        return $series;
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

                if ($endNumber > $startNumber && ($endNumber - $startNumber) <= 200 && $startNumber < 1900) {
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
            $matched = false;

            // Use word boundaries to match book numbers precisely
            // Matches: "[04]", "Book 04", "Book 4", " 04-", " 04 ", " 4-", " 4 ", etc.
            foreach ($numbers as $number) {
                $patterns = [
                    '/\[0*' . $number . '\]/',           // [04], [4]
                    '/\b0*' . $number . '\b/',           // word boundary (4, 04 as whole number)
                    '/[\s\-_]0*' . $number . '[\s\-_\.]/', // surrounded by separators
                ];

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $filename)) {
                        if (!isset($splitGroups[$number])) {
                            $splitGroups[$number] = [];
                        }
                        $splitGroups[$number][] = [
                            'file' => $filePath,
                            'title' => $title,
                        ];
                        $matched = true;

                        Log::debug("Multi-book file matched", [
                            'filename' => $filename,
                            'matched_number' => $number,
                            'pattern' => $pattern,
                        ]);
                        break 2; // Break out of both pattern and number loops
                    }
                }
            }

            if (!$matched) {
                if (!isset($splitGroups['unmatched'])) {
                    $splitGroups['unmatched'] = [];
                }
                $splitGroups['unmatched'][] = [
                    'file' => $filePath,
                    'title' => $title,
                ];
                Log::warning("Multi-book file unmatched", [
                    'filename' => $filename,
                ]);
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
            config('filesystems.disks.books.root'),
            config('app.book_root'),
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
    public function getValidGenres(): array
    {
        return [
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
    }

    protected function validateAndMapGenre(string $genreName): string
    {
        $validGenres = $this->getValidGenres();

        // If already a valid genre, return as-is
        if (in_array($genreName, $validGenres)) {
            return $genreName;
        }

        // Map to valid primary genre using GenreMappingService
        return $this->genreMappingService->mapToPrimaryGenre($genreName);
    }

    /**
     * Initialize persistent cache system
     */
    public function initializeCache(
        bool $noCacheOption,
        bool $clearCacheOption,
        callable $infoCallback,
        callable $loadCacheCallback,
        callable $cleanupCacheCallback
    ): array {
        // Check if caching is disabled
        if ($noCacheOption) {
            $infoCallback("📦 Background processing cache disabled");
            return [];
        }

        // Set up cache directory
        $cacheDirectory = storage_path('app/audiobook-cache');
        $cacheFilePath = $cacheDirectory . '/background-processing-cache.json';

        // Create cache directory if it doesn't exist
        if (!File::isDirectory($cacheDirectory)) {
            File::makeDirectory($cacheDirectory, 0755, true);
        }

        // Clear cache if requested
        if ($clearCacheOption) {
            if (file_exists($cacheFilePath)) {
                unlink($cacheFilePath);
                $infoCallback("🗑️  Background processing cache cleared");
            }
            return [];
        }

        // Load existing cache
        $backgroundCache = $loadCacheCallback();

        // Clean up old/invalid cache entries
        $cleanupCacheCallback($backgroundCache);

        $cacheSize = count($backgroundCache);
        if ($cacheSize > 0) {
            $infoCallback("📦 Loaded {$cacheSize} cached background processing results");
        }

        return $backgroundCache;
    }

    /**
     * Get cache key for an audiobook
     */
    public function getCacheKey(array $audiobook): string
    {
        return md5($audiobook['path'] . '|' . ($audiobook['total_size'] ?? 0));
    }

    /**
     * Load cache from disk
     */
    public function loadCache(
        bool $cacheEnabled,
        ?string $cacheFilePath,
        int $cacheVersion,
        ?callable $infoCallback = null,
        ?callable $warnCallback = null
    ): array {
        if (!$cacheEnabled || !$cacheFilePath || !file_exists($cacheFilePath)) {
            return [];
        }

        try {
            $cacheData = json_decode(file_get_contents($cacheFilePath), true);

            if (!$cacheData || !is_array($cacheData)) {
                return [];
            }

            // Check cache version compatibility
            if (($cacheData['version'] ?? 1) !== $cacheVersion) {
                if ($infoCallback) {
                    $infoCallback("📦 Cache version mismatch - rebuilding cache");
                }
                return [];
            }

            return $cacheData['data'] ?? [];
        } catch (\Exception $e) {
            if ($warnCallback) {
                $warnCallback("⚠️  Failed to load cache: " . $e->getMessage());
            }
            return [];
        }
    }

    /**
     * Save cache to disk
     */
    public function saveCache(
        array $backgroundCache,
        bool $cacheEnabled,
        ?string $cacheFilePath,
        int $cacheVersion
    ): void {
        if (!$cacheEnabled || !$cacheFilePath) {
            return;
        }

        try {
            $cacheData = [
                'version' => $cacheVersion,
                'last_updated' => time(),
                'data' => $backgroundCache,
            ];

            file_put_contents($cacheFilePath, json_encode($cacheData, JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            // Silently fail on save errors
        }
    }

    /**
     * Clean up old or invalid cache entries
     */
    public function cleanupCache(
        array &$backgroundCache,
        callable $getDirectoryModificationTimeCallback,
        ?callable $infoCallback = null
    ): int {
        $cleaned = 0;
        $maxAge = 86400 * 7; // 7 days
        $currentTime = time();

        foreach ($backgroundCache as $cacheKey => $cacheEntry) {
            // Remove entries older than max age
            if (isset($cacheEntry['timestamp']) && ($currentTime - $cacheEntry['timestamp']) > $maxAge) {
                unset($backgroundCache[$cacheKey]);
                $cleaned++;
                continue;
            }

            // Remove entries for directories that no longer exist
            if (isset($cacheEntry['path']) && !is_dir($cacheEntry['path'])) {
                unset($backgroundCache[$cacheKey]);
                $cleaned++;
                continue;
            }

            // Remove entries where files have been modified
            if (isset($cacheEntry['path']) && isset($cacheEntry['directory_mtime'])) {
                $currentMtime = $getDirectoryModificationTimeCallback($cacheEntry['path']);
                if ($currentMtime > $cacheEntry['directory_mtime']) {
                    unset($backgroundCache[$cacheKey]);
                    $cleaned++;
                    continue;
                }
            }
        }

        if ($cleaned > 0 && $infoCallback) {
            $infoCallback("🧹 Cleaned {$cleaned} stale cache entries");
        }

        return $cleaned;
    }

    /**
     * Preprocess metadata in background (enhanced)
     */
    public function preprocessMetadataInBackground(
        array $audiobook,
        callable $extractBasicMetadataCallback,
        callable $hasCdDirectoriesCallback,
        callable $analyzeFileTypesCallback,
        callable $analyzeDirectoryNameCallback,
        callable $isMultiBookDirectoryCallback,
        callable $findCoverImageCallback
    ): array {
        // Start comprehensive metadata extraction
        $metadata = $extractBasicMetadataCallback($audiobook);

        // Pre-analyze directory structure
        $directoryAnalysis = [
            'has_subdirectories' => !empty(File::directories($audiobook['path'])),
            'cd_directories' => $hasCdDirectoriesCallback($audiobook['path']),
            'file_types' => $analyzeFileTypesCallback($audiobook['files']),
            'total_size' => array_sum(array_map('filesize', $audiobook['files'])),
            'directory_depth' => substr_count($audiobook['path'], '/'),
        ];

        // Pre-extract basic info from directory name
        $directoryInfo = $analyzeDirectoryNameCallback(basename($audiobook['path']));

        // Check for special markers
        $specialMarkers = [
            'multi_book' => $isMultiBookDirectoryCallback($audiobook['path']),
            'graphic_audio' => str_contains(strtolower($audiobook['path']), 'graphic'),
            'series_book' => preg_match('/\d+/', basename($audiobook['path'])),
        ];

        return [
            'basic_metadata' => $metadata,
            'directory_analysis' => $directoryAnalysis,
            'directory_info' => $directoryInfo,
            'special_markers' => $specialMarkers,
            'audio_files_counted' => count($audiobook['files']),
            'cover_image_found' => $findCoverImageCallback($audiobook['path']) !== null,
            'ready_for_processing' => true,
            'timestamp' => time(),
        ];
    }

    /**
     * Scan directory structure in background
     */
    public function scanDirectoryInBackground(array $data): array
    {
        $path = $data['path'];

        return [
            'directory_structure' => [
                'subdirectories' => File::directories($path),
                'file_count' => count(File::allFiles($path)),
                'total_size' => $this->getDirectorySize($path),
            ],
            'timestamp' => time(),
        ];
    }

    /**
     * Check for duplicates in background
     */
    public function checkDuplicatesInBackground(array $audiobook, callable $findSimilarBooksCallback): array
    {
        return [
            'existing_books' => $findSimilarBooksCallback($audiobook),
            'timestamp' => time(),
        ];
    }

    /**
     * Extract detailed metadata in background
     */
    public function extractMetadataInBackground(
        array $audiobook,
        callable $extractTagMetadataCallback,
        callable $extractNfoDataCallback
    ): array {
        $metadata = [];

        // Extract file tags
        $metadata['file_tags'] = $extractTagMetadataCallback($audiobook);

        // Extract NFO data if available
        $nfoData = $extractNfoDataCallback($audiobook['path']);
        if ($nfoData) {
            $metadata['nfo_data'] = $nfoData;
        }

        return [
            'metadata' => $metadata,
            'timestamp' => time(),
        ];
    }

    /**
     * Analyze audio files in background
     */
    public function analyzeAudioFilesInBackground(array $audiobook): array
    {
        $audioFiles = [];
        $totalSize = 0;

        foreach ($audiobook['files'] as $file) {
            if (file_exists($file)) {
                $size = filesize($file);
                $audioFiles[] = [
                    'path' => $file,
                    'size' => $size,
                ];
                $totalSize += $size;
            }
        }

        $largestFile = null;
        $smallestFile = null;

        foreach ($audioFiles as $file) {
            if ($largestFile === null || $file['size'] > $largestFile['size']) {
                $largestFile = $file;
            }
            if ($smallestFile === null || $file['size'] < $smallestFile['size']) {
                $smallestFile = $file;
            }
        }

        $averageFileSize = count($audioFiles) > 0 ? $totalSize / count($audioFiles) : 0;

        return [
            'total_files' => count($audioFiles),
            'audio_files' => count($audioFiles),
            'largest_file' => $largestFile,
            'smallest_file' => $smallestFile,
            'total_size' => $totalSize,
            'average_file_size' => $averageFileSize,
        ];
    }

    /**
     * Prepare cover image in background
     */
    public function prepareCoverImageInBackground(array $audiobook, callable $findCoverImageCallback): array
    {
        $result = [
            'has_cover' => false,
            'cover_path' => null,
            'cover_type' => null,
        ];

        $coverPath = $findCoverImageCallback($audiobook['path']);
        if ($coverPath) {
            $result['has_cover'] = true;
            $result['cover_path'] = $coverPath;
            $result['cover_type'] = pathinfo($coverPath, PATHINFO_EXTENSION);
        }

        return [
            'result' => $result,
            'timestamp' => time(),
        ];
    }

    /**
     * Execute a specific background task (with caching)
     */
    public function executeBackgroundTask(
        array $task,
        callable $getCachedResultCallback,
        callable $executeBackgroundTaskInternalCallback,
        callable $setCachedResultCallback
    ): array {
        $audiobook = $task['data'];
        $taskType = $task['type'];

        // Check cache first
        $cachedResult = $getCachedResultCallback($audiobook, $taskType);
        if ($cachedResult !== null) {
            return array_merge($cachedResult, ['from_cache' => true]);
        }

        // Execute task if not cached
        $result = $executeBackgroundTaskInternalCallback($taskType, $audiobook);

        // Store result in cache
        $setCachedResultCallback($audiobook, $taskType, $result);

        return array_merge($result, ['from_cache' => false]);
    }

    /**
     * Internal task execution without caching
     */
    public function executeBackgroundTaskInternal(
        string $taskType,
        array $audiobook,
        callable $preprocessMetadataCallback,
        callable $scanDirectoryCallback,
        callable $checkDuplicatesCallback,
        callable $extractMetadataCallback,
        callable $analyzeAudioFilesCallback,
        callable $prepareCoverImageCallback
    ): array {
        switch ($taskType) {
            case 'preprocess_metadata':
                return $preprocessMetadataCallback($audiobook);
            case 'scan_directory':
                return $scanDirectoryCallback($audiobook);
            case 'duplicate_check':
                return $checkDuplicatesCallback($audiobook);
            case 'extract_metadata':
                return $extractMetadataCallback($audiobook);
            case 'analyze_audio_files':
                return $analyzeAudioFilesCallback($audiobook);
            case 'prepare_cover_image':
                return $prepareCoverImageCallback($audiobook);
            default:
                throw new \Exception("Unknown task type: {$taskType}");
        }
    }

    /**
     * Schedule a background task
     */
    public function scheduleBackgroundTask(string $type, array $data, array &$backgroundTasks): void
    {
        $taskId = md5(serialize($data));

        if (!isset($backgroundTasks[$taskId])) {
            $backgroundTasks[$taskId] = [
                'type' => $type,
                'data' => $data,
                'status' => 'pending',
                'result' => null,
            ];
        }
    }

    /**
     * Start continuous background processing to maintain at least 3 running tasks
     */
    public function startContinuousBackgroundProcessing(
        callable $processBackgroundTasksCallback
    ): void {
        // Process background tasks multiple times to ensure continuous operation
        for ($i = 0; $i < 5; $i++) {
            $processBackgroundTasksCallback();

            // Small delay to simulate processing time
            usleep(50000); // 50ms
        }
    }

    /**
     * Enhanced ask method with background processing and quit handling
     */
    public function askWithBackground(
        string $question,
        ?string $default,
        array $backgroundData,
        callable $queueBackgroundTaskCallback,
        callable $startContinuousBackgroundProcessingCallback,
        callable $askWithImmediateInterruptCallback,
        callable $handleUserQuitCallback
    ): string {
        // Start background processing if data provided
        if (!empty($backgroundData)) {
            foreach ($backgroundData as $task) {
                $queueBackgroundTaskCallback($task['type'], $task['data'], 'high');
            }
        }

        // Continuously process background tasks while waiting for user input
        $startContinuousBackgroundProcessingCallback();

        $response = $askWithImmediateInterruptCallback($question, $default);

        // Handle quit request or interruption
        if (strtolower(trim($response)) === 'q') {
            $handleUserQuitCallback();
        }

        return $response;
    }

    /**
     * Process pending background tasks (enhanced with concurrent task management)
     */
    public function processBackgroundTasks(
        array &$backgroundTasks,
        callable $maintainConcurrentTasksCallback,
        callable $executeBackgroundTaskCallback,
        int &$runningTaskCount
    ): void {
        // Maintain at least 3 concurrent background tasks
        $runningTaskCount = $maintainConcurrentTasksCallback();

        // Process currently running tasks
        foreach ($backgroundTasks as $taskId => &$task) {
            if ($task['status'] === 'processing') {
                // Check if task should be completed (simulated async processing)
                if (!isset($task['start_time'])) {
                    $task['start_time'] = microtime(true);
                }

                // Simulate processing time (remove this in real async implementation)
                $processingTime = microtime(true) - $task['start_time'];
                if ($processingTime > 0.1) { // 100ms simulation
                    try {
                        $task['result'] = $executeBackgroundTaskCallback($task);
                        $task['status'] = 'completed';
                        $task['end_time'] = microtime(true);
                        $runningTaskCount--;
                    } catch (\Exception $e) {
                        $task['status'] = 'failed';
                        $task['error'] = $e->getMessage();
                        $task['end_time'] = microtime(true);
                        $runningTaskCount--;
                    }
                }
            }
        }
    }

    /**
     * Start background processing tasks while waiting for user input (enhanced)
     */
    public function startBackgroundProcessing(
        array $audiobooks,
        int $currentIndex,
        callable $queueBackgroundTaskCallback,
        callable $processBackgroundTasksCallback,
        callable $showEnhancedBackgroundStatusCallback
    ): void {
        // Process more books ahead (increased to 7 for deeper queue)
        $lookaheadCount = min(7, count($audiobooks) - $currentIndex - 1);

        for ($i = $currentIndex + 1; $i <= $currentIndex + $lookaheadCount; $i++) {
            if (isset($audiobooks[$i])) {
                $audiobook = $audiobooks[$i];
                $distance = $i - $currentIndex;

                // Prioritize tasks for closer books
                $priority = $distance <= 2 ? 'high' : 'normal';

                // Queue multiple task types for each upcoming book
                $queueBackgroundTaskCallback('preprocess_metadata', $audiobook, $priority);
                $queueBackgroundTaskCallback('scan_directory', $audiobook, $priority);
                $queueBackgroundTaskCallback('duplicate_check', $audiobook, $priority);
                $queueBackgroundTaskCallback('extract_metadata', $audiobook, $priority);
                $queueBackgroundTaskCallback('analyze_audio_files', $audiobook, $priority);
                $queueBackgroundTaskCallback('prepare_cover_image', $audiobook, $priority);
            }
        }

        // Continuously process background tasks to maintain 3+ concurrent operations
        $processBackgroundTasksCallback();

        // Show enhanced background processing status
        $showEnhancedBackgroundStatusCallback();
    }

    /**
     * Maintain at least 3 concurrent background tasks
     */
    public function maintainConcurrentTasks(
        array $backgroundTasks,
        array $taskQueue,
        int $maxConcurrentTasks,
        callable $startBackgroundTaskCallback
    ): int {
        // Count currently running tasks
        $runningTasks = 0;
        foreach ($backgroundTasks as $task) {
            if ($task['status'] === 'processing') {
                $runningTasks++;
            }
        }

        // Start new tasks to maintain minimum concurrent count
        while ($runningTasks < $maxConcurrentTasks && !empty($taskQueue)) {
            $nextTask = array_shift($taskQueue);
            $startBackgroundTaskCallback($nextTask);
            $runningTasks++;
        }

        return $runningTasks;
    }

    /**
     * Start a background task immediately
     */
    public function startBackgroundTask(array $taskInfo, array &$backgroundTasks): string
    {
        $taskId = md5(serialize($taskInfo));

        if (!isset($backgroundTasks[$taskId])) {
            $backgroundTasks[$taskId] = [
                'type' => $taskInfo['type'],
                'data' => $taskInfo['data'],
                'status' => 'processing',
                'result' => null,
                'start_time' => microtime(true),
                'priority' => $taskInfo['priority'] ?? 'normal'
            ];
        }

        return $taskId;
    }

    /**
     * Get result from background task if available
     */
    public function getBackgroundResult(array $backgroundTasks, string $taskId): ?array
    {
        if (isset($backgroundTasks[$taskId]) && $backgroundTasks[$taskId]['status'] === 'completed') {
            return $backgroundTasks[$taskId]['result'];
        }
        return null;
    }

    /**
     * Start queued tasks if we have capacity
     */
    public function startQueuedTasks(
        array $taskQueue,
        int $runningTaskCount,
        int $maxConcurrentTasks,
        callable $startBackgroundTaskCallback
    ): void {
        while ($runningTaskCount < $maxConcurrentTasks && !empty($taskQueue)) {
            $nextTask = array_shift($taskQueue);
            $startBackgroundTaskCallback($nextTask);
            $runningTaskCount++;
        }
    }

    /**
     * Queue a background task with priority
     */
    public function queueBackgroundTask(string $type, array $data, array &$taskQueue, string $priority = 'normal'): void
    {
        $taskInfo = [
            'type' => $type,
            'data' => $data,
            'priority' => $priority,
        ];

        // Insert based on priority (high priority tasks go first)
        if ($priority === 'high') {
            array_unshift($taskQueue, $taskInfo);
        } else {
            array_push($taskQueue, $taskInfo);
        }
    }

    /**
     * Store result in cache
     */
    public function setCachedResult(
        array $backgroundCache,
        array $audiobook,
        string $taskType,
        array $result,
        bool $cacheEnabled,
        callable $getCacheKeyCallback,
        callable $getDirectoryModificationTimeCallback
    ): void {
        if (!$cacheEnabled) {
            return;
        }

        $cacheKey = $getCacheKeyCallback($audiobook);
        $fullKey = $cacheKey . '_' . $taskType;

        $backgroundCache[$fullKey] = [
            'path' => $audiobook['path'],
            'task_type' => $taskType,
            'result' => $result,
            'timestamp' => time(),
            'directory_mtime' => $getDirectoryModificationTimeCallback($audiobook['path']),
        ];
    }

    /**
     * Get cached result for a background task
     */
    public function getCachedResult(array $backgroundCache, array $audiobook, string $taskType, bool $cacheEnabled): ?array
    {
        if (!$cacheEnabled) {
            return null;
        }

        $cacheKey = $this->getCacheKey($audiobook);
        $fullKey = $cacheKey . '_' . $taskType;

        if (isset($backgroundCache[$fullKey])) {
            return $backgroundCache[$fullKey];
        }

        return null;
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
    public function showBackgroundProcessingStatus(array $backgroundTasks, ?callable $lineCallback = null): string
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
            if ($lineCallback) {
                $lineCallback($status);
            }
            return $status;
        }

        return '';
    }

    /**
     * Show enhanced background processing status
     */
    public function showEnhancedBackgroundStatus(array $backgroundTasks, int $taskQueueCount, ?callable $lineCallback = null): string
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

            $status = "🔄 Background: " . implode(', ', $parts);
            if ($lineCallback) {
                $lineCallback($status);
            }
            return $status;
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
    public function displaySummary(
        int $totalFound,
        array $processedBooks,
        array $failedBooks,
        array $skippedBooks,
        callable $infoCallback,
        callable $warnCallback,
        callable $lineCallback,
        callable $getTotalCostCallback,
        ?callable $tableCallback = null
    ): void {
        if ($tableCallback) {
            $tableCallback(
                ['Metric', 'Count'],
                [
                    ['Total Found', $totalFound],
                    ['Successfully Imported', count($processedBooks)],
                    ['Failed', count($failedBooks)],
                    ['Skipped', count($skippedBooks)],
                ]
            );
        }

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
     * Display partial summary when quitting mid-process
     */
    public function displayPartialSummary(
        int $totalFound,
        array $processedBooks,
        array $failedBooks,
        array $skippedBooks,
        callable $newLineCallback,
        callable $infoCallback,
        callable $warnCallback,
        callable $lineCallback
    ): void {
        $newLineCallback();
        $infoCallback("📊 Partial Import Summary (before quit):");

        $processed = count($processedBooks);
        $failed = count($failedBooks);
        $skipped = count($skippedBooks);

        if ($processed > 0) {
            $infoCallback("✅ Successfully processed: {$processed} books");
        }

        if ($failed > 0) {
            $warnCallback("❌ Failed: {$failed} books");
            foreach ($failedBooks as $book) {
                $lineCallback("  • " . basename($book['path']) . " - " . $book['error']);
            }
        }

        if ($skipped > 0) {
            $infoCallback("⏭️  Skipped: {$skipped} books");
        }

        $total = $processed + $failed + $skipped;
        if ($totalFound > $total) {
            $remaining = $totalFound - $total;
            $lineCallback("⏸️  Not processed: {$remaining} books");
        }
    }

    /**
     * Display image using Kitty graphics protocol or kitten icat
     */
    public function displayKittyImage(string $imageData, callable $lineCallback, callable $systemCallback): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'cover_') . '.png';

        try {
            $tempOriginal = tempnam(sys_get_temp_dir(), 'orig_') . '.jpg';
            file_put_contents($tempOriginal, $imageData);

            $imageInfo = getimagesize($tempOriginal);
            if (!$imageInfo) {
                $lineCallback("  (Could not read image dimensions)");
                return;
            }
            $width = $imageInfo[0];
            $height = $imageInfo[1];

            $maxWidth = 200;
            $scale = min($maxWidth / $width, $maxWidth / $height);
            $thumbWidth = (int) ($width * $scale);
            $thumbHeight = (int) ($height * $scale);

            $thumb = $this->createThumbnail($tempOriginal, $thumbWidth, $thumbHeight);
            if ($thumb) {
                imagepng($thumb, $tempFile);
                imagedestroy($thumb);

                if (file_exists('/usr/bin/kitten') && is_executable('/usr/bin/kitten')) {
                    $systemCallback("kitten icat --align=left '$tempFile' 2>/dev/null");
                } else {
                    $base64Image = base64_encode(file_get_contents($tempFile));
                    fwrite(STDOUT, "\033_Ga=T,f=100;{$base64Image}\033\\");
                    echo "\n";
                }
            } else {
                $lineCallback("  (Could not create thumbnail)");
            }

            @unlink($tempOriginal);
        } catch (\Exception $e) {
            $lineCallback("  (Image display error: " . $e->getMessage() . ")");
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Display cover image if terminal supports it
     */
    public function displayCoverImage(string $imageUrl, callable $lineCallback, callable $displayKittyImageCallback): void
    {
        $term = getenv('TERM_PROGRAM') ?: getenv('TERM');
        $termEnv = getenv('TERM') ?? '';
        $termProgram = getenv('TERM_PROGRAM') ?? '';

        $kittySupport = $termEnv === 'xterm-kitty' ||
            $termEnv === 'xterm-ghostty' ||
            strpos($termEnv, 'kitty') !== false ||
            $termProgram === 'ghostty';

        if ($kittySupport || in_array($term, ['Ghostty', 'iTerm.app', 'WezTerm'])) {
            try {
                $imageData = @file_get_contents($imageUrl);

                if ($imageData) {
                    $lineCallback("\n📸 Cover Preview: {$imageUrl}");

                    if ($kittySupport) {
                        $displayKittyImageCallback($imageData);
                    } elseif ($term === 'iTerm.app') {
                        $base64Image = base64_encode($imageData);
                        $lineCallback("\033]1337;File=inline=1;width=200px;height=150px:{$base64Image}\007");
                    }

                    $lineCallback("");
                } else {
                    $lineCallback("📸 Cover available: {$imageUrl}");
                }
            } catch (\Exception $e) {
                $lineCallback("📸 Cover available: {$imageUrl} (display error: {$e->getMessage()})");
            }
        } else {
            $lineCallback("📸 Cover available: {$imageUrl}");
        }
    }

    /**
     * Handle cover selection - when multiple cover sources exist, prompt the user to pick one.
     * Also searches for alternatives when the current cover is low quality.
     *
     * @param ?callable $getEmbeddedCoverTempPathCallback Converts embedded cover data to a temp file path for preview.
     */
    public function handleCoverSelection(
        array &$metadata,
        callable $isTextOnWhiteCoverCallback,
        callable $searchAlternativeCoversCallback,
        callable $warnCallback,
        callable $lineCallback,
        callable $infoCallback,
        callable $commentCallback,
        callable $displayCoverOptionsCallback,
        callable $promptForCoverSelectionCallback,
        bool $isInteractive,
        ?callable $getEmbeddedCoverTempPathCallback = null
    ): void {
        $coverOptions = [];
        $hasValidLocalSource = false;

        // Add embedded cover as an option (convert to temp path for preview)
        if (!empty($metadata['cover_data'])) {
            $embeddedPreviewPath = null;
            if ($getEmbeddedCoverTempPathCallback) {
                $embeddedPreviewPath = $getEmbeddedCoverTempPathCallback($metadata['cover_data']);
            }
            $coverOptions[] = [
                'type' => 'embedded',
                'url' => $embeddedPreviewPath ?? '',
                'label' => 'Embedded (audio file tags)',
                'cover_data' => $metadata['cover_data'],
                'isLocal' => true,
            ];
            $hasValidLocalSource = true;
        }

        // Add local cover file as an option
        if (!empty($metadata['cover_path']) && file_exists((string) $metadata['cover_path'])) {
            $coverOptions[] = [
                'type' => 'file',
                'url' => (string) $metadata['cover_path'],
                'label' => 'Local file: ' . basename((string) $metadata['cover_path']),
                'isLocal' => true,
            ];
            $hasValidLocalSource = true;
        }

        // Add URL-based cover as an option (with quality check)
        $currentCoverUrl = $metadata['cover_url'] ?? '';
        $hasValidUrlCover = false;
        if (!empty($currentCoverUrl)) {
            $tempCoverPath = null;
            try {
                $tempCoverPath = tempnam(sys_get_temp_dir(), 'cover_') . '.jpg';
                $imageData = @file_get_contents($currentCoverUrl);
                if ($imageData) {
                    file_put_contents($tempCoverPath, $imageData);
                    $isTextOnWhite = $isTextOnWhiteCoverCallback($tempCoverPath);
                    if ($isTextOnWhite) {
                        $warnCallback('⚠️  Current cover URL appears to be text-only on white background (low quality)');
                        $coverOptions[] = [
                            'type' => 'url',
                            'url' => $currentCoverUrl,
                            'label' => 'Remote URL (text-only - low quality)',
                            'isCurrentLowQuality' => true,
                        ];
                    } else {
                        $hasValidUrlCover = true;
                        $coverOptions[] = [
                            'type' => 'url',
                            'url' => $currentCoverUrl,
                            'label' => 'Remote URL',
                            'isCurrent' => true,
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Error analyzing cover URL', ['error' => $e->getMessage()]);
            } finally {
                if ($tempCoverPath && file_exists($tempCoverPath)) {
                    @unlink($tempCoverPath);
                }
            }
        }

        // Search for Google alternatives when no quality local/URL cover is available
        $hasValidCover = $hasValidLocalSource || $hasValidUrlCover;
        if (!$hasValidCover) {
            $lineCallback('🔍 Searching for alternative book covers...');
            $searchResults = $searchAlternativeCoversCallback($metadata, 3);

            if ($searchResults['success'] && !empty($searchResults['images'])) {
                $infoCallback('Found ' . count($searchResults['images']) . ' alternative cover(s)');
                foreach ($searchResults['images'] as $index => $image) {
                    $coverOptions[] = [
                        'type' => 'url',
                        'url' => $image['url'],
                        'label' => 'Google Image ' . ($index + 1),
                        'isGoogle' => true,
                    ];
                }
            } else {
                if (isset($searchResults['error'])) {
                    $commentCallback('Could not search for alternative covers: ' . $searchResults['error']);
                }
            }
        }

        if (count($coverOptions) === 0) {
            $commentCallback('No cover image found');
            return;
        }

        // Single source with no alternatives: apply directly without prompting
        if (count($coverOptions) === 1 || !$isInteractive) {
            if (!$isInteractive) {
                // Auto-mode: prefer local sources; fall back to first Google result
                $selected = null;
                foreach ($coverOptions as $opt) {
                    if (!empty($opt['isLocal'])) {
                        $selected = $opt;
                        break;
                    }
                }
                if ($selected === null) {
                    foreach ($coverOptions as $opt) {
                        if (!empty($opt['isGoogle'])) {
                            $infoCallback('🤖 Auto-selecting first Google Image cover');
                            $selected = $opt;
                            break;
                        }
                    }
                }
                if ($selected !== null) {
                    $this->applyCoverOption($metadata, $selected);
                }
            } else {
                $this->applyCoverOption($metadata, $coverOptions[0]);
            }
            return;
        }

        // Multiple sources: let the user pick with previews
        $displayCoverOptionsCallback($coverOptions, $metadata);
        $selectedUrl = $promptForCoverSelectionCallback($coverOptions);
        if ($selectedUrl !== null && $selectedUrl !== '') {
            $matched = null;
            foreach ($coverOptions as $opt) {
                if (($opt['url'] ?? '') === $selectedUrl) {
                    $matched = $opt;
                    break;
                }
            }
            if ($matched !== null) {
                $this->applyCoverOption($metadata, $matched);
            } else {
                // Treat as a custom URL the user typed
                $metadata['cover_url'] = $selectedUrl;
                $metadata['cover_data'] = null;
                $metadata['cover_path'] = null;
            }
        }
    }

    /**
     * Apply a selected cover option to $metadata, setting the appropriate field.
     */
    private function applyCoverOption(array &$metadata, array $option): void
    {
        $type = $option['type'] ?? 'url';

        if ($type === 'embedded') {
            $metadata['cover_data'] = $option['cover_data'] ?? null;
            $metadata['cover_path'] = null;
            unset($metadata['cover_url']);
        } elseif ($type === 'file') {
            $metadata['cover_path'] = $option['url'];
            $metadata['cover_data'] = null;
            unset($metadata['cover_url']);
        } else {
            $metadata['cover_url'] = $option['url'];
            $metadata['cover_data'] = null;
            $metadata['cover_path'] = null;
        }
    }

    /**
     * Display available cover options
     */
    public function displayCoverOptions(array $coverOptions, callable $displayCoverImageCallback, ?callable $newLineCallback = null, ?callable $lineCallback = null): void
    {
        if ($newLineCallback) {
            $newLineCallback();
        }
        if ($lineCallback) {
            $lineCallback('📚 Available Cover Options:');
        }
        if ($newLineCallback) {
            $newLineCallback();
        }

        foreach ($coverOptions as $index => $option) {
            $label = ($index + 1) . '. ' . $option['label'];
            $displayCoverImageCallback($option['url']);
        }
    }


    /**
     * Process a single audiobook with AI and external enrichment
     */
    public function processAudiobook(
        array $audiobook,
        ?AIBookProcessor $aiProcessor,
        callable $buildUiMetadataCallback,
        callable $uiServiceLogCallback,
        callable $infoCallback,
        callable $lineCallback,
        callable $newLineCallback,
        callable $warnCallback,
        callable $displayEnrichedMetadataCallback,
        callable $reviewAndApproveCallback,
        callable $hasEnrichmentDataCallback,
        callable $getFileOperationCallback,
        callable $enrichWithExternalDataCallback,
        callable $getEnrichmentServiceCallback,
        callable $findExistingBookCallback,
        callable $compareDirectoriesCallback,
        callable $displayDirectoryComparisonCallback,
        callable $promptForDuplicateActionCallback,
        callable $cleanupSourceDirectoryCallback,
        callable $formatBytesCallback,
        callable $extractSeriesNumberFromTitleCallback,
        callable $detectMultiBookPatternCallback,
        callable $analyzeMultiBookFilesCallback,
        callable $processMultiBookSplitCallback,
        callable $handleLowConfidenceMetadataCallback,
        callable $processWithAICallback,
        array &$skippedBooks,
        array &$processedBooks,
        bool $isAutoMode,
        bool $isDryRun,
        bool $skipEnrichment,
        ?object $uiService = null,
        ?callable $addToHistoryCallback = null
    ): void {
        if ($uiService) {
            $uiService->setCurrentBook($buildUiMetadataCallback([
                'title' => $audiobook['name'] ?? '',
                'source_path' => $audiobook['path'] ?? '',
                'author' => [],
                'genre' => [],
                'confidence' => 0,
            ]));
        }

        $missingFiles = 0;
        $sampleSize = min(3, count($audiobook['files']));
        for ($i = 0; $i < $sampleSize; $i++) {
            if (!file_exists($audiobook['files'][$i])) {
                $missingFiles++;
            }
        }

        if ($missingFiles > 0) {
            $warnCallback("⚠️  Skipping {$audiobook['name']} - {$missingFiles} of {$sampleSize} sample files missing");
            $skippedBooks[] = [
                'path' => $audiobook['path'],
                'reason' => 'Some audio files no longer exist',
            ];
            return;
        }

        if (empty($audiobook['files']) || count($audiobook['files']) === 0) {
            $warnCallback("⚠️  Skipping {$audiobook['name']} - no audio files found");
            $skippedBooks[] = [
                'path' => $audiobook['path'],
                'reason' => 'No audio files found',
            ];
            return;
        }

        if ($uiService) {
            $uiServiceLogCallback('📖 Processing: ' . $audiobook['name']);
            $uiServiceLogCallback('📁 Path: ' . $audiobook['path']);
            $uiServiceLogCallback(
                '📄 Files: ' . count($audiobook['files']) . ' (' . $formatBytesCallback($audiobook['total_size']) . ')'
            );
            $uiServiceLogCallback('🤖 Analyzing metadata with AI...');
        } else {
            $newLineCallback();
            $infoCallback("📖 Processing: " . $audiobook['name']);
            $lineCallback("📁 Path: " . $audiobook['path']);
            $lineCallback(
                "📄 Files: " . count($audiobook['files']) . " (" . $formatBytesCallback($audiobook['total_size']) . ")"
            );
        }

        // Detect if the source path is already inside the book root.
        // If so, lock the directory to its current relative path — no rename will occur.
        $bookStorageRoot = rtrim(config('filesystems.disks.books.root') ?? config('app.book_root', ''), '/');
        $sourcePath = rtrim($audiobook['path'], '/');
        $isInBookRoot = $bookStorageRoot !== '' && str_starts_with($sourcePath, $bookStorageRoot . '/');

        $aiMetadata = $processWithAICallback($audiobook);

        if ($isInBookRoot) {
            $relativeDir = substr($sourcePath, strlen($bookStorageRoot) + 1);
            $aiMetadata['custom_directory_path'] = $relativeDir;
            $infoCallback("📍 Path is inside book root — directory locked: {$relativeDir}");
        }

        // Check for OpenAudible metadata - if found, merge it with AI metadata
        // OpenAudible metadata is superior and enables skipping enrichment
        $openAudibleMetadata = $this->lookupOpenAudibleMetadata($audiobook);
        if ($openAudibleMetadata !== null) {
            $openAudibleTitle = $openAudibleMetadata['title'] ?? 'Unknown';
            if ($uiService) {
                $uiServiceLogCallback("📚 Found OpenAudible metadata for: {$openAudibleTitle}");
                $uiServiceLogCallback("📖 Chapters: " . count($openAudibleMetadata['chapters'] ?? []));
            } else {
                $infoCallback("📚 Found OpenAudible metadata for: {$openAudibleTitle}");
                $infoCallback("📖 Chapters: " . count($openAudibleMetadata['chapters'] ?? []));
            }

            // Merge OpenAudible metadata - it takes priority
            $aiMetadata = $this->mergeWithOpenAudibleMetadata($aiMetadata, $openAudibleMetadata);

            // OpenAudible data is so good, we can skip enrichment
            $skipEnrichment = true;
        }

        $hasCriticalTagMetadata = false;
        $tagMetadata = $this->extractTagMetadataFromAudiobook($audiobook, $aiProcessor);
        if ($this->hasCriticalTagMetadata($tagMetadata)) {
            $hasCriticalTagMetadata = true;
        }

        if ($handleLowConfidenceMetadataCallback($audiobook, $aiMetadata)) {
            return;
        }

        $successMessage = "✅ AI processing successful (confidence: {$aiMetadata['confidence']}%)";
        if ($uiService) {
            $uiServiceLogCallback($successMessage);
        } else {
            $infoCallback($successMessage);
        }

        $multiBookInfo = null;
        if (empty($audiobook['_force_single_mode'])) {
            $multiBookInfo = $detectMultiBookPatternCallback($audiobook['name']);

            // Check for Flat Archive (files in root that don't look like parts)
            if (!$multiBookInfo) {
                $flatArchiveInfo = $this->detectFlatArchive($audiobook);
                if ($flatArchiveInfo) {
                    $multiBookInfo = [
                        'series_name' => $flatArchiveInfo['series_name'],
                        'start_number' => 1,
                        'end_number' => count($flatArchiveInfo['files']),
                        'numbers' => range(1, count($flatArchiveInfo['files'])),
                        'is_flat_archive' => true,
                    ];
                    $infoCallback("📚 Detected potential flat archive with " . count($flatArchiveInfo['files']) . " files");
                }
            }
        }

        if ($multiBookInfo) {
            $authors = is_array($aiMetadata['author']) ? $aiMetadata['author'] : [$aiMetadata['author']];
            $cleanedSeriesName = $this->cleanSeriesName($multiBookInfo['series_name'], $authors);
            $multiBookInfo['series_name'] = $cleanedSeriesName;

            $infoCallback(
                "📚 Detected multi-book directory: {$cleanedSeriesName} " .
                "[{$multiBookInfo['start_number']}-{$multiBookInfo['end_number']}]"
            );

            if (isset($multiBookInfo['is_flat_archive'])) {
                $splitGroups = $this->convertFlatFilesToSplitGroups($audiobook['files']);
            } else {
                $splitGroups = $analyzeMultiBookFilesCallback($audiobook, $multiBookInfo);
            }

            if (count($splitGroups) >= 2) {
                $bookCount = count($splitGroups);
                $border = str_repeat('═', 60);
                $infoCallback("\e[1;33m╔{$border}╗\e[0m");
                $infoCallback("\e[1;33m║\e[0m  📚 \e[1mAUTO-SPLIT: {$bookCount} BOOKS DETECTED\e[0m" . str_repeat(' ', max(0, 55 - mb_strlen("AUTO-SPLIT: {$bookCount} BOOKS DETECTED"))) . "\e[1;33m║\e[0m");
                $infoCallback("\e[1;33m║\e[0m  Series: \e[1m{$cleanedSeriesName}\e[0m" . str_repeat(' ', max(0, 51 - mb_strlen($cleanedSeriesName))) . "\e[1;33m║\e[0m");
                $infoCallback("\e[1;33m╠{$border}╣\e[0m");
                foreach ($splitGroups as $num => $fileInfos) {
                    $title = $fileInfos[0]['title'] ?? '';
                    $fileCount = count($fileInfos);
                    $fileLabel = $fileCount === 1 ? '1 file' : "{$fileCount} files";
                    $label = "  Book {$num}: {$title} ({$fileLabel})";
                    $infoCallback("\e[1;33m║\e[0m" . $label . str_repeat(' ', max(0, 61 - mb_strlen($label))) . "\e[1;33m║\e[0m");
                }
                $infoCallback("\e[1;33m╚{$border}╝\e[0m");
                $processMultiBookSplitCallback($audiobook, $multiBookInfo, $splitGroups, $aiMetadata);
                return;
            } else {
                $infoCallback(
                    '📖 No individual book files found - will create combined entry with multiple series numbers'
                );
                $aiMetadata['series'] = $cleanedSeriesName;
                $aiMetadata['multi_book_numbers'] = $multiBookInfo['numbers'];
                $aiMetadata['title'] = $cleanedSeriesName;
            }
        } else {
            $extractSeriesNumberFromTitleCallback($aiMetadata);
        }

        // Clean series name from title after series number extraction
        if (!empty($aiMetadata['title']) && !empty($aiMetadata['series'])) {
            $aiMetadata['title'] = $this->removeSeriesFromTitle($aiMetadata['title'], $aiMetadata['series']);
        }

        // Look up genre from existing books in the same series by the same author
        $seriesGenre = $this->lookupGenreFromExistingSeries($aiMetadata);
        if ($seriesGenre) {
            $aiMetadata['genre'] = $seriesGenre;
            $infoCallback("📚 Genre set from existing series: {$seriesGenre}");
        }

        $this->adjustConfidence($aiMetadata, $infoCallback);

        $existingBook = $findExistingBookCallback($audiobook['path'], $aiMetadata);
        if ($existingBook) {
            $warnCallback("⚠️  Book already exists (detected after AI processing)");
            $lineCallback("  Found existing book: '{$existingBook->title}' (ID: {$existingBook->id})");

            $bookStoragePath = config('filesystems.disks.books.root') ?? config('app.book_root');
            if ($bookStoragePath && $existingBook->directory_path) {
                $existingDir = $bookStoragePath . '/' . $existingBook->directory_path;

                if (\Illuminate\Support\Facades\File::isDirectory($existingDir)) {
                    $comparison = $compareDirectoriesCallback($audiobook['path'], $existingDir);

                    // Check if it's a file by looking at the path structure (has audio extension)
                    $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'wma', 'aac'];
                    $extension = strtolower(pathinfo($audiobook['path'] ?? '', PATHINFO_EXTENSION));
                    $isFile = in_array($extension, $audioExtensions);
                    $sourceType = $isFile ? 'file' : 'directory';
                    $sourceTypePlural = $isFile ? 'files' : 'directories';

                    if ($comparison['identical']) {
                        $infoCallback("🔍 Source and existing {$sourceTypePlural} are identical");

                        // Always ask for confirmation before deleting
                        $options = [
                            '1' => "Skip import completely (keep both {$sourceTypePlural})",
                            '2' => "Delete source {$sourceType} (keep existing)",
                            '3' => 'Import anyway with new name',
                        ];

                        $choice = $uiService->select("Identical {$sourceTypePlural} detected - choose action", $options, '1');

                        switch ($choice) {
                            case '2':
                                $infoCallback("🗑️ Removing source {$sourceType}, keeping existing");
                                $cleanupSourceDirectoryCallback($audiobook, true);
                                $skippedBooks[] = [
                                    'path' => $audiobook['path'],
                                    'reason' => "User chose to keep existing over source (identical {$sourceTypePlural})",
                                ];
                                return;

                            case '3':
                                $infoCallback("📁 Will import with renamed {$sourceType} to avoid conflict");
                                $aiMetadata['_force_rename_directory'] = true;
                                break;

                            case '1':
                            default:
                                $infoCallback("📁 Skipping import, leaving both {$sourceTypePlural} unchanged");
                                $skippedBooks[] = [
                                    'path' => $audiobook['path'],
                                    'reason' => "User chose to skip import (identical {$sourceTypePlural})",
                                ];
                                return;
                        }
                    } else {
                        // If the target directory has no audio files it is effectively empty —
                        // treat it the same as the directory-missing path and auto-merge.
                        $targetAudioCount = $comparison['target']['count'] ?? 0;
                        if ($targetAudioCount === 0) {
                            $infoCallback("📋 Existing book directory has no audio files — merging into existing record");

                            $selectCb = $uiService ? fn ($q, $o, $d) => $uiService->select($q, $o, $d) : fn ($q, $o, $d) => $d;
                            $askCb = $uiService ? fn ($q, $d) => $uiService->ask($q, $d ?? '') : fn ($q, $d) => $d ?? '';

                            $mergedMetadata = $this->buildMergeMetadata(
                                $existingBook,
                                $aiMetadata,
                                $selectCb,
                                $askCb,
                                $infoCallback
                            );

                            if ($mergedMetadata === null) {
                                $skippedBooks[] = [
                                    'path' => $audiobook['path'],
                                    'reason' => 'User cancelled merge into existing book (empty target directory)',
                                ];
                                return;
                            }

                            if (!$isDryRun) {
                                $operation = $getFileOperationCallback();
                                $book = $this->mergeIntoExistingBook(
                                    $existingBook,
                                    $audiobook,
                                    $mergedMetadata,
                                    $warnCallback,
                                    $infoCallback,
                                    fn () => $operation
                                );

                                $infoCallback("✅ Book merged successfully: {$book->title} (ID: {$book->id})");
                                $processedBooks[] = [
                                    'path' => $audiobook['path'],
                                    'book_id' => $book->id,
                                    'title' => $book->title,
                                ];
                            } else {
                                $infoCallback("🔍 [DRY RUN] Would merge into existing: {$existingBook->title} (ID: {$existingBook->id})");
                            }

                            return;
                        }

                        $warnCallback("📁 {$sourceTypePlural} differ — manual decision needed");
                        $displayDirectoryComparisonCallback($comparison);

                        $options = [
                            '1' => 'Skip import completely',
                            '2' => 'Replace existing with source',
                            '3' => "Delete source {$sourceType} (keep existing)",
                            '4' => 'Import anyway with new name',
                        ];

                        $choice = $uiService->select(ucfirst($sourceTypePlural) . " differ - choose action", $options, '1');

                        switch ($choice) {
                            case '2':
                                $infoCallback("🗑️ Removing existing {$sourceType} to replace with source");
                                $trashResult = $this->sourceTrashService->movePathToTrash(
                                    $existingDir,
                                    'directory_diff_replace',
                                    ['conflict_reason' => "{$sourceTypePlural} differ - user chose replace"]
                                );
                                if ($trashResult) {
                                    $infoCallback("✅ Moved existing {$sourceType} to trash ({$trashResult['id']})");
                                }
                                break;

                            case '3':
                                $infoCallback("🗑️ Removing source {$sourceType}, keeping existing");
                                $cleanupSourceDirectoryCallback($audiobook, true);
                                $skippedBooks[] = [
                                    'path' => $audiobook['path'],
                                    'reason' => 'User chose to keep existing over source',
                                ];
                                return;

                            case '4':
                                $infoCallback("📁 Will import with renamed {$sourceType} to avoid conflict");
                                $aiMetadata['_force_rename_directory'] = true;
                                break;

                            case '1':
                            default:
                                $infoCallback("📁 Skipping import, leaving both {$sourceTypePlural} unchanged");
                                $skippedBooks[] = [
                                    'path' => $audiobook['path'],
                                    'reason' => "User chose to skip import ({$sourceType} conflict)",
                                ];
                                return;
                        }
                    }
                } else {
                    $infoCallback("📋 Existing book found but directory missing - merging into existing record");
                    $lineCallback("  Existing book: '{$existingBook->title}' (ID: {$existingBook->id})");
                    $lineCallback("  Expected path: {$existingDir}");

                    $selectCb = $uiService ? fn ($q, $o, $d) => $uiService->select($q, $o, $d) : fn ($q, $o, $d) => $d;
                    $askCb = $uiService ? fn ($q, $d) => $uiService->ask($q, $d ?? '') : fn ($q, $d) => $d ?? '';

                    $mergedMetadata = $this->buildMergeMetadata(
                        $existingBook,
                        $aiMetadata,
                        $selectCb,
                        $askCb,
                        $infoCallback
                    );

                    if ($mergedMetadata === null) {
                        $skippedBooks[] = [
                            'path' => $audiobook['path'],
                            'reason' => 'User cancelled merge into existing book',
                        ];
                        return;
                    }

                    if (!$isDryRun) {
                        $operation = $getFileOperationCallback();
                        $book = $this->mergeIntoExistingBook(
                            $existingBook,
                            $audiobook,
                            $mergedMetadata,
                            $warnCallback,
                            $infoCallback,
                            fn () => $operation
                        );

                        $infoCallback("✅ Book merged successfully: {$book->title} (ID: {$book->id})");
                        $processedBooks[] = [
                            'path' => $audiobook['path'],
                            'book_id' => $book->id,
                            'title' => $book->title,
                        ];
                    } else {
                        $infoCallback("🔍 [DRY RUN] Would merge into existing: {$existingBook->title} (ID: {$existingBook->id})");
                    }

                    return;
                }
            } else {
                $infoCallback("📋 Existing book found but directory path missing - merging into existing record");
                $lineCallback("  Existing book: '{$existingBook->title}' (ID: {$existingBook->id})");

                $selectCb = $uiService ? fn ($q, $o, $d) => $uiService->select($q, $o, $d) : fn ($q, $o, $d) => $d;
                $askCb = $uiService ? fn ($q, $d) => $uiService->ask($q, $d ?? '') : fn ($q, $d) => $d ?? '';

                $mergedMetadata = $this->buildMergeMetadata(
                    $existingBook,
                    $aiMetadata,
                    $selectCb,
                    $askCb,
                    $infoCallback
                );

                if ($mergedMetadata === null) {
                    $skippedBooks[] = [
                        'path' => $audiobook['path'],
                        'reason' => 'User cancelled merge into existing book',
                    ];
                    return;
                }

                if (!$isDryRun) {
                    $operation = $getFileOperationCallback();
                    $book = $this->mergeIntoExistingBook(
                        $existingBook,
                        $audiobook,
                        $mergedMetadata,
                        $warnCallback,
                        $infoCallback,
                        fn () => $operation
                    );

                    $infoCallback("✅ Book merged successfully: {$book->title} (ID: {$book->id})");
                    $processedBooks[] = [
                        'path' => $audiobook['path'],
                        'book_id' => $book->id,
                        'title' => $book->title,
                    ];
                } else {
                    $infoCallback("🔍 [DRY RUN] Would merge into existing: {$existingBook->title} (ID: {$existingBook->id})");
                }

                return;
            }
        }

        $genreBySource = [];
        if (!$skipEnrichment) {
            $infoCallback("🔍 Attempting to enrich with external data...");
            $enrichedData = $enrichWithExternalDataCallback($aiMetadata);
            if ($enrichedData) {
                $enrichmentService = $getEnrichmentServiceCallback();
                if ($enrichmentService->isValidEnrichment($aiMetadata, $enrichedData)) {
                    $genreBySource = $enrichedData['_genre_by_source'] ?? [];
                    $enrichmentResults = $enrichedData['_enrichment_results'] ?? [];
                    $aiMetadata = array_merge($aiMetadata, $enrichedData);
                    $successSources = array_keys(array_filter($enrichmentResults, fn ($v) => $v === 'success'));
                    $sourceList = implode(', ', array_map(fn ($s) => ucfirst(str_replace('_', ' ', $s)), $successSources));
                    $infoCallback("✅ Found enrichment data!" . ($sourceList ? " ({$sourceList})" : ''));
                } else {
                    $warnCallback("⚠️  Invalid enrichment data - skipping merge.");
                }
            } else {
                $warnCallback("⚠️  No enrichment data found");
            }
        }

        // Force genre from config if provided - do this after enrichment
        // so it overrides anything found externally
        if (!empty($this->config['genre'])) {
            $aiMetadata['genre'] = $this->config['genre'];
        }

        // Last-resort fallback: if enrichment still left a weak genre, use the author's DB history.
        // This only applies when enrichment truly couldn't determine a genre — not to override a
        // real enrichment result. Author history is a weaker signal than any external source.
        $postEnrichmentGenre = $aiMetadata['genre'] ?? '';
        if (is_array($postEnrichmentGenre)) {
            $postEnrichmentGenre = $postEnrichmentGenre[0] ?? '';
        }
        $weakGenres = ['General Fiction', 'Action', 'Other', 'Unknown', ''];
        if (in_array($postEnrichmentGenre, $weakGenres, true)) {
            $authors = $aiMetadata['author'] ?? [];
            if (is_string($authors)) {
                $authors = [$authors];
            }
            $preferredGenre = $this->getAuthorPreferredGenre($authors);
            if ($preferredGenre && !in_array($preferredGenre, $weakGenres, true)) {
                if (is_array($aiMetadata['genre'])) {
                    $aiMetadata['genre'] = [$preferredGenre];
                } else {
                    $aiMetadata['genre'] = $preferredGenre;
                }
                $sourceParts = [];
                foreach ($genreBySource as $src => $srcGenre) {
                    $sourceParts[] = ucfirst(str_replace('_', ' ', $src)) . ': ' . ($srcGenre ?? 'no genre');
                }
                $sourceDetail = $sourceParts ? implode(', ', $sourceParts) : null;
                $enrichmentFoundGenre = !empty(array_filter($genreBySource));
                if (!$enrichmentFoundGenre) {
                    $detail = $sourceDetail ? "AI returned '{$postEnrichmentGenre}'; enrichment found no genre ({$sourceDetail})" : "AI returned '{$postEnrichmentGenre}'; no enrichment ran";
                } else {
                    $detail = "enrichment returned '{$postEnrichmentGenre}'" . ($sourceDetail ? ": {$sourceDetail}" : '');
                }
                $infoCallback("🔄 Genre set to '{$preferredGenre}' (author DB history fallback; {$detail})");
            }
        }

        // Disambiguate when enrichment returned multiple genre candidates (e.g. ["Science Fiction", "Fantasy"])
        $genreAfterEnrichment = $aiMetadata['genre'] ?? '';
        if (is_array($genreAfterEnrichment) && count($genreAfterEnrichment) > 1) {
            $resolved = $this->disambiguateGenreCandidates($genreAfterEnrichment, $aiMetadata, $aiProcessor, $infoCallback, $genreBySource);
            $aiMetadata['genre'] = $resolved;
        }

        // Clean series name from title after enrichment (enrichment may override with unclean title)
        if (!empty($aiMetadata['title']) && !empty($aiMetadata['series'])) {
            $aiMetadata['title'] = $this->removeSeriesFromTitle($aiMetadata['title'], $aiMetadata['series']);
        }

        $newLineCallback();
        $displayEnrichedMetadataCallback($aiMetadata);
        $newLineCallback();

        $aiMetadata['source_path'] = $audiobook['path'];
        $expectedPath = $this->generateDirectoryPath($aiMetadata);
        $infoCallback("📁 Expected directory path: {$expectedPath}");

        if (!$isAutoMode && !$isDryRun) {
            $approved = $reviewAndApproveCallback($aiMetadata, $audiobook);

            if (isset($aiMetadata['_action']) && $aiMetadata['_action'] === 'reprocess_multi') {
                $infoCallback("🔄 Reprocessing as Multi-Book Archive (Flat Split requested)...");
                $splitGroups = $this->convertFlatFilesToSplitGroups($audiobook['files']);
                $multiBookInfo = [
                    'series_name' => $aiMetadata['series'] ?? $audiobook['name'],
                    'start_number' => 1,
                    'end_number' => count($audiobook['files']),
                    'numbers' => range(1, count($audiobook['files'])),
                ];
                $processMultiBookSplitCallback($audiobook, $multiBookInfo, $splitGroups, $aiMetadata);
                return;
            }

            if (isset($aiMetadata['_action']) && $aiMetadata['_action'] === 'merge_parent') {
                $infoCallback("🔄 Merging into Parent Book requested...");
                throw new MergeIntoParentException("User requested merge into parent book");
            }

            if (!$approved) {
                $warnCallback("❌ Import rejected by user");
                $skippedBooks[] = [
                    'path' => $audiobook['path'],
                    'reason' => 'Rejected by user',
                ];
                if ($addToHistoryCallback) {
                    $addToHistoryCallback('skipped', $audiobook, $aiMetadata);
                }
                return;
            }
        } elseif ($isAutoMode && !$hasEnrichmentDataCallback($aiMetadata)) {
            $warnCallback("⚠️  No enrichment data found in auto mode - skipping (detected fields might be incorrect)");
            $skippedBooks[] = [
                'path' => $audiobook['path'],
                'reason' => 'No enrichment data in auto mode',
            ];
            return;
        }

        if (!$isDryRun) {
            if ($uiService) {
                $uiServiceLogCallback('💾 Creating database record...');
            }

            $book = $this->createBookFromMetadata($aiMetadata, $audiobook);

            if ($book) {
                $infoCallback("✅ Book imported successfully: {$book->title} (ID: {$book->id})");

                $operation = $getFileOperationCallback();
                $this->moveFilesToLibrary(
                    $audiobook,
                    $book,
                    $warnCallback,
                    fn () => config('filesystems.disks.books.root') ?? config('app.book_root', '/media/lyra_data1/audiobooks/books'),
                    fn () => $operation === 'copy'
                );

                // Process cover image AFTER files are moved (and directory created) to prevent conflict detection
                $this->processCoverImage($book, $aiMetadata);

                $processedBooks[] = [
                    'path' => $audiobook['path'],
                    'book_id' => $book->id,
                    'title' => $book->title,
                ];
                if ($addToHistoryCallback) {
                    $addToHistoryCallback('imported', $audiobook, [], $book->id, $book->title);
                }
            }
        } else {
            $infoCallback("🔍 [DRY RUN] Would import: {$aiMetadata['title']}");
        }
    }

    /**
     * Manual review and approval
     */
    public function reviewAndApprove(
        array &$metadata,
        array $audiobook,
        callable $buildUiMetadataCallback,
        callable $uiServiceLogCallback,
        callable $selectWithImmediateInterruptCallback,
        callable $askInlineCallback,
        callable $buildReviewOptionsCallback,
        callable $editMetadataFieldsCallback,
        callable $manualEnrichmentWithComparisonCallback,
        callable $getEnrichmentServiceCallback,
        callable $getValidGenresCallback,
        callable $hasEnrichmentDataCallback,
        callable $generateDirectoryPathCallback,
        bool &$inputInterrupted,
        ?callable $fixPreviousImportCallback = null,
        ?array $previousImport = null
    ): bool {
        $uiServiceLogCallback('setCurrentBook', $buildUiMetadataCallback($metadata));

        $currentDirectoryPath = (string) ($metadata['custom_directory_path'] ?? '');
        if ($currentDirectoryPath === '') {
            $currentDirectoryPath = $generateDirectoryPathCallback($metadata, [
                'include_title' => true,
            ]);
        }

        $currentGenre = $metadata['genre'] ?? 'Other';
        if (is_array($currentGenre)) {
            $currentGenre = $currentGenre[0] ?? 'Other';
        }

        $currentCoverUrl = (string) ($metadata['cover_url'] ?? '');

        $validGenres = $getValidGenresCallback();
        $normalizedGenre = is_string($currentGenre) ? trim($currentGenre) : '';
        $isGenreValid = in_array($normalizedGenre, $validGenres, true);

        // Check for enrichment data and set warning/default choice accordingly
        $hasEnrichmentData = $hasEnrichmentDataCallback($metadata);
        if (!$hasEnrichmentData) {
            $uiServiceLogCallback("⚠️  No external enrichment data found - detected fields may be incorrect");
        }

        $defaultChoice = '2';

        // ALWAYS run the review loop - this ensures user confirmation regardless of enrichment data
        while (true) {
            // Recalculate default choice each iteration (confidence may have changed after edits)
            $confidence = $metadata['confidence'] ?? 0;
            $currentGenre = $metadata['genre'] ?? 'Other';
            if (is_array($currentGenre)) {
                $currentGenre = $currentGenre[0] ?? 'Other';
            }
            $normalizedGenre = is_string($currentGenre) ? trim($currentGenre) : '';
            $isGenreValid = in_array($normalizedGenre, $validGenres, true);

            $currentAuthor = $metadata['author'] ?? '';
            $authorStr = is_array($currentAuthor) ? implode('', $currentAuthor) : (string) $currentAuthor;
            $hasAuthor = trim($authorStr) !== '';

            // confidence 100 means user manually edited/confirmed, so trust it fully
            $userConfirmed = $confidence >= 100;
            if (!$isGenreValid) {
                $defaultChoice = '2';
            } elseif ($userConfirmed && $hasAuthor) {
                $defaultChoice = '1';
            } elseif ($confidence >= 75 && $hasAuthor && $hasEnrichmentData) {
                $defaultChoice = '1';
            } else {
                $defaultChoice = '2';
            }

            // Automatically recalculate proposed directory path if not manually overridden
            // This ensures changes to Genre, Title, or Series are reflected in the UI
            if (empty($metadata['custom_directory_path'])) {
                $currentDirectoryPath = $generateDirectoryPathCallback($metadata, [
                    'include_title' => true,
                ]);
            }

            $options = $buildReviewOptionsCallback($currentCoverUrl, $currentGenre, $currentDirectoryPath, false, count($audiobook['files'] ?? []), $previousImport);
            $choice = $selectWithImmediateInterruptCallback('Choose an option', $options, $defaultChoice);

            $choice = strtolower(trim($choice));
            if (in_array($choice, ['1', 'a', 'accept'], true)) {
                if (!$isGenreValid) {
                    $uiServiceLogCallback('⚠️  Cannot accept: genre is invalid - please update genre first (Option 2 → Genre)');
                    continue;
                }
                return true;
            }
            if (in_array($choice, ['3', 's', 'skip'], true)) {
                return false;
            }

            if ($choice === '2' || $choice === 'e' || $choice === 'edit') {
                $metadata = $editMetadataFieldsCallback($metadata, false);
                if ($inputInterrupted) {
                    return false;
                }

                $currentGenre = $metadata['genre'] ?? $currentGenre;
                if (is_array($currentGenre)) {
                    $currentGenre = $currentGenre[0] ?? 'Other';
                }
                $normalizedGenre = is_string($currentGenre) ? trim($currentGenre) : '';
                $isGenreValid = in_array($normalizedGenre, $validGenres, true);
                if ($isGenreValid) {
                    $uiServiceLogCallback('[Genre] ✅ Genre updated to a valid value: ' . $currentGenre);
                }
                $currentCoverUrl = (string) ($metadata['cover_url'] ?? $currentCoverUrl);
                $uiServiceLogCallback('setCurrentBook', $buildUiMetadataCallback($metadata));
                continue;
            }

            if ($choice === '4') {
                $metadata['_action'] = 'reprocess_multi';
                return true;
            }

            if ($choice === '5') {
                $metadata['_action'] = 'merge_parent';
                return true;
            }

            if ($choice === 'p' && $fixPreviousImportCallback !== null && $previousImport !== null) {
                $fixPreviousImportCallback($previousImport);
                // Restore current book in the details panel and show a clear return banner
                $uiServiceLogCallback('setCurrentBook', $buildUiMetadataCallback($metadata));
                $border = str_repeat('─', 40);
                $title = $metadata['title'] ?? 'current book';
                $uiServiceLogCallback("\e[1;36m┌{$border}┐\e[0m");
                $uiServiceLogCallback("\e[1;36m│\e[0m  ↩ Back to: \e[1m{$title}\e[0m" . str_repeat(' ', max(0, 37 - mb_strlen($title))) . "\e[1;36m│\e[0m");
                $uiServiceLogCallback("\e[1;36m└{$border}┘\e[0m");
                // Force Edit as default so the current book can't be accepted by accident
                $defaultChoice = '2';
                continue;
            }

            break;
        }

        // This should never be reached, but return false to be safe
        return false;
    }

    /**
     * Detect if a directory is a flat archive containing multiple independent book files
     *
     * Returns null if files look like parts/tracks of the same audiobook (should be treated as single book).
     * Returns archive info if files look like independent books (should be split).
     */
    public function detectFlatArchive(array $audiobook): ?array
    {
        $files = $audiobook['files'] ?? [];
        if (count($files) < 2) {
            return null;
        }

        // Check if files look like parts/tracks of the same book
        // If they do, we assume it's a single book (unless overridden by user)
        $partCount = 0;
        $trackCount = 0;
        $chapterCount = 0;
        $sequentialNumberCount = 0;

        foreach ($files as $file) {
            $filename = basename($file);

            // Check for CD/Disc/Part patterns (e.g., "CD 1", "Disc-02", "Part_3")
            if (preg_match('/(cd|disc|disk|part)\s*[-_.]?\s*\d+/i', $filename)) {
                $partCount++;
                continue;
            }

            // Check for Track patterns (e.g., "Track 01", "Track_02", "track-3")
            if (preg_match('/track\s*[-_.]?\s*\d+/i', $filename)) {
                $trackCount++;
                continue;
            }

            // Check for Chapter patterns (e.g., "Chapter 01", "Ch. 02", "ch_3")
            if (preg_match('/(chapter|ch\.?)\s*[-_.]?\s*\d+/i', $filename)) {
                $chapterCount++;
                continue;
            }

            // Check for sequential numbering at start of filename (e.g., "01 - Title", "02_Title", "03.mp3")
            // This catches files like "01 - Introduction.mp3", "02 - Chapter One.mp3", etc.
            if (preg_match('/^(\d{1,3})\s*[-_.\s]/i', $filename)) {
                $sequentialNumberCount++;
                continue;
            }
        }

        $totalPartPatterns = $partCount + $trackCount + $chapterCount + $sequentialNumberCount;

        // If most files look like parts/tracks/chapters of the same book, assume single book
        if ($totalPartPatterns > count($files) / 2) {
            return null;
        }

        // Additional check: if all files share a common prefix (same author/title), they're likely the same book
        // But we must be careful: if the prefix is "Series Name - Book ", the remainder might be "1" and "2", which are different books!
        if ($this->filesShareCommonPrefix($files)) {
            return null;
        }

        // Final check: Inspect metadata (ID3 tags) of a few files
        // If they share the same 'album' tag, they are parts of the same book
        if ($this->filesShareCommonMetadata($files)) {
            return null;
        }

        return [
            'type' => 'flat_archive',
            'series_name' => $audiobook['name'], // Default series name to directory name
            'files' => $files,
        ];
    }

    /**
     * Check if files share a common prefix (indicating they're parts of the same audiobook)
     */
    protected function filesShareCommonPrefix(array $files): bool
    {
        if (count($files) < 2) {
            return false;
        }

        $filenames = array_map(function ($file) {
            $name = basename($file);
            // Remove extension
            $name = preg_replace('/\.[^.]+$/', '', $name);
            // Remove leading track/chapter numbers
            $name = preg_replace('/^(\d{1,3})\s*[-_.\s]+/', '', $name);
            $name = preg_replace('/^(track|chapter|ch\.?|cd|disc|disk|part)\s*[-_.]?\s*\d+\s*[-_.\s]*/i', '', $name);
            return trim($name);
        }, $files);

        // Check if all remaining filenames are very similar (just track numbers differ)
        $firstFilename = $filenames[0] ?? '';
        if (strlen($firstFilename) < 3) {
            // Filenames are too short after stripping - likely just numbered tracks
            return true;
        }

        // Check if filenames share a significant common prefix (at least 60% of average length)
        $commonPrefix = $firstFilename;
        foreach ($filenames as $filename) {
            $commonPrefix = $this->getCommonPrefix($commonPrefix, $filename);
            if (strlen($commonPrefix) < 3) {
                break;
            }
        }

        $avgLength = array_sum(array_map('strlen', $filenames)) / count($filenames);

        // If common prefix is NOT significant, they are different books
        if (strlen($commonPrefix) < ($avgLength * 0.6)) {
            return false;
        }

        // Additional heuristic: Check the *differences* (suffixes)
        // If the differences contain significant text (not just numbers/parts), they are likely different books
        foreach ($filenames as $filename) {
            $suffix = substr($filename, strlen($commonPrefix));
            // Remove standard separators/part indicators
            $suffix = preg_replace('/^[\s\-_.]+/', '', $suffix);
            $suffix = preg_replace('/(part|cd|disc|disk|track|chapter|ch\.?)\s*\d*/i', '', $suffix);
            $suffix = preg_replace('/\d+/', '', $suffix);
            $suffix = trim($suffix);

            // If suffix still has significant text (more than 2 chars), it implies different titles
            // e.g. "Book 1 - The Stone" vs "Book 2 - The Water" -> Suffixes "The Stone", "The Water"
            if (strlen($suffix) > 2) {
                return false; // Different books
            }
        }

        return true;
    }

    /**
     * Check if files share common metadata (indicating they're parts of the same audiobook)
     */
    protected function filesShareCommonMetadata(array $files): bool
    {
        if (count($files) < 2) {
            return false;
        }

        // Check up to 3 files (first, middle, last) to save time
        $indicesToCheck = [0];
        if (count($files) > 2) {
            $indicesToCheck[] = (int) floor(count($files) / 2);
        }
        if (count($files) > 1) {
            $indicesToCheck[] = count($files) - 1;
        }
        $indicesToCheck = array_unique($indicesToCheck);

        $albums = [];
        $titles = [];

        foreach ($indicesToCheck as $index) {
            $filePath = $files[$index];
            try {
                // Use existing method to extract raw tags
                $tags = $this->extractSingleFileTags($filePath);

                // Collect Album names
                if (!empty($tags['album'])) {
                    $albums[] = trim($tags['album']);
                }

                // Collect Titles to check for "Chapter" patterns
                if (!empty($tags['title'])) {
                    $titles[] = trim($tags['title']);
                }
            } catch (\Exception $e) {
                // Ignore errors reading tags
                continue;
            }
        }

        // 1. Check if we found common albums
        if (count($albums) >= 2) {
            $firstAlbum = $albums[0];
            $allSameAlbum = true;
            foreach ($albums as $album) {
                if (strcasecmp($album, $firstAlbum) !== 0) {
                    $allSameAlbum = false;
                    break;
                }
            }
            if ($allSameAlbum) {
                return true; // Files belong to the same album -> Same book
            }
        }

        // 2. Check if titles indicate parts (contain "Chapter", "Track", or start with number)
        $partTitleCount = 0;
        foreach ($titles as $title) {
            if (
                preg_match('/(chapter|ch\.?|track|part|cd|disc|disk)\s*[-_.]?\s*\d+/i', $title) ||
                preg_match('/^\d+[\s\-_]/', $title) ||
                is_numeric($title)
            ) {
                $partTitleCount++;
            }
        }

        // If detected titles look like parts, assume it's a single book
        if (count($titles) > 0 && $partTitleCount >= count($titles) / 2) {
            return true;
        }

        return false;
    }

    /**
     * Get the common prefix between two strings
     */
    protected function getCommonPrefix(string $str1, string $str2): string
    {
        $minLen = min(strlen($str1), strlen($str2));
        $prefix = '';
        for ($i = 0; $i < $minLen; $i++) {
            if (strtolower($str1[$i]) === strtolower($str2[$i])) {
                $prefix .= $str1[$i];
            } else {
                break;
            }
        }
        return $prefix;
    }

    /**
     * Process a flat archive by treating each file as a separate book
     */
    public function processFlatArchiveSplit(
        array $audiobook,
        array $archiveInfo,
        array $aiMetadata,
        callable $infoCallback,
        callable $processSingleBookCallback
    ): void {
        $files = $audiobook['files'] ?? [];
        $infoCallback("📚 Processing flat archive: found " . count($files) . " files");

        foreach ($files as $index => $filePath) {
            $filename = basename($filePath);
            $infoCallback("  Processing file " . ($index + 1) . "/" . count($files) . ": {$filename}");

            // Create a virtual audiobook for this single file
            $virtualAudiobook = [
                'path' => dirname($filePath), // Same directory
                'name' => pathinfo($filename, PATHINFO_FILENAME), // Title from filename
                'files' => [$filePath],
                'total_size' => filesize($filePath),
            ];

            // Metadata for this specific book
            $bookMetadata = $aiMetadata;
            $bookMetadata['title'] = $virtualAudiobook['name'];
            $bookMetadata['series'] = $archiveInfo['series_name'] ?? $aiMetadata['series'] ?? null;
            // Clear duration so it's recalculated for the single file
            unset($bookMetadata['duration']);

            // Process it
            $processSingleBookCallback($virtualAudiobook, $bookMetadata);
        }
    }

    /**
     * Convert list of files to split groups (one file per group)
     */
    public function convertFlatFilesToSplitGroups(array $files): array
    {
        $groups = [];
        $i = 1;
        sort($files); // Ensure consistent order
        foreach ($files as $file) {
            $filename = basename($file);

            // Try to extract book number from filename to use as series number
            // e.g. "03 - Title.mp3" -> 3
            $bookNum = $this->extractTrackNumber($filename);

            if ($bookNum !== null && $bookNum > 0) {
                $index = $bookNum;
            } else {
                $index = $i;
            }

            // Handle collisions by incrementing
            while (isset($groups[$index])) {
                $index++;
            }

            $groups[$index] = [[
                'file' => $file,
                'title' => pathinfo($file, PATHINFO_FILENAME),
            ]];
            $i++;
        }
        return $groups;
    }
}
