<?php

namespace App\Console\Commands;

use App\Services\HardcoverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class TestHardcoverCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hardcover:test {--title= : Book title to search for} {--author= : Author name to filter by}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the Hardcover API integration';

    /**
     * Execute the console command.
     */
    public function handle(HardcoverService $hardcoverService)
    {
        $title = $this->option('title') ?: 'Dune';
        $author = $this->option('author');

        $this->info("Searching for books with title: {$title}" . ($author ? " by {$author}" : ''));
        
        // Test search
        $books = $hardcoverService->searchBooks($title, $author);
        
        if (empty($books)) {
            $this->error('No books found or API request failed');
            return 1;
        }
        
        $this->info("\nFound " . count($books) . " books:");
        
        foreach ($books as $index => $book) {
            $this->line("\n[{$index}] " . $book['title']);
            $this->line("    ID: " . $book['id']);
            $this->line("    Authors: " . implode(', ', array_column($book['authors'] ?? [], 'author.name')));
            $this->line("    Genres: " . implode(', ', array_column($book['genres'] ?? [], 'genre.name')));
            $this->line("    Cover: " . ($book['cover_image_url'] ?? 'N/A'));
            
            if ($index === 0) {
                // Get details for the first book
                $this->info("\nGetting details for the first book...");
                $details = $hardcoverService->getBookDetails($book['id']);
                
                if ($details) {
                    $this->line("    Description: " . substr($details['description'] ?? 'N/A', 0, 100) . '...');
                    $this->line("    Pages: " . ($details['pages'] ?? 'N/A'));
                    $this->line("    Publisher: " . ($details['publisher']['name'] ?? 'N/A'));
                    $this->line("    Narrators: " . implode(', ', array_column($details['narrators'] ?? [], 'author.name')));
                }
            }
        }
        
        // Check token expiration
        $expiresAt = Config::get('hardcover.token_expires_at');
        if ($expiresAt) {
            $daysLeft = now()->diffInDays($expiresAt, false);
            $this->info("\nAPI token expires in: " . ($daysLeft > 0 ? "$daysLeft days" : 'EXPIRED'));
        } else {
            $this->warn("\nAPI token expiration date not set");
        }
        
        return 0;
    }
}
