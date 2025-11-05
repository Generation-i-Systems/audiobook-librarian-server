<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Traits\HandlesLibraryJson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EnsureLibraryJson extends Command
{
    use HandlesLibraryJson;

    protected $signature = 'books:ensure-library-json
                            {--all : Process all books, not just those missing librarian.json}
                            {--book-id= : Process only the book with this ID}
                            {--dry-run : Show what would be done without making any changes}';

    protected $description = 'Ensure all books have librarian.json files in their directories';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $processAll = $this->option('all');
        $bookId = $this->option('book-id');

        if ($bookId) {
            $book = Book::with(['authors', 'narrators', 'genres', 'series', 'publisher'])
                ->find($bookId);

            if (!$book) {
                $this->error("Book with ID {$bookId} not found.");
                return 1;
            }

            $books = collect([$book]);
            $this->info(sprintf('Processing single book: %s (ID: %d)', $book->title, $book->id));
        } else {
            $query = Book::with(['authors', 'narrators', 'genres', 'series', 'publisher'])
                ->whereNotNull('directory_path');

            if (!$processAll) {
                $query->where(function ($q) {
                    $q->whereNull('last_library_json_update')
                      ->orWhereRaw('last_library_json_update < updated_at');
                });
            }

            $books = $query->get();
            $this->info(sprintf('Found %d books to process', $books->count()));
        }

        if ($books->isEmpty()) {
            $this->info('No books to process.');
            return 0;
        }

        $success = 0;
        $skipped = 0;
        $errors = 0;

        $bar = $this->output->createProgressBar($books->count());
        $bar->start();

        foreach ($books as $book) {
            try {
                if (empty($book->directory_path)) {
                    $this->line('');
                    $this->warn(sprintf('Skipping book %d (%s): No directory path', $book->id, $book->title));
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                if (!is_dir($book->directory_path)) {
                    $this->line('');
                    $this->warn(sprintf('Skipping book %d (%s): Directory does not exist: %s', $book->id, $book->title, $book->directory_path));
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                $jsonPath = rtrim($book->directory_path, '/') . '/librarian.json';

                if ($dryRun) {
                    if (!file_exists($jsonPath) || $processAll) {
                        $this->line('');
                        $this->line(sprintf('[DRY RUN] Would create/update librarian.json for: %s', $book->title));
                    }
                    $success++;
                } else {
                    $result = $this->updateLibraryJson($book);
                    if ($result) {
                        $success++;
                    } else {
                        $errors++;
                    }
                }
            } catch (\Exception $e) {
                $this->line('');
                $this->error(sprintf('Error processing book %d: %s', $book->id, $e->getMessage()));
                Log::error('Failed to ensure librarian.json', [
                    'book_id' => $book->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $errors++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Library JSON generation complete!');
        $this->line(sprintf('Processed: %d', $books->count()));
        $this->line(sprintf('Success: %d', $success));
        $this->line(sprintf('Skipped: %d', $skipped));
        $this->line(sprintf('Errors: %d', $errors));

        return $errors === 0 ? 0 : 1;
    }
}
