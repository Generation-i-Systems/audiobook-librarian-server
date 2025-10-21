<?php

namespace App\Console\Commands;

use App\Models\Genre;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class QuarantineInvalidGenreBooks extends Command
{
    protected $signature = 'books:quarantine-invalid-genres
                            {--dry-run : Show what would be moved without actually moving}';

    protected $description = 'Move all books with invalid genres to quarantine for reprocessing';

    public function handle(): int
    {
        $bookRoot = rtrim(config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');
        $quarantinePath = $bookRoot . '/_NEEDS_REPROCESSING';

        $this->info('🔍 Finding books with invalid genres...');

        $validGenres = [
            'Science Fiction', 'Fantasy', 'LitRPG', 'Romance', 'History',
            'Historical Fiction', 'Non Fiction', 'Religion', 'Church',
            'Kids', 'Action', 'Classic', 'General Fiction', 'Computer',
            'Western', 'Horror', 'Mystery', 'Other', 'Science',
        ];

        $invalidGenres = Genre::whereNotIn('name', $validGenres)->get();

        if ($invalidGenres->isEmpty()) {
            $this->info('✅ No invalid genres found!');
            return 0;
        }

        $this->warn("Found {$invalidGenres->count()} invalid genres");
        $this->newLine();

        $totalMoved = 0;
        $totalFailed = 0;

        foreach ($invalidGenres as $invalidGenre) {
            $books = $invalidGenre->books;

            if ($books->isEmpty()) {
                continue;
            }

            $this->info("📁 '{$invalidGenre->name}' ({$books->count()} books)");

            foreach ($books as $book) {
                $oldFullPath = $bookRoot . '/' . $book->directory_path;

                if (!file_exists($oldFullPath)) {
                    $this->warn("  ⚠️  Directory not found: {$book->directory_path}");
                    $totalFailed++;
                    continue;
                }

                // New path in quarantine, preserving author/series structure
                $pathParts = explode('/', $book->directory_path);
                // Remove the genre (first part)
                array_shift($pathParts);
                $newRelativePath = '_NEEDS_REPROCESSING/' . implode('/', $pathParts);
                $newFullPath = $bookRoot . '/' . $newRelativePath;

                if ($this->option('dry-run')) {
                    $this->line("  [DRY RUN] Would move: {$book->title}");
                    $this->line("    From: {$book->directory_path}");
                    $this->line("    To: {$newRelativePath}");
                } else {
                    try {
                        // Create parent directory
                        $parentDir = dirname($newFullPath);
                        if (!file_exists($parentDir)) {
                            File::makeDirectory($parentDir, 0755, true);
                        }

                        // Move the directory
                        File::move($oldFullPath, $newFullPath);

                        // Update database
                        $book->directory_path = $newRelativePath;
                        $book->needs_review = true;
                        $book->save();

                        $this->line("  ✓ Moved: {$book->title}");
                        $totalMoved++;
                    } catch (\Exception $e) {
                        $this->error("  ✗ Failed to move {$book->title}: " . $e->getMessage());
                        $totalFailed++;
                    }
                }
            }

            $this->newLine();
        }

        $this->newLine();
        if ($this->option('dry-run')) {
            $this->info("🔍 [DRY RUN] Would move {$totalMoved} books to {$quarantinePath}");
            if ($totalFailed > 0) {
                $this->warn("⚠️  {$totalFailed} books would fail to move");
            }
        } else {
            $this->info("✅ Moved {$totalMoved} books to {$quarantinePath}");
            if ($totalFailed > 0) {
                $this->warn("⚠️  {$totalFailed} books failed to move");
            }
            $this->info("📝 All moved books are flagged with needs_review=true");
            $this->info("🔄 You can now reprocess them with the correct import method");
        }

        return 0;
    }
}
