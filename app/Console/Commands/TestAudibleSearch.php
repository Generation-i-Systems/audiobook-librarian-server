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
        // Attempt 1: Log the default driver
        try {
            $defaultDriverInstance = Log::getLogger();
            $driverClass = $defaultDriverInstance ? get_class($defaultDriverInstance) : 'N/A';
            $this->info("Default log driver class: " . $driverClass); // Output to console
            Log::info('TestAudibleSearch: Default driver info.', ['driver_class' => $driverClass]);
        } catch (\Throwable $e) {
            $this->error("Error getting default log driver: " . $e->getMessage());
            try {
                // Emergency log for driver issue
                $emergencyLogger = new \Monolog\Logger('emergency_driver_error');
                $emergencyLogger->pushHandler(new \Monolog\Handler\StreamHandler(storage_path('logs/emergency_debug.log'), \Monolog\Level::Debug));
                $emergencyLogger->error("EMERGENCY: Error getting default log driver in TestAudibleSearch: " . $e->getMessage());
            } catch (\Throwable $innerE) {
/* Suppress */
            }
        }

        // Attempt 2: Standard Log::info (this replaces the previous simple one)
        Log::info('TestAudibleSearch: handle method called (standard log attempt).');

        // Attempt 3: Custom logger to a new file
        $customLogPath = storage_path('logs/custom_command_debug.log');
        try {
            $customLogger = Log::build([
                'driver' => 'single',
                'path' => $customLogPath,
                'level' => env('LOG_LEVEL', 'debug'),
            ]);
            $customLogger->info('TestAudibleSearch: Log message to custom_command_debug.log.', ['time' => now()->toDateTimeString()]);
            $this->info("Attempted to write to custom log: " . $customLogPath); // Console output
        } catch (\Throwable $e) {
            $this->error("Error setting up/writing to custom logger ('{$customLogPath}'): " . $e->getMessage());
            try {
                 // Emergency log for custom logger issue
                $emergencyLogger = new \Monolog\Logger('emergency_custom_log_error');
                $emergencyLogger->pushHandler(new \Monolog\Handler\StreamHandler(storage_path('logs/emergency_debug.log'), \Monolog\Level::Debug));
                $emergencyLogger->error("EMERGENCY: Error with custom logger in TestAudibleSearch: " . $e->getMessage());
            } catch (\Throwable $innerE) {
/* Suppress */
            }
        }

        $query = $this->argument('query');
        $author = $this->option('author');
        $limit = (int)$this->option('limit');

        $this->info("Searching for: $query" . ($author ? " by $author" : ''));

        try {
            $options = [];
            if ($author) {
                $options['author'] = $author;
            }
            if ($limit) {
                $options['limit'] = $limit;
            }

            // Add no-cache logic
            $noCacheOption = $this->option('no-cache');
            if ($noCacheOption) {
                $options['no_cache'] = true;
            }

            // Log the state of no_cache being passed to the service
            // Ensure $customLogger is available; it's defined earlier in this method.
            if (isset($customLogger)) {
                $customLogger->info('TestAudibleSearch: no-cache option details.', ['no_cache_input' => $noCacheOption, 'final_options_to_service' => $options]);
            }
            $this->info('Attempting to call audibleService->searchBooks...');
            $results = $this->audibleService->searchBooks($query, $options);

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
                    $narrators = array_map(function ($narratorItem) {
                        return $narratorItem['narrator']['name'] .
                               (!empty($narratorItem['narrator']['id']) ? " (ID: {$narratorItem['narrator']['id']})" : '');
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

                if (!empty($book['series']) && is_array($book['series']) && isset($book['series']['name'])) {
                    $seriesName = $book['series']['name'];
                    $seriesPart = $book['series']['part'] ?? 'N/A';
                    $this->line("Series: {$seriesName} (Part: {$seriesPart})");
                } elseif (!empty($book['series']) && is_string($book['series'])) {
                    // Fallback for older data or if series is just a string name
                    $this->line("Series: {$book['series']}");
                } elseif (!empty($book['series_number'])) {
                    // Fallback if only series_number is available (less likely with new transform)
                    $this->line("Series Part: {$book['series_number']}");
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
