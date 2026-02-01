<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Series;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ImportCollectionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'books:import-collection
                            {--collection=Top 100-ish Sci-Fi Books : Collection name}
                            {--path=/media/lyra_data1/audiobooks/books/Science Fiction/VA/Top 100-ish Sci-Fi Books : Source directory path}
                            {--dry-run : Show what would be done without making changes}
                            {--books=* : Specific book directories to import (for testing)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import books from a collection directory with special naming format: [number] - [title] - [author] - [year]';

    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        parent::__construct();
        $this->documentStoreService = $documentStoreService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $collectionName = $this->option('collection');
        $sourcePath = $this->option('path');
        $dryRun = $this->option('dry-run');
        $specificBooks = $this->option('books');

        if (!File::exists($sourcePath)) {
            $this->error("Source path does not exist: {$sourcePath}");
            return 1;
        }

        $this->info("Collection Import Tool");
        $this->info("Collection: {$collectionName}");
        $this->info("Source: {$sourcePath}");
        $this->info("Mode: " . ($dryRun ? 'DRY RUN' : 'LIVE'));
        $this->newLine();

        // Get all subdirectories
        $directories = File::directories($sourcePath);

        if (empty($directories)) {
            $this->warn('No subdirectories found in source path.');
            return 0;
        }

        // Filter to specific books if provided
        if (!empty($specificBooks)) {
            $directories = array_filter($directories, function ($dir) use ($specificBooks) {
                $basename = basename($dir);
                foreach ($specificBooks as $book) {
                    if (str_contains($basename, $book)) {
                        return true;
                    }
                }
                return false;
            });
        }

        $this->info("Found " . count($directories) . " book(s) to process");
        $this->newLine();

        $processed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($directories as $directory) {
            $basename = basename($directory);

            try {
                $parsed = $this->parseDirectoryName($basename);

                if (!$parsed) {
                    $this->warn("Skipping (invalid format): {$basename}");
                    $skipped++;
                    continue;
                }

                $this->line("\n" . str_repeat('=', 80));
                $this->info("Processing: {$basename}");
                $this->line(str_repeat('=', 80));

                $this->table(
                    ['Field', 'Value'],
                    [
                        ['Collection #', $parsed['collection_number']],
                        ['Title', $parsed['title']],
                        ['Author(s)', $parsed['author']],
                        ['Year', $parsed['year']],
                    ]
                );

                // Calculate target path
                $targetPath = $this->calculateTargetPath($parsed);
                $this->info("Source: {$directory}");
                $this->info("Target: {$targetPath}");

                if (!$dryRun) {
                    // Move directory
                    if (!$this->moveDirectory($directory, $targetPath)) {
                        $this->error("Failed to move directory");
                        $errors++;
                        continue;
                    }

                    // Import book
                    $bookId = $this->importBook($targetPath, $parsed, $collectionName);

                    if ($bookId) {
                        $this->info("✓ Successfully imported book ID: {$bookId}");
                        $processed++;
                    } else {
                        $this->error("✗ Failed to import book");
                        $errors++;
                    }
                } else {
                    $this->comment("[DRY RUN] Would move and import this book");
                    $processed++;
                }
            } catch (\Exception $e) {
                $this->error("Error processing {$basename}: " . $e->getMessage());
                Log::error('Collection import error', [
                    'directory' => $basename,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $errors++;
            }
        }

        $this->newLine();
        $this->line(str_repeat('=', 80));
        $this->info("Import Summary");
        $this->line(str_repeat('=', 80));
        $this->info("Processed: {$processed}");
        $this->warn("Skipped: {$skipped}");
        $this->error("Errors: {$errors}");

        return $errors > 0 ? 1 : 0;
    }

    /**
     * Parse directory name in format: [number] - [title] - [author] - [year]
     */
    protected function parseDirectoryName(string $dirname): ?array
    {
        // Pattern: "82 - The Lathe of Heaven - Ursula K Le Guin - 1971"
        if (!preg_match('/^(\d+)\s*-\s*(.+?)\s*-\s*(.+?)\s*-\s*(\d{4})$/', $dirname, $matches)) {
            return null;
        }

        return [
            'collection_number' => (int) $matches[1],
            'title' => trim($matches[2]),
            'author' => trim($matches[3]),
            'year' => (int) $matches[4],
        ];
    }

    /**
     * Calculate target path based on author and title
     */
    protected function calculateTargetPath(array $parsed): string
    {
        $bookRoot = rtrim(config('library.paths.books', '/media/lyra_data1/audiobooks/books'), '/');

        // Determine genre (for this collection, it's Science Fiction)
        $genre = 'Science Fiction';

        // Get first author for directory structure
        $authors = $this->parseAuthors($parsed['author']);
        $primaryAuthor = $authors[0] ?? 'Unknown';

        // Get author last name for sorting
        $authorParts = explode(' ', $primaryAuthor);
        $lastName = end($authorParts);
        $firstLetter = strtoupper(substr($lastName, 0, 1));

        // Build path: /genre/FirstLetter/Author Name/Book Title
        $authorDir = $this->sanitizePathComponent($primaryAuthor);
        $titleDir = $this->sanitizePathComponent($parsed['title']);

        return "{$bookRoot}/{$genre}/{$firstLetter}/{$authorDir}/{$titleDir}";
    }

    /**
     * Parse author string which may contain multiple authors separated by &, and, or ,
     */
    protected function parseAuthors(string $authorString): array
    {
        // Split by common separators
        $authors = preg_split('/\s*(?:&|\band\b|,)\s*/i', $authorString);

        return array_filter(array_map('trim', $authors));
    }

    /**
     * Sanitize path component for filesystem
     */
    protected function sanitizePathComponent(string $component): string
    {
        // Remove or replace invalid characters
        $component = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $component);
        $component = preg_replace('/\s+/', ' ', $component);
        return trim($component);
    }

    /**
     * Move directory to target location
     */
    protected function moveDirectory(string $source, string $target): bool
    {
        if (File::exists($target)) {
            $this->warn("Target already exists: {$target}");
            return false;
        }

        // Create parent directory if needed
        $parentDir = dirname($target);
        if (!File::exists($parentDir)) {
            File::makeDirectory($parentDir, 0755, true);
        }

        return File::move($source, $target);
    }

    /**
     * Import book with metadata and enrichment
     */
    protected function importBook(string $path, array $parsed, string $collectionName): ?string
    {
        try {
            $relativePath = str_replace(
                rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/') . '/',
                '',
                $path
            );

            // Parse authors
            $authors = $this->parseAuthors($parsed['author']);

            // Create book data
            $bookData = [
                'id' => (string) Str::uuid(),
                'title' => $parsed['title'],
                'directory_path' => $relativePath,
                'release_date' => $parsed['year'] . '-01-01',
                'published_year' => $parsed['year'],
                'directory_exists' => true,
            ];

            // Process authors
            $authorIds = [];
            foreach ($authors as $authorName) {
                $author = Author::firstOrCreate(['name' => $authorName]);
                $authorIds[] = $author->id;
            }

            // Process genre (Science Fiction for this collection)
            $genre = Genre::firstOrCreate(['name' => 'Science Fiction']);

            // Process collection as series
            $series = Series::firstOrCreate(
                ['name' => $collectionName],
                ['is_collection' => true]
            );

            // If series exists but not marked as collection, update it
            if (!$series->is_collection) {
                $series->is_collection = true;
                $series->save();
            }

            // Try to get cover image and additional metadata
            $enrichment = $this->enrichMetadata($parsed['title'], $authors[0] ?? null);

            if ($enrichment) {
                $bookData = array_merge($bookData, $enrichment);
            }

            // Create book
            $book = Book::create($bookData);

            // Attach relationships
            $book->authors()->attach($authorIds);
            $book->genres()->attach($genre->id);
            $book->series()->attach($series->id, ['series_number' => $parsed['collection_number']]);

            $this->info("Book created with enriched metadata");

            return (string) $book->id;
        } catch (\Exception $e) {
            Log::error('Failed to import book', [
                'path' => $path,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Enrich metadata using external APIs (Google Books, Open Library, etc.)
     */
    protected function enrichMetadata(string $title, ?string $author): ?array
    {
        $this->comment("Attempting to enrich metadata...");

        try {
            // Try Google Books API
            $query = urlencode($title . ($author ? ' ' . $author : ''));
            $response = Http::timeout(10)->get("https://www.googleapis.com/books/v1/volumes?q={$query}&maxResults=1");

            if ($response->successful() && $response->json('totalItems') > 0) {
                $book = $response->json('items.0.volumeInfo');

                $enrichment = [];

                if (!empty($book['description'])) {
                    $enrichment['description'] = $book['description'];
                }

                if (!empty($book['publisher'])) {
                    $enrichment['publisher'] = $book['publisher'];
                }

                if (!empty($book['industryIdentifiers'])) {
                    foreach ($book['industryIdentifiers'] as $identifier) {
                        if ($identifier['type'] === 'ISBN_13') {
                            $enrichment['isbn'] = $identifier['identifier'];
                        }
                    }
                }

                // Download cover image if available
                if (!empty($book['imageLinks']['thumbnail'])) {
                    $coverUrl = str_replace('http://', 'https://', $book['imageLinks']['thumbnail']);
                    $coverPath = $this->downloadCoverImage($coverUrl, $title);
                    if ($coverPath) {
                        $enrichment['cover_image'] = $coverPath;
                    }
                }

                $this->info("✓ Metadata enriched from Google Books");
                return $enrichment;
            }
        } catch (\Exception $e) {
            $this->warn("Could not enrich metadata: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Download and save cover image
     */
    protected function downloadCoverImage(string $url, string $title): ?string
    {
        try {
            $response = Http::timeout(10)->get($url);

            if ($response->successful()) {
                $filename = Str::slug($title) . '.jpg';
                $path = 'covers/' . $filename;
                $fullPath = storage_path('app/public/' . $path);

                // Ensure directory exists
                File::ensureDirectoryExists(dirname($fullPath));

                File::put($fullPath, $response->body());

                return $path;
            }
        } catch (\Exception $e) {
            $this->warn("Could not download cover: " . $e->getMessage());
        }

        return null;
    }
}
