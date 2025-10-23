<?php

namespace App\Console\Commands;

use App\Models\Book;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FixBookDirectoryPath extends Command
{
    protected $signature = 'books:fix-directory-path {id} {path} {--dry-run}';
    protected $description = 'Fix the directory path for a specific book';

    public function handle()
    {
        $book = Book::find($this->argument('id'));

        if (!$book) {
            $this->error('Book not found');
            return 1;
        }

        $newPath = $this->argument('path');

        $this->info("Current directory_path: " . ($book->directory_path ?? 'NULL'));
        $this->info("New directory_path:     " . $newPath);

        if ($this->option('dry-run')) {
            $this->info('[DRY RUN] Would update book directory path');
            return 0;
        }

        try {
            $book->directory_path = $newPath;
            $book->save();

            $this->info('Successfully updated book directory path');
            Log::info("Updated directory_path for book ID {$book->id} to: $newPath");

            return 0;
        } catch (\Exception $e) {
            $this->error('Error updating book: ' . $e->getMessage());
            Log::error('Error updating book directory path', [
                'book_id' => $book->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }
}
