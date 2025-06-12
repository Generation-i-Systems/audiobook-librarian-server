<?php

namespace App\Console\Commands;

use App\Services\AudibleService;
use Illuminate\Console\Command;

class TestAudibleLookup extends Command
{
    protected $signature = 'test:audible {asin} {--debug} {--no-cache}';

    protected $description = 'Test Audible API lookup for a specific ASIN';

    protected $audibleService;

    public function __construct(AudibleService $audibleService)
    {
        parent::__construct();
        $this->audibleService = $audibleService;
    }

    public function handle()
    {
        $asin = $this->argument('asin');
        $debug = $this->option('debug');

        $this->info("Looking up ASIN: $asin");

        try {
            // Get book details
            $options = [];
            if ($this->option('no-cache')) {
                $options['no_cache'] = true;
            }
            $book = $this->audibleService->getBookDetails($asin, $options);

            if (!$book) {
                $this->error('No book found for the given ASIN');

                return 1;
            }

            // Display book information
            $this->info("\nBook Details:");
            $this->info('Title: ' . ($book['title'] ?? 'N/A'));
            $this->info('Subtitle: ' . ($book['subtitle'] ?? 'N/A'));

            // Display authors
            $this->info("\nAuthors:");
            if (!empty($book['authors'])) {
                foreach ($book['authors'] as $author) {
                    $this->info('- ' . ($author['author']['name'] ?? 'Unknown') .
                        (isset($author['author']['id']) ? " (ID: {$author['author']['id']})" : ''));
                }
            } else {
                $this->warn('No authors found');
            }

            // Display narrators
            $this->info("\nNarrators:");
            if (!empty($book['narrators'])) {
                foreach ($book['narrators'] as $narrator) {
                    $this->info('- ' . ($narrator['author']['name'] ?? 'Unknown') .
                        (isset($narrator['author']['id']) ? " (ID: {$narrator['author']['id']})" : ''));
                }
            } else {
                $this->warn('No narrators found');
            }

            // Display debug info if requested
            if ($debug) {
                $this->info("\nRaw API Response:");
                $this->line(json_encode($book, JSON_PRETTY_PRINT));
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            $this->error('Stack trace: ' . $e->getTraceAsString());

            return 1;
        }
    }
}
