<?php

namespace App\Console\Commands;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Series;
use App\Services\TerminalImageService;
use Illuminate\Console\Command;

class ShowBookInfo extends Command
{
    protected $signature = 'books:info {directories?*}
                            {--compact : Use compact view instead of table}
                            {--cover= : Update cover image (filename or path)}
                            {--title= : Update book title}
                            {--author=* : Update authors (+add, -remove, or replace all)}
                            {--series=* : Update series (+add, -remove, or replace all)}
                            {--genre=* : Update genres (+add, -remove, or replace all)}
                            {--publisher= : Update publisher name}
                            {--language= : Update language code}
                            {--release-date= : Update release date (YYYY-MM-DD)}
                            {--description= : Update book description}
                            {--source= : Update source of the book data}';

    protected $description = 'Display and optionally update book information from database';

    protected $aliases = ['books:show'];

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
            // Update book if any options were provided
            if ($this->hasUpdateOptions()) {
                $this->updateBookFields($book, $directory);
            }
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
        // Check if we have a cover image to display
        $coverPath = null;
        if ($book->coverImage) {
            $coverPath = $book->coverImage;
            if (!str_starts_with($coverPath, 'http')) {
                $bookRoot = config('app.book_root', '/media/lyra_data1/audiobooks/books');
                $coverPath = $bookRoot . '/' . ltrim($coverPath, '/');
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
        $wrappedDirectory = $this->wrapText($book->directoryPath ?? 'N/A', $directoryWidth);
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
        $bufferedOutput = new \Symfony\Component\Console\Output\BufferedOutput();
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

        // Display the table
        echo $tableOutput;

        // Display image overlaid on upper right of table
        if ($coverPath && $this->terminalImageService->supportsImages() && $rightBorderPos !== null) {
            $imageWidth = 15;
            $imageHeight = 13;

            // Calculate horizontal position: rightBorderPos - imageWidth + 1
            $leftPos = max(0, $rightBorderPos - $imageWidth + 1);

            // Move cursor up to the second line of the table (tableLines - 1 to get to top, then +1 for second line)
            // But we want to start one line higher, so tableLines instead of tableLines - 1
            $linesToMoveUp = $tableLines;
            echo "\033[{$linesToMoveUp}A"; // Move cursor up

            // Move cursor to the left position
            echo "\033[{$leftPos}G"; // Move cursor to column (1-indexed)

            // Get the current cursor position to calculate absolute coordinates for --place
            // We'll use stty to read cursor position
            $cursorPos = $this->getCursorPosition();

            if ($cursorPos) {
                [$cursorRow, $cursorCol] = $cursorPos;

                // Now we know exactly where we are on screen
                $place = "{$imageWidth}x{$imageHeight}@{$cursorCol}x{$cursorRow}";

                $this->terminalImageService->displayImage($coverPath, function ($msg) {
                    // Silent - image will overlay the table
                }, 'left', $place);

                // Move cursor back to bottom of table
                echo "\033[{$linesToMoveUp}B"; // Move cursor down
                echo "\r"; // Move to beginning of line
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
                $coverPath = $bookRoot . '/' . ltrim($coverPath, '/');
            }
            if (file_exists($coverPath) || str_starts_with($book->coverImage, 'http')) {
                $this->terminalImageService->displayImage(
                    $coverPath,
                    fn($msg) => $this->line($msg)
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
        $this->printField('Directory', $book->directoryPath ?? 'N/A', $leftWidth);

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

    protected function updateBookFields(Book $book, string $directory): void
    {
        $updated = false;
        $bookRoot = config('app.book_root', '/media/lyra_data1/audiobooks/books');

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
                $book->coverImage = $coverInput;
                $this->info("✓ Updated cover image URL: {$coverInput}");
                $updated = true;
            } elseif (file_exists($coverInput)) {
                $realCoverPath = realpath($coverInput);

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
        // Save current terminal settings
        $sttySettings = shell_exec('stty -g 2>&1 < /dev/tty');

        // Set terminal to raw mode to read response
        shell_exec('stty -icanon -echo 2>&1 < /dev/tty');

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
        shell_exec("stty {$sttySettings} 2>&1 < /dev/tty");

        // Parse response (format: ESC[row;colR)
        if (preg_match('/\033\[(\d+);(\d+)R/', $response, $matches)) {
            return [(int)$matches[1], (int)$matches[2]];
        }

        return null;
    }
}
