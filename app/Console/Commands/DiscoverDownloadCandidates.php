<?php

namespace App\Console\Commands;

use App\Models\Author;
use App\Models\Series;
use App\Services\DownloadCandidateDiscoveryService;
use Illuminate\Console\Command;

class DiscoverDownloadCandidates extends Command
{
    protected $signature = 'abb:discover-candidates
                            {--author-id= : Only discover for this author ID}
                            {--series-id= : Only discover for this series ID}';

    protected $description = 'Search AudiobookBay for new releases by favorited authors/series and stage them for review';

    public function __construct(
        protected DownloadCandidateDiscoveryService $discoveryService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($authorId = $this->option('author-id')) {
            $author = Author::findOrFail($authorId);
            $created = $this->discoveryService->discoverForAuthor($author);
            $this->info("Discovered {$created->count()} candidate(s) for author: {$author->name}");

            return Command::SUCCESS;
        }

        if ($seriesId = $this->option('series-id')) {
            $series = Series::findOrFail($seriesId);
            $created = $this->discoveryService->discoverForSeries($series);
            $this->info("Discovered {$created->count()} candidate(s) for series: {$series->name}");

            return Command::SUCCESS;
        }

        $summary = $this->discoveryService->discoverAll();
        $this->info(
            "Checked {$summary['authors']} favorited author(s) and {$summary['series']} favorited series - " .
            "created {$summary['candidates_created']} new candidate(s)."
        );

        return Command::SUCCESS;
    }
}
