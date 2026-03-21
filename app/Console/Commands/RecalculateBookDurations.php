<?php

namespace App\Console\Commands;

use App\Contracts\DocumentStoreServiceInterface;
use App\Services\AudioFileAnalyzer;
use Illuminate\Console\Command;

class RecalculateBookDurations extends Command
{
    protected $signature = 'books:recalculate-durations
                            {--all : Recalculate durations for all books}
                            {--id= : Recalculate duration for a specific book ID}
                            {--since= : Recalculate books added or modified on or after this date (YYYY-MM-DD)}
                            {--search= : Recalculate books whose title matches this text}
                            {--series= : Recalculate books belonging to this series name}
                            {--directory= : Recalculate books whose directory path contains this string}
                            {--dry-run : Show what would change without saving}';

    protected $description = 'Recalculate book durations by scanning audio files on disk';

    public function __construct(
        private DocumentStoreServiceInterface $documentStore,
        private AudioFileAnalyzer $audioFileAnalyzer
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $bookRoot = rtrim((string) config('app.book_root', '/media/lyra_data1/audiobooks/books'), '/');

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be saved');
        }

        $books = $this->resolveBooks();
        if ($books === null) {
            return 1;
        }

        if (empty($books)) {
            $this->warn('No books matched the given filters.');
            return 0;
        }

        $stats = ['total' => count($books), 'updated' => 0, 'unchanged' => 0, 'no_files' => 0, 'errors' => 0];
        $bar = $this->output->createProgressBar($stats['total']);
        $bar->start();

        foreach ($books as $book) {
            $bar->advance();
            $bookId = (string) ($book['id'] ?? '');
            $title = $book['title'] ?? 'Unknown';
            $directoryPath = $book['directoryPath'] ?? $book['directory_path'] ?? null;

            if (!$directoryPath) {
                $stats['no_files']++;
                continue;
            }

            $absolutePath = $bookRoot . '/' . ltrim($directoryPath, '/');
            if (!is_dir($absolutePath)) {
                $stats['no_files']++;
                continue;
            }

            try {
                $durationInfo = $this->audioFileAnalyzer->getDirectoryAudioDuration($absolutePath);
                $newSeconds = (int) $durationInfo['total_seconds'];

                if ($newSeconds === 0) {
                    $stats['no_files']++;
                    continue;
                }

                $oldSeconds = (int) ($book['duration'] ?? 0);
                $oldFormatted = $oldSeconds > 0 ? gmdate('H:i:s', $oldSeconds) : '(none)';
                $newFormatted = $durationInfo['formatted'];

                if ($oldSeconds === $newSeconds) {
                    $stats['unchanged']++;
                    continue;
                }

                $bar->clear();
                $this->line(sprintf(
                    '  <fg=yellow>%s</> [%s] %s → <fg=green>%s</>',
                    $bookId,
                    $title,
                    $oldFormatted,
                    $newFormatted
                ));
                $bar->display();

                if (!$dryRun) {
                    $this->documentStore->updateBook($bookId, ['duration' => $newSeconds]);
                }

                $stats['updated']++;
            } catch (\Throwable $e) {
                $bar->clear();
                $this->error("  Error processing book {$bookId} ({$title}): " . $e->getMessage());
                $bar->display();
                $stats['errors']++;
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->line('═══════════════════════════════════════════════════════════════');
        $this->line('  Summary' . ($dryRun ? ' (DRY RUN)' : ''));
        $this->line('═══════════════════════════════════════════════════════════════');
        $this->line("  Total books:     {$stats['total']}");
        $this->line("  <fg=green>Updated:</>        {$stats['updated']}");
        $this->line("  <fg=cyan>Unchanged:</>      {$stats['unchanged']}");
        $this->line("  <fg=yellow>No audio files:</> {$stats['no_files']}");
        $this->line("  <fg=red>Errors:</>         {$stats['errors']}");
        $this->line('═══════════════════════════════════════════════════════════════');

        if ($dryRun && $stats['updated'] > 0) {
            $this->newLine();
            $this->info('Run without --dry-run to apply these changes.');
        }

        return 0;
    }

    /**
     * Resolve the set of books to process based on the provided options.
     * Returns null on invalid input, empty array if nothing matches.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function resolveBooks(): ?array
    {
        $id = $this->option('id');
        $all = $this->option('all');
        $since = $this->option('since');
        $search = $this->option('search');
        $series = $this->option('series');
        $directory = $this->option('directory');

        if (!$id && !$all && !$since && !$search && !$series && !$directory) {
            $this->error('Specify at least one filter: --all, --id, --since, --search, --series, or --directory');
            return null;
        }

        if ($id) {
            $book = $this->documentStore->getBook((string) $id);
            return $book ? [$book] : [];
        }

        $filters = ['include_needs_review' => true];

        if ($search) {
            $filters['search'] = $search;
        }
        if ($series) {
            $filters['series'] = $series;
        }

        $result = $this->documentStore->listBooks(1, 100000, $filters, true);
        $books = $result['data'] ?? [];

        if ($since) {
            $sinceTs = strtotime($since);
            if ($sinceTs === false) {
                $this->error("Invalid --since date: {$since}. Use YYYY-MM-DD format.");
                return null;
            }
            $books = array_filter($books, function (array $book) use ($sinceTs): bool {
                $updated = $book['updated_at'] ?? $book['created_at'] ?? null;
                return $updated && strtotime($updated) >= $sinceTs;
            });
        }

        if ($directory) {
            $needle = strtolower($directory);
            $books = array_filter($books, function (array $book) use ($needle): bool {
                $path = strtolower((string) ($book['directoryPath'] ?? $book['directory_path'] ?? ''));
                return str_contains($path, $needle);
            });
        }

        return array_values($books);
    }
}
