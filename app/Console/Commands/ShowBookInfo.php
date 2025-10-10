<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\TerminalImageService;
use Illuminate\Console\Command;

class ShowBookInfo extends Command
{
    protected $signature = 'books:show {directories?*}';

    protected $description = 'Display book information from database with terminal graphics';

    public function __construct(
        protected TerminalImageService $terminalImageService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $directories = $this->argument('directories');

        if (empty($directories)) {
            $directories = [getcwd()];
        }

        foreach ($directories as $directory) {
            $directory = realpath($directory);

            if (!$directory || !is_dir($directory)) {
                $this->error("Directory not found: {$directory}");
                continue;
            }

            $this->showBookFromDirectory($directory);
        }

        return Command::SUCCESS;
    }

    protected function showBookFromDirectory(string $directory): void
    {
        $bookRoot = config('app.book_root', '/media/lyra_data1/audiobooks/books');
        $searchPath = $directory;

        if (str_starts_with($directory, $bookRoot)) {
            $searchPath = ltrim(substr($directory, strlen($bookRoot)), '/');
        }

        $book = Book::where('directory_path', $searchPath)->first();
        $exactMatch = (bool) $book;

        if (!$book) {
            $book = Book::where('directory_path', $directory)->first();
            $exactMatch = (bool) $book;
        }

        if ($book) {
            $this->displayBookInfo($book);
            $this->newLine();
            return;
        }

        $books = Book::where('directory_path', 'LIKE', $searchPath . '%')->get();
        $isFuzzyMatch = false;

        if ($books->isEmpty()) {
            $books = Book::where('directory_path', 'LIKE', '%' . basename($searchPath) . '%')
                ->where('directory_path', 'LIKE', dirname($searchPath) . '%')
                ->get();
            $isFuzzyMatch = $books->isNotEmpty();
        }

        if ($books->isEmpty()) {
            $this->error("No books found in database for directory: {$directory}");
            $this->newLine();
            return;
        }

        if ($books->count() === 1 && $isFuzzyMatch) {
            $book = $books->first();
            $this->warn("Found book with mismatched path:");
            $this->line("  Database: {$book->directoryPath}");
            $this->line("  Actual:   {$searchPath}");
            $this->newLine();

            if ($this->confirm('Update database to match actual directory path?', true)) {
                $oldPath = $book->directoryPath;
                $book->directoryPath = $searchPath;
                $book->save();
                $this->info("✓ Updated directory path from '{$oldPath}' to '{$searchPath}'");
                $this->newLine();
            }
        } else {
            $this->info("Found {$books->count()} book(s) matching: {$directory}");
            $this->newLine();
        }

        foreach ($books as $book) {
            $this->displayBookInfo($book);
            $this->newLine();
        }
    }

    protected function displayBookInfo(Book $book): void
    {
        $this->info("═══════════════════════════════════════════════════════════════");
        $this->info("  BOOK INFORMATION");
        $this->info("═══════════════════════════════════════════════════════════════");
        $this->newLine();

        $tableData = [];

        $tableData[] = ['ID', $book->id];
        $tableData[] = ['Title', $book->title ?? 'N/A'];

        if ($book->authors()->count() > 0) {
            $authors = $book->authors()->pluck('name')->join(', ');
            $tableData[] = ['Authors', $authors];
        }

        if ($book->narrators()->count() > 0) {
            $narrators = $book->narrators()->pluck('name')->join(', ');
            $tableData[] = ['Narrators', $narrators];
        }

        if ($book->series()->count() > 0) {
            $seriesInfo = $book->series()->get()->map(function ($series) {
                $number = $series->pivot->series_number;
                return "{$series->name}" . ($number ? " #{$number}" : '');
            })->join(', ');
            $tableData[] = ['Series', $seriesInfo];
        }

        if ($book->genres()->count() > 0) {
            $genres = $book->genres()->pluck('name')->join(', ');
            $tableData[] = ['Genres', $genres];
        }

        $tableData[] = ['Publisher', $book->publisher ?? 'N/A'];
        $tableData[] = ['Release Date', $book->releaseDate?->format('Y-m-d') ?? 'N/A'];
        $tableData[] = ['Language', $book->language ?? 'N/A'];

        if ($book->duration) {
            $hours = floor($book->duration / 3600);
            $minutes = floor(($book->duration % 3600) / 60);
            $durationStr = "{$hours}h {$minutes}m";
            $tableData[] = ['Duration', $durationStr];
        }

        $tableData[] = ['Audio Files', $book->audioFileCount ?? 0];
        $tableData[] = ['Source', $book->source ?? 'N/A'];
        $tableData[] = ['Directory', $book->directoryPath ?? 'N/A'];

        if ($book->needsReview) {
            $reasons = $book->needsReviewReasons ? implode(', ', $book->needsReviewReasons) : 'Unknown';
            $tableData[] = ['Needs Review', "Yes ({$reasons})"];
        }

        if ($book->description) {
            $tableData[] = ['Description', $this->truncateText($book->description, 200)];
        }

        if ($book->audibleInfo) {
            $tableData[] = ['Audible ASIN', $book->audibleInfo['asin'] ?? 'N/A'];
        }

        if ($book->googleBooksInfo) {
            $tableData[] = ['Google Books ID', $book->googleBooksInfo['id'] ?? 'N/A'];
        }

        if ($book->hardcoverInfo) {
            $tableData[] = ['Hardcover ID', $book->hardcoverInfo['id'] ?? 'N/A'];
        }

        $tableData[] = ['Created', $book->createdAt?->format('Y-m-d H:i:s') ?? 'N/A'];
        $tableData[] = ['Updated', $book->updatedAt?->format('Y-m-d H:i:s') ?? 'N/A'];

        $this->table(['Field', 'Value'], $tableData);

        if ($book->coverImage) {
            $this->terminalImageService->displayImage(
                $book->coverImage,
                fn($msg) => $this->line($msg)
            );
        }
    }

    protected function truncateText(string $text, int $maxLength): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);

        if (mb_strlen($text) <= $maxLength) {
            return trim($text);
        }

        return trim(mb_substr($text, 0, $maxLength)) . '...';
    }
}
