<?php

namespace App\Console\Commands;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Publisher;
use App\Models\Series;
use App\Services\BookDeletionService;
use App\Services\BookFilesystemService;
use App\Services\TerminalImageService;
use App\Traits\BookImportTrait;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class ShowBookInfo extends Command
{
    use BookImportTrait;

    private array $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'opus', 'aac', 'wav', 'wma'];

    private const AUDIO_EXTENSIONS = ['mp3', 'm4b', 'm4a', 'flac', 'ogg', 'opus', 'aac', 'wav', 'wma', 'aiff'];
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    protected $signature = 'books:info {directories?*}
                {--compact : Use compact view instead of table}
                {--show-paths : Show full paths being checked}
                {--D|delete= : Delete book(s) by ID(s) or directory}
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
                {--e|edit : Open book edit page in browser}
                {--merge : Interactively merge duplicate book directories into one}
                {--merge-directories : Merge multiple directories (or subdirectories) into one}
                {--force : Skip confirmation prompts}';

    protected $description = 'Display and optionally update book information from database';

    protected $aliases = ['books:show'];

    public function __construct(
        protected TerminalImageService $terminalImageService,
        protected BookDeletionService $deletionService,
        protected Book $bookModel,
        protected Publisher $publisherModel,
        protected BookFilesystemService $bookFilesystemService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Handle delete option first
        if ($deleteId = $this->option('delete')) {
            return $this->handleDelete($deleteId);
        }

        // Handle --merge option
        if ($this->option('merge')) {
            return $this->handleMerge();
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
        $bookRoot = config('filesystems.disks.books.root') ?? config('app.book_root');

        foreach ($directories as $directory) {
            if ($this->option('verbose')) {
                $this->line("<fg=blue>[DEBUG]</> Processing: {$directory}");
            }

            $realPath = realpath($directory);

            if ($this->option('verbose')) {
                $resolvedPath = $realPath ?: 'false';
                $this->line(sprintf(
                    '<fg=blue>[DEBUG]</> realpath() returned: %s',
                    $resolvedPath
                ));
                $isDirectory = is_dir($realPath ?: $directory) ? 'true' : 'false';
                $this->line(sprintf(
                    '<fg=blue>[DEBUG]</> is_dir() returned: %s',
                    $isDirectory
                ));
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

    /**
     * Centralized builder for Book queries with eager-loaded relations.
     *
     * @return Builder<Book>
     */
    private function queryBook(): Builder
    {
        return $this->bookModel->newQuery()
        ->with(['authors', 'narrators', 'genres', 'series', 'publisher']);
    }

    protected function showBookById(string $bookId): void
    {
        /** @var Book|null $book */
        $book = $this->queryBook()->find($bookId);

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
        $bookRoot = config('filesystems.disks.books.root') ?? config('app.book_root', '/media/lyra_data1/audiobooks/books');
        // Resolve symlinks in book root for consistent path handling
        $bookRoot = realpath($bookRoot) ?: $bookRoot;
        $searchPath = $directory;

        if (str_starts_with($directory, $bookRoot)) {
            $searchPath = ltrim(substr($directory, strlen($bookRoot)), '/');
        }

        /** @var Book|null $book */
        $book = $this->queryBook()->where('directory_path', $searchPath)->first();
        $exactMatch = (bool) $book;

        if (!$book) {
            /** @var Book|null $book */
            $book = $this->queryBook()->where('directory_path', $directory)->first();
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

        /** @var \Illuminate\Support\Collection<int, Book> $books */
        $books = $this->queryBook()->where('directory_path', 'LIKE', rtrim($searchPath, '/') . '/%')->get();

        if ($books->isEmpty()) {
            // Check if directory is under book root and has audio files
            if (str_starts_with($directory, $bookRoot) && $this->hasAudioFiles($directory)) {
                $this->warn("No book found in database for directory: {$directory}");
                $this->newLine();

                if ($this->confirm('This directory contains audio files. Would you like to import it?', true)) {
                    $this->info("Running import command...");
                    $this->call('book:import', ['path' => $directory]);
                    $this->newLine();

                    // Try to find the book again
                    /** @var Book|null $book */
                    $book = $this->queryBook()->where('directory_path', $searchPath)->first();
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

        if ($books->count() > 1) {
            $this->info("Found {$books->count()} book(s) matching: {$directory}");
            $this->newLine();
        }

        foreach ($books as $book) {
            /** @var Book $book */
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
                $bookRoot = config('filesystems.disks.books.root') ?? config('app.book_root', '/media/lyra_data1/audiobooks/books');
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
        $hasCoverImage = (bool) $coverPath;
        $shortWidth = $hasCoverImage ? max($maxWidth - 16, 40) : $maxWidth;
        $imageCoverageRows = 7; // Number of data rows that the image will overlay

        $tableData = [];

        $tableData[] = ['ID', $book->id];
        $this->addRow(
            $tableData,
            'Title',
            $book->title ?? 'N/A',
            true,
            $hasCoverImage,
            $shortWidth,
            $maxWidth,
            $imageCoverageRows
        );

        if ($book->authors()->count() > 0) {
            $authors = $book->authors()->pluck('name')->join(', ');
            $this->addRow(
                $tableData,
                'Authors',
                $authors,
                true,
                $hasCoverImage,
                $shortWidth,
                $maxWidth,
                $imageCoverageRows
            );
        }

        if ($book->narrators()->count() > 0) {
            $narrators = $book->narrators()->pluck('name')->join(', ');
            $this->addRow(
                $tableData,
                'Narrators',
                $narrators,
                true,
                $hasCoverImage,
                $shortWidth,
                $maxWidth,
                $imageCoverageRows
            );
        }

        if ($book->series()->count() > 0) {
            /** @var \Illuminate\Database\Eloquent\Collection<int, Series> $seriesCollection */
            $seriesCollection = $book->series()->get();

            $seriesInfo = $seriesCollection->map(function (Series $series): string {
                $number = $series->pivot?->getAttribute('series_number');

                return "{$series->name}" . ($number ? " #{$number}" : '');
            })->join(', ');
            $this->addRow(
                $tableData,
                'Series',
                $seriesInfo,
                true,
                $hasCoverImage,
                $shortWidth,
                $maxWidth,
                $imageCoverageRows
            );
        }

        if ($book->genres()->count() > 0) {
            $genres = $book->genres()->pluck('name')->join(', ');
            $this->addRow(
                $tableData,
                'Genres',
                $genres,
                true,
                $hasCoverImage,
                $shortWidth,
                $maxWidth,
                $imageCoverageRows
            );
        }

        $publisherName = $book->publisher ? $book->publisher->name : 'N/A';
        $this->addRow(
            $tableData,
            'Publisher',
            $publisherName,
            true,
            $hasCoverImage,
            $shortWidth,
            $maxWidth,
            $imageCoverageRows
        );

        $this->addRow(
            $tableData,
            'Release Date',
            $book->releaseDate?->format('Y-m-d') ?? 'N/A',
            false,
            $hasCoverImage,
            $shortWidth,
            $maxWidth,
            $imageCoverageRows
        );

        $this->addRow(
            $tableData,
            'Language',
            $book->language ?? 'N/A',
            false,
            $hasCoverImage,
            $shortWidth,
            $maxWidth,
            $imageCoverageRows
        );

        if ($book->duration) {
            $hours = floor($book->duration / 3600);
            $minutes = floor(($book->duration % 3600) / 60);
            $durationStr = "{$hours}h {$minutes}m";
            $this->addRow(
                $tableData,
                'Duration',
                $durationStr,
                false,
                $hasCoverImage,
                $shortWidth,
                $maxWidth,
                $imageCoverageRows
            );
        }

        $this->addRow(
            $tableData,
            'Audio Files',
            (string) ($book->audioFileCount ?? 0),
            false,
            $hasCoverImage,
            $shortWidth,
            $maxWidth,
            $imageCoverageRows
        );

        $this->addRow(
            $tableData,
            'Source',
            $book->source ?? 'N/A',
            false,
            $hasCoverImage,
            $shortWidth,
            $maxWidth,
            $imageCoverageRows
        );

        $directoryPath = $book->directoryPath ?? 'N/A';

        // Check if directory exists on disk
        $bookRoot = config('filesystems.disks.books.root') ?? config('app.book_root', '/media/lyra_data1/audiobooks/books');
        // Resolve symlinks in book root for consistent path handling
        $bookRoot = realpath($bookRoot) ?: $bookRoot;
        $fullPath = $bookRoot . '/' . ltrim($directoryPath, '/');
        // Use file_exists and is_dir together, and check that it's readable
        $directoryExists = file_exists($fullPath) && is_dir($fullPath) && is_readable($fullPath);

        $directoryValue = $directoryPath;

        // Add debug info if requested
        if ($this->option('show-paths')) {
            if (!$directoryExists && $directoryPath !== 'N/A') {
                $directoryValue .= "\n  (Missing: {$fullPath})";
            } elseif ($directoryPath !== 'N/A') {
                $directoryValue .= "\n  (Exists: {$fullPath})";
            }
        }

        // Apply color styling using OutputFormatterStyle
        if (!$directoryExists && $directoryPath !== 'N/A') {
            $directoryValue = "<fg=red>{$directoryValue}</>";
        }

        $this->addRow(
            $tableData,
            'Directory',
            $directoryValue,
            true,
            $hasCoverImage,
            $shortWidth,
            $maxWidth,
            $imageCoverageRows
        );

        if ($book->needsReview) {
            $reasons = $book->needsReviewReasons ? implode(', ', $book->needsReviewReasons) : 'Unknown';
            $this->addRow(
                $tableData,
                'Needs Review',
                "Yes ({$reasons})",
                true,
                $hasCoverImage,
                $shortWidth,
                $maxWidth,
                $imageCoverageRows
            );
        }

        if ($book->description) {
            $description = strip_tags($book->description);
            $description = preg_replace('/\s+/', ' ', $description);
            $description = trim($description);
            $this->addRow(
                $tableData,
                'Description',
                $description,
                true,
                $hasCoverImage,
                $shortWidth,
                $maxWidth,
                $imageCoverageRows
            );
        }

        if ($book->audibleInfo) {
            $this->addRow(
                $tableData,
                'Audible ASIN',
                $book->audibleInfo['asin'] ?? 'N/A',
                false,
                $hasCoverImage,
                $shortWidth,
                $maxWidth,
                $imageCoverageRows
            );
        }

        if ($book->googleBooksInfo) {
            $this->addRow(
                $tableData,
                'Google Books ID',
                $book->googleBooksInfo['id'] ?? 'N/A',
                false,
                $hasCoverImage,
                $shortWidth,
                $maxWidth,
                $imageCoverageRows
            );
        }

        if ($book->hardcoverInfo) {
            $this->addRow(
                $tableData,
                'Hardcover ID',
                $book->hardcoverInfo['id'] ?? 'N/A',
                false,
                $hasCoverImage,
                $shortWidth,
                $maxWidth,
                $imageCoverageRows
            );
        }

        $this->addRow(
            $tableData,
            'Created',
            $book->createdAt?->format('Y-m-d H:i:s') ?? 'N/A',
            false,
            $hasCoverImage,
            $shortWidth,
            $maxWidth,
            $imageCoverageRows
        );
        $this->addRow(
            $tableData,
            'Updated',
            $book->updatedAt?->format('Y-m-d H:i:s') ?? 'N/A',
            false,
            $hasCoverImage,
            $shortWidth,
            $maxWidth,
            $imageCoverageRows
        );

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

    private function addRow(
        array &$rows,
        string $label,
        string $value,
        bool $wrap,
        bool $hasCoverImage,
        int $shortWidth,
        int $maxWidth,
        int $imageCoverageRows
    ): void {
        $rowIndex = count($rows) + 1;
        $preferredWidth = $hasCoverImage && $rowIndex <= $imageCoverageRows ? $shortWidth : $maxWidth;
        $rows[] = [
        $label,
        $wrap ? $this->wrapText($value, $preferredWidth) : $value,
        ];
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
                $bookRoot = config('filesystems.disks.books.root') ?? config('app.book_root', '/media/lyra_data1/audiobooks/books');
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
            /** @var \Illuminate\Database\Eloquent\Collection<int, Series> $seriesCollection */
            $seriesCollection = $book->series()->get();

            $seriesInfo = $seriesCollection->map(function (Series $series): string {
                $number = $series->pivot?->getAttribute('series_number');

                return "{$series->name}" . ($number ? " #{$number}" : '');
            })->join(', ');
            $this->printField('Series', $seriesInfo, $leftWidth);
        }

        if ($book->genres()->count() > 0) {
            $genres = $book->genres()->pluck('name')->join(', ');
            $this->printField('Genres', $genres, $leftWidth);
        }

        $this->printField('Publisher', $book->publisher ? $book->publisher->name : 'N/A', $leftWidth);
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
        $bookRoot = config('filesystems.disks.books.root') ?? config('app.book_root', '/media/lyra_data1/audiobooks/books');
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
        if (empty($text) || $maxWidth <= 0) {
            return $text;
        }

        $tokens = preg_split('/(<[^>]+>|\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $lines = [];
        $currentLine = '';
        $visibleLength = 0;

        $flushLine = static function (array &$lines, string &$currentLine, int &$visibleLength): void {
            if ($currentLine !== '') {
                $lines[] = rtrim($currentLine);
            }

            $currentLine = '';
            $visibleLength = 0;
        };

        foreach ($tokens as $token) {
            if ($token[0] === '<' && str_ends_with($token, '>')) {
                $currentLine .= $token;
                continue;
            }

            if (preg_match('/^\s+$/u', $token)) {
                if (str_contains($token, "\n") || str_contains($token, "\r")) {
                    $segments = preg_split(
                        "/(\r\n|\r|\n)/",
                        $token,
                        -1,
                        PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
                    );
                    foreach ($segments as $segment) {
                        if (preg_match("/\r\n|\r|\n/", $segment)) {
                            $flushLine($lines, $currentLine, $visibleLength);
                        } elseif ($visibleLength > 0) {
                            if ($visibleLength + 1 > $maxWidth) {
                                $flushLine($lines, $currentLine, $visibleLength);
                            }
                            $currentLine .= ' ';
                            $visibleLength += 1;
                        }
                    }
                } elseif ($visibleLength > 0) {
                    if ($visibleLength + 1 > $maxWidth) {
                        $flushLine($lines, $currentLine, $visibleLength);
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
                    $flushLine($lines, $currentLine, $visibleLength);
                    $available = $maxWidth;
                }

                $chunk = mb_substr($remaining, 0, $available);
                $chunkLength = mb_strlen($chunk);

                $currentLine .= $chunk;
                $visibleLength += $chunkLength;
                $remaining = mb_substr($remaining, $chunkLength);

                if ($remaining !== '') {
                    $flushLine($lines, $currentLine, $visibleLength);
                }
            }
        }

        $flushLine($lines, $currentLine, $visibleLength);

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
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $extension = strtolower($file->getExtension());
                if (in_array($extension, $this->audioExtensions)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function updateBookFields(Book $book, ?string $directory): void
    {
        $updated = false;
        $bookRoot = config('filesystems.disks.books.root') ?? config('app.book_root', '/media/lyra_data1/audiobooks/books');
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
            /** @var Publisher $publisher */
            $publisher = $this->publisherModel->newQuery()->firstOrCreate(['name' => $publisherName]);
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
                $book->releaseDate = CarbonImmutable::parse($date);
                $this->info("✓ Updated release date: {$book->releaseDate->format('Y-m-d')}");
                $updated = true;
            } catch (\Throwable $e) {
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
        if (
            app()->environment('testing') ||
            !is_resource(STDIN) ||
            (function_exists('posix_isatty') && !@posix_isatty(STDIN))
        ) {
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
    protected function openUrlInBrowser(string $url): void
    {
        $this->info("Opening edit page: {$url}");

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
                    exec("{$cmd} " . escapeshellarg($url) . " > /dev/null 2>&1 &");
                    return;
                }
            }
            $this->warn("Could not find a browser to open the URL. Please open manually:");
            $this->line("  {$url}");
        } elseif ($os === 'Darwin') {
            exec("open " . escapeshellarg($url));
        } elseif ($os === 'Windows') {
            exec("start " . escapeshellarg($url));
        } else {
            $this->warn("Unknown OS. Please open this URL manually:");
            $this->line("  {$url}");
        }
    }

    /**
     * Open the book's edit page in browser
     */
    protected function openEditPage(Book $book): void
    {
        $appUrl = rtrim(config('app.url', 'http://localhost'), '/');
        $editUrl = $appUrl . '/admin/books/' . $book->id . '/edit';
        $this->openUrlInBrowser($editUrl);
    }

    /**
     * Interactively merge duplicate book directories.
     */
    protected function handleMerge(): int
    {
        $directories = $this->argument('directories');

        // Clean + deduplicate as in handle()
        $cleaned = [];
        foreach ($directories as $dir) {
            foreach (preg_split('/[\r\n,]+/', $dir) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $cleaned[] = $part;
                }
            }
        }
        $directories = array_unique($cleaned);

        if (count($directories) < 2) {
            $this->error('--merge requires at least two directories.');
            return Command::FAILURE;
        }

        $bookRoot = realpath(config('filesystems.disks.books.root') ?? config('app.book_root', '/media/lyra_data1/audiobooks/books')) ?: config('filesystems.disks.books.root');

        // Resolve each argument (ID or directory path) to a Book
        $books = collect();
        foreach ($directories as $dir) {
            if (ctype_digit($dir)) {
                /** @var Book|null $book */
                $book = $this->queryBook()->find($dir);
                if (!$book) {
                    $this->error("Book not found with ID: {$dir}");
                    return Command::FAILURE;
                }
                $books->push($book);
                continue;
            }

            $realDir = realpath($dir) ?: (realpath(rtrim($bookRoot, '/') . '/' . ltrim($dir, '/')) ?: null);
            if (!$realDir || !is_dir($realDir)) {
                $this->error("Directory not found: {$dir}");
                return Command::FAILURE;
            }

            $searchPath = str_starts_with($realDir, $bookRoot)
                ? ltrim(substr($realDir, strlen($bookRoot)), '/')
                : $realDir;

            /** @var Book|null $book */
            $book = $this->queryBook()->where('directory_path', $searchPath)->first();
            if (!$book) {
                $this->error("No book found in database for: {$dir}");
                return Command::FAILURE;
            }

            $books->push($book);
        }

        if ($this->option('edit')) {
            $bookIds = $books->pluck('id')->sort()->values()->all();
            $directoryPath = $books->first()->directory_path ?? 'cli-merge-' . time();

            /** @var \App\Models\LibraryRepairIssue $issue */
            $issue = \App\Models\LibraryRepairIssue::query()->firstOrNew([
                'issue_type' => \App\Enums\LibraryRepairIssueType::DUPLICATE_DIRECTORY->value,
                'directory_path' => $directoryPath,
            ]);

            $issue->metadata = ['book_ids' => $bookIds];
            $issue->status = 'pending';
            $issue->save();

            $appUrl = rtrim(config('app.url', 'http://localhost'), '/');
            $url = $appUrl . '/admin/library-repair/' . $issue->id . '/compare';
            $this->openUrlInBrowser($url);

            return Command::SUCCESS;
        }

        // ---- Display comparison table ----
        $this->newLine();
        $this->line('<fg=cyan>Comparing ' . $books->count() . ' books:</fg=cyan>');
        $this->newLine();

        $compareFields = [
            'title'       => 'Title',
            'authors'     => 'Authors',
            'narrators'   => 'Narrators',
            'series'      => 'Series',
            'genres'      => 'Genres',
            'language'    => 'Language',
            'release_date' => 'Release Date',
            'isbn'        => 'ISBN',
            'asin'        => 'ASIN',
            'description' => 'Description',
        ];

        $fieldValues = [];
        foreach ($compareFields as $field => $label) {
            $fieldValues[$field] = $books->map(function (Book $book) use ($field): string {
                return match ($field) {
                    'authors'   => $book->authors->pluck('name')->join(', '),
                    'narrators' => $book->narrators->pluck('name')->join(', '),
                    'series'    => $book->series->map(function (Series $s): string {
                        $n = $s->pivot?->getAttribute('series_number');
                        return $s->name . ($n ? " #{$n}" : '');
                    })->join(', '),
                    'genres'    => $book->genres->pluck('name')->join(', '),
                    'release_date' => $book->release_date?->format('Y-m-d') ?? '',
                    default     => (string) ($book->$field ?? ''),
                };
            })->all();
        }

        // Render comparison table
        $headers = ['Field', ...$books->map(fn (Book $b) => "Book #{$b->id}")->all()];
        $rows = [];
        foreach ($compareFields as $field => $label) {
            $values = $fieldValues[$field];
            $differs = count(array_unique($values)) > 1;
            $labelText = $differs ? "<fg=yellow>{$label}</>" : $label;
            $row = [$labelText];
            foreach ($values as $val) {
                $row[] = mb_strimwidth($val ?: '—', 0, 50, '…');
            }
            $rows[] = $row;
        }

        $this->table($headers, $rows);

        // ---- Choose keeper ----
        $keeperChoices = $books->map(fn (Book $b) => "Book #{$b->id}: " . ($b->title ?? '(no title)'))->all();
        $keeperAnswer = $this->choice('Which book should be kept (the DB record that survives)?', $keeperChoices, 0);
        $keeperIndex = array_search($keeperAnswer, $keeperChoices, true);
        /** @var Book $keeper */
        $keeper = $books->get($keeperIndex);

        // ---- Per-field source selection (only for differing fields) ----
        $fieldSources = [];
        $mergeableFields = array_keys(array_filter($compareFields, function ($label, $field) use ($fieldValues): bool {
            return count(array_unique($fieldValues[$field])) > 1;
        }, ARRAY_FILTER_USE_BOTH));

        if (!empty($mergeableFields)) {
            $this->newLine();
            $this->line('<fg=cyan>Select field sources (fields that differ between books):</fg=cyan>');
            $this->line('Press Enter to use the keeper\'s value for each field.');
            $this->newLine();

            foreach ($mergeableFields as $field) {
                $label = $compareFields[$field];
                $choices = $books->map(fn (Book $b, int $i) => "Book #{$b->id}: " . mb_strimwidth($fieldValues[$field][$i] ?: '(empty)', 0, 60, '…'))->all();
                $defaultIndex = $keeperIndex;
                $answer = $this->choice("  {$label}?", $choices, $defaultIndex);
                $chosenIdx = array_search($answer, $choices, true);
                $chosenBook = $books->get($chosenIdx);
                if ($chosenBook && $chosenBook->id !== $keeper->id) {
                    $fieldSources[$field] = $chosenBook->id;
                }
            }
        }

        // ---- File selection ----
        $directoryPath = trim($keeper->directory_path ?? '', '/');
        $listing = $this->bookFilesystemService->listFiles($directoryPath);
        $keepFiles = [];

        if ($listing['exists'] && !empty($listing['files'])) {
            $this->newLine();
            $this->line('<fg=cyan>Files in shared directory (select files to KEEP):</fg=cyan>');
            $this->newLine();

            foreach ($listing['files'] as $file) {
                $ext = strtolower($file['extension'] ?? '');
                $sizeMb = number_format(($file['size'] ?? 0) / 1048576, 1);
                $isAudioOrImage = in_array($ext, self::AUDIO_EXTENSIONS, true)
                    || in_array($ext, self::IMAGE_EXTENSIONS, true);
                $default = $isAudioOrImage;
                if ($this->confirm("  Keep {$file['name']} ({$sizeMb} MB)?", $default)) {
                    $keepFiles[] = $file['name'];
                }
            }
        }

        // ---- Summary + confirm ----
        $this->newLine();
        $this->line('<fg=yellow>Summary:</fg=yellow>');
        $this->line("  Keeper book: #{$keeper->id} — " . ($keeper->title ?? '(no title)'));
        $duplicateIds = $books->reject(fn (Book $b) => $b->id === $keeper->id)->pluck('id')->all();
        $this->line('  Books to trash: ' . implode(', ', array_map(fn ($id) => "#{$id}", $duplicateIds)));
        if (!empty($fieldSources)) {
            $this->line('  Field overrides:');
            foreach ($fieldSources as $field => $sourceId) {
                $this->line("    {$compareFields[$field]}: from Book #{$sourceId}");
            }
        }
        $trashedFiles = collect($listing['files'] ?? [])->pluck('name')->diff($keepFiles)->all();
        if (!empty($trashedFiles)) {
            $this->line('  Files to trash: ' . implode(', ', $trashedFiles));
        }

        $this->newLine();
        if (!$this->option('force') && !$this->confirm('Proceed with merge?', false)) {
            $this->info('Cancelled.');
            return Command::SUCCESS;
        }

        // ---- Execute merge ----
        $booksById = $books->keyBy('id');

        $this->applyMergeFieldSources($keeper, $booksById, $fieldSources);
        $keeper->forceFill(['needs_review' => false, 'needs_review_reasons' => null])->save();
        $this->info('✓ Keeper metadata updated.');

        if (!empty($listing['files'])) {
            $this->trashMergeFiles($directoryPath, $keepFiles);
            $this->info('✓ Unchecked files moved to trash.');
        }

        foreach ($duplicateIds as $dupId) {
            $result = $this->deletionService->moveToTrash((string) $dupId, false);
            if ($result['success'] ?? false) {
                $this->info("✓ Book #{$dupId} trashed (DB only).");
            } else {
                $this->warn("⚠ Could not trash Book #{$dupId}: " . ($result['error'] ?? 'Unknown error'));
            }
        }

        $this->newLine();
        $this->info('Merge complete.');
        return Command::SUCCESS;
    }

    /**
     * Apply field source overrides to keeper book (mirrors controller applyFieldSources).
     *
     * @param \Illuminate\Database\Eloquent\Collection<int,Book> $books
     * @param array<string,int> $fieldSources field => source book id
     */
    private function applyMergeFieldSources(Book $keeper, $books, array $fieldSources): void
    {
        $scalarFields = ['title', 'description', 'language', 'asin', 'release_date', 'isbn', 'cover_image'];
        $relationFields = ['authors', 'narrators', 'series', 'genres'];

        $scalarUpdates = [];
        foreach ($scalarFields as $field) {
            $sourceId = $fieldSources[$field] ?? null;
            if ($sourceId === null || $sourceId === $keeper->id) {
                continue;
            }
            $source = $books->get($sourceId);
            if ($source === null) {
                continue;
            }
            $scalarUpdates[$field] = $source->$field;
        }
        if (!empty($scalarUpdates)) {
            $keeper->forceFill($scalarUpdates)->save();
        }

        foreach ($relationFields as $field) {
            $sourceId = $fieldSources[$field] ?? null;
            if ($sourceId === null || $sourceId === $keeper->id) {
                continue;
            }
            $source = $books->get($sourceId);
            if ($source === null) {
                continue;
            }
            $this->syncMergeRelation($keeper, $source, $field);
        }
    }

    private function syncMergeRelation(Book $keeper, Book $source, string $field): void
    {
        if ($field === 'series') {
            $syncData = [];
            foreach ($source->series as $series) {
                $seriesNumber = $series->pivot->getAttribute('series_number');
                $syncData[$series->id] = ['series_number' => $seriesNumber];
            }
            $keeper->series()->sync($syncData);
            return;
        }

        $ids = $source->$field->pluck('id')->all();
        match ($field) {
            'authors'   => $keeper->authors()->sync($ids),
            'narrators' => $keeper->narrators()->sync($ids),
            'genres'    => $keeper->genres()->sync($ids),
            default     => null,
        };
    }

    /**
     * @param array<int,string> $keepFiles
     */
    private function trashMergeFiles(string $directoryPath, array $keepFiles): void
    {
        $directoryPath = trim($directoryPath, '/');
        $listing = $this->bookFilesystemService->listFiles($directoryPath);
        if (!$listing['exists']) {
            return;
        }

        $timestamp = now()->format('Y-m-d_His');
        $trashItemId = $timestamp . '_files_' . md5($directoryPath);
        $trashDisk = Storage::disk('trash');
        $booksDisk = Storage::disk('books');

        foreach ($listing['files'] as $file) {
            $filename = $file['name'];
            if (in_array($filename, $keepFiles, true)) {
                continue;
            }
            $relativePath = $directoryPath . '/' . $filename;
            if (!$booksDisk->exists($relativePath)) {
                continue;
            }
            $trashDisk->makeDirectory($trashItemId . '/files');
            $trashPath = $trashItemId . '/files/' . $filename;
            try {
                rename($booksDisk->path($relativePath), $trashDisk->path($trashPath));
            } catch (\Throwable $e) {
                $this->warn("Could not trash file {$filename}: " . $e->getMessage());
            }
        }
    }

    /**
     * Handle book deletion
     */
    protected function handleDelete(string $deleteInput): int
    {
        $bookRoot = config('app.book_root', '/media/lyra_data1/audiobooks/books');
        $bookRoot = realpath($bookRoot) ?: $bookRoot;

        $items = array_map('trim', explode(',', $deleteInput));
        $booksToDelete = collect();
        $orphanDirectories = [];
        $notFoundItems = [];

        foreach ($items as $item) {
            if (ctype_digit($item)) {
                $book = $this->queryBook()->find($item);
                if ($book) {
                    $booksToDelete->push($book);
                } else {
                    $notFoundItems[] = "Book ID: {$item}";
                }
            } else {
                $item = rtrim($item, '/');
                $trimmedBookRoot = rtrim($bookRoot, '/');
                $books = collect();

                // Build candidate search paths to try against the DB
                $searchPaths = [];

                // If absolute path under book root, strip to relative
                if (str_starts_with($item, $trimmedBookRoot . '/')) {
                    $searchPaths[] = ltrim(substr($item, strlen($trimmedBookRoot)), '/');
                }

                // Shell CWD from wrapper script (getcwd/PWD return artisan project dir)
                $cwd = getenv('SHELL_CWD') ?: (getenv('PWD') ?: getcwd());
                $cwd = realpath($cwd) ?: $cwd;
                if ($cwd && str_starts_with($cwd, $trimmedBookRoot)) {
                    $cwdRelative = ltrim(substr($cwd, strlen($trimmedBookRoot)), '/');
                    $searchPaths[] = $cwdRelative ? $cwdRelative . '/' . $item : $item;
                }

                // As-is relative from book root
                $searchPaths[] = ltrim($item, '/');

                foreach ($searchPaths as $searchPath) {
                    $books = $this->queryBook()->where('directory_path', $searchPath)->get();
                    if ($books->isNotEmpty()) {
                        break;
                    }
                    $prefixSearch = rtrim($searchPath, '/') . '/%';
                    $books = $this->queryBook()->where('directory_path', 'LIKE', $prefixSearch)->get();
                    if ($books->isNotEmpty()) {
                        break;
                    }
                }

                if ($books->isEmpty()) {
                    // No DB record — check if the directory exists on disk (orphan)
                    $resolvedRelativePath = null;
                    foreach ($searchPaths as $searchPath) {
                        if (Storage::disk('books')->exists($searchPath)) {
                            $resolvedRelativePath = $searchPath;
                            break;
                        }
                    }

                    if ($resolvedRelativePath !== null) {
                        $orphanDirectories[] = $resolvedRelativePath;
                    } else {
                        $notFoundItems[] = "Directory: {$item}";
                    }
                }

                foreach ($books as $book) {
                    if (!$booksToDelete->contains('id', $book->id)) {
                        $booksToDelete->push($book);
                    }
                }
            }
        }

        if (!empty($notFoundItems)) {
            $this->warn("The following items were not found:");
            foreach ($notFoundItems as $item) {
                $this->line("  - {$item}");
            }
            $this->newLine();

            if (!$this->option('force') && !$this->confirm("Continue with remaining books?", true)) {
                $this->info("Cancelled");
                return 0;
            }
        }

        if ($booksToDelete->isEmpty() && empty($orphanDirectories)) {
            $this->error("No books found to delete");
            return 1;
        }

        $this->info("Found {$booksToDelete->count()} book(s) to delete:");
        $this->newLine();

        foreach ($booksToDelete as $book) {
            $this->line("  ID: {$book->id}");
            $this->line("  Title: {$book->title}");
            $this->line("  Author: " .
                ($book->authors()->count() > 0 ? $book->authors()->pluck('name')->join(', ') : 'N/A'));
            $this->line("  Directory: {$book->directoryPath}");
            $this->newLine();
        }

        $this->newLine();

        $successCount = 0;
        $failCount = 0;
        $skippedCount = 0;

        foreach ($booksToDelete as $index => $book) {
            $this->line("<fg=cyan>Book " . ($index + 1) . " of {$booksToDelete->count()}:</>");
            $this->line("  ID: {$book->id}");
            $this->line("  Title: {$book->title}");
            $this->line("  Author: " .
                ($book->authors()->count() > 0 ? $book->authors()->pluck('name')->join(', ') : 'N/A'));
            $this->line("  Directory: {$book->directoryPath}");

            $fullPath = $bookRoot . '/' . ltrim($book->directoryPath, '/');
            $directoryExists = file_exists($fullPath) && is_dir($fullPath);

            $deleteDirectory = false;

            if ($directoryExists) {
                $this->line("  Directory exists: <fg=green>Yes</>");

                $otherBooks = $this->queryBook()
                    ->where('directory_path', $book->directoryPath)
                    ->where('id', '!=', $book->id)
                    ->get();

                if ($otherBooks->count() > 0) {
                    $this->error(
                        "\n⚠️  WARNING: This directory is used by " . $otherBooks->count() . " other book(s):"
                    );
                    foreach ($otherBooks as $otherBook) {
                        $this->line("  - ID {$otherBook->id}: {$otherBook->title}");
                    }
                    $this->error("\nCannot delete directory that is shared with other books!");
                    $this->line("The book record will be deleted, but the directory will be preserved.");

                    if (
                        !$this->option('force') &&
                        !$this->confirm("\nDelete only the book record (preserve directory)?", false)
                    ) {
                        $skippedCount++;
                        $this->info("Skipped");
                        $this->newLine();
                        continue;
                    }

                    $deleteDirectory = false;
                } else {
                    $this->line("  No other books use this directory");

                    if ($this->option('force')) {
                        $deleteDirectory = false;
                    } else {
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
                            $skippedCount++;
                            $this->info("Skipped");
                            $this->newLine();
                            continue;
                        }

                        $deleteDirectory = $choice === 'Book record AND directory (delete everything)';
                    }
                }
            } else {
                $this->line("  Directory exists: <fg=red>No</>");

                if (!$this->option('force') && !$this->confirm("\nDelete book record?", false)) {
                    $skippedCount++;
                    $this->info("Skipped");
                    $this->newLine();
                    continue;
                }

                $deleteDirectory = false;
            }

            try {
                $result = $this->deletionService->moveToTrash((string) $book->id, $deleteDirectory);

                if ($result['success']) {
                    $successCount++;
                    if ($deleteDirectory) {
                        $this->info("✓ Book and files moved to trash");
                        $this->line("  Trash ID: {$result['trash_item_id']}");
                        $this->line("  Files moved: {$result['file_count']}");
                    } else {
                        $this->info("✓ Book record moved to trash (files preserved)");
                        $this->line("  Trash ID: {$result['trash_item_id']}");
                    }
                } else {
                    $failCount++;
                    $this->error("✗ Failed to delete book: " . ($result['error'] ?? 'Unknown error'));
                }
            } catch (\Exception $e) {
                $failCount++;
                $this->error("✗ Failed to delete book: " . $e->getMessage());
            }

            $this->newLine();
        }

        foreach ($orphanDirectories as $orphanPath) {
            $this->line("<fg=yellow>Orphan directory (no DB record):</> {$orphanPath}");

            if (!$this->option('force') && !$this->confirm("Move orphan directory to trash?", true)) {
                $skippedCount++;
                $this->info("Skipped");
                $this->newLine();
                continue;
            }

            try {
                $result = $this->deletionService->moveOrphanDirectoryToTrash($orphanPath);

                if ($result['success']) {
                    $successCount++;
                    $this->info("✓ Orphan directory moved to trash");
                    $this->line("  Trash ID: {$result['trash_item_id']}");
                    $this->line("  Files moved: {$result['file_count']}");
                } else {
                    $failCount++;
                    $this->error("✗ Failed to move orphan directory: " . ($result['error'] ?? 'Unknown error'));
                }
            } catch (\Exception $e) {
                $failCount++;
                $this->error("✗ Failed to move orphan directory: " . $e->getMessage());
            }

            $this->newLine();
        }

        $this->info("Deletion completed: {$successCount} deleted, {$skippedCount} skipped, {$failCount} failed");

        if ($successCount > 0) {
            $this->info("You can restore deleted books from /admin/trash");
        }

        return $failCount > 0 ? 1 : 0;
    }
}
