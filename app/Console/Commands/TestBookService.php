<?php

namespace App\Console\Commands;

use App\Facades\BookService;
use Illuminate\Console\Command;

class TestBookService extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:book-service
                            {query : The search query}
                            {--service= : Specific service to use (audible, google_books, etc.)}
                            {--limit=5 : Maximum number of results to return}
                            {--details : Fetch full details for the first result}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the book service integration';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $query = $this->argument('query');
        $serviceName = $this->option('service');
        $limit = (int) $this->option('limit');
        $fetchDetails = $this->option('details');

        $this->info("Searching for: {$query}");

        if ($serviceName) {
            $this->info("Using service: {$serviceName}");
            $services = [$serviceName => BookService::get($serviceName)];

            if (!$services[$serviceName]) {
                $this->error("Service '{$serviceName}' not found.");
                $this->line('Available services: ' . implode(', ', array_keys(BookService::all())));

                return 1;
            }
        } else {
            $services = BookService::all();
            $this->info('Using all available services: ' . implode(', ', array_keys($services)));
        }

        // Search for books
        $results = [];
        foreach ($services as $name => $service) {
            $this->line("\nSearching {$name}...");

            try {
                $serviceResults = $service->searchBooks($query, ['limit' => $limit]);

                if (empty($serviceResults)) {
                    $this->warn("No results from {$name}");

                    continue;
                }

                $results[$name] = $serviceResults;
                $this->info(sprintf('Found %d result(s) from %s', count($serviceResults), $name));

                // Display search results
                $this->displayResults($serviceResults, $name);

                // Fetch details for first result if requested
                if ($fetchDetails) {
                    $firstResult = $serviceResults[0];
                    $this->info("\nFetching details for: " . ($firstResult['title'] ?? 'Unknown Title'));

                    $details = $service->getBookDetails($firstResult['id']);
                    if ($details) {
                        $this->displayBookDetails($details);
                    } else {
                        $this->warn('Failed to fetch details for this book.');
                    }
                }
            } catch (\Exception $e) {
                $this->error("Error searching {$name}: " . $e->getMessage());
                if ($this->getOutput()->isVerbose()) {
                    $this->line($e->getTraceAsString());
                }
            }
        }

        if (empty($results)) {
            $this->warn('No results found from any service.');

            return 1;
        }

        return 0;
    }

    /**
     * Display search results in a table
     */
    protected function displayResults(array $results, string $serviceName): void
    {
        $headers = ['#', 'Title', 'Author', 'Year', 'ID'];
        $rows = [];

        foreach ($results as $i => $book) {
            $author = $book['authors'][0]['author']['name'] ?? 'Unknown';
            if (count($book['authors'] ?? []) > 1) {
                $author .= ' et al.';
            }

            $rows[] = [
                $i + 1,
                $book['title'] ?? 'Unknown Title',
                $author,
                $book['published_date'] ? substr($book['published_date'], 0, 4) : 'N/A',
                $book['id'] ?? 'N/A',
            ];
        }

        $this->table($headers, $rows);
    }

    /**
     * Display detailed book information
     */
    protected function displayBookDetails(array $book): void
    {
        $this->line('"' . ($book['title'] ?? 'Unknown Title') . '"');

        if (!empty($book['subtitle'])) {
            $this->line("Subtitle: {$book['subtitle']}");
        }

        $this->line('');

        $this->line('Authors: ' . $this->formatPeople($book['authors'] ?? []));

        $this->line('Narrators: ' . $this->formatPeople($book['narrators'] ?? []));

        if (!empty($book['publisher']['name'])) {
            $this->line("Publisher: {$book['publisher']['name']}");
        }

        if (!empty($book['published_date'])) {
            $this->line("Published: {$book['published_date']}");
        }

        if (!empty($book['description'])) {
            $this->line('');
            $this->line(wordwrap($book['description'], 80));
        }

        if (!empty($book['cover_image_url'])) {
            $this->line('');
            $this->line("Cover: {$book['cover_image_url']}");
        }
    }

    /**
     * Format an array of people (authors, narrators) into a string
     */
    protected function formatPeople(array $people): string
    {
        $names = [];

        foreach ($people as $person) {
            // Handle different structure variations
            $name = $person['author']['name'] ??
                    $person['narrator']['name'] ??
                    $person['name'] ??
                    null;

            if ($name) {
                $names[] = $name;
            }
        }

        return implode(', ', $names) ?: 'Unknown';
    }
}
