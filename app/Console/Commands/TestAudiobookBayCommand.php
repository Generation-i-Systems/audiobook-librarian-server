<?php

namespace App\Console\Commands;

use App\Services\AudiobookBayService;
use App\Services\AudiobookBayApiService;
use Illuminate\Console\Command;

class TestAudiobookBayCommand extends Command
{
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
        {--list : List results without showing details}
        {--get-images : Download cover images for matched books}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test AudiobookBay API login and search functionality. Use --get-images to download cover images.';

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

        $this->info('Testing AudiobookBay API...');
        $this->line('');

        // Test login
        $this->info('1. Testing login...');
        $cookie = $this->audiobookBayService->getApiService()->getAudiobookBayCookie();

        if (empty($cookie)) {
            $this->error('❌ Login failed. Please check your AUDIOBOOK_BAY_USERNAME and AUDIOBOOK_BAY_PASSWORD in .env');
            $this->line('Make sure your credentials are correct and your IP is not blocked by AudiobookBay.');

            return 1;
        }

        $this->info('✅ Login successful!');
        $this->line('');

        // If book ID is provided, fetch details directly
        if ($bookId) {
            return $this->handleBookDetails($bookId, $debug);
        }

        // If no query provided, show help
        if (!$query) {
            $this->info("No search query provided. Here's an example search:");
            $this->line("  php artisan audiobookbay:test 'stephen king'");
            $this->line('  php artisan audiobookbay:test --id=book-id-123');

            return 0;
        }

        // Parse results
        $this->info('3. Parsing search results...');

        /** @var string|array<mixed> $response */
        $response = $this->audiobookBayService->getApiService()->audiobookBaySearch($query);

        if (empty($response)) {
            $this->error('❌ Search failed or returned no results');

            return 1;
        }

        $results = is_array($response)
            ? $response
            : $this->audiobookBayService->getApiService()->parseSearchResults((string) $response);

        if (empty($results)) {
            $this->warn('⚠️  No results found or failed to parse results');

            if ($debug) {
                $this->line("\nRaw HTML response:");
                /** @phpstan-ignore-next-line */
                $this->line(is_array($response) ? 'Array response' : (string) $response);
            }

            return 0;
        }

        $this->info(sprintf('✅ Found %d results', count($results)));

        // Display results
        $this->displayResults($results);

        // Enrich logic: try to match as in searchAndMerge
        if (!$listOnly) {
            $inputTitle = $query;
            $inputAuthor = '';
            // Try to extract author from query if in 'title - author' format
            if (strpos($query, ' - ') !== false) {
                [$inputTitle, $inputAuthor] = array_map('trim', explode(' - ', $query, 2));
            }
            $inputNumber = null;
            $bestMatch = null;
            $bestScore = 0;
            $matchReason = '';
            foreach ($results as $result) {
                $resultTitle = $result['title'] ?? '';
                $resultAuthors = $result['author'] ?? ($result['authors'][0]['name'] ?? '');
                $resultNumber = $result['number'] ?? null;
                // Author match: author name must appear in result title (case-insensitive)
                if ($inputAuthor && stripos($resultTitle, $inputAuthor) === false) {
                    $matchReason = "Author '{$inputAuthor}' not found in result title '{$resultTitle}'";

                    continue;
                }
                // Remove author from result title for comparison
                $titleNoAuthor = $resultTitle;
                if ($inputAuthor) {
                    $titleNoAuthor = trim(str_ireplace($inputAuthor, '', $resultTitle));
                }
                // Title similarity
                $sim = \App\Services\AudiobookBayApiService::calculateSimilarity($inputTitle, $titleNoAuthor);
                if ($sim < 0.7) {
                    $matchReason = "Title similarity too low (score: {$sim}) for '{$inputTitle}' vs '{$titleNoAuthor}'";

                    continue;
                }

                if ($sim > $bestScore) {
                    $bestScore = $sim;
                    $bestMatch = $result;
                }
            }
            if ($bestMatch && !empty($bestMatch['url'])) {
                $this->line("\n4. Enrich match found! Fetching details for best match...");

                return $this->handleBookDetails($bestMatch['url'], $debug);
            } else {
                $this->warn("\nNo sufficiently similar result found for enrichment.");
                $this->line("  Searched for: '{$inputTitle}'" . ($inputAuthor ? " by '{$inputAuthor}'" : ''));

                $this->line('  Search results:');
                foreach ($results as $i => $r) {
                    $sim = AudiobookBayApiService::calculateSimilarity($inputTitle, $r['title'] ?? '');
                    $authorMatch = $inputAuthor ? (stripos($r['title'] ?? '', $inputAuthor) !== false ? 'yes' : 'no') : 'n/a';
                    $this->line(
                        "    [{$i}] Title: '{$r['title']}' | Author match: {$authorMatch} | Similarity: {$sim}"
                    );
                }

                if ($matchReason) {
                    $this->line("  Last rejection reason: {$matchReason}");
                }
            }
        }

        // If debug mode, show raw HTML
        if ($debug) {
            $this->line("\nRaw HTML response:");
            $this->line(is_array($response) ? 'Array response' : (string) $response);
        }

        return 0;
    }

    /**
     * Display search results in a table
     */
    protected function displayResults(array $results): void
    {
        $headers = ['#', 'Title', 'Author', 'Narrator', 'Size', 'Format'];
        $rows = [];

        foreach ($results as $i => $result) {
            $rows[] = [
                $i + 1,
                $result['title'] ?? 'N/A',
                $result['author'] ?? 'N/A',
                $result['narrator'] ?? 'N/A',
                $result['size'] ?? 'N/A',
                $result['format'] ?? 'N/A',
            ];
        }

        $this->table($headers, $rows);

        if (count($results) > 0) {
            $this->line("\nTo view details for a specific book, use:");
            $this->line('  php artisan audiobookbay:test --id=book-id-123');
            $this->line("  (replace 'book-id-123' with the book's URL or ID)");
        }
    }

    /**
     * Handle fetching and displaying book details
     */
    protected function handleBookDetails(string $bookIdOrUrl, bool $debug = false): int
    {
        $getImages = $this->option('get-images');
        $url = $this->audiobookBayService->buildDetailsUrl($bookIdOrUrl);

        $this->info('Fetching details from: ' . $url);

        // Fetch details from service
        $book = $this->audiobookBayService->getApiService()->getAudiobookDetails($url);

        if (empty($book)) {
            $this->error('❌ Failed to fetch book details');

            return 1;
        }

        $this->info("\n📚 " . ($book['title'] ?? 'No Title'));
        $this->line(str_repeat('=', 80));

        // Display basic info
        $this->line('' . ($book['description'] ?? 'No description available') . "\n");

        $this->line('Cover: ' . ($book['coverImageUrl'] ?? 'No cover image available'));

        $info = [
            'Author' => $this->mapToString($book['authors'] ?? 'Unknown'),
            'Narrator' => $this->mapToString($book['narrators'] ?? 'Unknown'),
            'Series' => isset($book['series']) ? ($book['series'] . (isset($book['seriesNumber']) ? ' #' . $book['seriesNumber'] : '')) : 'N/A',
            'Published' => $book['datePublished'] ?? 'Unknown',
            'Genres' => !empty($book['genres']) ? implode(', ', $book['genres']) : 'N/A',
            'Tags' => !empty($book['tags']) ? implode(', ', $book['tags']) : 'N/A',
        ];

        foreach ($info as $label => $value) {
            $this->line("<fg=blue>{$label}:</> {$value}");
        }

        // Display cover image if available
        if (!empty($book['coverImage'])) {
            $this->line("\n📷 Cover: " . $book['coverImage']);
            if (
                $getImages &&
                !empty($book['coverImageUrl'])
            ) {
                $this->line('Attempting to download cover image...');
                $coverPath = $this->audiobookBayService->downloadCoverImage(
                    $book['coverImageUrl'],
                    storage_path('app/public/audiobookbay_covers'),
                    preg_replace('/[^a-zA-Z0-9_-]/', '_', $book['title'] ?? 'cover')
                );
                if ($coverPath) {
                    $this->info("✅ Cover image downloaded: $coverPath");
                } else {
                    $this->warn('⚠️  Failed to download cover image.');
                }
            }
        }

        // If debug mode, show raw data
        if ($debug) {
            $this->line("\n📋 Raw data:");
            $this->line(json_encode($book, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return 0;
    }

    // utility method to dump a list of authors or narrators to a string
    protected function mapToString(array $map): string
    {
        $result = '';
        foreach ($map as $item) {
            if (isset($item['author']) && isset($item['author']['name'])) {
                $result = $item['author']['name'];
            } elseif (isset($item['narrator']) && isset($item['narrator']['name'])) {
                $result = $item['narrator']['name'];
            } elseif (isset($item['name'])) {
                $result .= $item['name'] . ', ';
            }
        }

        return rtrim($result, ', ');
    }
}
