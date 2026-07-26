<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\BookFileChunkHashService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class CacheBookFileChunkHashes extends Command
{
    protected $signature = 'books:cache-file-chunk-hashes
        {books?* : Book ids to process}
        {--book=* : Book id to process; may be used more than once}
        {--newest= : Process this many newest books}
        {--all : Process all local books}
        {--limit= : Stop after this many selected books}
        {--max-load= : Skip or stop when 1-minute system load is at or above this value}
        {--force : Regenerate cached hashes even when file metadata still matches}';

    protected $description = 'Pre-generate cached per-file chunk SHA-256 hashes for download manifests';

    public function __construct(private readonly BookFileChunkHashService $chunkHashService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $maxLoad = $this->floatOption('max-load');
        if ($this->isSystemLoadTooHigh($maxLoad)) {
            $this->warn(sprintf(
                'Skipping: 1-minute system load %.2f is at or above threshold %.2f.',
                $this->currentOneMinuteLoad() ?? 0.0,
                $maxLoad
            ));
            return self::SUCCESS;
        }

        $bookIds = $this->selectedBookIds();
        $newest = $this->intOption('newest');
        $limit = $this->intOption('limit');
        $force = (bool) $this->option('force');

        if ($bookIds === [] && $newest === null && ! $this->option('all')) {
            $this->error('Select books with book ids, --book, --newest, or --all.');
            return self::INVALID;
        }

        $query = $this->buildBookQuery($bookIds, $newest, $limit);

        $processedBooks = 0;
        $generated = 0;
        $cached = 0;
        $skipped = 0;
        $missing = 0;

        foreach ($query->cursor() as $book) {
            if ($this->isSystemLoadTooHigh($maxLoad)) {
                $this->warn(sprintf(
                    'Stopping: 1-minute system load %.2f is at or above threshold %.2f.',
                    $this->currentOneMinuteLoad() ?? 0.0,
                    $maxLoad
                ));
                break;
            }

            $stats = $this->chunkHashService->cacheBook($book, $force);
            $processedBooks++;
            $generated += $stats['generated'];
            $cached += $stats['cached'];
            $skipped += $stats['skipped'];
            $missing += $stats['missing'];

            $this->line(sprintf(
                'Book %d: %d generated, %d cached, %d missing, %d skipped',
                $book->id,
                $stats['generated'],
                $stats['cached'],
                $stats['missing'],
                $stats['skipped']
            ));
        }

        $this->info(sprintf(
            'Processed %d books: %d files generated, %d already cached, %d missing files/directories, %d skipped books.',
            $processedBooks,
            $generated,
            $cached,
            $missing,
            $skipped
        ));

        return self::SUCCESS;
    }

    /**
     * @param array<int, int> $bookIds
     * @return Builder<Book>
     */
    private function buildBookQuery(array $bookIds, ?int $newest, ?int $limit): Builder
    {
        $query = Book::query()
            ->whereNotNull('directory_path')
            ->where(function (Builder $query): void {
                $query->whereNull('source')->orWhere('source', '!=', 'librivox');
            });

        if ($bookIds !== []) {
            $query->whereIn('id', $bookIds)->orderBy('id');
        } elseif ($newest !== null) {
            $query->orderByDesc('created_at')->limit($newest);
        } else {
            $query->orderBy('id');
        }

        if ($limit !== null && $newest === null) {
            $query->limit($limit);
        }

        return $query;
    }

    /**
     * @return array<int, int>
     */
    private function selectedBookIds(): array
    {
        $ids = array_merge($this->argument('books'), $this->option('book'));

        return array_values(array_unique(array_map('intval', array_filter($ids, static function (mixed $id): bool {
            return is_numeric($id) && (int) $id > 0;
        }))));
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }

    private function floatOption(string $name): ?float
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function isSystemLoadTooHigh(?float $maxLoad): bool
    {
        $load = $this->currentOneMinuteLoad();

        return $maxLoad !== null && $load !== null && $load >= $maxLoad;
    }

    private function currentOneMinuteLoad(): ?float
    {
        $load = sys_getloadavg();

        return is_array($load) ? (float) $load[0] : null;
    }
}
