<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Traits\HandlesLibraryJson;
use Illuminate\Console\Command;

class UpdateBookInfo extends Command
{
    use HandlesLibraryJson;

    protected $signature = 'books:update {directory}
                            {--cover= : Path to cover image file or URL}
                            {--title= : Book title}
                            {--publisher= : Publisher name}
                            {--language= : Language code (e.g., en, es)}
                            {--release-date= : Release date (YYYY-MM-DD format)}
                            {--description= : Book description}
                            {--source= : Source of the book data}';

    protected $description = 'Update book information in the database';

    public function handle(): int
    {
        $directory = $this->argument('directory');
        $directory = realpath($directory);

        if (!$directory || !is_dir($directory)) {
            $this->error("Directory not found: {$directory}");
            return Command::FAILURE;
        }

        // Find the book
        $bookRoot = config('app.book_root', '/media/lyra_data1/audiobooks/books');
        $searchPath = $directory;

        if (str_starts_with($directory, $bookRoot)) {
            $searchPath = ltrim(substr($directory, strlen($bookRoot)), '/');
        }

        $book = Book::where('directory_path', $searchPath)->first();

        if (!$book) {
            $book = Book::where('directory_path', $directory)->first();
        }

        if (!$book) {
            $this->error("No book found in database for directory: {$directory}");
            return Command::FAILURE;
        }

        $this->info("Updating book: {$book->title} (ID: {$book->id})");
        $this->newLine();

        $updated = false;

        // Update cover image
        if ($this->option('cover')) {
            $coverPath = $this->option('cover');

            if (filter_var($coverPath, FILTER_VALIDATE_URL)) {
                // URL - download it and save to book directory
                $this->info("Downloading cover image from URL...");

                try {
                    // Use stream context to follow redirects
                    $context = stream_context_create([
                        'http' => [
                            'follow_location' => true,
                            'max_redirects' => 10,
                            'timeout' => 30,
                            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                        ],
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false
                        ]
                    ]);

                    $imageData = file_get_contents($coverPath, false, $context);
                    if ($imageData === false) {
                        throw new \Exception("Failed to download image");
                    }

                    // Validate it's actually an image
                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                    $mimeType = $finfo->buffer($imageData);
                    if (!str_starts_with($mimeType, 'image/')) {
                        throw new \Exception("Downloaded content is not an image (got: {$mimeType})");
                    }

                    // Determine file extension from URL or content type
                    $extension = 'jpg';
                    $urlPath = parse_url($coverPath, PHP_URL_PATH);
                    if ($urlPath && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $urlPath, $matches)) {
                        $extension = strtolower($matches[1]);
                    }

                    // Save to book directory
                    $bookDirectory = $bookRoot . '/' . $book->directory_path;
                    $coverFilename = 'cover.' . $extension;
                    $coverFullPath = $bookDirectory . '/' . $coverFilename;

                    if (!is_dir($bookDirectory)) {
                        $this->error("Book directory not found: {$bookDirectory}");
                        return Command::FAILURE;
                    }

                    if (file_put_contents($coverFullPath, $imageData) === false) {
                        throw new \Exception("Failed to save image file");
                    }

                    chmod($coverFullPath, 0664);

                    // Store relative path
                    $relativePath = $book->directory_path . '/' . $coverFilename;
                    $book->coverImage = $relativePath;
                    $this->info("✓ Downloaded and saved cover image: {$relativePath}");
                    $updated = true;
                } catch (\Exception $e) {
                    $this->error("Failed to download cover image: " . $e->getMessage());
                    return Command::FAILURE;
                }
            } elseif (file_exists($coverPath)) {
                // Local file - make path relative to book root
                $realCoverPath = realpath($coverPath);

                if (str_starts_with($realCoverPath, $bookRoot)) {
                    $relativePath = ltrim(substr($realCoverPath, strlen($bookRoot)), '/');
                    $book->coverImage = $relativePath;
                    $this->info("✓ Updated cover image: {$relativePath}");
                    $updated = true;
                } else {
                    $this->warn("Cover image is outside book root directory");
                    $book->coverImage = $realCoverPath;
                    $this->info("✓ Updated cover image (absolute path): {$realCoverPath}");
                    $updated = true;
                }
            } else {
                $this->error("Cover image file not found: {$coverPath}");
                return Command::FAILURE;
            }
        }

        // Update title
        if ($this->option('title')) {
            $book->title = $this->option('title');
            $this->info("✓ Updated title: {$book->title}");
            $updated = true;
        }

        // Update publisher
        if ($this->option('publisher')) {
            $publisherName = (string) $this->option('publisher');
            $publisher = \App\Models\Publisher::firstOrCreate(['name' => $publisherName]);
            $book->publisher()->associate($publisher);
            $this->info("✓ Updated publisher: {$publisherName}");
            $updated = true;
        }

        // Update language
        if ($this->option('language')) {
            $book->language = (string) $this->option('language');
            $this->info("✓ Updated language: {$book->language}");
            $updated = true;
        }

        // Update release date
        if ($this->option('release-date')) {
            $date = (string) $this->option('release-date');
            try {
                $book->release_date = $date;
                $this->info("✓ Updated release date: {$date}");
                $updated = true;
            } catch (\Exception $e) {
                $this->error("Invalid date format: {$date}");
                return Command::FAILURE;
            }
        }

        // Update description
        if ($this->option('description')) {
            $book->description = $this->option('description');
            $this->info("✓ Updated description");
            $updated = true;
        }

        // Update source
        if ($this->option('source')) {
            $book->source = $this->option('source');
            $this->info("✓ Updated source: {$book->source}");
            $updated = true;
        }

        if (!$updated) {
            $this->warn("No fields were updated. Use --help to see available options.");
            return Command::SUCCESS;
        }

        $book->save();

        // Update librarian.json with the new information
        try {
            $book->load(['authors', 'narrators', 'genres', 'series', 'publisher']);
            $this->updateLibraryJson($book);
            $this->info("✓ Updated librarian.json");
        } catch (\Exception $e) {
            $this->warn("Failed to update librarian.json: " . $e->getMessage());
        }

        $this->newLine();
        $this->info("Book information updated successfully!");

        return Command::SUCCESS;
    }
}
