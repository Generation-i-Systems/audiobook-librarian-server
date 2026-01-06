<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Book;
use Illuminate\Support\Facades\DB;

class CheckRelationships extends Command
{
    protected $signature = 'check:relationships {--limit=5 : Number of books to check}';
    protected $description = 'Check book relationships in the database';

    public function handle()
    {
        $limit = (int) $this->option('limit');

        $this->info("Checking relationships for up to $limit books...");

        // Get books with relationships
        $books = Book::with(['authors', 'narrators', 'genres'])->limit($limit)->get();

        if ($books->isEmpty()) {
            $this->warn('No books found in the database.');
            return 0;
        }

        $this->info("\nFound {$books->count()} books. Checking relationships...\n");

        foreach ($books as $book) {
            $this->line("<fg=blue>Book:</> {$book->title} (ID: {$book->id}, Mongo ID: {$book->mongo_id})");

            $authors = $book->authors->isNotEmpty() ? $book->authors->pluck('name')->join(', ') : 'None';
            $this->line("  Authors: " . $authors);

            $narrators = $book->narrators->isNotEmpty() ? $book->narrators->pluck('name')->join(', ') : 'None';
            $this->line("  Narrators: " . $narrators);

            $genres = $book->genres->isNotEmpty() ? $book->genres->pluck('name')->join(', ') : 'None';
            $this->line("  Genres: " . $genres);
            $this->line("");
        }

        // Check pivot table counts
        $this->info("\nPivot table counts:");
        $this->line("  author_book: " . DB::table('author_book')->count());
        $this->line("  book_narrator: " . DB::table('book_narrator')->count());
        $this->line("  book_genre: " . DB::table('book_genre')->count());

        return 0;
    }
}
