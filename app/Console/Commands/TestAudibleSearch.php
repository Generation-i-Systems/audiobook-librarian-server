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
        $limit = (int) $this->option('limit');

        $this->info("Searching for: $query" . ($author ? " by $author" : ''));

        try {
            $options = [];
            if ($author) {
                $options['author'] = $author;
            }
            if ($limit) {
                $options['limit'] = $limit;
            }

            if ($this->option('no-cache')) {
                $options['no_cache'] = true;
            }

            Log::info('TestAudibleSearch: Calling audibleService->searchBooks.', [
                'query' => $query,
                'options' => $options,
            ]);

            $results = $this->audibleService->searchBooks($query, $options);

            if (empty($results)) {
                $this->warn('No results found');

                return 0;
            }

            $this->info("\nFound " . count($results) . " results:\n");

            foreach ($results as $index => $book) {
                $this->line('<fg=yellow>[' . ($index + 1) . '] ' . (is_array($book['title']) ? implode(', ', $book['title']) : $book['title']) . '</>');
                $this->line('ASIN: ' . (is_array($book['id']) ? implode(', ', $book['id']) : $book['id']));

                if (!empty($book['authors'])) {
                    $authors = array_map(function ($author) {
                        $authorId = $author['author']['id'] ?? null;
                        $authorStr = $author['author']['name'];
                        if ($authorId) {
                            $authorStr .= ' (ID: ' . $authorId . ')';
                        }

                        return $authorStr;
                    }, $book['authors']);
                    $this->line('Authors: ' . implode(', ', $authors));
                }

                if (!empty($book['narrators'])) {
                    $narrators = array_map(function ($narratorItem) {
                        $narratorId = $narratorItem['narrator']['id'] ?? null;
                        $narratorStr = $narratorItem['narrator']['name'];
                        if ($narratorId) {
                            $narratorStr .= ' (ID: ' . $narratorId . ')';
                        }

                        return $narratorStr;
                    }, $book['narrators']);
                    $this->line('Narrators: ' . implode(', ', $narrators));
                }

                if (!empty($book['publisher'])) {
                    $publisher = is_array($book['publisher']) && isset($book['publisher']['name']) ? $book['publisher']['name'] : (is_array($book['publisher']) ? implode(
                        ', ',
                        $book['publisher']
                    ) : $book['publisher']);
                    $this->line('Publisher: ' . $publisher);
                }

                if (!empty($book['release_date'])) {
                    $releaseDate = is_array($book['release_date']) ? implode(', ', $book['release_date']) : $book['release_date'];
                    $this->line('Release Date: ' . $releaseDate);
                }

                if (!empty($book['coverImageUrl'])) {
                    $coverImage = is_array($book['coverImageUrl']) ? implode(', ', $book['coverImageUrl']) : $book['coverImageUrl'];
                    $this->line('Cover: ' . $coverImage);
                }

                if (!empty($book['series'])) {
                    if (is_array($book['series']) && isset($book['series']['name'])) {
                        $seriesName = $book['series']['name'];
                        $seriesPart = $book['series']['part'] ?? 'N/A';
                        $this->line('Series: ' . $seriesName . ' (Part: ' . $seriesPart . ')');
                    } elseif (is_array($book['series'])) {
                        // Associative array: seriesName => seriesNumber
                        $seriesParts = [];
                        foreach ($book['series'] as $seriesName => $seriesNumber) {
                            $seriesParts[] = $seriesName . ' #' . $seriesNumber;
                        }
                        $this->line('Series: ' . implode(', ', $seriesParts));
                    } else {
                        $this->line('Series: ' . $book['series']);
                    }
                } elseif (!empty($book['series_number'])) {
                    $seriesNumber = is_array($book['series_number']) ? implode(', ', $book['series_number']) : $book['series_number'];
                    $this->line('Series Part: ' . $seriesNumber);
                }

                $this->line(''); // Empty line between results
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            Log::error('TestAudibleSearch: Exception caught.', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 1;
        }
    }
}
