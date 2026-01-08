<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\BookDeletionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class PrepareForReprocessing extends Command
{
    protected $signature = 'books:prepare-reprocessing
                            {--dry-run : Show what would be done without making changes}
                            {--delete-db : Delete database entries for books in _NEEDS_REPROCESSING}
                            {--flatten : Flatten directory structure to max 1 level deep}
                            {--delete-google-covers : Delete Google Books cover images}
                            {--remove-paths : Remove author/series directory structure (move to root of _NEEDS_REPROCESSING)}
                            {--permanent : Permanently delete without using trash}';

    protected $description = 'Prepare books in _NEEDS_REPROCESSING for reimport';

    protected array $changeLog = [];

    public function __construct(protected BookDeletionService $deletionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $bookRoot = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
        $quarantinePath = $bookRoot . '/_NEEDS_REPROCESSING';
        $permanent = $this->option('permanent');

        if (!is_dir($quarantinePath)) {
            $this->error("❌ Directory not found: {$quarantinePath}");
            return 1;
        }

        if ($this->option('delete-db')) {
            if ($permanent) {
                $this->warn('Using --permanent flag: Books will be permanently deleted without trash.');
            } else {
                $this->info('Deleted books will be moved to trash (can be restored from /admin/trash).');
            }
        }

        $this->info('🔍 Finding books in _NEEDS_REPROCESSING...');
        $this->newLine();

        // Find all books in quarantine
        $books = Book::where('directory_path', 'like', '_NEEDS_REPROCESSING/%')->get();

        if ($books->isEmpty()) {
            $this->info('✅ No books found in _NEEDS_REPROCESSING');
            return 0;
        }

        $this->warn("Found {$books->count()} books in _NEEDS_REPROCESSING");
        $this->newLine();

        $logFile = storage_path('logs/reprocessing_' . date('Y-m-d_His') . '.log');
        $this->info("📝 Change log will be saved to: {$logFile}");
        $this->newLine();

        $deletedCount = 0;
        $flattenedCount = 0;
        $coversDeletedCount = 0;

        foreach ($books as $book) {
            $fullPath = $bookRoot . '/' . $book->directory_path;

            if (!is_dir($fullPath)) {
                $this->warn("  ⚠️  Directory not found: {$book->directory_path}");
                continue;
            }

            $this->info("📖 {$book->title}");
            $this->line("   Current path: {$book->directory_path}");

            // Log original state
            $this->logChange([
                'action' => 'prepare_reprocessing',
                'book_id' => $book->id,
                'title' => $book->title,
                'original_path' => $book->directory_path,
                'original_data' => [
                    'authors' => $book->authors->pluck('name')->toArray(),
                    'genres' => $book->genres->pluck('name')->toArray(),
                    'series' => $book->series->map(fn ($s) => [
                        'name' => $s->name,
                        'number' => $s->pivot->book_number ?? null,
                    ])->toArray(),
                    'publisher' => $book->publisher,
                    'release_date' => $book->release_date,
                    'duration' => $book->duration,
                    'cover_image' => $book->cover_image,
                ],
            ]);

            // Delete Google Books cover images
            if ($this->option('delete-google-covers')) {
                $coverDeleted = $this->deleteGoogleBooksCover($fullPath, $book);
                if ($coverDeleted) {
                    $coversDeletedCount++;
                }
            }

            // Remove author/series paths (move to root of _NEEDS_REPROCESSING)
            if ($this->option('remove-paths')) {
                $newPath = $this->removeAuthorSeriesPaths($fullPath, $book, $bookRoot);
                if ($newPath) {
                    if (!$this->option('dry-run')) {
                        $this->line("   ✓ Moved to: {$newPath['relative_path']}");
                        $fullPath = $newPath['full_path']; // Update for subsequent operations
                    }
                    // In dry-run, don't update fullPath since move didn't actually happen
                }
            }

            // Flatten directory structure (skip if dry-run and path was moved)
            if ($this->option('flatten')) {
                // In dry-run mode, skip flatten if remove-paths was specified (path doesn't exist yet)
                if ($this->option('dry-run') && $this->option('remove-paths')) {
                    $this->line("   ℹ️  [DRY RUN] Would flatten after move");
                } else {
                    $result = $this->flattenDirectory($fullPath, $book);
                    if ($result) {
                        $flattenedCount++;
                        $this->line("   ✓ Flattened to: {$result['relative_path']}");
                    } else {
                        $this->line("   ℹ️  Already flat (no changes needed)");
                    }
                }
            }

            // Delete database entry
            if ($this->option('delete-db')) {
                if (!$this->option('dry-run')) {
                    try {
                        if ($this->option('permanent')) {
                            // Permanently delete without trash
                            // Use trash system even for "permanent" delete
                            $result = $this->deletionService->moveToTrash((string) $book->id, false);

                            if ($result['success']) {
                                $this->line("   ✓ Moved to trash (database entry soft-deleted)");
                                $deletedCount++;
                            } else {
                                $this->error("   ✗ Failed to move to trash: " . ($result['error'] ?? 'Unknown error'));
                            }
                        } else {
                            // Move to trash (files are already in _NEEDS_REPROCESSING, don't move them again)
                            $result = $this->deletionService->moveToTrash((string) $book->id, false);

                            if ($result['success']) {
                                $this->line("   ✓ Moved to trash (database entry preserved)");
                                $deletedCount++;
                            } else {
                                $this->error("   ✗ Failed to move to trash: " . ($result['error'] ?? 'Unknown error'));
                            }
                        }
                    } catch (\Exception $e) {
                        if ($this->option('permanent')) {
                            DB::rollBack();
                        }
                        $this->error("   ✗ Failed to delete: " . $e->getMessage());
                    }
                } else {
                    $action = $this->option('permanent') ? 'permanently delete' : 'move to trash';
                    $this->line("   [DRY RUN] Would {$action} from database");
                    $deletedCount++;
                }
            }

            $this->newLine();
        }

        // Save change log
        if (!$this->option('dry-run')) {
            File::put($logFile, json_encode($this->changeLog, JSON_PRETTY_PRINT));
            $this->info("📝 Change log saved: {$logFile}");
        }

        $this->newLine();
        $this->info('=== Summary ===');
        if ($this->option('dry-run')) {
            $this->info("🔍 [DRY RUN] Would process {$books->count()} books");
            if ($this->option('delete-db')) {
                $this->info("   Would delete {$deletedCount} database entries");
            }
            if ($this->option('flatten')) {
                $this->info("   Would flatten {$flattenedCount} directories");
            }
            if ($this->option('delete-google-covers')) {
                $this->info("   Would delete {$coversDeletedCount} Google Books covers");
            }
        } else {
            $this->info("✅ Processed {$books->count()} books");
            if ($this->option('delete-db')) {
                $this->info("   Deleted {$deletedCount} database entries");
            }
            if ($this->option('flatten')) {
                $this->info("   Flattened {$flattenedCount} directories");
            }
            if ($this->option('delete-google-covers')) {
                $this->info("   Deleted {$coversDeletedCount} Google Books covers");
            }
        }

        return 0;
    }

    protected function deleteGoogleBooksCover(string $fullPath, Book $book): bool
    {
        $googleCover = $fullPath . '/cover_googlebooks.jpg';

        if (file_exists($googleCover)) {
            if (!$this->option('dry-run')) {
                try {
                    unlink($googleCover);
                    $this->line("   ✓ Deleted Google Books cover");
                    $this->logChange([
                        'action' => 'delete_google_cover',
                        'book_id' => $book->id,
                        'cover_path' => $googleCover,
                    ]);
                    return true;
                } catch (\Exception $e) {
                    $this->error("   ✗ Failed to delete cover: " . $e->getMessage());
                }
            } else {
                $this->line("   [DRY RUN] Would delete Google Books cover");
                return true;
            }
        }

        return false;
    }

    protected function flattenDirectory(string $fullPath, Book $book): ?array
    {
        // Check if directory is already flat (only audio files, no subdirectories with audio)
        $files = File::allFiles($fullPath);
        $audioFiles = array_filter($files, function ($file) {
            return in_array(strtolower($file->getExtension()), ['mp3', 'm4b', 'm4a', 'flac']);
        });

        // If only 1 audio file, it's already flat
        if (count($audioFiles) <= 1) {
            return null;
        }

        // Check if all audio files are in root (not in subdirectories)
        $allInRoot = true;
        foreach ($audioFiles as $file) {
            $relativePath = str_replace($fullPath . '/', '', $file->getPathname());
            if (str_contains($relativePath, '/')) {
                $allInRoot = false;
                break;
            }
        }

        if ($allInRoot) {
            return null; // Already flat
        }

        $bookRoot = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
        $relativePath = str_replace($bookRoot . '/', '', $fullPath);

        // Need to flatten - move all audio files to root
        if (!$this->option('dry-run')) {
            try {
                foreach ($audioFiles as $file) {
                    $newPath = $fullPath . '/' . $file->getFilename();
                    if ($file->getPathname() !== $newPath) {
                        File::move($file->getPathname(), $newPath);
                    }
                }

                // Remove empty subdirectories
                $subdirs = File::directories($fullPath);
                foreach ($subdirs as $subdir) {
                    if (count(File::allFiles($subdir)) === 0) {
                        File::deleteDirectory($subdir);
                    }
                }

                $this->line("   ✓ Flattened directory structure");
                $this->logChange([
                    'action' => 'flatten_directory',
                    'book_id' => $book->id,
                    'path' => $fullPath,
                    'relative_path' => $relativePath,
                    'files_moved' => count($audioFiles),
                ]);
                return [
                    'full_path' => $fullPath,
                    'relative_path' => $relativePath,
                ];
            } catch (\Exception $e) {
                $this->error("   ✗ Failed to flatten: " . $e->getMessage());
            }
        } else {
            $this->line("   [DRY RUN] Would flatten directory (move " . count($audioFiles) . " files to root)");
            return [
                'full_path' => $fullPath,
                'relative_path' => $relativePath,
            ];
        }

        return null;
    }

    protected function removeAuthorSeriesPaths(string $fullPath, Book $book, string $bookRoot): ?array
    {
        // Extract just the book directory name from the full path
        // Example: "_NEEDS_REPROCESSING/Wrong Author/Wrong Series/Book Title" -> "Book Title"
        $pathParts = explode('/', $book->directory_path);

        // Get the last part (book directory name)
        $bookDirName = end($pathParts);

        // New path: _NEEDS_REPROCESSING/BookTitle
        $newRelativePath = '_NEEDS_REPROCESSING/' . $bookDirName;
        $newFullPath = $bookRoot . '/' . $newRelativePath;

        // Check if already at root level
        if ($book->directory_path === $newRelativePath) {
            return null; // Already at root
        }

        // Check if target already exists
        if (file_exists($newFullPath)) {
            $this->warn("   ⚠️  Target already exists: {$newRelativePath}");
            return null;
        }

        if (!$this->option('dry-run')) {
            try {
                // Move the directory
                File::move($fullPath, $newFullPath);

                // Update database
                $book->directory_path = $newRelativePath;
                $book->save();

                $this->logChange([
                    'action' => 'remove_paths',
                    'book_id' => $book->id,
                    'old_path' => $book->directory_path,
                    'new_path' => $newRelativePath,
                ]);

                return [
                    'full_path' => $newFullPath,
                    'relative_path' => $newRelativePath,
                ];
            } catch (\Exception $e) {
                $this->error("   ✗ Failed to move: " . $e->getMessage());
                return null;
            }
        } else {
            $this->line("   [DRY RUN] Would move to: {$newRelativePath}");
            return [
                'full_path' => $newFullPath,
                'relative_path' => $newRelativePath,
            ];
        }
    }

    protected function logChange(array $data): void
    {
        $data['timestamp'] = now()->toIso8601String();
        $this->changeLog[] = $data;
    }
}
