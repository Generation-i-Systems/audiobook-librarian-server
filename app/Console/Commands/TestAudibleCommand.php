<?php

namespace App\Console\Commands;

use App\Services\AudibleService;
use Illuminate\Console\Command;

class TestAudibleCommand extends Command
{
    protected $signature = 'test:audible
                            {action : search or details}
                            {query? : Search query or ASIN for details}
                            {--a|author= : Filter by author}
                            {--l|limit=5 : Max results for search}';

    protected $description = 'Test Audible API integration';

    private AudibleService $audibleService;

    public function __construct(AudibleService $audibleService)
    {
        parent::__construct();
        $this->audibleService = $audibleService;
    }

    public function handle()
    {
        $action = $this->argument('action');
        $query = $this->argument('query');
        $author = $this->option('author');
        $limit = (int) $this->option('limit');

        if (!in_array($action, ['search', 'details'])) {
            $this->error('Action must be either "search" or "details"');

            return 1;
        }

        if ($action === 'search') {
            if (!$query) {
                $query = $this->ask('Enter search query:');
            }
            $this->handleSearch($query, $author, $limit);
        } else {
            if (!$query) {
                $query = $this->ask('Enter ASIN:');
            }
            $this->handleDetails($query);
        }

        return 0;
    }

    private function handleSearch(string $query, ?string $author, int $limit): void
    {
        $this->info("Searching for: {$query}" . ($author ? " by {$author}" : ''));

        $books = $this->audibleService->searchBooks($query, [
            'author' => $author,
            'limit' => $limit,
        ]);

        if (empty($books)) {
            $this->error('No books found or an error occurred.');

            return;
        }

        $this->info("\nFound " . count($books) . " results:\n");

        foreach ($books as $index => $book) {
            $this->line(($index + 1) . ". {$book['title']}");
            $this->line('   Authors: ' . implode(', ', array_column($book['authors'] ?? [], 'author.name')));
            $this->line('   Narrators: ' . implode(', ', array_column($book['narrators'] ?? [], 'author.name')));
            $this->line('   Published: ' . ($book['releaseDate'] ?? 'N/A'));
            $this->line('   Cover: ' . ($book['coverImageUrl'] ?? 'N/A'));
            $this->line('   ASIN: ' . ($book['asin'] ?? 'N/A'));
            $this->line('');
        }
    }

    private function handleDetails(string $asin): void
    {
        $this->info("Fetching details for ASIN: {$asin}");

        $book = $this->audibleService->getBookDetails($asin);

        if (!$book) {
            $this->error('Book not found or an error occurred.');

            return;
        }

        $this->info("\nTitle: {$book['title']}");
        $this->line('Subtitle: ' . ($book['subtitle'] ?? 'N/A'));
        $this->line('Authors: ' . implode(', ', array_column($book['authors'] ?? [], 'author.name')));
        $this->line('Narrators: ' . implode(', ', array_column($book['narrators'] ?? [], 'author.name')));
        $this->line('Publisher: ' . ($book['publisher']['name'] ?? 'N/A'));
        $this->line('Published: ' . ($book['releaseDate'] ?? 'N/A'));
        $this->line('Duration: ' . ($book['duration'] ?? 'N/A'));

        // Handle genres
        $genres = array_map(function ($genre) {
            return $genre['genre']['name'] ?? null;
        }, $book['genres'] ?? []);
        $genres = array_filter($genres);
        $this->line('Genres: ' . (!empty($genres) ? implode(', ', $genres) : 'N/A'));

        // Handle rating safely
        $rating = null;
        $ratingsCount = 0;

        if (isset($book['rating']) && is_array($book['rating'])) {
            if (isset($book['rating']['average_rating'])) {
                $rating = is_numeric($book['rating']['average_rating']) ? round($book['rating']['average_rating'], 1) : null;
            }
            if (isset($book['rating']['ratings_count'])) {
                $ratingsCount = is_numeric($book['rating']['ratings_count']) ? (int) $book['rating']['ratings_count'] : 0;
            }
        }

        $ratingDisplay = $rating !== null ? "$rating/5" : 'N/A';
        $ratingsDisplay = $ratingsCount > 0 ? " ($ratingsCount ratings)" : '';
        $this->line("Rating: $ratingDisplay$ratingsDisplay");

        // Handle cover image
        $coverUrl = $book['coverImageUrl'] ?? null;
        if ($coverUrl) {
            $this->line("Cover: $coverUrl");
            $this->line('Cover (direct): ' . str_replace('_SL500_', '_SL1000_', $coverUrl));
        } else {
            $this->line('Cover: N/A');
        }

        if (!empty($book['description'])) {
            $this->line("\nDescription:");
            $this->line(wordwrap($book['description'], 80));
        }
    }
}
