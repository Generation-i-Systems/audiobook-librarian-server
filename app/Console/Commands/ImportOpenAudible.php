<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Series;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ImportOpenAudible extends Command
{
    protected $signature = 'books:import-openaudible
                            {--source=/media/audiobooks/OpenAudible : OpenAudible directory}
                            {--dry-run : Show what would be imported without making changes}
                            {--include-old : Also import books from books_old directory}
                            {--force : Reimport books that already exist}
                            {--limit= : Limit number of books to import}';

    protected $description = 'Import books from OpenAudible with full metadata';

    private string $bookRoot;
    private DocumentStoreServiceInterface $documentStore;
    private array $stats = [
        'total' => 0,
        'imported' => 0,
        'skipped' => 0,
        'errors' => 0,
        'updated' => 0,
    ];

    public function __construct(DocumentStoreServiceInterface $documentStore)
    {
        parent::__construct();
        $this->documentStore = $documentStore;
        $this->bookRoot = rtrim(env('BOOK_STORAGE_PATH'), '/');
    }

    public function handle(): int
    {
        $source = rtrim($this->option('source'), '/');
        $dryRun = $this->option('dry-run');
        $includeOld = $this->option('include-old');
        $force = $this->option('force');
        $limit = $this->option('limit');

        // CRITICAL SAFETY: Validate source directory
        if (!is_dir($source)) {
            $this->error("Source directory does not exist: {$source}");
            return 1;
        }

        // CRITICAL SAFETY: Validate books.json exists
        $booksJsonPath = $source . '/books.json';
        if (!file_exists($booksJsonPath)) {
            $this->error("books.json not found: {$booksJsonPath}");
            return 1;
        }

        // CRITICAL SAFETY: Validate book root
        if (!is_dir($this->bookRoot)) {
            $this->error("Book root does not exist: {$this->bookRoot}");
            return 1;
        }

        if (!is_writable($this->bookRoot)) {
            $this->error("Book root is not writable: {$this->bookRoot}");
            return 1;
        }

        // CRITICAL SAFETY: Validate database connection
        try {
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            $this->error("Database connection failed: " . $e->getMessage());
            return 1;
        }

        $this->info("Loading OpenAudible metadata...");
        
        // Load books.json
        try {
            $booksData = json_decode(file_get_contents($booksJsonPath), true);
            
            if (!is_array($booksData)) {
                $this->error("Invalid books.json format");
                return 1;
            }
            
            $this->stats['total'] = count($booksData);
            $this->info("Found {$this->stats['total']} books in metadata");
            
        } catch (\Exception $e) {
            $this->error("Failed to parse books.json: " . $e->getMessage());
            return 1;
        }

        // Apply limit if specified
        if ($limit) {
            $booksData = array_slice($booksData, 0, (int)$limit);
            $this->info("Limited to {$limit} books");
        }

        if ($dryRun) {
            $this->warn("=== DRY RUN MODE ===");
        }

        // Process each book
        $this->info("\nProcessing books...");
        $progressBar = $this->output->createProgressBar(count($booksData));
        $progressBar->start();

        foreach ($booksData as $bookData) {
            try {
                $this->processBook($bookData, $source, $dryRun, $force, $includeOld);
            } catch (\Exception $e) {
                $this->stats['errors']++;
                Log::error('OpenAudible import error', [
                    'book' => $bookData['title'] ?? 'Unknown',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                if (!$dryRun) {
                    $this->newLine();
                    $this->error("Error importing '{$bookData['title']}': " . $e->getMessage());
                }
            }
            
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Display summary
        $this->displaySummary($dryRun);

        return 0;
    }

    private function processBook(array $bookData, string $source, bool $dryRun, bool $force, bool $includeOld): void
    {
        // Extract metadata
        $title = $bookData['title'] ?? null;
        $asin = $bookData['asin'] ?? $bookData['product_id'] ?? null;
        
        if (!$title) {
            $this->stats['skipped']++;
            return;
        }

        // Check if book already exists
        $existingBook = null;
        if ($asin) {
            $existingBook = Book::where('asin', $asin)->first();
        }
        
        if (!$existingBook && $title) {
            $existingBook = Book::where('title', $title)->first();
        }

        if ($existingBook && !$force) {
            $this->stats['skipped']++;
            return;
        }

        // Find audio file
        $audioFile = $this->findAudioFile($bookData, $source, $includeOld);
        
        if (!$audioFile) {
            $this->stats['skipped']++;
            return;
        }

        // CRITICAL SAFETY: Validate audio file exists
        if (!file_exists($audioFile)) {
            $this->stats['skipped']++;
            return;
        }

        // Prepare destination directory
        $destDir = $this->prepareDestinationDirectory($bookData);
        $destPath = $this->bookRoot . '/' . $destDir;

        if ($dryRun) {
            if ($existingBook) {
                $this->stats['updated']++;
            } else {
                $this->stats['imported']++;
            }
            return;
        }

        // CRITICAL SAFETY: Use transaction
        DB::beginTransaction();
        
        try {
            // Create destination directory
            if (!File::exists($destPath)) {
                File::makeDirectory($destPath, 0755, true);
            }

            // Copy audio file and associated files
            $copiedFiles = $this->copyBookFiles($audioFile, $destPath, $bookData, $source);
            
            if (empty($copiedFiles)) {
                throw new \Exception("Failed to copy any files");
            }

            // Create or update book record
            if ($existingBook) {
                $book = $this->updateBookRecord($existingBook, $bookData, $destDir, $copiedFiles);
                $this->stats['updated']++;
            } else {
                $book = $this->createBookRecord($bookData, $destDir, $copiedFiles);
                $this->stats['imported']++;
            }

            // Create relationships
            $this->createRelationships($book, $bookData);

            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            // CRITICAL SAFETY: Clean up copied files on error
            if (isset($copiedFiles)) {
                foreach ($copiedFiles as $file) {
                    @unlink($destPath . '/' . basename($file));
                }
            }
            
            throw $e;
        }
    }

    private function findAudioFile(array $bookData, string $source, bool $includeOld): ?string
    {
        // Try to find audio file from metadata
        if (!empty($bookData['files'])) {
            foreach ($bookData['files'] as $file) {
                if ($file['kind'] === 'audio' && $file['type'] === 'M4B') {
                    $path = $source . '/books/' . $file['path'];
                    if (file_exists($path)) {
                        return $path;
                    }
                    
                    // Try books_old if enabled
                    if ($includeOld) {
                        $oldPath = $source . '/books_old/' . $file['path'];
                        if (file_exists($oldPath)) {
                            return $oldPath;
                        }
                    }
                }
            }
        }

        // Fallback: try to find by filename
        $filename = $bookData['filename'] ?? $bookData['title'];
        if ($filename) {
            $m4bFile = $source . '/books/' . $filename . '.m4b';
            if (file_exists($m4bFile)) {
                return $m4bFile;
            }
            
            if ($includeOld) {
                $oldM4bFile = $source . '/books_old/' . $filename . '.m4b';
                if (file_exists($oldM4bFile)) {
                    return $oldM4bFile;
                }
            }
        }

        return null;
    }

    private function prepareDestinationDirectory(array $bookData): string
    {
        // Extract genre (use first genre from hierarchy)
        $genre = 'General Fiction'; // Default
        if (!empty($bookData['genre'])) {
            $genreParts = explode(':', $bookData['genre']);
            $genre = $this->sanitizePath(trim($genreParts[0]));
        }
        
        $author = $this->sanitizePath($bookData['author'] ?? 'Unknown Author');
        $title = $this->sanitizePath($bookData['title_short'] ?? $bookData['title'] ?? 'Unknown Title');
        
        // If part of series, organize by series
        if (!empty($bookData['series_name'])) {
            $series = $this->sanitizePath($bookData['series_name']);
            $sequence = $bookData['series_sequence'] ?? '';
            
            // Add sequence number prefix if available
            if ($sequence) {
                $title = str_pad($sequence, 2, '0', STR_PAD_LEFT) . ' ' . $title;
            }
            
            return "{$genre}/{$author}/{$series}/{$title}";
        }

        return "{$genre}/{$author}/{$title}";
    }

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

    private function copyBookFiles(string $audioFile, string $destPath, array $bookData, string $source): array
    {
        $copiedFiles = [];
        
        // Copy main audio file
        $audioFilename = basename($audioFile);
        $destAudioFile = $destPath . '/' . $audioFilename;
        
        if (!copy($audioFile, $destAudioFile)) {
            throw new \Exception("Failed to copy audio file");
        }
        
        $copiedFiles[] = $audioFilename;

        // Copy associated files (cover image, PDFs, etc.)
        if (!empty($bookData['files'])) {
            foreach ($bookData['files'] as $file) {
                if ($file['kind'] === 'image' || $file['type'] === 'PDF') {
                    $srcFile = $source . '/books/' . $file['path'];
                    
                    if (!file_exists($srcFile)) {
                        $srcFile = $source . '/books_old/' . $file['path'];
                    }
                    
                    if (file_exists($srcFile)) {
                        $filename = basename($file['path']);
                        $destFile = $destPath . '/' . $filename;
                        
                        if (@copy($srcFile, $destFile)) {
                            $copiedFiles[] = $filename;
                        }
                    }
                }
            }
        }

        return $copiedFiles;
    }

    private function createBookRecord(array $bookData, string $destDir, array $copiedFiles): Book
    {
        // Parse duration
        $duration = $this->parseDuration($bookData);
        
        // Find cover image
        $coverImage = null;
        foreach ($copiedFiles as $file) {
            if (preg_match('/\.(jpg|jpeg|png)$/i', $file)) {
                $coverImage = $destDir . '/' . $file;
                break;
            }
        }

        return Book::create([
            'directory_path' => $destDir,
            'title' => $bookData['title'],
            'description' => strip_tags($bookData['description'] ?? $bookData['summary'] ?? ''),
            'duration' => $duration,
            'audio_file_count' => count(array_filter($copiedFiles, fn($f) => str_ends_with($f, '.m4b'))),
            'total_size' => $this->calculateTotalSize($destDir, $copiedFiles),
            'cover_image' => $coverImage,
            'asin' => $bookData['asin'] ?? $bookData['product_id'] ?? null,
            'release_date' => $bookData['release_date'] ?? null,
            'publisher' => $bookData['publisher'] ?? null,
            'language' => $bookData['language'] ?? 'english',
            'abridged' => ($bookData['abridged'] ?? 'false') === 'true',
            'needs_review' => false,
        ]);
    }

    private function updateBookRecord(Book $book, array $bookData, string $destDir, array $copiedFiles): Book
    {
        $duration = $this->parseDuration($bookData);
        
        $coverImage = null;
        foreach ($copiedFiles as $file) {
            if (preg_match('/\.(jpg|jpeg|png)$/i', $file)) {
                $coverImage = $destDir . '/' . $file;
                break;
            }
        }

        $book->update([
            'directory_path' => $destDir,
            'description' => strip_tags($bookData['description'] ?? $bookData['summary'] ?? $book->description),
            'duration' => $duration ?: $book->duration,
            'audio_file_count' => count(array_filter($copiedFiles, fn($f) => str_ends_with($f, '.m4b'))),
            'total_size' => $this->calculateTotalSize($destDir, $copiedFiles),
            'cover_image' => $coverImage ?: $book->cover_image,
            'asin' => $bookData['asin'] ?? $bookData['product_id'] ?? $book->asin,
            'release_date' => $bookData['release_date'] ?? $book->release_date,
            'publisher' => $bookData['publisher'] ?? $book->publisher,
            'language' => $bookData['language'] ?? $book->language,
            'abridged' => ($bookData['abridged'] ?? 'false') === 'true',
        ]);

        return $book;
    }

    private function createRelationships(Book $book, array $bookData): void
    {
        // Authors
        if (!empty($bookData['author'])) {
            $authorNames = explode(',', $bookData['author']);
            foreach ($authorNames as $authorName) {
                $authorName = trim($authorName);
                $author = Author::firstOrCreate(['name' => $authorName]);
                
                if (!$book->authors()->where('author_id', $author->id)->exists()) {
                    $book->authors()->attach($author->id);
                }
            }
        }

        // Narrators
        if (!empty($bookData['narrated_by'])) {
            $narratorNames = explode(',', $bookData['narrated_by']);
            foreach ($narratorNames as $narratorName) {
                $narratorName = trim($narratorName);
                $narrator = Narrator::firstOrCreate(['name' => $narratorName]);
                
                if (!$book->narrators()->where('narrator_id', $narrator->id)->exists()) {
                    $book->narrators()->attach($narrator->id);
                }
            }
        }

        // Genres
        if (!empty($bookData['genre'])) {
            $genreNames = explode(':', $bookData['genre']);
            foreach ($genreNames as $genreName) {
                $genreName = trim($genreName);
                if ($genreName) {
                    $genre = Genre::firstOrCreate(['name' => $genreName]);
                    
                    if (!$book->genres()->where('genre_id', $genre->id)->exists()) {
                        $book->genres()->attach($genre->id);
                    }
                }
            }
        }

        // Series
        if (!empty($bookData['series_name'])) {
            $series = Series::firstOrCreate(['name' => $bookData['series_name']]);
            $seriesNumber = $bookData['series_sequence'] ?? null;
            
            if (!$book->series()->where('series_id', $series->id)->exists()) {
                $book->series()->attach($series->id, [
                    'series_number' => $seriesNumber,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function parseDuration(array $bookData): ?int
    {
        if (!empty($bookData['seconds'])) {
            return (int)$bookData['seconds'];
        }

        if (!empty($bookData['duration'])) {
            // Parse HH:MM:SS format
            $parts = explode(':', $bookData['duration']);
            if (count($parts) === 3) {
                return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
            }
        }

        return null;
    }

    private function calculateTotalSize(string $destDir, array $copiedFiles): int
    {
        $totalSize = 0;
        $fullPath = $this->bookRoot . '/' . $destDir;
        
        foreach ($copiedFiles as $file) {
            $filePath = $fullPath . '/' . $file;
            if (file_exists($filePath)) {
                $totalSize += filesize($filePath);
            }
        }

        return $totalSize;
    }

    private function displaySummary(bool $dryRun): void
    {
        $this->info("=== Import Summary ===");
        $this->info("Total books in metadata: {$this->stats['total']}");
        $this->info("Imported: {$this->stats['imported']}");
        $this->info("Updated: {$this->stats['updated']}");
        $this->info("Skipped: {$this->stats['skipped']}");
        $this->info("Errors: {$this->stats['errors']}");

        if ($dryRun) {
            $this->warn("\nThis was a dry run. No changes were made.");
        }
    }
}
