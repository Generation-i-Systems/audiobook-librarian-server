<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Book; // Assuming your Book model is here
use App\Services\ExternalCoverService;
use App\Services\AudibleService;
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
    protected $signature = 'cover:check {--attempt-audible : Attempt to fetch cover from Audible when local image not found} {--dry-run : Do not modify data or download files} {--limit=0 : Limit number of books processed (0 = no limit)}';

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

        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) ($this->option('limit') ?? 0);
        $attemptAudible = (bool) $this->option('attempt-audible');

        if ($dryRun) {
            $this->info('DRY RUN MODE: No changes will be made');
        }
        if ($limit > 0) {
            $this->info("Limiting to {$limit} books");
        }

        // Use the 'books' disk as defined in config/filesystems.php
        $diskName = 'books';

        // Ensure the disk is configured
        if (!config("filesystems.disks.$diskName")) {
            $this->error("Filesystem disk \"$diskName\" not configured. Please check config/filesystems.php");
            return Command::FAILURE;
        }

        $books = Book::query()->when($limit > 0, fn ($q) => $q->limit($limit))->get();

        $this->comment("\n--- Attempting to fix missing/invalid cover images ---");
        $fixedCount = 0;

        foreach ($books as $book) {
            $currentCoverImage = $book->coverImage;
            $directoryPath = $book->directoryPath;
            // Handle needs_review_reasons as an array per model casts; tolerate legacy string JSON
            $rawReasons = $book->needs_review_reasons;
            $needsReviewReasons = $this->normalizeNeedsReviewReasons($rawReasons);
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
                // Save as array (casted by Eloquent)
                $book->needs_review_reasons = array_values($needsReviewReasons);
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
                    if ($dryRun) {
                        $this->info("  -> DRY RUN: Would set coverImage to {$finalCoverPath} for Book ID: {$book->id}");
                    } else {
                        $book->coverImage = $finalCoverPath;
                        $book->needs_review = false; // Clear needs_review if fixed
                        $needsReviewReasons = array_diff($needsReviewReasons, ['invalid image']); // Remove reason
                        $book->needs_review_reasons = array_values($needsReviewReasons);
                        $book->save();
                        $this->info("  -> Fixed Book ID: {$book->id} - new coverImage: {$finalCoverPath}");
                    }
                    $fixedCount++;
                } else {
                    $this->error("  -> Could not find a suitable cover image for Book ID: {$book->id} in directory: {$directoryPath}");

                    // Attempt Audible fetch if requested
                    if ($attemptAudible) {
                        $this->comment('    -> Attempting to fetch cover from Audible...');
                        $audibleResult = $this->attemptFetchFromAudible($book, $directoryPath, $dryRun);
                        if ($audibleResult['success']) {
                            $finalCoverPath = $audibleResult['path'];
                            if ($dryRun) {
                                $this->info("    -> DRY RUN: Would set coverImage to {$finalCoverPath} for Book ID: {$book->id}");
                            } else {
                                $book->coverImage = $finalCoverPath;
                                $book->needs_review = false;
                                $needsReviewReasons = array_diff($needsReviewReasons, ['invalid image']);
                                $book->needs_review_reasons = array_values($needsReviewReasons);
                                $book->save();
                                $this->info("    -> Fetched and set cover from Audible: {$finalCoverPath}");
                            }
                            $fixedCount++;
                            // Continue to next book
                            continue;
                        } else {
                            $this->warn('    -> Audible fetch failed: ' . ($audibleResult['error'] ?? 'unknown error'));
                        }
                    }

                    // If no fix found, ensure needs_review is set
                    if (!$originalNeedsReview) { // Only set if it wasn't already set by directoryPath check
                        if ($dryRun) {
                            $this->info("    -> DRY RUN: Would set needs_review and add reason for Book ID: {$book->id}");
                        } else {
                            $book->needs_review = true;
                            if (!in_array('no suitable image found', $needsReviewReasons)) {
                                $needsReviewReasons[] = 'no suitable image found';
                            }
                            $book->needs_review_reasons = array_values($needsReviewReasons);
                            $book->save();
                        }
                    }
                }
            } else {
                // If no fix was needed, ensure needs_review is false and reasons are clear for image issues
                $needsReviewReasons = array_diff($needsReviewReasons, ['invalid image', 'no suitable image found']);
                if (empty($needsReviewReasons)) {
                    $book->needs_review = false;
                }
                $book->needs_review_reasons = array_values($needsReviewReasons);
                if ($book->isDirty()) {
                    if ($dryRun) {
                        $this->info("  -> DRY RUN: Would update review flags for Book ID: {$book->id}");
                    } else {
                        $book->save();
                    }
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
     * Attempt to fetch a cover image from Audible using existing audible_info or a live search.
     *
     * @param  Book   $book
     * @param  string $directoryPath
     * @param  bool   $dryRun
     * @return array{success: bool, path: string|null, error: string|null}
     */
    protected function attemptFetchFromAudible(Book $book, string $directoryPath, bool $dryRun = false): array
    {
        $result = ['success' => false, 'path' => null, 'error' => null];

        // Validate directory path exists on books disk before attempting download
        if (!Storage::disk('books')->exists($directoryPath)) {
            $result['error'] = 'Directory does not exist on books disk';
            return $result;
        }

        /** @var ExternalCoverService $externalCover */
        $externalCover = app(ExternalCoverService::class);
        /** @var AudibleService $audible */
        $audible = app(AudibleService::class);

        // 1) Try using audible_info if present
        $audibleInfo = $book->audibleInfo ?? [];
        $audibleCoverUrl = $audibleInfo['coverImageUrl'] ?? null;
        $audibleId = $audibleInfo['id'] ?? null;

        if ($audibleCoverUrl && $audibleId) {
            if ($dryRun) {
                return ['success' => true, 'path' => rtrim($directoryPath, '/') . '/cover_audible_' . $audibleId . '.jpg', 'error' => null];
            }
            $download = $externalCover->downloadCoverImage($audibleCoverUrl, $directoryPath, 'audible', $audibleId);
            if (!empty($download['success'])) {
                return ['success' => true, 'path' => $download['path'], 'error' => null];
            }
            // Fall through to search if download failed
        }

        // 2) Live search via Audible using title and first author if available
        $title = $book->title;
        $authorName = null;
        try {
            /** @var \App\Models\Author|null $firstAuthor */
            $firstAuthor = $book->authors()->select('authors.id', 'authors.name')->first();
            $authorName = $firstAuthor?->name;
        } catch (\Throwable $e) {
            Log::debug('CheckCoverImages: failed to load authors for book', ['bookId' => $book->id, 'error' => $e->getMessage()]);
        }

        $searchResults = $audible->searchBooksWithFiltering($title, $authorName, ['limit' => 3]);
        if (!empty($searchResults)) {
            $best = $searchResults[0];
            $coverUrl = $best['coverImageUrl'] ?? ($best['audibleCoverImageUrl'] ?? null);
            $asin = $best['id'] ?? null;
            if ($coverUrl && $asin) {
                if ($dryRun) {
                    return ['success' => true, 'path' => rtrim($directoryPath, '/') . '/cover_audible_' . $asin . '.jpg', 'error' => null];
                }
                $download = $externalCover->downloadCoverImage($coverUrl, $directoryPath, 'audible', $asin);
                if (!empty($download['success'])) {
                    return ['success' => true, 'path' => $download['path'], 'error' => null];
                }
                $result['error'] = $download['error'] ?? 'Download failed';
                return $result;
            }
        }

        $result['error'] = 'No Audible match found';
        return $result;
    }

    protected function normalizeNeedsReviewReasons(mixed $rawReasons): array
    {
        if (is_array($rawReasons)) {
            return $rawReasons;
        }

        if (is_string($rawReasons)) {
            $decoded = json_decode($rawReasons, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
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
        $bookStoragePath = config('filesystems.disks.books.root') ?? config('app.book_root');

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
