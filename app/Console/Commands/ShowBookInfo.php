<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\TerminalImageService;
use Illuminate\Console\Command;

class ShowBookInfo extends Command
{
    protected $signature = 'books:show {directories?*} {--compact : Use compact view instead of table}';

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
        if ($this->option('compact')) {
            $this->displayCompactInfo($book);
        } else {
            $this->displayTableInfo($book);
        }
    }

    protected function displayTableInfo(Book $book): void
    {
        $this->info("═══════════════════════════════════════════════════════════════");
        $this->info("  BOOK INFORMATION");
        $this->info("═══════════════════════════════════════════════════════════════");
        $this->newLine();

        $maxWidth = $this->getTerminalWidth();
        $tableData = [];

        $tableData[] = ['ID', $book->id];
        $tableData[] = ['Title', $this->wrapText($book->title ?? 'N/A', $maxWidth)];

        if ($book->authors()->count() > 0) {
            $authors = $book->authors()->pluck('name')->join(', ');
            $tableData[] = ['Authors', $this->wrapText($authors, $maxWidth)];
        }

        if ($book->narrators()->count() > 0) {
            $narrators = $book->narrators()->pluck('name')->join(', ');
            $tableData[] = ['Narrators', $this->wrapText($narrators, $maxWidth)];
        }

        if ($book->series()->count() > 0) {
            $seriesInfo = $book->series()->get()->map(function ($series) {
                $number = $series->pivot->series_number;
                return "{$series->name}" . ($number ? " #{$number}" : '');
            })->join(', ');
            $tableData[] = ['Series', $this->wrapText($seriesInfo, $maxWidth)];
        }

        if ($book->genres()->count() > 0) {
            $genres = $book->genres()->pluck('name')->join(', ');
            $tableData[] = ['Genres', $this->wrapText($genres, $maxWidth)];
        }

        $tableData[] = ['Publisher', $this->wrapText($book->publisher ?? 'N/A', $maxWidth)];
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
        $tableData[] = ['Directory', $this->wrapText($book->directoryPath ?? 'N/A', $maxWidth)];

        if ($book->needsReview) {
            $reasons = $book->needsReviewReasons ? implode(', ', $book->needsReviewReasons) : 'Unknown';
            $tableData[] = ['Needs Review', $this->wrapText("Yes ({$reasons})", $maxWidth)];
        }

        if ($book->description) {
            $description = strip_tags($book->description);
            $description = preg_replace('/\s+/', ' ', $description);
            $tableData[] = ['Description', $this->wrapText(trim($description), $maxWidth)];
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
            $coverPath = $book->coverImage;

            if (!str_starts_with($coverPath, 'http')) {
                $bookRoot = config('app.book_root', '/media/lyra_data1/audiobooks/books');
                $coverPath = $bookRoot . '/' . ltrim($coverPath, '/');
            }

            if (file_exists($coverPath) || str_starts_with($book->coverImage, 'http')) {
                $this->terminalImageService->displayImage(
                    $coverPath,
                    fn($msg) => $this->line($msg)
                );
            }
        }
    }

    protected function displayCompactInfo(Book $book): void
    {
        $maxWidth = $this->getTerminalWidth() + 20;

        $this->line("<fg=cyan>━━━ BOOK INFO ━━━</>");
        $this->newLine();

        $this->printField('ID', $book->id, $maxWidth);
        $this->printField('Title', $book->title ?? 'N/A', $maxWidth);

        if ($book->authors()->count() > 0) {
            $authors = $book->authors()->pluck('name')->join(', ');
            $this->printField('Authors', $authors, $maxWidth);
        }

        if ($book->narrators()->count() > 0) {
            $narrators = $book->narrators()->pluck('name')->join(', ');
            $this->printField('Narrators', $narrators, $maxWidth);
        }

        if ($book->series()->count() > 0) {
            $seriesInfo = $book->series()->get()->map(function ($series) {
                $number = $series->pivot->series_number;
                return "{$series->name}" . ($number ? " #{$number}" : '');
            })->join(', ');
            $this->printField('Series', $seriesInfo, $maxWidth);
        }

        if ($book->genres()->count() > 0) {
            $genres = $book->genres()->pluck('name')->join(', ');
            $this->printField('Genres', $genres, $maxWidth);
        }

        $this->printField('Publisher', $book->publisher ?? 'N/A', $maxWidth);
        $this->printField('Release Date', $book->releaseDate?->format('Y-m-d') ?? 'N/A', $maxWidth);
        $this->printField('Language', $book->language ?? 'N/A', $maxWidth);

        if ($book->duration) {
            $hours = floor($book->duration / 3600);
            $minutes = floor(($book->duration % 3600) / 60);
            $durationStr = "{$hours}h {$minutes}m";
            $this->printField('Duration', $durationStr, $maxWidth);
        }

        $this->printField('Audio Files', $book->audioFileCount ?? 0, $maxWidth);
        $this->printField('Source', $book->source ?? 'N/A', $maxWidth);
        $this->printField('Directory', $book->directoryPath ?? 'N/A', $maxWidth);

        if ($book->needsReview) {
            $reasons = $book->needsReviewReasons ? implode(', ', $book->needsReviewReasons) : 'Unknown';
            $this->printField('Needs Review', "Yes ({$reasons})", $maxWidth);
        }

        if ($book->description) {
            $description = strip_tags($book->description);
            $description = preg_replace('/\s+/', ' ', $description);
            $this->printField('Description', trim($description), $maxWidth);
        }

        if ($book->audibleInfo) {
            $this->printField('Audible ASIN', $book->audibleInfo['asin'] ?? 'N/A', $maxWidth);
        }

        if ($book->googleBooksInfo) {
            $this->printField('Google Books ID', $book->googleBooksInfo['id'] ?? 'N/A', $maxWidth);
        }

        if ($book->hardcoverInfo) {
            $this->printField('Hardcover ID', $book->hardcoverInfo['id'] ?? 'N/A', $maxWidth);
        }

        $this->printField('Created', $book->createdAt?->format('Y-m-d H:i:s') ?? 'N/A', $maxWidth);
        $this->printField('Updated', $book->updatedAt?->format('Y-m-d H:i:s') ?? 'N/A', $maxWidth);

        if ($book->coverImage) {
            $coverPath = $book->coverImage;

            if (!str_starts_with($coverPath, 'http')) {
                $bookRoot = config('app.book_root', '/media/lyra_data1/audiobooks/books');
                $coverPath = $bookRoot . '/' . ltrim($coverPath, '/');
            }

            if (file_exists($coverPath) || str_starts_with($book->coverImage, 'http')) {
                $this->newLine();
                $this->terminalImageService->displayImage(
                    $coverPath,
                    fn($msg) => $this->line($msg)
                );
            }
        }
    }

    protected function printField(string $label, mixed $value, int $maxWidth): void
    {
        $wrappedValue = $this->wrapText((string) $value, $maxWidth);
        $lines = explode("\n", $wrappedValue);

        $this->line("<fg=yellow>{$label}:</> {$lines[0]}");

        for ($i = 1; $i < count($lines); $i++) {
            $padding = str_repeat(' ', mb_strlen($label) + 2);
            $this->line("{$padding}{$lines[$i]}");
        }
    }

    protected function getTerminalWidth(): int
    {
        $width = 80;

        if (function_exists('exec')) {
            $output = [];
            @exec('tput cols 2>/dev/null', $output);
            if (!empty($output[0]) && is_numeric($output[0])) {
                $width = (int) $output[0];
            }
        }

        return max($width - 20, 60);
    }

    protected function wrapText(string $text, int $maxWidth): string
    {
        if (empty($text)) {
            return $text;
        }

        $lines = [];
        $words = explode(' ', $text);
        $currentLine = '';

        foreach ($words as $word) {
            if (mb_strlen($currentLine . ' ' . $word) <= $maxWidth) {
                $currentLine .= ($currentLine ? ' ' : '') . $word;
            } else {
                if ($currentLine) {
                    $lines[] = $currentLine;
                }
                $currentLine = $word;
            }
        }

        if ($currentLine) {
            $lines[] = $currentLine;
        }

        return implode("\n", $lines);
    }
}
