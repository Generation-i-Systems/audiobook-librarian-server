<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\Series;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RepairBooks extends Command
{
    protected $signature = 'books:repair
        {book_id? : The ID of the book to repair}
        {--cover : Repair book covers}
        {--series : Repair series numbers}
        {--title : Repair book titles (leading chars, series numbers)}
        {--all : Repair all aspects (covers, series, titles)}
        {--force : Skip confirmation prompts}
        {--no-backup : Skip automatic database backup}';

    protected $description = 'Repair book metadata including covers, series numbers, and titles (creates a database backup by default)';

    protected $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public function handle()
    {
        // Create a database backup unless --no-backup is specified
        if (! $this->option('no-backup')) {
            $this->info('Creating a database backup before repairing books...');
            $this->call('backup:database');
            $this->info('Database backup created.');
        }

        $this->info('Starting book repair process...');

        $repairAll = (bool) $this->option('all');
        $repairCovers = (bool) $this->option('cover') || $repairAll;
        $repairSeries = (bool) $this->option('series') || $repairAll;
        $repairTitles = (bool) $this->option('title') || $repairAll;
        $repairTitleAndSeries = $repairTitles || $repairSeries;

        if (! $repairCovers && ! $repairTitleAndSeries) {
            $this->error('Please specify at least one repair action (--cover, --series, --title, or --all)');

            return Command::FAILURE;
        }

        $bookId = $this->argument('book_id');
        $books = $bookId ? Book::where('id', $bookId)->get() : Book::all();

        if ($books->isEmpty()) {
            $this->info('No books found to repair.');

            return Command::SUCCESS;
        }

        $processedCount = 0;
        $repairedCount = 0;

        $progressBar = $this->output->createProgressBar($books->count());
        $progressBar->start();

        foreach ($books as $book) {
            $changesMade = false;

            if ($repairCovers) {
                if ($this->repairCover($book)) {
                    $changesMade = true;
                }
            }

            if ($repairTitleAndSeries) {
                if ($this->repairTitleAndSeries($book)) {
                    $changesMade = true;
                }
            }

            if ($changesMade) {
                $repairedCount++;
            }
            $processedCount++;
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Repair process completed. Processed {$processedCount} books, repaired {$repairedCount} books.");

        return Command::SUCCESS;
    }

    protected function repairCover(Book $book): bool
    {
        $originalCoverImage = $book->coverImage;
        $directoryPath = $book->directoryPath;
        $changesMade = false;

        if (empty($directoryPath)) {
            $this->warn("Book ID: {$book->id} has no directoryPath. Cannot repair cover.");

            return false;
        }

        // Case 1: coverImage is missing or invalid (e.g., a full URL)
        if (empty($originalCoverImage) || filter_var($originalCoverImage, FILTER_VALIDATE_URL)) {
            $this->warn("Book ID: {$book->id} - coverImage is missing or invalid. Attempting to find a suitable replacement...");
            $bestImage = $this->findBestCoverImage($book, 'books');

            if ($bestImage) {
                $book->coverImage = $bestImage;
                $changesMade = true;
                $this->info("  -> Fixed Book ID: {$book->id} - new coverImage: {$bestImage}");
            } else {
                $this->error("  -> Could not find a suitable cover image for Book ID: {$book->id} in directory: {$directoryPath}");
            }
        } elseif (! Str::startsWith($originalCoverImage, $directoryPath)) {
            // Case 2: coverImage exists but doesn't contain the directory_path prefix
            $expectedCoverPath = rtrim($directoryPath, '/') . '/' . basename($originalCoverImage);

            if (Storage::disk('books')->exists($expectedCoverPath)) {
                $book->coverImage = $expectedCoverPath;
                $changesMade = true;
                $this->info("  -> Fixed Book ID: {$book->id} - prepended directoryPath to coverImage: {$expectedCoverPath}");
            } else {
                $this->warn("  -> Book ID: {$book->id} - coverImage '{$originalCoverImage}' does not match directoryPath and expected path '{$expectedCoverPath}' does not exist.");
                // Fallback to finding best image if the expected path doesn't exist
                $bestImage = $this->findBestCoverImage($book, 'books');

                if ($bestImage) {
                    $book->coverImage = $bestImage;
                    $changesMade = true;
                    $this->info("  -> Fixed Book ID: {$book->id} - found new best coverImage: {$bestImage}");
                }
            }
        }

        if ($changesMade) {
            $book->save();
        }

        return $changesMade;
    }

    protected function findBestCoverImage(Book $book, string $diskName): ?string
    {
        $directoryPath = $book->directoryPath;

        if (empty($directoryPath)) {
            return null;
        }

        $fullBookDirPath = rtrim($directoryPath, '/');

        if (! Storage::disk($diskName)->exists($fullBookDirPath)) {
            return null;
        }

        $filesInDir = Storage::disk($diskName)->files($fullBookDirPath);

        $bestCoverCandidate = null;
        $audibleGoogleCandidate = null;
        $bestTitleMatchCandidate = null;
        $anyImageCandidate = null;

        $normalizedBookTitle = Str::slug($book->title ?? '');

        foreach ($filesInDir as $filePath) {
            $extension = pathinfo($filePath, PATHINFO_EXTENSION);

            if (! in_array(strtolower($extension), $this->imageExtensions)) {
                continue;
            }

            $fileName = basename($filePath);
            $normalizedFileName = Str::slug(pathinfo($fileName, PATHINFO_FILENAME));

            if (Str::contains(strtolower($fileName), 'cover')) {
                $bestCoverCandidate = $filePath;
                break;
            }

            if (Str::contains(strtolower($fileName), ['audible', 'google'])) {
                if (empty($audibleGoogleCandidate)) {
                    $audibleGoogleCandidate = $filePath;
                }
            }

            if (! empty($normalizedBookTitle) && Str::contains($normalizedFileName, $normalizedBookTitle)) {
                if (empty($bestTitleMatchCandidate)) {
                    $bestTitleMatchCandidate = $filePath;
                }
            }

            if (empty($anyImageCandidate)) {
                $anyImageCandidate = $filePath;
            }
        }

        return $bestCoverCandidate ?? $audibleGoogleCandidate ?? $bestTitleMatchCandidate ?? $anyImageCandidate;
    }

    protected function repairTitleAndSeries(Book $book): bool
    {
        $originalTitle = $book->title;
        $originalSeriesId = $book->series->first()->id ?? null;
        $originalSeriesNumber = $book->series->first()->pivot->series_number ?? null;
        $changesMade = false;

        $newTitle = $originalTitle;
        $newSeriesNumber = $originalSeriesNumber;

        // 1. Clean leading spaces and dashes
        $cleanedTitle = preg_replace('/^[\s\-]+/', '', trim($newTitle));

        if ($cleanedTitle !== $newTitle) {
            $newTitle = $cleanedTitle;
            $changesMade = true;
            $this->info("  -> Book ID: {$book->id} - Removed leading spaces/dashes from title.");
        }

        // 2. Extract leading numbers as series number
        if (preg_match('/^(\d+)[\s\-]* (.*)$/', $newTitle, $matches)) {
            $extractedNumber = (int) $matches[1];
            $remainingTitle = $matches[2];

            // Only apply if it looks like a series number and not just a year or part of title
            // Simple heuristic: if it's a small number and there's an existing series, or no series
            if ($extractedNumber > 0 && $extractedNumber < 1000) { // Avoid treating years as series numbers
                if ($newSeriesNumber === null || $newSeriesNumber != $extractedNumber) {
                    $newSeriesNumber = $extractedNumber;
                    $newTitle = $remainingTitle;
                    $changesMade = true;
                    $this->info("  -> Book ID: {$book->id} - Extracted series number '{$extractedNumber}' from title.");
                }
            }
        }

        // Update book title if changed
        if ($book->title !== $newTitle) {
            $book->title = $newTitle;
            $changesMade = true;
        }

        // Update series number if changed
        if ($newSeriesNumber !== $originalSeriesNumber) {
            if ($book->series->isNotEmpty()) {
                $series = $book->series->first();
                $book->series()->updateExistingPivot($series->id, ['series_number' => $newSeriesNumber]);
                $changesMade = true;
                $this->info("  -> Book ID: {$book->id} - Updated series number to '{$newSeriesNumber}'.");
            } elseif ($newSeriesNumber !== null) {
                // If there's no series associated but we found a number, log it for manual review
                $this->warn("  -> Book ID: {$book->id} - Found series number '{$newSeriesNumber}' but no associated series. Manual review needed.");
            }
        }

        if ($changesMade) {
            $book->save();
        }

        return $changesMade;
    }

    protected function confirmAction($message, $default = false)
    {
        if ($this->option('force')) {
            return true;
        }

        return $this->confirm($message, $default);
    }
}
