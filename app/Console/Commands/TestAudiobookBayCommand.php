<?php

namespace App\Console\Commands;

use App\Services\AudiobookBayService;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TestAudiobookBayCommand extends Command
{
    /**
     * @var AudiobookBayService
     */
    protected AudiobookBayService $audiobookBayService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(AudiobookBayService $audiobookBayService)
    {
        parent::__construct();
        $this->audiobookBayService = $audiobookBayService;
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audiobookbay:test 
        {query? : Search query}
        {--id= : Get details for a specific book ID}
        {--debug : Show raw API responses}
        {--list : List results without showing details}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test AudiobookBay API login and search functionality';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $bookId = $this->option('id');
        $query = $this->argument('query');
        $debug = $this->option('debug');
        $listOnly = $this->option('list');

        $this->info("Testing AudiobookBay API...");
        $this->line("");

        // Test login
        $this->info("1. Testing login...");
        $cookie = $this->audiobookBayService->getApiService()->getAudiobookBayCookie();

        if (empty($cookie)) {
            $this->error("❌ Login failed. Please check your AUDIOBOOK_BAY_USERNAME and AUDIOBOOK_BAY_PASSWORD in .env");
            $this->line("Make sure your credentials are correct and your IP is not blocked by AudiobookBay.");
            return 1;
        }

        $this->info("✅ Login successful!");
        $this->line("");

        // If book ID is provided, fetch details directly
        if ($bookId) {
            return $this->handleBookDetails($bookId, $debug);
        }

        // If no query provided, show help
        if (!$query) {
            $this->info("No search query provided. Here's an example search:");
            $this->line("  php artisan audiobookbay:test 'stephen king'");
            $this->line("  php artisan audiobookbay:test --id=book-id-123");
            return 0;
        }

        // Perform search
        $this->info("2. Searching for: {$query}");
        $html = $this->audiobookBayService->getApiService()->audiobookBaySearch($query);

        if (empty($html)) {
            $this->error("❌ Search failed or returned no results");
            return 1;
        }

        $this->info("✅ Search successful!");
        $this->line("");

        // Parse results
        $this->info("3. Parsing search results...");
        $results = $this->audiobookBayService->getApiService()->parseSearchResults($html);

        if (empty($results)) {
            $this->warn("⚠️  No results found or failed to parse results");

            if ($debug) {
                $this->line("\nRaw HTML response:");
                $this->line($html);
            }

            return 0;
        }

        $this->info(sprintf("✅ Found %d results", count($results)));

        // Display results
        $this->displayResults($results);

        // If not in list-only mode, show details for the first result
        if (!$listOnly && !empty($results[0]['link'])) {
            $this->line("\n4. Fetching details for first result...");
            return $this->handleBookDetails($results[0]['link'], $debug);
        }

        // If debug mode, show raw HTML
        if ($debug) {
            $this->line("\nRaw HTML response:");
            $this->line($html);
        }

        return 0;
    }


    /**
     * Display search results in a table
     *
     * @param array $results
     * @return void
     */
    protected function displayResults(array $results): void
    {
        $headers = ['#', 'Title', 'Author', 'Narrator', 'Size', 'Format'];
        $rows = [];

        foreach ($results as $i => $result) {
            $rows[] = [
                $i + 1,
                $result['title'],
                $result['author'],
                $result['narrator'] ?: 'N/A',
                $result['size'] ?: 'N/A',
                $result['format'] ?: 'N/A',
            ];
        }

        $this->table($headers, $rows);

        if (count($results) > 0) {
            $this->line("\nTo view details for a specific book, use:");
            $this->line("  php artisan audiobookbay:test --id=book-id-123");
            $this->line("  (replace 'book-id-123' with the book's URL or ID)");
        }
    }

    /**
     * Handle fetching and displaying book details
     */
    protected function handleBookDetails(string $bookIdOrUrl, bool $debug = false): int
    {
        // If it's not a full URL, assume it's just the ID
        $url = filter_var($bookIdOrUrl, FILTER_VALIDATE_URL)
            ? $bookIdOrUrl
            : 'https://audiobookbay.lu/audiobook/' . $bookIdOrUrl;

        $this->info("Fetching details from: " . $url);

        // Use AudiobookBayService public method if available, fallback to apiService
        if (method_exists($this->audiobookBayService, 'performGetBookDetails')) {
            $book = $this->audiobookBayService->performGetBookDetails($url);
        } else {
            $book = $this->audiobookBayService->getApiService()->getAudiobookDetails($url);
        }

        if (empty($book)) {
            $this->error("❌ Failed to fetch book details");
            return 1;
        }

        $this->info("\n📚 " . ($book['title'] ?? 'No Title'));
        $this->line(str_repeat('=', 80));

        // Display basic info
        $this->line("" . ($book['description'] ?? 'No description available') . "\n");

        $info = [
            'Author' => $book['author'] ?? 'Unknown',
            'Narrator' => $book['narrator'] ?? 'Unknown',
            'Series' => isset($book['series']) ?
                ($book['series'] . (isset($book['seriesNumber']) ? ' #' . $book['seriesNumber'] : '')) : 'N/A',
            'Published' => $book['datePublished'] ?? 'Unknown',
            'Categories' => !empty($book['category']) ? implode(', ', $book['category']) : 'N/A',
            'Keywords' => !empty($book['keywords']) ? implode(', ', $book['keywords']) : 'N/A',
        ];

        foreach ($info as $label => $value) {
            $this->line("<fg=blue>{$label}:</> {$value}");
        }

        // Display cover image if available
        if (!empty($book['cover_image'])) {
            $this->line("\n📷 Cover: " . $book['cover_image']);
        }

        // If debug mode, show raw data
        if ($debug) {
            $this->line("\n📋 Raw data:");
            $this->line(json_encode($book, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return 0;
    }
}
