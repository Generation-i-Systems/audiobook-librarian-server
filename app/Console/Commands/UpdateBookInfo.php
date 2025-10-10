<?php

namespace App\Console\Commands;

use App\Models\Book;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class UpdateBookInfo extends Command
{
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
                // URL - store as-is
                $book->coverImage = $coverPath;
                $this->info("✓ Updated cover image URL: {$coverPath}");
                $updated = true;
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
            $book->publisher = $this->option('publisher');
            $this->info("✓ Updated publisher: {$book->publisher}");
            $updated = true;
        }

        // Update language
        if ($this->option('language')) {
            $book->language = $this->option('language');
            $this->info("✓ Updated language: {$book->language}");
            $updated = true;
        }

        // Update release date
        if ($this->option('release-date')) {
            $date = $this->option('release-date');
            try {
                $book->releaseDate = new \DateTime($date);
                $this->info("✓ Updated release date: {$book->releaseDate->format('Y-m-d')}");
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
        $this->newLine();
        $this->info("Book information updated successfully!");

        return Command::SUCCESS;
    }
}
