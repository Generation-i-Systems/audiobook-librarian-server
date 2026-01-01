<?php

namespace App\Console\Commands;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Publisher;
use App\Models\Series;
use App\Services\BookDeletionService;
use App\Services\TerminalImageService;
use App\Traits\BookImportTrait;
use Illuminate\Console\Command;

class ShowBookInfo extends Command
{
    use BookImportTrait;

    protected $signature = 'books:info {directories?*}
                            {--compact : Use compact view instead of table}
                            {--show-paths : Show full paths being checked}
                            {--delete= : Delete book by ID (with confirmation)}
                            {--c|cover= : Update cover image (filename or path)}
                            {--t|title= : Update book title}
                            {--a|author=* : Update authors (+add, -remove, or replace all)}
                            {--s|series=* : Update series (+add, -remove, or replace all)}
                            {--g|genre=* : Update genres (+add, -remove, or replace all)}
                            {--p|publisher= : Update publisher name}
                            {--l|language= : Update language code}
                            {--r|release-date= : Update release date (YYYY-MM-DD)}
                            {--d|description= : Update book description}
                            {--S|source= : Update source of the book data}
                            {--e|edit : Open book edit page in browser}';

    protected $description = 'Display and optionally update book information from database';

    protected $aliases = ['books:show'];

    public function __construct(
        protected TerminalImageService $terminalImageService,
        protected BookDeletionService $deletionService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Handle delete option first
        if ($deleteId = $this->option('delete')) {
            return $this->handleDelete($deleteId);
        }

        // Handle edit option - will open browser after displaying book info
        $openEdit = $this->option('edit');

        $directories = $this->argument('directories');

        // Clean up directories - trim whitespace and split on newlines
        $cleanedDirectories = [];
        foreach ($directories as $directory) {
            // Split on newlines in case multiple paths were concatenated
            $parts = preg_split('/[\r\n]+/', $directory);
            foreach ($parts as $part) {
                $trimmed = trim($part);
                if (!empty($trimmed)) {
                    $cleanedDirectories[] = $trimmed;
                }
            }
        }
        // Remove duplicates
        $directories = array_unique($cleanedDirectories);

        // Debug: Show what we received
        if ($this->option('verbose')) {
            $this->line("<fg=blue>[DEBUG]</> Received directories: " . json_encode($directories));
        }

        // If no directories provided, default to current directory
        if (empty($directories)) {
            $directories = [getcwd()];
        }

        // Filter out any arguments that look like option values
        $directories = array_filter($directories, fn ($dir) => !str_starts_with($dir, '-'));

        // If all arguments were filtered out (they were option values), use current directory
        if (empty($directories)) {
            $directories = [getcwd()];
        }

        // Check if arguments are book IDs (numeric) or directories
        $bookIds = [];
        $directoryPaths = [];

        foreach ($directories as $directory) {
            if (ctype_digit($directory)) {
                // It's a numeric ID
                $bookIds[] = $directory;
            } else {
                // It's a directory path
                $directoryPaths[] = $directory;
            }
        }

        // Handle book IDs first
        foreach ($bookIds as $bookId) {
            $this->showBookById($bookId);
        }

        // If we only had book IDs, we're done
        if (!empty($bookIds) && empty($directoryPaths)) {
            return Command::SUCCESS;
        }

        // Continue with directory processing
        $directories = $directoryPaths;

        // Validate directories - if any don't exist and we have update options, assume current directory
        $validDirectories = [];
        $hasInvalidDirectories = false;
        $bookRoot = env('BOOK_STORAGE_PATH', config('app.book_root', '/media/audiobooks/books'));

        foreach ($directories as $directory) {
            if ($this->option('verbose')) {
                $this->line("<fg=blue>[DEBUG]</> Processing: {$directory}");
            }

            $realPath = realpath($directory);

            if ($this->option('verbose')) {
                $this->line("<fg=blue>[DEBUG]</> realpath() returned: " . ($realPath ?: 'false'));
                $this->line("<fg=blue>[DEBUG]</> is_dir() returned: " . (is_dir($realPath ?: $directory) ? 'true' : 'false'));
            }

            // If not found as absolute path, try relative to book root (only if not already absolute)
            if ((!$realPath || !is_dir($realPath)) && !str_starts_with($directory, '/')) {
                $bookRootPath = rtrim($bookRoot, '/') . '/' . ltrim($directory, '/');
                $realPath = realpath($bookRootPath);

                if ($this->option('verbose')) {
                    $this->line("<fg=blue>[DEBUG]</> Tried book root path: {$bookRootPath}");
                    $this->line("<fg=blue>[DEBUG]</> realpath() returned: " . ($realPath ?: 'false'));
                }
            }

            if ($realPath && is_dir($realPath)) {
                $validDirectories[] = $realPath;
                if ($this->option('verbose')) {
                    $this->line("<fg=green>[DEBUG]</> Added to valid directories");
                }
            } else {
                $hasInvalidDirectories = true;
                if ($this->option('verbose')) {
                    $this->line("<fg=red>[DEBUG]</> Marked as invalid");
                }
            }
        }

        // If we have invalid directories and update options are provided, use current directory
        if ($hasInvalidDirectories && $this->hasUpdateOptions() && empty($validDirectories)) {
            $this->warn("Note: Using current directory (arguments don't appear to be valid paths)");
            $validDirectories = [getcwd()];
        } elseif ($hasInvalidDirectories) {
            // Show error for invalid directories only if we're not using update options
            foreach ($directories as $directory) {
                $realPath = realpath($directory);
                $triedPaths = [$directory];

                if ((!$realPath || !is_dir($realPath)) && !str_starts_with($directory, '/')) {
                    $bookRootPath = rtrim($bookRoot, '/') . '/' . ltrim($directory, '/');
                    $realPath = realpath($bookRootPath);
                    $triedPaths[] = $bookRootPath;
                }

                if (!$realPath || !is_dir($realPath)) {
                    $this->error("Directory not found: {$directory}");
                    foreach ($triedPaths as $tried) {
                        $this->line("  Tried: {$tried}");
                    }
                }
            }

            // If we have invalid directories and no valid ones, fail
            if (empty($validDirectories)) {
                return Command::FAILURE;
            }
        }

        // If no valid directories at this point, use current directory ONLY if no arguments were provided
        if (empty($validDirectories)) {
            $validDirectories = [getcwd()];
        }

        foreach ($validDirectories as $directory) {
            $this->showBookFromDirectory($directory);
        }

        return Command::SUCCESS;
    }

    protected function showBookById(string $bookId): void
    {
        $book = Book::find($bookId);

        if (!$book) {
            $this->error("Book not found with ID: {$bookId}");
            return;
        }

        // Update book if any options were provided
        if ($this->hasUpdateOptions()) {
            $this->updateBookFields($book, null);
        }

        $this->displayBookInfo($book);

        // Open edit page if requested
        if ($this->option('edit')) {
            $this->openEditPage($book);
        }

        $this->newLine();
    }

    protected function showBookFromDirectory(string $directory): void
    {
        $bookRoot = config('app.book_root', '/media/lyra_data1/audiobooks/books');
        // Resolve symlinks in book root for consistent path handling
        $bookRoot = realpath($bookRoot) ?: $bookRoot;
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
            // Update book if any options were provided
            if ($this->hasUpdateOptions()) {
                $this->updateBookFields($book, $directory);
            }
            $this->displayBookInfo($book);

            // Open edit page if requested
            if ($this->option('edit')) {
                $this->openEditPage($book);
            }

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
            // Check if directory is under book root and has audio files
            if (str_starts_with($directory, $bookRoot) && $this->hasAudioFiles($directory)) {
                $this->warn("No book found in database for directory: {$directory}");
                $this->newLine();

                if ($this->confirm('This directory contains audio files. Would you like to import it?', true)) {
                    $this->info("Running import command...");
                    $this->call('books:import-downloads', ['path' => $directory]);
                    $this->newLine();

                    // Try to find the book again
                    $book = Book::where('directory_path', $searchPath)->first();
                    if ($book) {
                        $this->info("Book imported successfully!");
                        $this->newLine();
                        $this->displayBookInfo($book);

                        // Open edit page if requested
                        if ($this->option('edit')) {
                            $this->openEditPage($book);
                        }

                        $this->newLine();
                        return;
                    }
                }
            } else {
                $this->error("No books found in database for directory: {$directory}");
            }
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

            // Update book if any options were provided
            if ($this->hasUpdateOptions()) {
                $this->updateBookFields($book, $directory);
            }

            $this->displayBookInfo($book);

            // Open edit page if requested
            if ($this->option('edit')) {
                $this->openEditPage($book);
            }

            $this->newLine();
            return;
        } else {
            $this->info("Found {$books->count()} book(s) matching: {$directory}");
            $this->newLine();
        }

        foreach ($books as $book) {
            // Update book if any options were provided (only if there's exactly one book)
            if ($books->count() === 1 && $this->hasUpdateOptions()) {
                $this->updateBookFields($book, $directory);
            }

            $this->displayBookInfo($book);

            // Open edit page if requested (only for single book)
            if ($books->count() === 1 && $this->option('edit')) {
                $this->openEditPage($book);
            }

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
        // Check if we have a cover image to display
        $coverPath = null;
        if ($book->coverImage) {
            $coverPath = $book->coverImage;
            if (!str_starts_with($coverPath, 'http')) {
                $bookRoot = config('app.book_root', '/media/lyra_data1/audiobooks/books');
                // Resolve symlinks in book root for consistent path handling
                $bookRoot = realpath($bookRoot) ?: $bookRoot;

                // Check if cover path already includes directory or is just filename
                if (!str_contains($coverPath, '/')) {
                    // Filename only - combine with book's directory path
                    $coverPath = $bookRoot . '/' . ltrim($book->directoryPath, '/') . '/' . ltrim($coverPath, '/');
                } else {
                    // Already includes directory path (legacy format)
                    $coverPath = $bookRoot . '/' . ltrim($coverPath, '/');
                }
            }
            if (!file_exists($coverPath) && !str_starts_with($book->coverImage, 'http')) {
                $coverPath = null;
            }
        }

        $maxWidth = $this->getTerminalWidth();

        // If we have a cover image, the first 7 rows need shorter wrapping to avoid the image
        $shortWidth = $coverPath ? max($maxWidth - 16, 40) : $maxWidth;
        $imageCoverageRows = 7; // Number of data rows that the image will overlay

        $tableData = [];
        $rowCount = 0; // Track how many data rows we've added

        $tableData[] = ['ID', $book->id];
        $rowCount++;

        $titleWidth = $rowCount <= $imageCoverageRows ? $shortWidth : $maxWidth;
        $wrappedTitle = $this->wrapText($book->title ?? 'N/A', $titleWidth);
        $tableData[] = ['Title', $wrappedTitle];
        $rowCount++;

        if ($book->authors()->count() > 0) {
            $authors = $book->authors()->pluck('name')->join(', ');
            $authorsWidth = $rowCount <= $imageCoverageRows ? $shortWidth : $maxWidth;
            $wrappedAuthors = $this->wrapText($authors, $authorsWidth);
            $tableData[] = ['Authors', $wrappedAuthors];
            $rowCount++;
        }

        if ($book->narrators()->count() > 0) {
            $narrators = $book->narrators()->pluck('name')->join(', ');
            $narratorsWidth = $rowCount <= $imageCoverageRows ? $shortWidth : $maxWidth;
            $wrappedNarrators = $this->wrapText($narrators, $narratorsWidth);
            $tableData[] = ['Narrators', $wrappedNarrators];
            $rowCount++;
        }

        if ($book->series()->count() > 0) {
            $seriesInfo = $book->series()->get()->map(function ($series) {
                $number = $series->pivot->series_number;
                return "{$series->name}" . ($number ? " #{$number}" : '');
            })->join(', ');
            $seriesWidth = $rowCount <= $imageCoverageRows ? $shortWidth : $maxWidth;
            $wrappedSeries = $this->wrapText($seriesInfo, $seriesWidth);
            $tableData[] = ['Series', $wrappedSeries];
            $rowCount++;
        }

        if ($book->genres()->count() > 0) {
            $genres = $book->genres()->pluck('name')->join(', ');
            $genresWidth = $rowCount <= $imageCoverageRows ? $shortWidth : $maxWidth;
            $wrappedGenres = $this->wrapText($genres, $genresWidth);
            $tableData[] = ['Genres', $wrappedGenres];
            $rowCount++;
        }

        $publisherWidth = $rowCount <= $imageCoverageRows ? $shortWidth : $maxWidth;
        $wrappedPublisher = $this->wrapText($book->publisher ?? 'N/A', $publisherWidth);
        $tableData[] = ['Publisher', $wrappedPublisher];
        $rowCount++;

        $tableData[] = ['Release Date', $book->releaseDate?->format('Y-m-d') ?? 'N/A'];
        $rowCount++;

        $tableData[] = ['Language', $book->language ?? 'N/A'];
        $rowCount++;

        if ($book->duration) {
            $hours = floor($book->duration / 3600);
            $minutes = floor(($book->duration % 3600) / 60);
            $durationStr = "{$hours}h {$minutes}m";
            $tableData[] = ['Duration', $durationStr];
            $rowCount++;
        }

        $tableData[] = ['Audio Files', $book->audioFileCount ?? 0];
        $rowCount++;

        $tableData[] = ['Source', $book->source ?? 'N/A'];
        $rowCount++;

        $directoryWidth = $rowCount <= $imageCoverageRows ? $shortWidth : $maxWidth;
        $directoryPath = $book->directoryPath ?? 'N/A';

        // Check if directory exists on disk
        $bookRoot = config('app.book_root', '/media/lyra_data1/audiobooks/books');
        // Resolve symlinks in book root for consistent path handling
        $bookRoot = realpath($bookRoot) ?: $bookRoot;
        $fullPath = $bookRoot . '/' . ltrim($directoryPath, '/');
        // Use file_exists and is_dir together, and check that it's readable
        $directoryExists = file_exists($fullPath) && is_dir($fullPath) && is_readable($fullPath);

        $wrappedDirectory = $this->wrapText($directoryPath, $directoryWidth);

        // Add debug info if requested
        if ($this->option('show-paths')) {
            if (!$directoryExists && $directoryPath !== 'N/A') {
                $wrappedDirectory .= "\n  (Missing: {$fullPath})";
            } elseif ($directoryPath !== 'N/A') {
                $wrappedDirectory .= "\n  (Exists: {$fullPath})";
            }
        }

        // Apply color styling using OutputFormatterStyle
        if (!$directoryExists && $directoryPath !== 'N/A') {
            $wrappedDirectory = "<fg=red>{$wrappedDirectory}</>";
        }

        $tableData[] = ['Directory', $wrappedDirectory];
        $rowCount++;

        if ($book->needsReview) {
            $reasons = $book->needsReviewReasons ? implode(', ', $book->needsReviewReasons) : 'Unknown';
            $needsReviewText = "Yes ({$reasons})";
            $needsReviewWidth = $rowCount <= $imageCoverageRows ? $shortWidth : $maxWidth;
            $wrappedNeedsReview = $this->wrapText($needsReviewText, $needsReviewWidth);
            $tableData[] = ['Needs Review', $wrappedNeedsReview];
            $rowCount++;
        }

        if ($book->description) {
            $description = strip_tags($book->description);
            $description = preg_replace('/\s+/', ' ', $description);
            $description = trim($description);
            $descriptionWidth = $rowCount <= $imageCoverageRows ? $shortWidth : $maxWidth;
            $wrappedDescription = $this->wrapText($description, $descriptionWidth);
            $tableData[] = ['Description', $wrappedDescription];
            $rowCount++;
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

        // Capture table output to count lines using Symfony's BufferedOutput
        // Enable decoration to preserve color tags
        $bufferedOutput = new \Symfony\Component\Console\Output\BufferedOutput(
            \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL,
            true // decorated
        );
        $table = new \Symfony\Component\Console\Helper\Table($bufferedOutput);
        $table->setRows($tableData);
        $table->render();

        $tableOutput = $bufferedOutput->fetch();

        // Count actual lines in the output
        $tableLines = substr_count($tableOutput, "\n");

        // Find the position of the right border '|' on the second line
        $lines = explode("\n", $tableOutput);
        $rightBorderPos = null;
        if (isset($lines[1])) { // Second line (index 1)
            $secondLine = $lines[1];
            // Find the last '|' character position
            $rightBorderPos = mb_strrpos($secondLine, '|');
            if ($rightBorderPos !== false) {
                // Subtract 2 to position inside the border
                $rightBorderPos = $rightBorderPos - 2;
            }
        }

        $this->output->write($tableOutput);

        // Display image overlaid on upper right of table
        if ($coverPath && $this->terminalImageService->supportsImages()) {
            $imageWidth = 15;
            $imageHeight = 13;

            $terminalColumns = $this->getTerminalColumns();
            $leftColumn = max(1, $terminalColumns - $imageWidth - 1);

            // Move cursor up to the second line of the table (tableLines - 1 to get to top, then +1 for second line)
            // But we want to start one line higher, so tableLines instead of tableLines - 1
            $linesToMoveUp = $tableLines;
            echo "\033[{$linesToMoveUp}A"; // Move cursor up

            // Move cursor to the left position
            echo "\033[{$leftColumn}G"; // Move cursor to column (1-indexed)

            // Get the current cursor position to calculate absolute coordinates for --place
            // We'll use stty to read cursor position
            $cursorPos = $this->getCursorPosition();

            if ($cursorPos) {
                [$cursorRow, $cursorCol] = $cursorPos;

                // Now we know exactly where we are on screen
                $place = "{$imageWidth}x{$imageHeight}@{$cursorCol}x{$cursorRow}";

                $this->terminalImageService->displayImage($coverPath, function ($msg) {
                    // Silent - image will overlay the table
                }, 'right', $place);

                // Move cursor back to bottom of table
                echo "\033[{$linesToMoveUp}B"; // Move cursor down
                echo "\r"; // Move to beginning of line
            } else {
                // If we cannot determine cursor position, at least move back to bottom of table
                echo "\033[{$linesToMoveUp}B";
                echo "\r";
            }
        }
    }

    protected function displayCompactInfo(Book $book): void
    {
        $termWidth = 80;
        if (function_exists('exec')) {
            $output = [];
            @exec('tput cols 2>&1 < /dev/tty', $output);
            if (!empty($output[0]) && is_numeric($output[0])) {
                $termWidth = (int) $output[0];
            }
        }

        if ($termWidth === 80 && function_exists('getenv')) {
            $columns = getenv('COLUMNS');
            if ($columns && is_numeric($columns)) {
                $termWidth = (int) $columns;
            }
        }

        $leftWidth = max($termWidth - 5, 40);

        // Display image at the beginning for compact mode
        if ($book->coverImage) {
            $coverPath = $book->coverImage;
            if (!str_starts_with($coverPath, 'http')) {
                $bookRoot = config('app.book_root', '/media/lyra_data1/audiobooks/books');
                // Resolve symlinks in book root for consistent path handling
                $bookRoot = realpath($bookRoot) ?: $bookRoot;

                // Check if cover path already includes directory or is just filename
                if (!str_contains($coverPath, '/')) {
                    // Filename only - combine with book's directory path
                    $coverPath = $bookRoot . '/' . ltrim($book->directoryPath, '/') . '/' . ltrim($coverPath, '/');
                } else {
                    // Already includes directory path (legacy format)
                    $coverPath = $bookRoot . '/' . ltrim($coverPath, '/');
                }
            }
            if (file_exists($coverPath) || str_starts_with($book->coverImage, 'http')) {
                $this->terminalImageService->displayImage(
                    $coverPath,
                    fn ($msg) => $this->line($msg)
                );
                $this->newLine();
            }
        }

        $this->printField('ID', $book->id, $leftWidth);
        $this->printField('Title', $book->title ?? 'N/A', $leftWidth);

        if ($book->authors()->count() > 0) {
            $authors = $book->authors()->pluck('name')->join(', ');
            $this->printField('Authors', $authors, $leftWidth);
        }

        if ($book->narrators()->count() > 0) {
            $narrators = $book->narrators()->pluck('name')->join(', ');
            $this->printField('Narrators', $narrators, $leftWidth);
        }

        if ($book->series()->count() > 0) {
            $seriesInfo = $book->series()->get()->map(function ($series) {
                $number = $series->pivot->series_number;
                return "{$series->name}" . ($number ? " #{$number}" : '');
            })->join(', ');
            $this->printField('Series', $seriesInfo, $leftWidth);
        }

        if ($book->genres()->count() > 0) {
            $genres = $book->genres()->pluck('name')->join(', ');
            $this->printField('Genres', $genres, $leftWidth);
        }

        $this->printField('Publisher', $book->publisher ?? 'N/A', $leftWidth);
        $this->printField('Release Date', $book->releaseDate?->format('Y-m-d') ?? 'N/A', $leftWidth);
        $this->printField('Language', $book->language ?? 'N/A', $leftWidth);

        if ($book->duration) {
            $hours = floor($book->duration / 3600);
            $minutes = floor(($book->duration % 3600) / 60);
            $durationStr = "{$hours}h {$minutes}m";
            $this->printField('Duration', $durationStr, $leftWidth);
        }

        $this->printField('Audio Files', $book->audioFileCount ?? 0, $leftWidth);
        $this->printField('Source', $book->source ?? 'N/A', $leftWidth);

        // Check if directory exists on disk
        $directoryPath = $book->directoryPath ?? 'N/A';
        $bookRoot = config('app.book_root', '/media/lyra_data1/audiobooks/books');
        // Resolve symlinks in book root for consistent path handling
        $bookRoot = realpath($bookRoot) ?: $bookRoot;
        $fullPath = $bookRoot . '/' . ltrim($directoryPath, '/');
        // Use file_exists and is_dir together, and check that it's readable
        $directoryExists = file_exists($fullPath) && is_dir($fullPath) && is_readable($fullPath);

        // Color red if directory doesn't exist
        if (!$directoryExists && $directoryPath !== 'N/A') {
            $this->printField('Directory', "<fg=red>{$directoryPath}</>", $leftWidth);
        } else {
            $this->printField('Directory', $directoryPath, $leftWidth);
        }

        if ($book->needsReview) {
            $reasons = $book->needsReviewReasons ? implode(', ', $book->needsReviewReasons) : 'Unknown';
            $this->printField('Needs Review', "Yes ({$reasons})", $leftWidth);
        }

        if ($book->description) {
            $description = strip_tags($book->description);
            $description = preg_replace('/\s+/', ' ', $description);
            $this->printField('Description', trim($description), $leftWidth);
        }

        if ($book->audibleInfo) {
            $this->printField('Audible ASIN', $book->audibleInfo['asin'] ?? 'N/A', $leftWidth);
        }

        if ($book->googleBooksInfo) {
            $this->printField('Google Books ID', $book->googleBooksInfo['id'] ?? 'N/A', $leftWidth);
        }

        if ($book->hardcoverInfo) {
            $this->printField('Hardcover ID', $book->hardcoverInfo['id'] ?? 'N/A', $leftWidth);
        }

        $this->printField('Created', $book->createdAt?->format('Y-m-d H:i:s') ?? 'N/A', $leftWidth);
        $this->printField('Updated', $book->updatedAt?->format('Y-m-d H:i:s') ?? 'N/A', $leftWidth);
    }

    protected function printField(string $label, mixed $value, int $maxWidth): void
    {
        $labelLength = mb_strlen($label) + 2;
        $valueWidth = max($maxWidth - $labelLength, 20);

        $wrappedValue = $this->wrapText((string) $value, $valueWidth);
        $lines = explode("\n", $wrappedValue);

        $this->line("<fg=yellow>{$label}:</> {$lines[0]}");

        for ($i = 1; $i < count($lines); $i++) {
            $padding = str_repeat(' ', $labelLength);
            $this->line("{$padding}{$lines[$i]}");
        }
    }

    protected function getTerminalWidth(): int
    {
        return max($this->getTerminalColumns() - 20, 40);
    }

    protected function getTerminalColumns(): int
    {
        $width = 80;

        if (function_exists('exec')) {
            $output = [];
            @exec('tput cols 2>&1 < /dev/tty', $output);
            if (!empty($output[0]) && is_numeric($output[0])) {
                $width = (int) $output[0];
            }
        }

        if ($width === 80 && function_exists('getenv')) {
            $columns = getenv('COLUMNS');
            if ($columns && is_numeric($columns)) {
                $width = (int) $columns;
            }
        }

        return max($width - 20, 40);
    }

    protected function wrapText(string $text, int $maxWidth): string
    {
        if (empty($text)) {
            return $text;
        }

        if ($maxWidth <= 0) {
            return $text;
        }

        $tokens = preg_split('/(<[^>]+>|\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $lines = [];
        $currentLine = '';
        $visibleLength = 0;

        $flushLine = static function () use (&$lines, &$currentLine, &$visibleLength): void {
            if ($currentLine !== '') {
                $lines[] = rtrim($currentLine);
            }
            $currentLine = '';
            $visibleLength = 0;
        };

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            if ($token[0] === '<' && str_ends_with($token, '>')) {
                $currentLine .= $token;
                continue;
            }

            if (preg_match('/^\s+$/u', $token)) {
                if (str_contains($token, "\n") || str_contains($token, "\r")) {
                    $segments = preg_split("/(\r\n|\r|\n)/", $token, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
                    foreach ($segments as $segment) {
                        if (preg_match("/\r\n|\r|\n/", $segment)) {
                            $flushLine();
                        } elseif ($visibleLength > 0) {
                            if ($visibleLength + 1 > $maxWidth) {
                                $flushLine();
                            }
                            $currentLine .= ' ';
                            $visibleLength += 1;
                        }
                    }
                } elseif ($visibleLength > 0) {
                    if ($visibleLength + 1 > $maxWidth) {
                        $flushLine();
                    }
                    $currentLine .= ' ';
                    $visibleLength += 1;
                }

                continue;
            }

            $remaining = $token;
            while ($remaining !== '') {
                $available = $maxWidth - $visibleLength;
                if ($available <= 0) {
                    $flushLine();
                    $available = $maxWidth;
                }

                $chunk = mb_substr($remaining, 0, $available);
                $chunkLength = mb_strlen($chunk);

                if ($chunkLength === 0) {
                    break;
                }

                $currentLine .= $chunk;
                $visibleLength += $chunkLength;
                $remaining = mb_substr($remaining, $chunkLength);

                if ($remaining !== '') {
                    $flushLine();
                }
            }
        }

        if ($currentLine !== '') {
            $lines[] = rtrim($currentLine);
        }

        return implode("\n", $lines);
    }

    protected function countWrappedLines(string $text, int $maxWidth): int
    {
        if (empty($text)) {
            return 1;
        }

        $wrapped = $this->wrapText($text, $maxWidth);
        return max(1, count(explode("\n", $wrapped)));
    }

    protected function hasUpdateOptions(): bool
    {
        return $this->option('cover')
            || $this->option('title')
            || !empty($this->option('author'))
            || !empty($this->option('series'))
            || !empty($this->option('genre'))
            || $this->option('publisher')
            || $this->option('language')
            || $this->option('release-date')
            || $this->option('description')
            || $this->option('source');
    }

    protected function hasAudioFiles(string $directory): bool
    {
        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'opus', 'wav'];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $extension = strtolower($file->getExtension());
                if (in_array($extension, $audioExtensions)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function updateBookFields(Book $book, ?string $directory): void
    {
        $updated = false;
        $bookRoot = config('app.book_root', '/media/lyra_data1/audiobooks/books');
        // Resolve symlinks in book root for consistent path handling
        $bookRoot = realpath($bookRoot) ?: $bookRoot;

        // Update cover image
        if ($this->option('cover')) {
            $coverInput = $this->option('cover');

            // If it's just a filename, search for it in the book directory
            if (basename($coverInput) === $coverInput) {
                $searchPath = $directory;
                $foundCover = null;

                // Search for the file in the directory
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($searchPath, \RecursiveDirectoryIterator::SKIP_DOTS)
                );

                foreach ($files as $file) {
                    if ($file->isFile() && $file->getFilename() === $coverInput) {
                        $foundCover = $file->getPathname();
                        break;
                    }
                }

                if ($foundCover) {
                    $coverInput = $foundCover;
                } else {
                    $this->error("Cover image file not found: {$coverInput}");
                    return;
                }
            }

            if (filter_var($coverInput, FILTER_VALIDATE_URL)) {
                // URL - download it and save to book directory
                $this->info("Downloading cover image from URL...");

                try {
                    // Use stream context to follow redirects
                    $context = stream_context_create([
                        'http' => [
                            'follow_location' => true,
                            'max_redirects' => 10,
                            'timeout' => 30,
                            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                        ],
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                        ],
                    ]);

                    $imageData = file_get_contents($coverInput, false, $context);
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
                    $urlPath = parse_url($coverInput, PHP_URL_PATH);
                    if ($urlPath && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $urlPath, $matches)) {
                        $extension = strtolower($matches[1]);
                    }

                    // Save to book directory
                    $coverFilename = 'cover.' . $extension;
                    $coverFullPath = $directory . '/' . $coverFilename;

                    if (!is_dir($directory)) {
                        $this->error("Book directory not found: {$directory}");
                        return;
                    }

                    if (file_put_contents($coverFullPath, $imageData) === false) {
                        throw new \Exception("Failed to save image file");
                    }

                    chmod($coverFullPath, 0664);

                    // Store relative path
                    if (str_starts_with($directory, $bookRoot)) {
                        $relativePath = ltrim(substr($directory, strlen($bookRoot)), '/') . '/' . $coverFilename;
                        $book->coverImage = $relativePath;
                        $this->info("✓ Downloaded and saved cover image: {$relativePath}");
                    } else {
                        $book->coverImage = $coverFullPath;
                        $this->info("✓ Downloaded and saved cover image: {$coverFullPath}");
                    }
                    $updated = true;
                } catch (\Exception $e) {
                    $this->error("Failed to download cover image: " . $e->getMessage());
                    return;
                }
            } elseif (file_exists($coverInput)) {
                $realCoverPath = realpath($coverInput);

                // Check if this is an m4b file - extract cover from it
                if (strtolower(pathinfo($realCoverPath, PATHINFO_EXTENSION)) === 'm4b') {
                    $extractedCoverFilename = $this->extractCoverFromM4B($realCoverPath, $directory);
                    if ($extractedCoverFilename) {
                        // The trait method returns just the filename, so construct the full path
                        $realCoverPath = $directory . '/' . $extractedCoverFilename;
                        $this->info("✓ Extracted cover from m4b file");
                    } else {
                        $this->error("Failed to extract cover from m4b file");
                        return;
                    }
                }

                if (str_starts_with($realCoverPath, $bookRoot)) {
                    $relativePath = ltrim(substr($realCoverPath, strlen($bookRoot)), '/');
                    $book->coverImage = $relativePath;
                    $this->info("✓ Updated cover image: {$relativePath}");
                } else {
                    $book->coverImage = $realCoverPath;
                    $this->info("✓ Updated cover image (absolute): {$realCoverPath}");
                }
                $updated = true;
            } else {
                $this->error("Cover image file not found: {$coverInput}");
                return;
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
            $publisherName = trim((string) $this->option('publisher'));
            $publisher = Publisher::firstOrCreate(['name' => $publisherName]);
            $book->publisher()->associate($publisher);
            $this->info("✓ Updated publisher: {$publisher->name}");
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
                return;
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

        // Update authors
        if (!empty($this->option('author'))) {
            $this->updateRelationship($book, 'authors', Author::class, $this->option('author'));
            $updated = true;
        }

        // Update series
        if (!empty($this->option('series'))) {
            $this->updateRelationship($book, 'series', Series::class, $this->option('series'), true);
            $updated = true;
        }

        // Update genres
        if (!empty($this->option('genre'))) {
            $this->updateRelationship($book, 'genres', Genre::class, $this->option('genre'));
            $updated = true;
        }

        if ($updated) {
            $book->save();
            $this->newLine();
        }
    }

    protected function updateRelationship(
        Book $book,
        string $relation,
        string $modelClass,
        array $values,
        bool $hasPivotNumber = false
    ): void {
        $adding = [];
        $removing = [];
        $replacing = [];

        foreach ($values as $value) {
            // Split comma-separated values
            $items = array_map('trim', explode(',', $value));

            foreach ($items as $item) {
                if (str_starts_with($item, '+')) {
                    $adding[] = ltrim($item, '+');
                } elseif (str_starts_with($item, '-')) {
                    $removing[] = ltrim($item, '-');
                } else {
                    $replacing[] = $item;
                }
            }
        }

        // If we have replacement values, detach all and attach new ones
        if (!empty($replacing)) {
            $book->$relation()->detach();
            foreach ($replacing as $name) {
                $this->attachRelation($book, $relation, $modelClass, $name, $hasPivotNumber);
            }
            $this->info("✓ Replaced " . ucfirst($relation) . ": " . implode(', ', $replacing));
            return;
        }

        // Handle additions
        foreach ($adding as $name) {
            $this->attachRelation($book, $relation, $modelClass, $name, $hasPivotNumber);
        }
        if (!empty($adding)) {
            $this->info("✓ Added " . ucfirst($relation) . ": " . implode(', ', $adding));
        }

        // Handle removals
        foreach ($removing as $name) {
            $model = $modelClass::where('name', $name)->first();
            if ($model) {
                $book->$relation()->detach($model->id);
                $this->info("✓ Removed from " . ucfirst($relation) . ": {$name}");
            } else {
                $this->warn("  {$name} not found in " . $relation);
            }
        }
    }

    protected function attachRelation(
        Book $book,
        string $relation,
        string $modelClass,
        string $name,
        bool $hasPivotNumber = false
    ): void {
        // Parse series number if present (e.g., "Series Name #1")
        $seriesNumber = null;
        if ($hasPivotNumber && str_contains($name, '#')) {
            [$name, $numberPart] = explode('#', $name, 2);
            $name = trim($name);
            $seriesNumber = (float) trim($numberPart);
        }

        $model = $modelClass::firstOrCreate(['name' => $name]);

        if ($hasPivotNumber && $seriesNumber !== null) {
            $book->$relation()->syncWithoutDetaching([$model->id => ['series_number' => $seriesNumber]]);
        } else {
            $book->$relation()->syncWithoutDetaching([$model->id]);
        }
    }

    protected function getCursorPosition(): ?array
    {
        // Skip if no TTY available (e.g., in testing or CI environments)
        if (!is_resource(STDIN) || !@posix_isatty(STDIN) || app()->environment('testing')) {
            return null;
        }

        // Save current terminal settings
        $sttySettings = @shell_exec('stty -g 2>/dev/null < /dev/tty');

        // Set terminal to raw mode to read response
        @shell_exec('stty -icanon -echo 2>/dev/null < /dev/tty');

        // Request cursor position using ANSI escape sequence
        fwrite(STDOUT, "\033[6n");
        fflush(STDOUT);

        // Read the response (format: ESC[row;colR)
        $stdin = fopen('/dev/tty', 'r');
        stream_set_blocking($stdin, false);

        $response = '';
        $start = microtime(true);
        while (microtime(true) - $start < 0.1) { // 100ms timeout
            $char = fread($stdin, 1);
            if ($char !== false && $char !== '') {
                $response .= $char;
                if ($char === 'R') {
                    break;
                }
            }
            usleep(1000); // 1ms
        }
        fclose($stdin);

        // Restore terminal settings
        @shell_exec("stty {$sttySettings} 2>/dev/null < /dev/tty");

        // Parse response (format: ESC[row;colR)
        if (preg_match('/\033\[(\d+);(\d+)R/', $response, $matches)) {
            return [(int) $matches[1], (int) $matches[2]];
        }

        return null;
    }

    /**
     * Open the book's edit page in browser
     */
    protected function openEditPage(Book $book): void
    {
        $appUrl = rtrim(config('app.url', 'http://localhost'), '/');
        $editUrl = $appUrl . '/admin/books/' . $book->id . '/edit';

        $this->info("Opening edit page: {$editUrl}");

        // Skip browser opening when running tests
        if (app()->environment('testing')) {
            $this->comment("(Skipped browser opening in test environment)");
            return;
        }

        // Detect OS and use appropriate browser command
        $os = PHP_OS_FAMILY;

        if ($os === 'Linux') {
            // Try xdg-open first (most common), then sensible-browser, then common browsers
            $commands = ['xdg-open', 'sensible-browser', 'firefox', 'google-chrome', 'chromium-browser'];
            foreach ($commands as $cmd) {
                $check = shell_exec("which {$cmd} 2>/dev/null");
                if ($check) {
                    exec("{$cmd} " . escapeshellarg($editUrl) . " > /dev/null 2>&1 &");
                    return;
                }
            }
            $this->warn("Could not find a browser to open the URL. Please open manually:");
            $this->line("  {$editUrl}");
        } elseif ($os === 'Darwin') {
            exec("open " . escapeshellarg($editUrl));
        } elseif ($os === 'Windows') {
            exec("start " . escapeshellarg($editUrl));
        } else {
            $this->warn("Unknown OS. Please open this URL manually:");
            $this->line("  {$editUrl}");
        }
    }

    /**
     * Handle book deletion
     */
    protected function handleDelete(string $bookId): int
    {
        // Find the book
        $book = Book::find($bookId);

        if (!$book) {
            $this->error("Book not found with ID: {$bookId}");
            return 1;
        }

        // Display book information
        $this->info("Book to delete:");
        $this->line("  ID: {$book->id}");
        $this->line("  Title: {$book->title}");
        $this->line("  Author: " . ($book->authors()->count() > 0 ? $book->authors()->pluck('name')->join(', ') : 'N/A'));
        $this->line("  Directory: {$book->directoryPath}");

        // Check if directory exists
        $bookRoot = config('app.book_root', '/media/lyra_data1/audiobooks/books');
        // Resolve symlinks in book root for consistent path handling
        $bookRoot = realpath($bookRoot) ?: $bookRoot;
        $fullPath = $bookRoot . '/' . ltrim($book->directoryPath, '/');
        $directoryExists = file_exists($fullPath) && is_dir($fullPath);

        if ($directoryExists) {
            $this->line("  Directory exists: <fg=green>Yes</>");

            // Check if any other books use this directory
            $otherBooks = Book::where('directory_path', $book->directoryPath)
                ->where('id', '!=', $book->id)
                ->get();

            if ($otherBooks->count() > 0) {
                $this->error("\n⚠️  WARNING: This directory is used by " . $otherBooks->count() . " other book(s):");
                foreach ($otherBooks as $otherBook) {
                    $this->line("  - ID {$otherBook->id}: {$otherBook->title}");
                }
                $this->error("\nCannot delete directory that is shared with other books!");
                $this->line("The book record will be deleted, but the directory will be preserved.");

                if (!$this->confirm("\nDelete only the book record (preserve directory)?", false)) {
                    $this->info("Cancelled");
                    return 0;
                }

                $deleteDirectory = false;
            } else {
                $this->line("  No other books use this directory");

                $choice = $this->choice(
                    "\nWhat would you like to delete?",
                    [
                        '1' => 'Book record only (preserve directory and files)',
                        '2' => 'Book record AND directory (delete everything)',
                        '3' => 'Cancel (delete nothing)',
                    ],
                    '1'
                );

                if ($choice === 'Cancel (delete nothing)') {
                    $this->info("Cancelled");
                    return 0;
                }

                $deleteDirectory = $choice === 'Book record AND directory (delete everything)';
            }
        } else {
            $this->line("  Directory exists: <fg=red>No</>");

            if (!$this->confirm("\nDelete book record?", false)) {
                $this->info("Cancelled");
                return 0;
            }

            $deleteDirectory = false;
        }

        // Delete the book using trash system
        try {
            $result = $this->deletionService->moveToTrash((string) $book->id, $deleteDirectory);

            if ($result['success']) {
                if ($deleteDirectory) {
                    $this->info("✓ Book and files moved to trash");
                    $this->line("  Trash ID: {$result['trash_item_id']}");
                    $this->line("  Files moved: {$result['file_count']}");
                } else {
                    $this->info("✓ Book record moved to trash (files preserved)");
                    $this->line("  Trash ID: {$result['trash_item_id']}");
                }
                $this->newLine();
                $this->info("You can restore this book from /admin/trash");
            } else {
                $this->error("Failed to delete book: " . ($result['error'] ?? 'Unknown error'));
                return 1;
            }
        } catch (\Exception $e) {
            $this->error("Failed to delete book: " . $e->getMessage());
            return 1;
        }

        $this->info("\n✓ Deletion completed successfully!");
        return 0;
    }
}
