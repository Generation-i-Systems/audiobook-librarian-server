<?php

namespace App\Services;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Series;
use App\Traits\BookImportTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Unified Book Importer
 *
 * Central service for ALL book imports regardless of source.
 * All import commands should use this service for consistency.
 */
class UnifiedBookImporter
{
    use BookImportTrait;

    private string $bookRoot;
    private DocumentStoreServiceInterface $documentStore;
    private BookDirectoryParser $parser;
    private ?GenreMappingService $genreMapper = null;

    public function __construct(
        DocumentStoreServiceInterface $documentStore,
        BookDirectoryParser $parser,
        ?GenreMappingService $genreMapper = null
    ) {
        $this->documentStore = $documentStore;
        $this->parser = $parser;
        $this->genreMapper = $genreMapper;
        if ($this->genreMapper === null) {
            try {
                $this->genreMapper = app(GenreMappingService::class);
            } catch (\Throwable) {
                $this->genreMapper = null;
            }
        }
        $this->bookRoot = $this->resolveBookRoot();
    }

    private function resolveBookRoot(): string
    {
        $configBookRoot = config('app.book_root');
        $envRoot = env('BOOK_STORAGE_PATH') ?: (getenv('BOOK_STORAGE_PATH') ?: null);
        $diskRoot = config('filesystems.disks.books.root');

        $candidates = array_values(array_filter([
            is_string($configBookRoot) ? trim($configBookRoot) : '',
            is_string($envRoot) ? trim($envRoot) : '',
            is_string($diskRoot) ? trim($diskRoot) : '',
        ]));

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_dir($candidate)) {
                return rtrim((string) (realpath($candidate) ?: $candidate), '/');
            }
        }

        $fallback = $candidates[0] ?? '';
        if ($fallback === '') {
            return '';
        }

        return rtrim((string) (realpath($fallback) ?: $fallback), '/');
    }

    /**
     * Import a book from any source
     *
     * @param array $bookData Normalized book metadata
     * @param array $options Import options
     * @return array Result with status and book
     */
    public function importBook(array $bookData, array $options = []): array
    {
        $this->bookRoot = $this->resolveBookRoot();

        $dryRun = $options['dry_run'] ?? false;
        $force = $options['force'] ?? false;
        $sourcePath = $options['source_path'] ?? null;
        $duplicateAction = $options['duplicate_action'] ?? null;

        try {
            // Check for existing book
            $existingBook = $this->findExistingBook($bookData);

            if ($existingBook && !$force && !$duplicateAction) {
                return [
                    'status' => 'skipped',
                    'reason' => 'already_exists',
                    'book' => $existingBook,
                ];
            }

            if ($dryRun) {
                return [
                    'status' => $existingBook ? 'would_update' : 'would_import',
                    'book' => $existingBook,
                ];
            }

            // Determine destination path
            $destPath = $this->prepareDestinationPath($bookData, $options);

            // Handle file operations
            if ($sourcePath) {
                $copiedFiles = $this->handleFileOperations(
                    $sourcePath,
                    $destPath,
                    $bookData,
                    $duplicateAction
                );
            } else {
                $copiedFiles = [];
            }

            // Find cover image
            $coverImage = $this->findOrCopyCoverImage($destPath, $sourcePath, $copiedFiles);
            if ($coverImage) {
                $bookData['cover_image'] = $coverImage;
            }

            // Create or update book record
            DB::beginTransaction();
            try {
                if ($existingBook) {
                    $book = $this->updateBook($existingBook, $bookData, $destPath, $copiedFiles);
                    $status = 'updated';
                } else {
                    $book = $this->createBook($bookData, $destPath, $copiedFiles);
                    $status = 'imported';
                }

                // Create relationships
                $this->createRelationships($book, $bookData);

                DB::commit();

                return [
                    'status' => $status,
                    'book' => $book,
                ];
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Book import failed', [
                'book_data' => $bookData,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Find existing book by various criteria
     */
    private function findExistingBook(array $bookData): ?Book
    {
        // Try by ASIN first
        if (!empty($bookData['asin'])) {
            $book = Book::where('asin', $bookData['asin'])->first();
            if ($book) {
                return $book;
            }
        }

        // Try by directory path
        if (!empty($bookData['directory_path'])) {
            $book = Book::where('directory_path', $bookData['directory_path'])->first();
            if ($book) {
                return $book;
            }
        }

        // Try by title and author
        if (!empty($bookData['title'])) {
            $query = Book::where('title', $bookData['title']);

            if (!empty($bookData['author'])) {
                $authorNames = is_array($bookData['author']) ? $bookData['author'] : [$bookData['author']];
                $query->whereHas('authors', function ($q) use ($authorNames) {
                    $q->whereIn('name', $authorNames);
                });
            }

            $book = $query->first();
            if ($book) {
                return $book;
            }

            // Fallback: if we couldn't match by author relationship (or authors are missing),
            // but there is exactly one book with this title, treat it as the existing record.
            $titleMatches = Book::where('title', $bookData['title'])->limit(2)->get();
            if ($titleMatches->count() === 1) {
                return $titleMatches->first();
            }
        }

        return null;
    }

    /**
     * Prepare destination path for book
     */
    private function prepareDestinationPath(array $bookData, array $options): string
    {
        // If already in library, use existing path
        if (!empty($options['source_path'])) {
            $sourcePath = realpath($options['source_path']);
            $bookRootPath = realpath($this->bookRoot);

            if ($sourcePath && $bookRootPath && str_starts_with($sourcePath, $bookRootPath)) {
                // Already in library
                return str_replace($bookRootPath . '/', '', $sourcePath);
            }
        }

        // Generate new path
        return $this->generateDirectoryPath($bookData);
    }

    /**
     * Generate directory path based on book metadata
     */
    private function generateDirectoryPath(array $bookData): string
    {
        if ($this->genreMapper === null) {
            try {
                $this->genreMapper = app(GenreMappingService::class);
            } catch (\Throwable) {
                $this->genreMapper = null;
            }
        }

        // Map genre if we have genre mapper
        $genre = 'General Fiction';
        if (!empty($bookData['genre'])) {
            $genreValue = $bookData['genre'];
            if (is_array($genreValue)) {
                $genreValue = $genreValue[0] ?? '';
            }

            if ($this->genreMapper) {
                $genre = $this->genreMapper->mapToPrimaryGenre($genreValue);
            } else {
                $genreParts = is_array($genreValue)
                    ? $genreValue
                    : explode(':', (string) $genreValue);
                $genre = trim($genreParts[0]);
            }
        }

        $genre = $this->sanitizePath($genre);

        $authorValue = $bookData['author'] ?? 'Unknown Author';
        if (is_array($authorValue)) {
            $authorValue = $authorValue[0] ?? 'Unknown Author';
        }
        $author = $this->sanitizePath((string) $authorValue);

        $titleValue = $bookData['title_short'] ?? $bookData['title'] ?? 'Unknown Title';
        if (is_array($titleValue)) {
            $titleValue = $titleValue[0] ?? 'Unknown Title';
        }
        $title = $this->sanitizePath((string) $titleValue);

        // If part of series, organize by series
        if (!empty($bookData['series_name']) || !empty($bookData['series'])) {
            $seriesName = $bookData['series_name'] ?? $bookData['series'];
            $series = $this->sanitizePath($seriesName);
            $sequence = $bookData['series_sequence'] ?? $bookData['series_number'] ?? '';

            // Add sequence number prefix if available
            if ($sequence) {
                $title = str_pad($sequence, 2, '0', STR_PAD_LEFT) . ' ' . $title;
            }

            return "{$genre}/{$author}/{$series}/{$title}";
        }

        return "{$genre}/{$author}/{$title}";
    }

    /**
     * Sanitize path component
     */
    private function sanitizePath(string $path): string
    {
        // Remove invalid characters
        $path = preg_replace('/[<>:"|?*]/', '', $path);
        // Remove control characters
        $path = preg_replace('/[\x00-\x1F\x7F]/', '', $path);
        // Trim
        $path = trim($path);
        // Remove leading/trailing dots
        $path = trim($path, '.');

        return $path;
    }

    /**
     * Handle file operations (copy/move)
     */
    private function handleFileOperations(
        string $sourcePath,
        string $destPath,
        array $bookData,
        ?string $duplicateAction
    ): array {
        $fullDestPath = $this->bookRoot . '/' . $destPath;

        // Create destination directory
        if (!File::exists($fullDestPath)) {
            File::makeDirectory($fullDestPath, 0755, true);
        }

        $copiedFiles = [];

        // Handle duplicate action for audio files
        if ($duplicateAction === 'replace') {
            // Delete existing audio files
            $existingAudioFiles = glob($fullDestPath . '/*.{m4b,m4a,mp3}', GLOB_BRACE);
            foreach ($existingAudioFiles as $existingFile) {
                @unlink($existingFile);
            }
        }

        // Copy files
        if (is_dir($sourcePath)) {
            $files = File::files($sourcePath);
        } else {
            $files = [new \SplFileInfo($sourcePath)];
        }

        foreach ($files as $file) {
            $filename = $file->getFilename();
            $destFile = $fullDestPath . '/' . $filename;
            $ext = strtolower($file->getExtension());

            // Skip audio files if merge mode and file exists
            if (
                $duplicateAction === 'merge' &&
                in_array($ext, ['m4b', 'm4a', 'mp3']) &&
                file_exists($destFile)
            ) {
                continue;
            }

            // Copy file
            if (File::copy($file->getPathname(), $destFile)) {
                $copiedFiles[] = $filename;
                chmod($destFile, 0664);
            }
        }

        return $copiedFiles;
    }

    /**
     * Find or copy cover image
     */
    private function findOrCopyCoverImage(string $destPath, ?string $sourcePath, array $copiedFiles): ?string
    {
        // Check if cover was already copied
        foreach ($copiedFiles as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                if (stripos($file, 'cover') !== false) {
                    return trim($destPath, '/') . '/' . ltrim($file, '/');
                }
            }
        }

        // Use first image as fallback
        foreach ($copiedFiles as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                return trim($destPath, '/') . '/' . ltrim($file, '/');
            }
        }

        // Try to find in destination directory
        [$coverImage, $coverCandidates] = $this->findCoverImageCandidate($destPath);
        return $coverImage;
    }

    /**
     * Create new book record
     */
    private function createBook(array $bookData, string $destPath, array $copiedFiles): Book
    {
        $duration = $this->parseDuration($bookData);
        $totalSize = $this->calculateTotalSize($destPath, $copiedFiles);

        return Book::create([
            'title' => $bookData['title'] ?? 'Unknown Title',
            'directory_path' => $destPath,
            'description' => strip_tags($bookData['description'] ?? $bookData['summary'] ?? ''),
            'duration' => $duration,
            'audio_file_count' => count(array_filter($copiedFiles, fn ($f) => preg_match('/\.(m4b|m4a|mp3)$/i', $f))),
            'total_size' => $totalSize,
            'cover_image' => $bookData['cover_image'] ?? null,
            'asin' => $bookData['asin'] ?? $bookData['product_id'] ?? null,
            'release_date' => $bookData['release_date'] ?? null,
            'publisher' => $bookData['publisher'] ?? null,
            'language' => $bookData['language'] ?? 'en',
            'abridged' => ($bookData['abridged'] ?? 'false') === 'true',
        ]);
    }

    /**
     * Update existing book record
     */
    private function updateBook(Book $book, array $bookData, string $destPath, array $copiedFiles): Book
    {
        $duration = $this->parseDuration($bookData);
        $totalSize = $this->calculateTotalSize($destPath, $copiedFiles);

        $book->update([
            'directory_path' => $destPath,
            'description' => strip_tags($bookData['description'] ?? $bookData['summary'] ?? $book->description),
            'duration' => $duration ?: $book->duration,
            'audio_file_count' => count(array_filter($copiedFiles, fn ($f) => preg_match('/\.(m4b|m4a|mp3)$/i', $f))) ?: $book->audio_file_count,
            'total_size' => $totalSize ?: $book->total_size,
            'cover_image' => $bookData['cover_image'] ?? $book->cover_image,
            'asin' => $bookData['asin'] ?? $bookData['product_id'] ?? $book->asin,
            'release_date' => $bookData['release_date'] ?? $book->release_date,
            'publisher' => $bookData['publisher'] ?? $book->publisher,
            'language' => $bookData['language'] ?? $book->language,
            'abridged' => isset($bookData['abridged']) ? ($bookData['abridged'] === 'true') : $book->abridged,
        ]);

        return $book;
    }

    /**
     * Create book relationships (authors, narrators, genres, series)
     */
    private function createRelationships(Book $book, array $bookData): void
    {
        // Authors
        if (!empty($bookData['author'])) {
            $authorNames = is_array($bookData['author'])
                ? $bookData['author']
                : explode(',', $bookData['author']);

            $authorIds = [];
            foreach ($authorNames as $authorName) {
                $authorName = trim($authorName);
                if ($authorName) {
                    $author = Author::firstOrCreate(['name' => $authorName]);
                    $authorIds[] = $author->id;
                }
            }
            $book->authors()->sync($authorIds);
        }

        // Narrators
        if (!empty($bookData['narrated_by']) || !empty($bookData['narrator'])) {
            $narratorField = $bookData['narrated_by'] ?? $bookData['narrator'];
            $narratorNames = is_array($narratorField)
                ? $narratorField
                : explode(',', $narratorField);

            $narratorIds = [];
            foreach ($narratorNames as $narratorName) {
                $narratorName = trim($narratorName);
                if ($narratorName) {
                    $narrator = Narrator::firstOrCreate(['name' => $narratorName]);
                    $narratorIds[] = $narrator->id;
                }
            }
            $book->narrators()->sync($narratorIds);
        }

        // Genres with primary/secondary support
        if (!empty($bookData['genre'])) {
            $allGenres = $this->extractGenres($bookData['genre']);

            $genreData = [];
            foreach ($allGenres as $index => $genreName) {
                $genreName = trim($genreName);
                if ($genreName) {
                    $genre = Genre::firstOrCreate(['name' => $genreName]);
                    $genreData[$genre->id] = ['is_primary' => ($index === 0)];
                }
            }
            $book->genres()->sync($genreData);
        }

        // Series
        if (!empty($bookData['series_name']) || !empty($bookData['series'])) {
            $seriesName = $bookData['series_name'] ?? $bookData['series'];
            $series = Series::firstOrCreate(['name' => $seriesName]);
            $seriesNumber = $bookData['series_sequence'] ?? $bookData['series_number'] ?? null;

            $book->series()->sync([
                $series->id => [
                    'series_number' => $seriesNumber,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }

    /**
     * Extract genres from various formats
     */
    private function extractGenres($genreData): array
    {
        if (is_array($genreData)) {
            return $genreData;
        }

        if (is_string($genreData)) {
            // Handle colon-separated genres (OpenAudible format)
            if (str_contains($genreData, ':')) {
                return array_map('trim', explode(':', $genreData));
            }

            // Handle comma-separated genres
            if (str_contains($genreData, ',')) {
                return array_map('trim', explode(',', $genreData));
            }

            return [$genreData];
        }

        return [];
    }

    /**
     * Parse duration from various formats
     */
    private function parseDuration(array $bookData): ?int
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
     * Calculate total size of files
     */
    private function calculateTotalSize(string $destPath, array $copiedFiles): int
    {
        $totalSize = 0;
        $fullPath = $this->bookRoot . '/' . $destPath;

        foreach ($copiedFiles as $file) {
            $filePath = $fullPath . '/' . $file;
            if (file_exists($filePath)) {
                $totalSize += filesize($filePath);
            }
        }

        return $totalSize;
    }
}
