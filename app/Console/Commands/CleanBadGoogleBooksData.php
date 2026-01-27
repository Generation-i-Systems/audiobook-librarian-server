<?php

namespace App\Console\Commands;

use App\Models\Book;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanBadGoogleBooksData extends Command
{
    protected $signature = 'books:clean-bad-google-data
                            {--dry-run : Show what would be cleaned without making changes}';

    protected $description = 'Clean books with obviously wrong Google Books data (wrong year, wrong description, etc)';

    public function handle(): int
    {
        $this->info('🔍 Finding books with bad Google Books data...');

        // Find books with suspicious data patterns
        $books = Book::whereNotNull('google_books_info')
            ->where(function ($query) {
                // Books with 1971-01-01 date (default/garbage date)
                $query->where('release_date', '1971-01-01')
                    // Or books with "Micrographic reproduction" in description (wrong book match)
                    ->orWhere('description', 'like', '%Micrographic reproduction%')
                    ->orWhere('description', 'like', '%Oxford English dictionary%')
                    // Or books with "English language" as genre
                    ->orWhereHas('genres', function ($q) {
                        $q->where('name', 'English language');
                    });
            })
            ->get();

        if ($books->isEmpty()) {
            $this->info('✅ No books with bad Google Books data found!');
            return 0;
        }

        $this->warn("Found {$books->count()} books with suspicious Google Books data");
        $this->newLine();

        $cleaned = 0;

        foreach ($books as $book) {
            $issues = [];

            if ($book->release_date === '1971-01-01') {
                $issues[] = 'garbage date (1971-01-01)';
            }

            if (str_contains($book->description ?? '', 'Micrographic reproduction')) {
                $issues[] = 'wrong book description';
            }

            if ($book->genres()->where('name', 'English language')->exists()) {
                $issues[] = 'garbage genre';
            }

            $this->info("📖 {$book->title}");
            $this->line("   Author: " . $book->authors->pluck('name')->implode(', '));
            $this->line("   Issues: " . implode(', ', $issues));
            /** @var array<mixed>|string|null $googleBooksInfo */
            $googleBooksInfo = $book->google_books_info;
            $googleBooksId = 'unknown';
            if (is_string($googleBooksInfo)) {
                $decoded = json_decode($googleBooksInfo, true);
                if (is_array($decoded) && isset($decoded['id'])) {
                    $googleBooksId = (string) $decoded['id'];
                }
            } elseif (is_array($googleBooksInfo) && isset($googleBooksInfo['id'])) {
                $googleBooksId = (string) $googleBooksInfo['id'];
            }
            $this->line("   Google Books ID: " . $googleBooksId);

            if ($this->option('dry-run')) {
                $this->line("   [DRY RUN] Would clean Google Books data");
            } else {
                DB::beginTransaction();
                try {
                    // Clear Google Books data
                    $book->google_books_info = null;

                    // Reset bad fields to null
                    if ($book->release_date === '1971-01-01') {
                        $book->release_date = null;
                    }

                    if (str_contains($book->description ?? '', 'Micrographic reproduction')) {
                        $book->description = null;
                    }

                    // Remove garbage genres
                    $book->genres()->where('name', 'English language')->detach();

                    // Flag for review
                    $book->needs_review = true;
                    $book->save();

                    DB::commit();
                    $this->line("   ✓ Cleaned");
                    $cleaned++;
                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->error("   ✗ Failed: " . $e->getMessage());
                }
            }

            $this->newLine();
        }

        if ($this->option('dry-run')) {
            $this->info("🔍 [DRY RUN] Would clean {$cleaned} books");
        } else {
            $this->info("✅ Cleaned {$cleaned} books");
            $this->info("📝 All cleaned books are flagged with needs_review=true");
        }

        return 0;
    }
}
