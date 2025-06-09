<?php

namespace App\Console\Commands;

use App\Services\AudibleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestAudibleSearch extends Command
{
    protected $signature = 'test:audible:search {query} {--author=} {--limit=5} {--no-cache}';
    protected $description = 'Test Audible API search functionality';

    protected $audibleService;

    public function __construct(AudibleService $audibleService)
    {
        parent::__construct();
        $this->audibleService = $audibleService;
    }

    public function handle()
    {
        $query = $this->argument('query');
        $author = $this->option('author');
        $limit = (int)$this->option('limit');

        $this->info("Searching for: $query" . ($author ? " by $author" : ''));

        try {
            $results = $this->audibleService->searchBooks($query, $author, $limit);

            if (empty($results)) {
                $this->warn('No results found');
                return 0;
            }

            $this->info("\nFound " . count($results) . " results:\n");

            foreach ($results as $index => $book) {
                $this->line("<fg=yellow>[" . ($index + 1) . "] {$book['title']}</>");
                $this->line("ASIN: {$book['id']}");

                if (!empty($book['authors'])) {
                    $authors = array_map(function ($author) {
                        return $author['author']['name'] .
                               (!empty($author['author']['id']) ? " (ID: {$author['author']['id']})" : '');
                    }, $book['authors']);
                    $this->line("Authors: " . implode(', ', $authors));
                }

                if (!empty($book['narrators'])) {
                    $narrators = array_map(function ($narrator) {
                        return $narrator['author']['name'] .
                               (!empty($narrator['author']['id']) ? " (ID: {$narrator['author']['id']})" : '');
                    }, $book['narrators']);
                    $this->line("Narrators: " . implode(', ', $narrators));
                }

                if (!empty($book['publisher']['name'])) {
                    $this->line("Publisher: {$book['publisher']['name']}");
                }

                if (!empty($book['release_date'])) {
                    $this->line("Release Date: {$book['release_date']}");
                }

                if (!empty($book['cover_image_url'])) {
                    $this->line("Cover: {$book['cover_image_url']}");
                }

                $this->line(''); // Empty line between results
            }

            return 0;

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            $this->error("Stack trace: " . $e->getTraceAsString());
            return 1;
        }
    }
}
