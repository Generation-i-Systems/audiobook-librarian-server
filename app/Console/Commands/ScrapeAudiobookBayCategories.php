<?php

namespace App\Console\Commands;

use App\Models\FavoriteAuthor;
use App\Services\AudiobookBayCategoryScraperService;
use Illuminate\Console\Command;

class ScrapeAudiobookBayCategories extends Command
{
    protected $signature = 'abb:scrape-categories
                            {--category= : Specific category to scrape (sci-fi, fantasy, litrpg)}
                            {--all : Scrape all categories}
                            {--no-enrich : Skip fetching full details for each book}';

    protected $description = 'Scrape AudiobookBay categories for new books by favorite authors';

    protected AudiobookBayCategoryScraperService $scraperService;

    public function __construct(AudiobookBayCategoryScraperService $scraperService)
    {
        parent::__construct();
        $this->scraperService = $scraperService;
    }

    public function handle(): int
    {
        $this->info('Starting AudiobookBay category scraping...');

        $categoriesToScrape = $this->determineCategories();

        if (empty($categoriesToScrape)) {
            $this->error('No valid categories specified');

            return Command::FAILURE;
        }

        $totalNewBooks = 0;
        $totalMatchedBooks = 0;

        foreach ($categoriesToScrape as $category) {
            $this->info("Scraping category: $category");

            $lastSeenAbbId = $this->scraperService->getLastSeenBookForCategory($category);

            if ($lastSeenAbbId) {
                $this->comment("Last seen book for $category: $lastSeenAbbId");
            } else {
                $this->comment("No previous books found for $category, will scrape first page only");
                $lastSeenAbbId = 'first-run';
            }

            $newBooks = $this->scraperService->scrapeCategoryUntilLastSeen($category, $lastSeenAbbId === 'first-run' ? null : $lastSeenAbbId);

            if ($lastSeenAbbId === 'first-run' && count($newBooks) > 20) {
                $newBooks = array_slice($newBooks, 0, 20);
                $this->comment('First run, limited to 20 books');
            }

            $this->info("Found " . count($newBooks) . " new books in $category");

            $matchedBooks = $this->processNewBooks($newBooks);

            $totalNewBooks += count($newBooks);
            $totalMatchedBooks += $matchedBooks;

            $this->info("Matched $matchedBooks books with favorite authors in $category");
        }

        $this->info("\nScraping complete!");
        $this->info("Total new books: $totalNewBooks");
        $this->info("Books matching favorites: $totalMatchedBooks");

        return Command::SUCCESS;
    }

    protected function determineCategories(): array
    {
        if ($this->option('all')) {
            return $this->scraperService->getCategories();
        }

        if ($category = $this->option('category')) {
            if (!in_array($category, $this->scraperService->getCategories())) {
                $this->error("Invalid category: $category");
                $this->info("Valid categories: " . implode(', ', $this->scraperService->getCategories()));

                return [];
            }

            return [$category];
        }

        return $this->scraperService->getCategories();
    }

    protected function processNewBooks(array $newBooks): int
    {
        $favoriteAuthors = $this->getAllFavoriteAuthors();
        $matchedCount = 0;

        $enrichDetails = !$this->option('no-enrich');

        foreach ($newBooks as $book) {
            if ($enrichDetails) {
                $book = $this->scraperService->enrichBookWithDetails($book);
                usleep(250000); // 0.25 second delay between detail fetches
            }

            $saved = $this->scraperService->saveDiscoveredBook($book);

            if ($saved && $this->matchesFavoriteAuthor($book, $favoriteAuthors)) {
                $matchedCount++;
                $this->line("  ✓ Matched: {$book['title']} by {$book['author']}");
            }
        }

        return $matchedCount;
    }

    protected function getAllFavoriteAuthors(): array
    {
        return FavoriteAuthor::with('user')
            ->get()
            ->pluck('author_name')
            ->map(fn ($name) => strtolower(trim($name)))
            ->unique()
            ->toArray();
    }

    protected function matchesFavoriteAuthor(array $book, array $favoriteAuthors): bool
    {
        if (empty($book['author'])) {
            return false;
        }

        $bookAuthor = strtolower(trim($book['author']));

        foreach ($favoriteAuthors as $favoriteAuthor) {
            if (str_contains($bookAuthor, $favoriteAuthor) || str_contains($favoriteAuthor, $bookAuthor)) {
                return true;
            }
        }

        return false;
    }
}
