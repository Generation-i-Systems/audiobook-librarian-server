<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Book; // Assuming your Book model is here
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CheckCoverImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cover:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Checks for inconsistencies in book cover images and attempts to fix them.';

    /**
     * Image file extensions to consider.
     *
     * @var array
     */
    protected $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting cover image consistency check and fix...');

        // Use the 'books' disk as defined in config/filesystems.php
        $diskName = 'books';

        // Ensure the disk is configured
        if (!config("filesystems.disks.$diskName")) {
            $this->error("Filesystem disk \"$diskName\" not configured. Please check config/filesystems.php");
            return Command::FAILURE;
        }

        $books = Book::all();

        $this->comment("\n--- Attempting to fix missing/invalid cover images ---");
        $fixedCount = 0;

        foreach ($books as $book) {
            $currentCoverImage = $book->coverImage;
            $directoryPath = $book->directoryPath;
            $needsReviewReasons = json_decode($book->needs_review_reasons ?? '[]', true);
            $originalNeedsReview = $book->needs_review;

            $needsFix = false;
            $reason = '';

            // Check for invalid directoryPath first
            $fullBookDirPath = rtrim($directoryPath, '/');
            if (empty($directoryPath) || !Storage::disk($diskName)->exists($fullBookDirPath)) {
                $this->warn("Book ID: {$book->id} - directoryPath is invalid or does not exist: {$directoryPath}");
                if (!in_array('invalid directoryPath', $needsReviewReasons)) {
                    $needsReviewReasons[] = 'invalid directoryPath';
                }
                $book->needs_review = true;
                // If directory is invalid, we cannot find a cover, so skip further cover checks for this book
                $book->needs_review_reasons = json_encode($needsReviewReasons);
                if ($book->isDirty()) {
                    $book->save();
                }
                continue;
            }

            if (empty($currentCoverImage)) {
                $needsFix = true;
                $reason = 'empty';
            } elseif (filter_var($currentCoverImage, FILTER_VALIDATE_URL)) {
                $needsFix = true;
                $reason = 'full URL';
                if (!in_array('invalid image', $needsReviewReasons)) {
                    $needsReviewReasons[] = 'invalid image';
                }
                $book->needs_review = true;
            } else {
                // Check if the current cover image file exists on disk
                // $currentCoverImage is now expected to be the full path relative to the disk root
                if (!Storage::disk($diskName)->exists($currentCoverImage)) {
                    $needsFix = true;
                    $reason = 'file not found';
                    if (!in_array('invalid image', $needsReviewReasons)) {
                        $needsReviewReasons[] = 'invalid image';
                    }
                    $book->needs_review = true;
                }
            }

            if ($needsFix) {
                $this->warn("Book ID: {$book->id} - coverImage is {$reason}. Attempting to find a suitable replacement...");
                $bestImage = $this->findBestCoverImage($book, $diskName);

                if ($bestImage) {
                    // Check if the best image needs directoryPath prefix
                    $finalCoverPath = $this->processCoverImagePath($bestImage, $directoryPath);
                    $book->coverImage = $finalCoverPath;
                    $book->needs_review = false; // Clear needs_review if fixed
                    $needsReviewReasons = array_diff($needsReviewReasons, ['invalid image']); // Remove reason
                    $book->needs_review_reasons = json_encode(array_values($needsReviewReasons));
                    $book->save();
                    $this->info("  -> Fixed Book ID: {$book->id} - new coverImage: {$finalCoverPath}");
                    $fixedCount++;
                } else {
                    $this->error("  -> Could not find a suitable cover image for Book ID: {$book->id} in directory: {$directoryPath}");
                    // If no fix found, ensure needs_review is set
                    if (!$originalNeedsReview) { // Only set if it wasn't already set by directoryPath check
                        $book->needs_review = true;
                        if (!in_array('no suitable image found', $needsReviewReasons)) {
                            $needsReviewReasons[] = 'no suitable image found';
                        }
                        $book->needs_review_reasons = json_encode($needsReviewReasons);
                        $book->save();
                    }
                }
            } else {
                // If no fix was needed, ensure needs_review is false and reasons are clear for image issues
                $needsReviewReasons = array_diff($needsReviewReasons, ['invalid image', 'no suitable image found']);
                if (empty($needsReviewReasons)) {
                    $book->needs_review = false;
                }
                $book->needs_review_reasons = json_encode(array_values($needsReviewReasons));
                if ($book->isDirty()) {
                    $book->save();
                }
            }
        }

        if ($fixedCount > 0) {
            $this->info("Successfully fixed {$fixedCount} cover image issues.");
        } else {
            $this->info("No missing/invalid cover images found that could be fixed.");
        }

        $this->comment("\n--- Checking for unreferenced image files on disk ---");
        $issuesFound = false;

        // Get all unique book directory paths from the database
        $bookDirectoryPaths = $books->pluck('directoryPath')->unique()->filter()->all();

        foreach ($bookDirectoryPaths as $bookDirectoryPath) {
            // List all files in the book's directory on the 'books' disk
            $fullBookDirPath = rtrim($bookDirectoryPath, '/');

            Log::debug("Scanning directory for unreferenced images: {$fullBookDirPath}");

            if (!Storage::disk($diskName)->exists($fullBookDirPath)) {
                $this->warn("Book directory does not exist on '{$diskName}' disk: {$fullBookDirPath}");
                continue;
            }

            $filesInDir = Storage::disk($diskName)->files($fullBookDirPath);

            if (empty($filesInDir)) {
                Log::debug("No files found in directory: {$fullBookDirPath}");
            }

            foreach ($filesInDir as $filePath) {
                $extension = pathinfo($filePath, PATHINFO_EXTENSION);
                if (!in_array(strtolower($extension), $this->imageExtensions)) {
                    Log::debug("Skipping non-image file: {$filePath}");
                    continue; // Not an image file
                }

                // Check if this image file is referenced by any book in the database
                $isReferenced = $books->contains(function ($book) use ($filePath) {
                    if (empty($book->coverImage)) {
                        return false;
                    }

                    // Now, $book->coverImage is expected to be the full path relative to the disk root
                    // So, we compare $book->coverImage directly with $filePath
                    Log::debug("Comparing: Book ID {$book->id}, coverImage: {$book->coverImage} vs. Scanned FilePath: {$filePath}");

                    return $book->coverImage === $filePath;
                });

                if (!$isReferenced) {
                    $this->warn("Unreferenced image file found: {$filePath}");
                    $issuesFound = true;
                }
            }
        }

        if (!$issuesFound) {
            $this->info("No unreferenced image files found on disk.");
        }

        $this->info('Cover image consistency check complete.');

        return Command::SUCCESS;
    }

    /**
     * Finds the best cover image filename in a book's directory based on a priority system.
     *
     * @param Book $book
     * @param string $diskName
     * @return string|null The full relative path of the best image, or null if none found.
     */
    protected function findBestCoverImage(Book $book, string $diskName): ?string
    {
        $directoryPath = $book->directoryPath;
        if (empty($directoryPath)) {
            Log::debug("Book ID: {$book->id} has no directoryPath. Cannot find cover.");
            return null;
        }

        $fullBookDirPath = rtrim($directoryPath, '/');

        if (!Storage::disk($diskName)->exists($fullBookDirPath)) {
            Log::debug("Book directory does not exist on '{$diskName}' disk: {$fullBookDirPath}");
            return null;
        }

        $filesInDir = Storage::disk($diskName)->files($fullBookDirPath);

        $bestCoverCandidate = null;
        $audibleGoogleCandidate = null;
        $bestTitleMatchCandidate = null;
        $anyImageCandidate = null;

        $normalizedBookTitle = Str::slug($book->title ?? ''); // Normalize title for comparison

        foreach ($filesInDir as $filePath) {
            $extension = pathinfo($filePath, PATHINFO_EXTENSION);
            if (!in_array(strtolower($extension), $this->imageExtensions)) {
                continue; // Not an image file
            }

            $fileName = basename($filePath);
            $normalizedFileName = Str::slug(pathinfo($fileName, PATHINFO_FILENAME));

            Log::debug("  - Evaluating image: {$filePath}");

            // Priority 1: Filename contains "cover"
            if (Str::contains(strtolower($fileName), 'cover')) {
                $bestCoverCandidate = $filePath;
                Log::debug("    -> Found 'cover' candidate: {$filePath}");
                break; // Highest priority, so we can stop here
            }

            // Priority 1.5: Filename contains "audible" or "google"
            if (Str::contains(strtolower($fileName), ['audible', 'google'])) {
                if (empty($audibleGoogleCandidate)) { // Only take the first one found
                    $audibleGoogleCandidate = $filePath;
                    Log::debug("    -> Found 'audible/google' candidate: {$filePath}");
                }
            }

            // Priority 2: Filename contains a reasonable portion of the book title
            // Check if a significant part of the title is in the filename
            if (!empty($normalizedBookTitle) && Str::contains($normalizedFileName, $normalizedBookTitle)) {
                if (empty($bestTitleMatchCandidate)) { // Only take the first best title match
                    $bestTitleMatchCandidate = $filePath;
                    Log::debug("    -> Found title match candidate: {$filePath}");
                }
            }

            // Priority 3: Any image
            if (empty($anyImageCandidate)) {
                $anyImageCandidate = $filePath;
                Log::debug("    -> Found any image candidate: {$filePath}");
            }
        }

        return $bestCoverCandidate ?? $audibleGoogleCandidate ?? $bestTitleMatchCandidate ?? $anyImageCandidate;
    }

    /**
     * Process cover image path to ensure it includes directoryPath if needed
     *
     * @param string $coverImagePath
     * @param string|null $directoryPath
     * @return string
     */
    protected function processCoverImagePath(string $coverImagePath, ?string $directoryPath = null): string
    {
        if (empty($directoryPath)) {
            return $coverImagePath;
        }

        // If the cover image path already contains the directory path, return as-is
        if (Str::contains($coverImagePath, $directoryPath)) {
            return $coverImagePath;
        }

        // Check if file exists without directoryPath prefix
        $diskName = 'books';
        $bookStoragePath = env('BOOK_STORAGE_PATH', '/media/audiobooks/books');

        // If the coverImagePath is just a filename, check if it needs directoryPath prefix
        $baseFileName = basename($coverImagePath);
        $coverWithoutDir = rtrim($directoryPath, '/') . '/' . $baseFileName;
        $coverWithDir = $coverImagePath;

        // Check if file exists with directoryPath prefix
        if (Storage::disk($diskName)->exists($coverWithoutDir)) {
            $this->info("    -> Adding directoryPath prefix to cover: {$coverWithoutDir}");
            return $coverWithoutDir;
        }

        return $coverImagePath;
    }
}
